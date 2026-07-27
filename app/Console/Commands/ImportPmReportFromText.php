<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\PmServiceReportImporter;
use Illuminate\Console\Command;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

/**
 * Carga un PM Service Report que llegó en **PDF** en vez de Excel.
 *
 * El cliente manda normalmente un .xlsx y el panel solo acepta eso. El reporte
 * del 24/07/2026 llegó en PDF, y esperar el Excel dejaba a la flota corriendo
 * con datos de una semana antes —tres máquinas pasadas de servicio sin que el
 * panel lo supiera—. Este comando es el puente.
 *
 * **No abre una superficie nueva de UI a propósito.** Es un comando de consola,
 * así que no agrega acciones, ni permisos, ni una pantalla que habría que
 * auditar entera. Y no reimplementa nada: convierte el texto al mismo formato de
 * dos filas por máquina que ya lee `PmServiceReportImporter` y le entrega el
 * archivo, de modo que TODA la regla (coherencia, no retroceder, ancla,
 * duplicados, bitácora, warnings) es exactamente la misma que la del panel.
 *
 * Entrada: la salida de
 *
 *     pdftotext -table "PM_Service Report as of 7242026.pdf" reporte.txt
 *
 * `-table` es importante: reproduce la estructura de columnas. Con `-layout` las
 * lecturas se desalinean de su máquina y el resultado es basura silenciosa.
 */
class ImportPmReportFromText extends Command
{
    protected $signature = 'pm:import-text
        {file : Ruta al .txt generado con `pdftotext -table`}
        {--apply : Escribe. Sin este flag solo informa qué haría}
        {--causer= : Email del usuario que queda como autor en la bitácora}';

    protected $description = 'Importa un PM Service Report que llegó en PDF, por el mismo camino que el importador del panel.';

    public function handle(PmServiceReportImporter $importer): int
    {
        $ruta = $this->argument('file');

        if (! is_readable($ruta)) {
            $this->error("No puedo leer {$ruta}.");

            return self::FAILURE;
        }

        $registros = $this->parse(file($ruta, FILE_IGNORE_NEW_LINES));

        if ($registros === []) {
            $this->error('No encontré ninguna máquina en el archivo. ¿Lo generaste con `pdftotext -table`?');

            return self::FAILURE;
        }

        $this->info(count($registros).' fila(s) de máquina leídas del texto.');

        $duplicados = [];
        $vistos = [];
        foreach ($registros as $r) {
            if (isset($vistos[$r['id']])) {
                $duplicados[$r['id']] = true;
            }
            $vistos[$r['id']] = true;
        }

        if ($duplicados !== []) {
            $this->warn('Códigos repetidos en el archivo: '.implode(', ', array_keys($duplicados)).
                ' — los resuelve el importador por fecha de lectura y los declara.');
        }

        if (! $this->option('apply')) {
            $this->table(
                ['id', 'último servicio', 'última lectura', 'restantes'],
                array_map(fn ($r) => [$r['id'], $r['last'], $r['reading'], $r['remaining']], array_slice($registros, 0, 15))
            );
            $this->line('… ('.count($registros).' en total)');
            $this->newLine();
            $this->warn('SIMULACIÓN: no se escribió nada. Agregá --apply para importar.');

            return self::SUCCESS;
        }

        $causer = null;
        if ($email = $this->option('causer')) {
            $causer = User::where('email', $email)->first();
            if (! $causer) {
                $this->error("No existe el usuario {$email}.");

                return self::FAILURE;
            }
        }

        $xlsx = $this->buildXlsx($registros);
        $resultado = $importer->import($xlsx, $causer, basename($ruta));
        @unlink($xlsx);

        $this->newLine();
        $this->info(count($resultado['updated']).' máquina(s) actualizadas.');

        if ($resultado['unmatched'] !== []) {
            $this->warn(count($resultado['unmatched']).' código(s) del reporte sin máquina en el sistema: '
                .implode(', ', array_column($resultado['unmatched'], 'id_code')));
        }

        if ($resultado['warnings'] !== []) {
            $this->newLine();
            $this->warn('Avisos ('.count($resultado['warnings']).'):');
            foreach ($resultado['warnings'] as $aviso) {
                $this->line('  - '.$aviso);
            }
        }

        return self::SUCCESS;
    }

    /**
     * Convierte el texto de `pdftotext -table` en filas. Mismo criterio que el
     * parser del xlsx: la cabecera de máquina es la línea cuyo primer token
     * mezcla letras y dígitos, y la siguiente trae ubicación + los pares
     * "<horas> Hrs <fecha>" + las horas restantes.
     *
     * @param  array<int, string>  $lineas
     * @return array<int, array{id: string, last: string, reading: string, remaining: string}>
     */
    private function parse(array $lineas): array
    {
        $registros = [];
        $indiceActual = null;

        foreach ($lineas as $linea) {
            $trim = trim($linea);

            if ($trim === ''
                || str_contains($trim, 'MACHINE DESCRIPTION')
                || str_contains($trim, 'DP DEVELOPMENT')
                || str_contains($trim, 'PM SERVICE REPORT')) {
                continue;
            }

            $esCabecera = preg_match('/^([A-Z][A-Z0-9\-]{2,18})\s+(\S.*)$/', $trim, $m) === 1
                && preg_match('/[0-9]/', $m[1]) === 1
                && preg_match('/[0-9]\s*(Hrs|Mls)\.?\s+[0-9]{1,2}\//i', $trim) !== 1;

            if ($esCabecera) {
                $registros[] = ['id' => strtoupper($m[1]), 'last' => '', 'reading' => '', 'remaining' => '', 'nota' => ''];
                $indiceActual = count($registros) - 1;

                continue;
            }

            if ($indiceActual === null) {
                continue;
            }

            $r = &$registros[$indiceActual];

            preg_match_all('/([0-9][0-9,]*)\s*(Hrs|Mls)\.?\s+([0-9]{1,2}\/[0-9]{1,2}\/[0-9]{2,4})/i', $trim, $pares, PREG_SET_ORDER);

            if (isset($pares[0]) && $r['last'] === '') {
                $r['last'] = $this->normalizar($pares[0]);
            }
            if (isset($pares[1]) && $r['reading'] === '') {
                $r['reading'] = $this->normalizar($pares[1]);
            }
            if ($r['remaining'] === '' && preg_match('/([0-9][0-9,]*)\s*(Hrs|Mls)\.?\s*$/i', $trim, $mm)) {
                $r['remaining'] = str_replace(',', '', $mm[1]).' '.ucfirst(strtolower($mm[2]));
            }
            // "PAST DUE" es el 0 del cliente: el servicio ya venció.
            if ($r['remaining'] === '' && preg_match('/PAST\s*DUE/i', $trim)) {
                $r['remaining'] = '0 Hrs';
            }
            if ($r['nota'] === '' && preg_match('/\(Add\s+[0-9,]+\s+to current hrs\)/i', $trim, $mn)) {
                $r['nota'] = $mn[0];
            }
            unset($r);
        }

        // Solo las filas con algún dato aprovechable.
        return array_values(array_filter(
            $registros,
            fn ($r) => $r['reading'] !== '' || $r['last'] !== '' || $r['remaining'] !== ''
        ));
    }

    /**
     * @param  array<int, string>  $par
     */
    private function normalizar(array $par): string
    {
        // El año puede venir con 2 o 4 dígitos; el parser del importador espera
        // la forma "<horas> Hrs <M/D/YY>".
        $fecha = $par[3];
        if (preg_match('#^(\d{1,2})/(\d{1,2})/(\d{4})$#', $fecha, $f)) {
            $fecha = $f[1].'/'.$f[2].'/'.substr($f[3], -2);
        }

        return str_replace(',', '', $par[1]).' '.ucfirst(strtolower($par[2])).' '.$fecha;
    }

    /**
     * Arma el xlsx con el formato exacto que espera PmServiceReportImporter.
     *
     * @param  array<int, array<string, string>>  $registros
     */
    private function buildXlsx(array $registros): string
    {
        $libro = new Spreadsheet;
        $hoja = $libro->getActiveSheet();
        $hoja->setTitle('Sheet1');

        $fila = 1;
        $hoja->setCellValue('A'.$fila++, 'MACHINE DESCRIPTION');

        foreach ($registros as $r) {
            $hoja->setCellValueExplicit('A'.$fila++, $r['id'].' IMPORTADO DEL PDF S/N: -', DataType::TYPE_STRING);
            $hoja->setCellValueExplicit('A'.$fila, trim('Ubicación '.$r['nota']), DataType::TYPE_STRING);
            $hoja->setCellValueExplicit('E'.$fila, $r['last'], DataType::TYPE_STRING);
            $hoja->setCellValueExplicit('G'.$fila, $r['reading'], DataType::TYPE_STRING);
            $hoja->setCellValueExplicit('J'.$fila, $r['remaining'], DataType::TYPE_STRING);
            $fila++;
        }

        $ruta = storage_path('app/pm_desde_pdf_'.now()->format('YmdHis').'.xlsx');
        (new Xlsx($libro))->save($ruta);

        return $ruta;
    }
}

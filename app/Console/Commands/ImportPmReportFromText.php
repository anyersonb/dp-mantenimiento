<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\PmServiceReportImporter;
use Illuminate\Console\Command;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\Console\Formatter\OutputFormatter;

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

        $parseado = $this->parse(file($ruta, FILE_IGNORE_NEW_LINES));
        $registros = $parseado['registros'];
        $descartes = $parseado['descartes'];
        $totalEnTexto = $parseado['total'];

        if ($totalEnTexto === 0) {
            $this->error('No encontré ninguna máquina en el archivo. ¿Lo generaste con `pdftotext -table`?');

            return self::FAILURE;
        }

        // Hallazgo: antes esto imprimía count($registros) YA FILTRADO (solo las
        // filas con algún dato legible) como si fuera "lo que había en el
        // archivo". Con el informe del 9/04/2026 (80 máquinas) eso mostraba "66
        // fila(s) leídas" en verde, sin ningún indicio de que 14 se habían
        // descartado en silencio. Ahora se informan los dos números.
        $this->info("{$totalEnTexto} máquina(s) en el texto del informe, ".count($registros).' con datos legibles para importar.');

        if ($descartes !== []) {
            $this->newLine();
            $this->warn(count($descartes).' descarte(s) — no entran en la importación (ver motivo):');
            $this->table(
                ['id', 'motivo', 'renglón crudo'],
                // El id sale de un regex restringido a [A-Z0-9-], pero se
                // escapa igual por defensa en profundidad: mismo remedio que
                // en recortar(), nunca confiar en que un dato de origen no
                // pueda imitar un tag de color de la consola.
                array_map(fn ($d) => [OutputFormatter::escape($d['id']), $d['motivo'], $d['crudo']], $descartes)
            );
        }

        if ($registros === []) {
            $this->error('Ninguna máquina quedó con datos legibles después del descarte.');

            return self::FAILURE;
        }

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
                array_map(fn ($r) => [OutputFormatter::escape($r['id']), $r['last'], $r['reading'], $r['remaining']], array_slice($registros, 0, 15))
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
     * Par "<horas> Hrs <fecha>", con el kilometraje entre paréntesis como dato
     * al margen tolerado entre la unidad y la fecha (defecto A: cuatro
     * volquetes traen "5700 Hrs (79637Mi.) 8/14/26" y el paréntesis rompía el
     * emparejado; el kilometraje se descarta a propósito, se mide en horas).
     *
     * SOLO "Hrs". Hallazgo de seguridad: esta alternancia incluía "Mls" —la
     * abreviatura de MILLAS que usa el propio encabezado del reporte
     * ("Remanining Hrs/Mls")— y una celda como "146037 Mls 6/20/25" se
     * emparejaba como si fueran 146.037 HORAS. Una unidad que no sea horas
     * nunca debe entrar por este par; la maneja PAR_MILLAS_FECHA.
     */
    private const PAR_HORAS_FECHA = '/([0-9][0-9,]*)\s*(Hrs)\.?\s*(?:\([^)]*\)\s*)?([0-9]{1,2}\/[0-9]{1,2}\/[0-9]{2,4})/i';

    /**
     * Espejo de PAR_HORAS_FECHA con la lectura PRIMARIA en millas y el margen
     * (si lo hay) en horas: "17309 Mi (2730 Hrs) 4/15/26" (TW007). Se aplica
     * ANTES de PAR_ESTIMACION_PARENTESIS para no confundir este caso —hay una
     * lectura primaria real, solo que en una unidad no soportada— con una
     * estimación declarada enteramente dentro del paréntesis (TW004).
     */
    private const PAR_MILLAS_FECHA = '/([0-9][0-9,]*)\s*(?:Miles|Mls?|Mi)\.?\s*(?:\([^)]*\)\s*)?([0-9]{1,2}\/[0-9]{1,2}\/[0-9]{2,4})/i';

    /** Lectura entera entre paréntesis: "(29959 Mi) 10/29/25" — una estimación declarada, no una medición firme (defecto E). */
    private const PAR_ESTIMACION_PARENTESIS = '/\(\s*[0-9][0-9,]*\s*(?:Hrs|Miles|Mls?|Mi)\.?\s*\)\s*[0-9]{1,2}\/[0-9]{1,2}\/[0-9]{2,4}/i';

    /**
     * Número seguido de cualquier grafía de millas ("Mi", "Mi.", "Mls", "Ml",
     * "Miles") fuera de paréntesis: lectura en millas, no soportada (defecto
     * B). El orden de la alternancia importa poco (hay backtracking), pero se
     * lista de más larga a más corta por claridad.
     */
    private const LECTURA_EN_MILLAS = '/[0-9][0-9,]*\s*(?:Miles|Mls?|Mi)\.?(?!\w)/i';

    /**
     * Convierte el texto de `pdftotext -table` en filas. Mismo criterio que el
     * parser del xlsx: la cabecera de máquina es la línea cuyo primer token
     * mezcla letras y dígitos, y la siguiente trae ubicación + los pares
     * "<horas> Hrs <fecha>" + las horas restantes.
     *
     * Defecto C: antes, toda fila sin dato aprovechable se tiraba en
     * `array_filter` sin dejar rastro. Ahora se devuelve también la lista de
     * descartes con motivo, para que "N fila(s) leídas" no vuelva a parecer
     * éxito cuando en realidad hay máquinas que no entraron.
     *
     * @param  array<int, string>  $lineas
     * @return array{registros: array<int, array{id: string, last: string, reading: string, remaining: string, nota: string}>, descartes: array<int, array{id: string, motivo: string, crudo: string}>, total: int}
     */
    private function parse(array $lineas): array
    {
        $registros = [];
        $indiceActual = null;
        $fechaInforme = null;

        foreach ($lineas as $linea) {
            $trim = trim($linea);

            if ($fechaInforme === null) {
                $fechaInforme = $this->fechaDelInforme($trim);
            }

            if ($trim === ''
                || str_contains($trim, 'MACHINE DESCRIPTION')
                || str_contains($trim, 'DP DEVELOPMENT')
                || str_contains($trim, 'PM SERVICE REPORT')) {
                continue;
            }

            $esCabecera = preg_match('/^([A-Z][A-Z0-9\-]{2,18})\s+(\S.*)$/', $trim, $m) === 1
                && preg_match('/[0-9]/', $m[1]) === 1
                && preg_match('/[0-9]\s*(?:Hrs|Miles|Mls?|Mi)\.?\s+[0-9]{1,2}\//i', $trim) !== 1;

            if ($esCabecera) {
                $registros[] = [
                    'id' => strtoupper($m[1]), 'last' => '', 'reading' => '', 'remaining' => '', 'nota' => '',
                    'crudoDatos' => '', 'fueraDeServicio' => false, 'estimacionParentesis' => false,
                    'estimacionRaw' => '', 'millas' => false, 'millasRaw' => '',
                    'tuvoFechaInvalida' => false, 'fechaInvalidaRaw' => '',
                ];
                $indiceActual = count($registros) - 1;

                continue;
            }

            if ($indiceActual === null) {
                continue;
            }

            $r = &$registros[$indiceActual];

            if ($r['crudoDatos'] === '') {
                $r['crudoDatos'] = $trim;
            }

            if (preg_match('/not\s*in\s*service/i', $trim) === 1) {
                $r['fueraDeServicio'] = true;
            }

            preg_match_all(self::PAR_HORAS_FECHA, $trim, $pares, PREG_SET_ORDER);

            // Defecto E antes que defecto B: una lectura enteramente entre
            // paréntesis ("(29959 Mi) 10/29/25") es una estimación declarada
            // por el cliente ("Odometer broken"), no una medición en millas
            // más. Se descuenta del resto ANTES de buscar millas sueltas, para
            // no reportar el mismo dato dos veces con motivos distintos.
            //
            // Si `preg_replace` fallara (null) se conserva $trim tal cual: un
            // guard que "no corrió" nunca debe traducirse en menos rechazo.
            $resto = preg_replace(self::PAR_HORAS_FECHA, '', $trim) ?? $trim;

            // TW007 (defecto B revisado): "17309 Mi (2730 Hrs) 4/15/26" es la
            // imagen espejo de TD006 — acá la lectura PRIMARIA está en millas
            // y el margen entre paréntesis es el equivalente en horas. Se
            // descuenta ANTES del chequeo de "estimación entre paréntesis"
            // para no confundir un margen legítimo (que sí acompaña una
            // millas-primaria) con una estimación declarada enteramente
            // dentro del paréntesis (TW004).
            if (preg_match(self::PAR_MILLAS_FECHA, $resto) === 1) {
                $r['millas'] = true;
                $r['millasRaw'] = $r['millasRaw'] !== '' ? $r['millasRaw'] : $trim;
                $resto = preg_replace(self::PAR_MILLAS_FECHA, '', $resto) ?? $resto;
            }

            if (preg_match(self::PAR_ESTIMACION_PARENTESIS, $resto) === 1) {
                $r['estimacionParentesis'] = true;
                $r['estimacionRaw'] = $r['estimacionRaw'] !== '' ? $r['estimacionRaw'] : $trim;
                $resto = preg_replace(self::PAR_ESTIMACION_PARENTESIS, '', $resto) ?? $resto;
            }

            // Defecto B. IMPORTANTE: esto NO se agrega a PAR_HORAS_FECHA. Las
            // celdas del informe suelen decir "Mi"/"Mi.", pero el propio
            // encabezado del reporte abrevia la columna como "Mls" y al menos
            // un informe real trajo esa misma grafía en el dato ("146037 Mls
            // 6/20/25"); `horometer_readings.hours` no tiene columna de
            // unidad, así que una lectura en millas —cualquiera sea su
            // grafía: Mi, Mi., Mls, Ml, Miles— nunca puede guardarse como si
            // fueran horas. El dato faltante es preferible al dato falso: se
            // rechaza con motivo visible en vez de dejarla pasar o perderla
            // en silencio.
            if (preg_match(self::LECTURA_EN_MILLAS, $resto) === 1) {
                $r['millas'] = true;
                $r['millasRaw'] = $r['millasRaw'] !== '' ? $r['millasRaw'] : $trim;
            }

            // Defecto D (revisado): el piso de "más de 3 años" castigaba el
            // último servicio LEGÍTIMO de una máquina de poco uso (ej. una
            // fecha real de hace 4 años, coherente con su propia lectura).
            // La señal precisa no es la antigüedad en sí, es la coherencia
            // INTERNA del renglón: la lectura más reciente (índice 1) no
            // puede ser anterior a su propio último servicio (índice 0) del
            // MISMO renglón. Eso es justo lo que delata a TD007 ("8/21/22"
            // en vez de "8/21/26" en la lectura, con el último servicio en
            // "8/21/26"). Primera pasada: solo se descarta una fecha
            // individual si es posterior al informe (imposible / typo de
            // año); segunda pasada: se descarta la lectura si es anterior a
            // su propio último servicio.
            $fechasPorIndice = [];
            foreach ($pares as $indice => $par) {
                $fecha = $this->fechaDesdeTexto($par[3]);

                if ($fecha !== null && $fechaInforme !== null && ! $this->fechaEsValida($fecha, $fechaInforme)) {
                    $r['tuvoFechaInvalida'] = true;
                    $r['fechaInvalidaRaw'] = $r['fechaInvalidaRaw'] !== '' ? $r['fechaInvalidaRaw'] : $trim;

                    continue;
                }

                $fechasPorIndice[$indice] = $fecha;
            }

            if (($fechasPorIndice[0] ?? null) !== null
                && ($fechasPorIndice[1] ?? null) !== null
                && $fechasPorIndice[1] < $fechasPorIndice[0]) {
                $r['tuvoFechaInvalida'] = true;
                $r['fechaInvalidaRaw'] = $r['fechaInvalidaRaw'] !== '' ? $r['fechaInvalidaRaw'] : $trim;
                unset($fechasPorIndice[1]);
            }

            foreach ($pares as $indice => $par) {
                if (! array_key_exists($indice, $fechasPorIndice)) {
                    // Fecha posterior al informe: este par se rechaza entero,
                    // no se usa ni para "last" ni para "reading".
                    continue;
                }

                if ($indice === 0 && $r['last'] === '') {
                    $r['last'] = $this->normalizar($par);
                }
                if ($indice === 1 && $r['reading'] === '') {
                    $r['reading'] = $this->normalizar($par);
                }
            }

            if ($r['remaining'] === '' && preg_match('/([0-9][0-9,]*)\s*(Hrs)\.?\s*$/i', $trim, $mm)) {
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

        $final = [];
        $descartes = [];

        foreach ($registros as $r) {
            // Defecto B/E: si la máquina se mide en millas o su única lectura
            // es una estimación entre paréntesis, la fila se rechaza ENTERA
            // (aunque "remaining" haya matcheado en horas, como MOT02: mezclar
            // un dato no soportado con uno parcial es más confuso que pedirle
            // a un humano que la revise a mano).
            $rechazarPorUnidad = $r['millas'] || $r['estimacionParentesis'];
            $tieneDatos = ! $rechazarPorUnidad
                && ($r['last'] !== '' || $r['reading'] !== '' || $r['remaining'] !== '');

            if ($tieneDatos) {
                if ($r['tuvoFechaInvalida']) {
                    $descartes[] = [
                        'id' => $r['id'],
                        'motivo' => 'fecha inválida',
                        'crudo' => $this->recortar($r['fechaInvalidaRaw']),
                    ];
                }

                $final[] = [
                    'id' => $r['id'], 'last' => $r['last'], 'reading' => $r['reading'],
                    'remaining' => $r['remaining'], 'nota' => $r['nota'],
                ];

                continue;
            }

            $motivo = match (true) {
                $r['fueraDeServicio'] => 'fuera de servicio',
                $r['estimacionParentesis'] => 'estimación entre paréntesis (no firme)',
                $r['millas'] => 'lectura en millas (no soportado)',
                $r['tuvoFechaInvalida'] => 'fecha inválida',
                default => 'sin lectura en el informe',
            };

            $crudo = $r['estimacionRaw'] ?: ($r['millasRaw'] ?: ($r['fechaInvalidaRaw'] ?: $r['crudoDatos']));

            $descartes[] = ['id' => $r['id'], 'motivo' => $motivo, 'crudo' => $this->recortar($crudo)];
        }

        return ['registros' => $final, 'descartes' => $descartes, 'total' => count($registros)];
    }

    /**
     * Sanea el renglón crudo antes de imprimirlo en la tabla de descartes.
     *
     * Hallazgo de seguridad: un .txt de origen puede traer códigos de
     * control C0 incrustados (ESC `\x1b`, BEL, etc.). No son `\s`, así que
     * sobreviven al collapse de espacios y llegan intactos a la terminal,
     * donde secuencias como `\x1b[2K`/`\x1b[F` pueden borrar o pisar líneas
     * YA impresas —incluido el propio conteo de descartes— y simular texto
     * falso. Se remueven ANTES de recortar (fail-closed: si `preg_replace`
     * fallara, se prefiere perder detalle a dejarlos pasar) y el resultado
     * pasa por `OutputFormatter::escape()` para neutralizar además cualquier
     * tag de color que el dato de origen pudiera imitar.
     */
    private function recortar(string $texto, int $max = 90): string
    {
        $sinControles = preg_replace('/[\x00-\x1F\x7F]/', '', $texto) ?? '';

        $texto = trim(preg_replace('/\s+/', ' ', $sinControles) ?? $sinControles);
        $texto = mb_strlen($texto) > $max ? mb_substr($texto, 0, $max - 1).'…' : $texto;

        return OutputFormatter::escape($texto);
    }

    /**
     * Fecha del encabezado del informe, ej. "MACHINERY PM SERVICE REPORT
     * Sep/04/2026" -> 2026-09-04. Es la referencia contra la que se valida
     * toda lectura (defecto D): nada puede ser posterior a esta fecha, ni más
     * vieja que 3 años.
     */
    private function fechaDelInforme(string $linea): ?\DateTimeImmutable
    {
        if (preg_match('/\b([A-Za-z]{3})\/([0-9]{1,2})\/([0-9]{4})\b/', $linea, $m) !== 1) {
            return null;
        }

        $meses = [
            'jan' => 1, 'feb' => 2, 'mar' => 3, 'apr' => 4, 'may' => 5, 'jun' => 6,
            'jul' => 7, 'aug' => 8, 'sep' => 9, 'oct' => 10, 'nov' => 11, 'dec' => 12,
        ];

        $mes = $meses[strtolower($m[1])] ?? null;
        if ($mes === null) {
            return null;
        }

        $fecha = \DateTimeImmutable::createFromFormat('Y-n-j', $m[3].'-'.$mes.'-'.$m[2]);

        return $fecha !== false ? $fecha->setTime(0, 0) : null;
    }

    /** Convierte "M/D/YY" o "M/D/YYYY" a fecha. Misma tolerancia que el importador del xlsx. */
    private function fechaDesdeTexto(string $mdyy): ?\DateTimeImmutable
    {
        if (preg_match('#^(\d{1,2})/(\d{1,2})/(\d{2,4})$#', trim($mdyy), $m) !== 1) {
            return null;
        }

        $anio = (int) $m[3];
        if ($anio < 100) {
            $anio += 2000;
        }
        $mes = (int) $m[1];
        $dia = (int) $m[2];

        if ($mes < 1 || $mes > 12 || $dia < 1 || $dia > 31) {
            return null;
        }

        $fecha = \DateTimeImmutable::createFromFormat('Y-n-j', "{$anio}-{$mes}-{$dia}");

        return $fecha !== false ? $fecha->setTime(0, 0) : null;
    }

    /**
     * No posterior al informe (defecto D). Físicamente imposible que una
     * lectura esté fechada después de cuando se generó el reporte — señal
     * de typo de año, como cualquier otra.
     *
     * Ya NO impone un piso de antigüedad ("más de 3 años atrás"): ese piso
     * castigaba el último servicio LEGÍTIMO de una máquina de poco uso
     * (defecto D revisado — ver `parse()`, donde la coherencia interna del
     * renglón hace ese trabajo con más precisión).
     */
    private function fechaEsValida(\DateTimeImmutable $fecha, \DateTimeImmutable $fechaInforme): bool
    {
        return $fecha <= $fechaInforme;
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

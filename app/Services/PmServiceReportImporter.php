<?php

namespace App\Services;

use App\Models\HorometerReading;
use App\Models\Machine;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\RichText\RichText;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/**
 * Importa el "PM Service Report" (.xlsx) que el cliente genera cada cierto
 * tiempo y actualiza horas/lecturas de máquinas que YA existen en el sistema.
 *
 * Decisión de negocio confirmada: este importador NUNCA crea máquinas nuevas.
 * Los id_code del reporte que no coincidan con ninguna máquina existente se
 * reportan como "unmatched" para que el responsable de mantenimiento decida
 * si se dan de alta manualmente.
 *
 * Formato del reporte (hoja "Sheet1", 2 filas por máquina):
 *   Fila impar: columna A = "<ID> <DESCRIPCION> <MAKE MODEL> S/N: <serie>"
 *   Fila par:   columna A = ubicación/nota (puede traer "(Add N to current hrs)")
 *               columna E = "<hrs> Hrs <M/D/YY>"  -> último servicio
 *               columna G = "<hrs> Hrs <M/D/YY>"  -> última lectura
 *               columna J = "<hrs> Hrs" | "Mls" | "DUE" -> horas/millas restantes
 *               columna M = combustible agregado (opcional)
 *
 * El reporte trae encabezados "MACHINE DESCRIPTION" repetidos por cada salto
 * de página impreso; se ignoran y no rompen el conteo de pares de filas.
 *
 * Regla de negocio (misma que database/seeders/FleetSeeder.php, que cargó la
 * flota originalmente desde este mismo tipo de reporte):
 *   - El "remaining_hours" del reporte es la fuente de verdad (snapshot). Solo
 *     se recalcula en vivo (Machine::getComputedRemainingHoursAttribute) cuando
 *     el reporte no trae el dato.
 *   - "hours_adjustment" corrige horómetros reemplazados (ej. EX013 "Add 5714
 *     to current hrs"): se detecta con la misma expresión regular del seeder.
 *
 * A diferencia del seeder (que hace una carga completa y autoritativa), este
 * importador es incremental: si un campo del reporte no se pudo leer para una
 * fila, se deja el valor existente de la máquina sin tocar (se registra un
 * warning) en lugar de sobrescribirlo con null.
 */
class PmServiceReportImporter
{
    private const HEADER_TEXT = 'MACHINE DESCRIPTION';

    /**
     * @return array{updated: array<int, array{id_code: string, current_hours: ?int, remaining_hours: ?int}>, unmatched: array<int, array{id_code: string, row: int}>, warnings: array<int, string>}
     */
    public function import(string $filePath, ?User $causer = null, ?string $originalFilename = null): array
    {
        $rows = $this->parseRows($filePath);

        $updated = [];
        $unmatched = [];
        $warnings = $rows['warnings'];

        // Si el reporte trae el mismo id_code más de una vez (ej. correcciones
        // al final del archivo), se queda con la última ocurrencia.
        $byIdCode = [];
        foreach ($rows['records'] as $record) {
            $byIdCode[$record['id_code']] = $record;
        }

        $machines = Machine::query()
            ->whereIn('id_code', array_keys($byIdCode))
            ->get()
            ->keyBy('id_code');

        foreach ($byIdCode as $idCode => $record) {
            $machine = $machines->get($idCode);

            if (! $machine) {
                $unmatched[] = ['id_code' => $idCode, 'row' => $record['row']];

                continue;
            }

            try {
                $result = DB::transaction(fn () => $this->applyToMachine($machine, $record, $causer, $originalFilename));
                $warnings = array_merge($warnings, $record['row_warnings']);

                if ($result !== null) {
                    $updated[] = $result;
                } else {
                    $warnings[] = "{$idCode} (fila {$record['row']}): coincide pero el reporte no trae ninguna lectura legible para esta fila, no se actualizó nada.";
                }
            } catch (\Throwable $e) {
                Log::error('pm_report_import.machine_update_failed', [
                    'id_code' => $idCode,
                    'row' => $record['row'],
                    'error' => $e->getMessage(),
                ]);
                $warnings[] = "{$idCode} (fila {$record['row']}): error al actualizar — {$e->getMessage()}";
            }
        }

        activity()
            ->causedBy($causer)
            ->event('imported')
            ->withProperties([
                'file' => $originalFilename,
                'updated' => array_column($updated, 'id_code'),
                'unmatched' => array_column($unmatched, 'id_code'),
                'warnings' => $warnings,
            ])
            ->log(__('mgmt.import_pm_report_log', [
                'updated' => count($updated),
                'unmatched' => count($unmatched),
            ]));

        return [
            'updated' => $updated,
            'unmatched' => $unmatched,
            'warnings' => $warnings,
        ];
    }

    /**
     * Aplica un registro parseado del reporte a una máquina existente.
     * Solo pisa los campos para los que el reporte trajo un valor legible.
     *
     * @return ?array{id_code: string, current_hours: ?int, remaining_hours: ?int} null si la fila coincidió con la máquina pero no traía ningún dato legible (nada que actualizar).
     */
    private function applyToMachine(Machine $machine, array $record, ?User $causer, ?string $originalFilename): ?array
    {
        // Fase 1: campos que no disparan el observer de HorometerReading pero
        // que su cálculo en vivo SÍ usa (last_service_hours, hours_adjustment).
        // Se aplican antes de crear la lectura para que, si el observer
        // recalcula remaining_hours, lo haga con los datos correctos.
        $preAttrs = [];
        if ($record['last_service']['hours'] !== null) {
            $preAttrs['last_service_hours'] = $record['last_service']['hours'];
        }
        if ($record['last_service']['date'] !== null) {
            $preAttrs['last_service_date'] = $record['last_service']['date'];
        }
        if ($record['hours_adjustment'] !== null) {
            $preAttrs['hours_adjustment'] = $record['hours_adjustment'];
        }
        if ($preAttrs !== []) {
            $machine->update($preAttrs);
        }

        // Lectura de horómetro para historial (solo si se pudo leer un valor
        // de horas). Se evita duplicar si se reimporta el mismo reporte
        // (misma máquina + misma fecha + fuente "import"): se usa whereDate()
        // en lugar de un firstOrCreate() con match exacto porque el cast
        // "date" del modelo puede persistir con componente de hora según el
        // driver (ej. SQLite en tests), lo que rompería la igualdad exacta.
        // Dispara HorometerReadingObserver, que actualiza current_hours y
        // hace un primer cálculo de remaining_hours.
        if ($record['latest_reading']['hours'] !== null) {
            $readAt = $record['latest_reading']['date'] ?? now()->toDateString();

            $exists = HorometerReading::query()
                ->where('machine_id', $machine->id)
                ->where('source', 'import')
                ->whereDate('read_at', $readAt)
                ->exists();

            if (! $exists) {
                HorometerReading::create([
                    'machine_id' => $machine->id,
                    'read_at' => $readAt,
                    'source' => 'import',
                    'hours' => $record['latest_reading']['hours'],
                    'recorded_by' => $causer?->id,
                    'note' => 'PM Service Report import'.($originalFilename ? " ({$originalFilename})" : ''),
                    'gallons' => $record['fuel_added'],
                    'verified' => true,
                ]);
            }
        }

        // Fase 2: el snapshot del reporte manda al final (misma regla del
        // FleetSeeder: se prioriza remaining_hours del reporte por sobre el
        // cálculo en vivo). Se fuerza también current_hours/current_hours_date
        // por si el observer no los tocó (ej. lectura no estrictamente mayor
        // a la actual).
        $postAttrs = [];
        if ($record['latest_reading']['hours'] !== null) {
            $postAttrs['current_hours'] = $record['latest_reading']['hours'];
        }
        if ($record['latest_reading']['date'] !== null) {
            $postAttrs['current_hours_date'] = $record['latest_reading']['date'];
        }
        if ($record['remaining_hours'] !== null) {
            $postAttrs['remaining_hours'] = $record['remaining_hours'];
        }
        if ($postAttrs !== []) {
            $machine->refresh();
            $machine->update($postAttrs);
        }

        if ($preAttrs === [] && $postAttrs === []) {
            // La fila coincidió con una máquina existente pero no traía
            // ningún dato legible (ej. "NOT IN SERVICE" sin lecturas, o
            // "No Info Available" en las 3 columnas): no hay nada que
            // actualizar, se reporta como warning en vez de "actualizada".
            return null;
        }

        $machine->refresh();

        return [
            'id_code' => $machine->id_code,
            'current_hours' => $machine->current_hours,
            'remaining_hours' => $machine->remaining_hours,
        ];
    }

    /**
     * @return array{records: array<int, array>, warnings: array<int, string>}
     */
    private function parseRows(string $filePath): array
    {
        $spreadsheet = IOFactory::load($filePath);
        $sheet = $spreadsheet->getSheetByName('Sheet1') ?? $spreadsheet->getActiveSheet();

        $records = [];
        $warnings = [];

        $highestRow = $sheet->getHighestRow();
        $pendingDescription = null; // ['id_code' => ..., 'row' => n]
        $seenHeader = false; // filas 1-5 (título/fecha) también vienen "en blanco"

        for ($r = 1; $r <= $highestRow; $r++) {
            $a = $this->cell($sheet, "A{$r}");

            if ($this->isHeaderRow($a)) {
                // Encabezado (el primero, o repetido por salto de página
                // impreso): se ignora sin consumir un "slot" de descripción/dato.
                $seenHeader = true;

                continue;
            }

            if (! $seenHeader) {
                // Aún en el bloque de título (filas 1-5 antes del encabezado
                // "MACHINE DESCRIPTION"): se ignora, no es fin de archivo.
                continue;
            }

            $isBlankRow = $a === null
                && $this->cell($sheet, "E{$r}") === null
                && $this->cell($sheet, "G{$r}") === null
                && $this->cell($sheet, "J{$r}") === null
                && $this->cell($sheet, "M{$r}") === null;

            if ($pendingDescription === null) {
                if ($isBlankRow) {
                    // Fin de los datos (relleno de filas vacías al final del sheet).
                    break;
                }

                $idCode = $this->extractIdCode($a);
                if ($idCode === null) {
                    $warnings[] = "Fila {$r}: no se pudo identificar un ID de máquina en \"{$a}\", se omite.";

                    continue;
                }

                $pendingDescription = ['id_code' => $idCode, 'row' => $r];

                continue;
            }

            // Fila de datos (segunda del par).
            $rowWarnings = [];
            $idCode = $pendingDescription['id_code'];
            $descRow = $pendingDescription['row'];
            $pendingDescription = null;

            $lastService = $this->parseHoursDate($this->cell($sheet, "E{$r}"));
            $latestReading = $this->parseHoursDate($this->cell($sheet, "G{$r}"));
            $remaining = $this->parseRemaining($this->cell($sheet, "J{$r}"));
            $fuel = $this->parseFuel($this->cell($sheet, "M{$r}"));

            if ($lastService['unparseable']) {
                $rowWarnings[] = "{$idCode} (fila {$r}): último servicio ilegible (\"{$lastService['raw']}\"), se conserva el valor actual.";
            }
            if ($latestReading['unparseable']) {
                $rowWarnings[] = "{$idCode} (fila {$r}): última lectura ilegible (\"{$latestReading['raw']}\"), se conserva el valor actual.";
            }
            if ($remaining['unparseable']) {
                $rowWarnings[] = "{$idCode} (fila {$r}): horas restantes ilegibles (\"{$remaining['raw']}\"), se conserva el valor actual.";
            }

            $hoursAdjustment = $this->extractHoursAdjustment($a);

            $records[] = [
                'id_code' => $idCode,
                'row' => $descRow,
                'last_service' => ['hours' => $lastService['hours'], 'date' => $lastService['date']],
                'latest_reading' => ['hours' => $latestReading['hours'], 'date' => $latestReading['date']],
                'remaining_hours' => $remaining['hours'],
                'hours_adjustment' => $hoursAdjustment,
                'fuel_added' => $fuel,
                'row_warnings' => $rowWarnings,
            ];
        }

        if ($pendingDescription !== null) {
            $warnings[] = "Fila {$pendingDescription['row']}: máquina \"{$pendingDescription['id_code']}\" no tiene fila de datos (fin de archivo), se omite.";
        }

        return ['records' => $records, 'warnings' => $warnings];
    }

    private function isHeaderRow(?string $value): bool
    {
        return $value !== null && strcasecmp(trim($value), self::HEADER_TEXT) === 0;
    }

    /** Primer token de la descripción, ej. "EX010 EXCAVATOR CAT..." -> "EX010". */
    private function extractIdCode(?string $description): ?string
    {
        if ($description === null || trim($description) === '') {
            return null;
        }

        $parts = preg_split('/\s+/', trim($description), 2);

        return $parts[0] !== '' ? $parts[0] : null;
    }

    /** Misma regla que FleetSeeder::hoursAdjustment(), aplicada a la nota de la fila de datos. */
    private function extractHoursAdjustment(?string $noteRaw): ?int
    {
        if ($noteRaw === null) {
            return null;
        }
        if (preg_match('/add\s+([\d,]+)\s+to\s+current/i', $noteRaw, $m)) {
            return (int) str_replace(',', '', $m[1]);
        }

        return null;
    }

    /**
     * Parsea celdas tipo "12055 Hrs 3/28/24", "0079 Hrs 3/12/2026", "007 Hrs"
     * (sin fecha), "3395 Hrs. 6/15/26". Celdas vacías, textuales ("No Info
     * Available") o con formato inesperado (ej. números crudos de Excel) se
     * marcan como "unparseable" sin lanzar excepción.
     *
     * @return array{hours: ?int, date: ?string, unparseable: bool, raw: string}
     */
    private function parseHoursDate(?string $raw): array
    {
        if ($raw === null || trim($raw) === '') {
            return ['hours' => null, 'date' => null, 'unparseable' => false, 'raw' => (string) $raw];
        }

        $raw = trim($raw);

        if (! preg_match('/^([\d,]+)\s*(?:Hrs?|Mls?)\.?\s*(\d{1,2}\/\d{1,2}\/\d{2,4})?$/i', $raw, $m)) {
            return ['hours' => null, 'date' => null, 'unparseable' => true, 'raw' => $raw];
        }

        $hours = (int) str_replace(',', '', $m[1]);
        $date = isset($m[2]) && $m[2] !== '' ? $this->normalizeDate($m[2]) : null;

        return ['hours' => $hours, 'date' => $date, 'unparseable' => false, 'raw' => $raw];
    }

    /**
     * Parsea la columna "Remaining Hrs/Mls" (ej. "415 Hrs", "DUE", "N/A").
     *
     * @return array{hours: ?int, unparseable: bool, raw: string}
     */
    private function parseRemaining(?string $raw): array
    {
        if ($raw === null || trim($raw) === '') {
            return ['hours' => null, 'unparseable' => false, 'raw' => (string) $raw];
        }

        $raw = trim($raw);

        if (strcasecmp($raw, 'DUE') === 0) {
            return ['hours' => 0, 'unparseable' => false, 'raw' => $raw];
        }

        if (! preg_match('/^([\d,]+)\s*(?:Hrs?|Mls?)\.?$/i', $raw, $m)) {
            return ['hours' => null, 'unparseable' => true, 'raw' => $raw];
        }

        return ['hours' => (int) str_replace(',', '', $m[1]), 'unparseable' => false, 'raw' => $raw];
    }

    private function parseFuel(?string $raw): ?float
    {
        if ($raw === null || trim($raw) === '' || ! is_numeric(trim($raw))) {
            return null;
        }

        return (float) trim($raw);
    }

    /** Misma regla que FleetSeeder::date(): M/D/YY o M/D/YYYY -> Y-m-d. */
    private function normalizeDate(string $raw): ?string
    {
        if (! preg_match('#^(\d{1,2})/(\d{1,2})/(\d{2,4})$#', trim($raw), $m)) {
            return null;
        }
        [, $mo, $da, $yr] = $m;
        $yr = (int) $yr;
        if ($yr < 100) {
            $yr += 2000;
        }
        if ((int) $mo < 1 || (int) $mo > 12 || (int) $da < 1 || (int) $da > 31) {
            return null;
        }

        return sprintf('%04d-%02d-%02d', $yr, (int) $mo, (int) $da);
    }

    /** Devuelve el valor de una celda como string trim, resolviendo RichText. */
    private function cell(Worksheet $sheet, string $coordinate): ?string
    {
        $value = $sheet->getCell($coordinate)->getValue();

        if ($value instanceof RichText) {
            $value = $value->getPlainText();
        }

        if ($value === null) {
            return null;
        }

        if (! is_string($value)) {
            // Valor numérico crudo (ej. Excel guardó un serial de fecha en una
            // celda donde se esperaba texto "N Hrs fecha"): no es un formato
            // que podamos leer con confianza, se trata como vacío/ilegible
            // más arriba según el contexto (parseHoursDate/parseRemaining).
            return (string) $value;
        }

        $trimmed = trim($value);

        return $trimmed === '' ? null : $trimmed;
    }
}

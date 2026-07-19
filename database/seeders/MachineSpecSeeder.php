<?php

namespace Database\Seeders;

use App\Models\Machine;
use App\Models\MachineCategory;
use App\Models\MachinePart;
use App\Models\Make;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Carga la ficha técnica verbatim del Machinery Info Book (database/data/fichas.json)
 * sobre la tabla `machines`, y su catálogo de partes sobre `machine_parts`.
 *
 * Idempotente: puede re-ejecutarse sin duplicar máquinas ni partes.
 * - Si la ficha trae un id_code que ya existe en `machines` -> actualiza solo los
 *   campos de ficha técnica (no toca horas/ubicación/categoría/make/relaciones operativas).
 * - Si el id_code no existe (o la ficha no trae id) -> crea una máquina nueva,
 *   marcada needs_review, para que el equipo la revise y la vincule a la operación real.
 */
class MachineSpecSeeder extends Seeder
{
    private int $updated = 0;

    private int $created = 0;

    private int $partsInserted = 0;

    /** Secuencia para fichas sin id_code (estable entre corridas: mismo orden de fichas.json). */
    private int $tmpSequence = 0;

    /** Marca reconocida -> nombre canónico. Se evalúa en este orden (más específico primero). */
    private array $knownMakes = [
        'Mercedes Benz' => 'Mercedes-Benz',
        'New Holland' => 'New Holland',
        'Wacker Neuson' => 'Wacker Neuson',
        'Case IH' => 'Case IH',
        'Lee Boy' => 'Leeboy',
        'Leeboy' => 'Leeboy',
        'Kobelko' => 'Kobelco',
        'Kobelco' => 'Kobelco',
        'Mersino' => 'Mersino',
        'Thompson' => 'Thompson',
        'Caterpillar' => 'Caterpillar',
        'CAT' => 'Caterpillar',
        'Komatsu' => 'Komatsu',
        'John Deere' => 'John Deere',
        'JD' => 'John Deere',
        'Bomag' => 'Bomag',
        'Ingram' => 'Ingram',
        'Terex' => 'Terex',
        'Generac' => 'Generac',
        'Magnum' => 'Magnum',
        'MWI' => 'MWI',
        'Pioneer' => 'Pioneer',
        'Titan' => 'Titan',
        'Cobra' => 'Cobra',
        'Ford' => 'Ford',
        'Freightliner' => 'Freightliner',
        'Kenworth' => 'Kenworth',
        'Peterbilt' => 'Peterbilt',
        'Powerscreen' => 'Powerscreen',
        'Sullair' => 'Sullair',
        'Case' => 'Case',
    ];

    /** machine_type -> categoría canónica (más específico primero). */
    private array $categoryMap = [
        'truck tractor' => 'Truck Tractor',
        'skid steer' => 'Skid Steer',
        'wheel loader' => 'Wheel Loader',
        'bulldozer' => 'Dozer',
        'street saw' => 'Cold Planer',
        'cold planer' => 'Cold Planer',
        'broom' => 'Broom Tractor',
        'tractor' => 'Tractor',
        'pump' => 'Pump',
        'paver' => 'Paver',
        'roller' => 'Roller',
        'screener' => 'Screen/Plant',
        'screen' => 'Screen/Plant',
        'light tower' => 'Light Tower',
        'crusher' => 'Crusher',
        'grader' => 'Grader',
        'excavator' => 'Excavator',
    ];

    public function run(): void
    {
        $fichas = json_decode(file_get_contents(database_path('data/fichas.json')), true) ?: [];

        foreach ($fichas as $ficha) {
            $idCode = $this->normalizeIdCode($ficha['id_code'] ?? null);

            $machine = $idCode ? Machine::where('id_code', $idCode)->first() : null;

            if ($machine) {
                $this->updateExisting($machine, $ficha);
                $this->updated++;
            } else {
                $machine = $this->createNew($ficha, $idCode);
                // updateOrCreate: en una re-siembra, un id_code generado (INFO-TMP-NN) ya
                // existente actualiza en vez de crear; refleja el conteo real, no el intento.
                $machine->wasRecentlyCreated ? $this->created++ : $this->updated++;
            }

            $this->partsInserted += $this->syncParts($machine, $ficha['parts'] ?? []);
        }

        Log::info('MachineSpecSeeder terminado', [
            'updated' => $this->updated,
            'created' => $this->created,
            'parts_inserted' => $this->partsInserted,
        ]);

        $this->command?->info(sprintf(
            'MachineSpecSeeder: %d actualizadas, %d creadas, %d partes insertadas.',
            $this->updated,
            $this->created,
            $this->partsInserted
        ));
    }

    /* ----------------------------- Update path ----------------------------- */

    private function updateExisting(Machine $machine, array $ficha): void
    {
        $data = [
            'spec_sheet' => $ficha['raw'] ?? null,
            'engine_model' => $this->blankToNull($ficha['eng_model'] ?? null),
            'engine_serial' => $this->blankToNull($ficha['eng_sn'] ?? null),
            'electrical_system' => $this->blankToNull($ficha['electrical'] ?? null),
            'battery_cca' => $this->blankToNull($ficha['cca'] ?? null) ?? $this->blankToNull($ficha['battery'] ?? null),
            'oil_capacity' => $this->blankToNull($ficha['oil_capacity'] ?? null),
            'tires' => $this->blankToNull($ficha['tires'] ?? null),
        ];

        // model/serial solo si el campo actual está vacío (no pisa datos operativos ya cargados)
        if (blank($machine->model) && $this->blankToNull($ficha['model'] ?? null)) {
            $data['model'] = trim($ficha['model']);
        }
        if (blank($machine->serial) && $this->blankToNull($ficha['serial'] ?? null)) {
            $data['serial'] = trim($ficha['serial']);
        }

        $this->applyReviewFlag($machine, $ficha, $data);

        $machine->update($data);
    }

    /* ----------------------------- Create path ----------------------------- */

    private function createNew(array $ficha, ?string $idCode): Machine
    {
        $idCode ??= $this->nextTmpIdCode();

        $category = $this->categoryFor($ficha['machine_type'] ?? null);
        $make = $this->makeFor($ficha['make_year'] ?? null);

        $data = [
            'id_code' => $idCode,
            'machine_category_id' => $category->id,
            'make_id' => $make?->id,
            'model' => $this->blankToNull($ficha['model'] ?? null),
            'serial' => $this->blankToNull($ficha['serial'] ?? null),
            'year' => $this->yearFor($ficha['make_year'] ?? null),
            'description' => $this->blankToNull($ficha['machine_type'] ?? null),
            'current_location_id' => null, // se desconoce la ubicación operativa real; queda pendiente de asignar
            'status' => 'unknown',
            'spec_sheet' => $ficha['raw'] ?? null,
            'engine_model' => $this->blankToNull($ficha['eng_model'] ?? null),
            'engine_serial' => $this->blankToNull($ficha['eng_sn'] ?? null),
            'electrical_system' => $this->blankToNull($ficha['electrical'] ?? null),
            'battery_cca' => $this->blankToNull($ficha['cca'] ?? null) ?? $this->blankToNull($ficha['battery'] ?? null),
            'oil_capacity' => $this->blankToNull($ficha['oil_capacity'] ?? null),
            'tires' => $this->blankToNull($ficha['tires'] ?? null),
            'needs_review' => true,
        ];

        $data['review_note'] = empty($ficha['id_code'])
            ? 'Ficha sin ID en el PDF'
            : 'Ficha del Info Book sin máquina operativa correspondiente';

        $this->applyReviewFlag(new Machine, $ficha, $data, force: true);

        return Machine::updateOrCreate(['id_code' => $idCode], $data);
    }

    /** Agrega needs_review/dup_of a la nota sin pisar el resto de la lógica de revisión. */
    private function applyReviewFlag(Machine $machine, array $ficha, array &$data, bool $force = false): void
    {
        $extraNotes = [];

        if (! empty($ficha['dup_of'])) {
            $data['needs_review'] = true;
            $extraNotes[] = 'Posible ficha duplicada del info book (dup_of: '.$ficha['dup_of'].').';
        }

        if (! empty($ficha['needs_review']) && is_string($ficha['needs_review'])) {
            $extraNotes[] = $ficha['needs_review'];
        } elseif (! empty($ficha['needs_review'])) {
            $data['needs_review'] = true;
        }

        if ($extraNotes) {
            $base = $force ? ($data['review_note'] ?? null) : $machine->review_note;
            $note = trim(collect([$base, ...$extraNotes])->filter()->unique()->implode(' | '));
            $data['review_note'] = $note !== '' ? $note : null;
        }
    }

    /* ----------------------------- Parts ----------------------------- */

    private function syncParts(Machine $machine, array $parts): int
    {
        $machine->parts()->delete();

        $count = 0;
        foreach ($parts as $part) {
            $label = trim($part['label'] ?? '');
            if ($label === '') {
                continue;
            }

            MachinePart::create([
                'machine_id' => $machine->id,
                'category' => $this->partCategory($label),
                'label' => $label,
                'detail' => $part['detail'] ?? null,
                'oem_number' => $this->oemNumber($part['refs'] ?? []),
                'napa_number' => $this->napaNumber($part['refs'] ?? []),
                'change_interval_hours' => $this->intervalHours($part['interval'] ?? null),
            ]);
            $count++;
        }

        return $count;
    }

    private function oemNumber(array $refs): ?string
    {
        foreach ($refs as $ref) {
            if (! Str::contains(strtolower($ref['brand'] ?? ''), 'napa')) {
                return $ref['number'] ?? null;
            }
        }

        return null;
    }

    private function napaNumber(array $refs): ?string
    {
        foreach ($refs as $ref) {
            if (Str::contains(strtolower($ref['brand'] ?? ''), 'napa')) {
                return $ref['number'] ?? null;
            }
        }

        return null;
    }

    private function intervalHours(?string $interval): ?int
    {
        if (! $interval) {
            return null;
        }
        if (preg_match('/(\d+)\s*hrs?\b/i', $interval, $mm)) {
            return (int) $mm[1];
        }

        return null;
    }

    private function partCategory(string $label): string
    {
        $l = strtolower($label);

        return match (true) {
            str_contains($l, 'filter'), str_contains($l, 'element') => 'filter',
            str_contains($l, 'belt') => 'belt',
            str_contains($l, 'bucket'), str_contains($l, 'cutting edge'), str_contains($l, 'adapter') => 'attachment',
            str_contains($l, 'battery'), str_contains($l, 'starter'), str_contains($l, 'alternator') => 'electrical',
            default => 'other',
        };
    }

    /* ----------------------------- Helpers ----------------------------- */

    private function normalizeIdCode(?string $idCode): ?string
    {
        $idCode = trim((string) $idCode);

        return $idCode !== '' ? $idCode : null;
    }

    private function nextTmpIdCode(): string
    {
        $this->tmpSequence++;

        return sprintf('INFO-TMP-%02d', $this->tmpSequence);
    }

    private function blankToNull(?string $value): ?string
    {
        $value = trim((string) $value);

        return $value !== '' ? $value : null;
    }

    private function categoryFor(?string $machineType): MachineCategory
    {
        $raw = trim((string) $machineType);
        $name = 'Por clasificar';

        if ($raw !== '') {
            $name = 'Por clasificar';
            foreach ($this->categoryMap as $needle => $canonical) {
                if (stripos($raw, $needle) !== false) {
                    $name = $canonical;
                    break;
                }
            }
            if ($name === 'Por clasificar') {
                // sin match conocido: limpia paréntesis/coletillas y usa el machine_type tal cual
                $name = trim(preg_replace('/\s*\(.*\)\s*$/', '', $raw));
                $name = $name !== '' ? $name : 'Por clasificar';
            }
        }

        return MachineCategory::firstOrCreate(
            ['slug' => Str::slug($name)],
            ['name' => $name, 'default_service_interval' => 500]
        );
    }

    private function makeFor(?string $makeYear): ?Make
    {
        $raw = trim((string) $makeYear);
        $name = null;

        if ($raw !== '') {
            foreach ($this->knownMakes as $needle => $canonical) {
                if (stripos($raw, $needle) !== false) {
                    $name = $canonical;
                    break;
                }
            }
            if (! $name) {
                $candidate = trim(explode(',', $raw, 2)[0]);
                // descarta candidatos que sean solo fecha/ruido (sin letras)
                $name = preg_match('/[A-Za-z]{2,}/', $candidate) ? $candidate : null;
            }
        }

        $name ??= 'Por clasificar';

        return Make::firstOrCreate(
            ['slug' => Str::slug($name)],
            ['name' => $name]
        );
    }

    private function yearFor(?string $makeYear): ?int
    {
        if ($makeYear && preg_match('/(19|20)\d{2}/', $makeYear, $mm)) {
            return (int) $mm[0];
        }

        return null;
    }
}

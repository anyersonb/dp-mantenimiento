<?php

namespace Database\Seeders;

use App\Models\HorometerReading;
use App\Models\Location;
use App\Models\Machine;
use App\Models\MachineCategory;
use App\Models\MachinePart;
use App\Models\Make;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class FleetSeeder extends Seeder
{
    /** Prefijo de id_code -> icono heroicon (para la UI). */
    private array $categoryPrefix = [
        'Excavator' => ['EX', 'heroicon-o-wrench-screwdriver'],
        'Wheel Loader' => ['LD', 'heroicon-o-truck'],
        'Skid Steer' => ['LD', 'heroicon-o-truck'],
        'Roller' => ['RL', 'heroicon-o-stop-circle'],
        'Grader' => ['GR', 'heroicon-o-minus'],
        'Dozer' => ['DZ', 'heroicon-o-square-3-stack-3d'],
        'Broom Tractor' => ['BT', 'heroicon-o-sparkles'],
        'Cold Planer' => ['MS', 'heroicon-o-scissors'],
        'Light Tower' => ['MS', 'heroicon-o-light-bulb'],
        'Paver' => ['PV', 'heroicon-o-rectangle-group'],
        'Pump' => ['PW', 'heroicon-o-arrow-path-rounded-square'],
        'Tractor' => ['TR', 'heroicon-o-truck'],
        'Screen/Plant' => ['SC', 'heroicon-o-funnel'],
        'Crusher' => ['CR', 'heroicon-o-cube'],
        'Other' => ['XX', 'heroicon-o-cog-6-tooth'],
    ];

    private array $knownMakes = [
        'Caterpillar', 'CAT', 'Komatsu', 'John Deere', 'JD', 'Case', 'Bomag', 'Ingram',
        'Leeboy', 'Wacker Neuson', 'Laymor', 'Kobelco', 'Kobelko', 'Terex', 'Generac',
        'Magnum', 'MWI', 'Mersino', 'Pioneer', 'Thompson', 'Titan', 'Cobra', 'Case IH',
    ];

    public function run(): void
    {
        $machines = $this->loadJson('machines.json');
        $infobook = collect($this->loadJson('infobook.json'))->keyBy('id_code');

        foreach ($machines as $m) {
            $category = $this->categoryFor($m['category'], $m['id_code']);
            $info = $infobook->get($m['id_code']);

            // Marca: primero del info book, si no, del texto de descripción
            $makeName = $this->makeFor($info['make_year'] ?? null, $m['description']);
            $make = $makeName ? Make::firstOrCreate(
                ['slug' => Str::slug($makeName)],
                ['name' => $makeName]
            ) : null;

            $location = $this->locationFor($m['location_raw'] ?? '');

            $machine = Machine::updateOrCreate(
                ['id_code' => $m['id_code']],
                [
                    'machine_category_id' => $category?->id,
                    'make_id' => $make?->id,
                    'model' => $this->modelFor($m['description'], $info['model'] ?? null),
                    'serial' => $m['serial'] ?? ($info['serial'] ?? null),
                    'serial_type' => $m['serial_type'] ?? null,
                    'year' => $this->yearFor($info['make_year'] ?? null),
                    'description' => $m['description'],
                    'current_location_id' => $location?->id,
                    'status' => $m['not_in_service'] ? 'not_in_service' : 'active',
                    'hourmeter_status' => $this->hourmeterStatus($m),
                    'hours_adjustment' => $this->hoursAdjustment($m['location_raw'] ?? ''),
                    'current_hours' => $m['latest_reading']['hrs'] ?? null,
                    'current_hours_date' => $this->date($m['latest_reading']['date'] ?? null),
                    'last_service_hours' => $m['last_service']['hrs'] ?? null,
                    'last_service_date' => $this->date($m['last_service']['date'] ?? null),
                    'service_interval_hours' => 500,
                    'remaining_hours' => $m['remaining_hrs'] ?? null,
                    'engine_model' => $info['eng_model'] ?? null,
                    'electrical_system' => $info['electrical'] ?? null,
                    'battery_cca' => $info['cca'] ?? null,
                    'tires' => $info['tires'] ?? null,
                    'spec_sheet' => $info['raw'] ?? null,
                    'needs_review' => (bool) ($m['needs_review'] ?? false),
                    'review_note' => is_string($m['needs_review'] ?? null) ? $m['needs_review'] : null,
                    'notes' => $this->buildNotes($m),
                ]
            );

            // Lectura de horómetro inicial (import)
            if (! empty($m['latest_reading']['hrs'])) {
                HorometerReading::firstOrCreate(
                    [
                        'machine_id' => $machine->id,
                        'read_at' => $this->date($m['latest_reading']['date']) ?? now()->toDateString(),
                        'source' => 'import',
                    ],
                    [
                        'hours' => $m['latest_reading']['hrs'],
                        'note' => 'Lectura importada del PM Service Report',
                        'verified' => true,
                    ]
                );
            }

            // Catálogo de partes desde el info book
            if ($info && ! empty($info['parts'])) {
                $machine->parts()->delete();
                foreach ($info['parts'] as $p) {
                    // solo partes reales (con nº OEM, NAPA o intervalo)
                    if (empty($p['oem']) && empty($p['napa']) && empty($p['change_interval'])) {
                        continue;
                    }
                    MachinePart::create([
                        'machine_id' => $machine->id,
                        'category' => $this->partCategory($p['label']),
                        'label' => $p['label'],
                        'oem_number' => $p['oem'] ?? null,
                        'napa_number' => $this->cleanNapa($p['napa'] ?? null),
                        'change_interval_hours' => $p['change_interval'] ?? null,
                        'detail' => $p['text'] ?? null,
                    ]);
                }
            }
        }
    }

    private function loadJson(string $file): array
    {
        $path = database_path('data/'.$file);

        return json_decode(file_get_contents($path), true) ?: [];
    }

    private function categoryFor(string $name, string $idCode): ?MachineCategory
    {
        $meta = $this->categoryPrefix[$name] ?? $this->categoryPrefix['Other'];

        return MachineCategory::firstOrCreate(
            ['slug' => Str::slug($name)],
            ['name' => $name, 'prefix' => $meta[0], 'icon' => $meta[1], 'default_service_interval' => 500]
        );
    }

    private function makeFor(?string $makeYear, string $description): ?string
    {
        $haystack = trim(($makeYear ?? '').' '.$description);
        foreach ($this->knownMakes as $mk) {
            if (stripos($haystack, $mk) !== false) {
                return match (strtoupper($mk)) {
                    'CAT' => 'Caterpillar',
                    'JD' => 'John Deere',
                    'KOBELKO' => 'Kobelco',
                    default => $mk,
                };
            }
        }

        return null;
    }

    private function modelFor(string $description, ?string $infoModel): ?string
    {
        if ($infoModel && strlen(trim($infoModel)) <= 40) {
            return trim($infoModel);
        }

        return null;
    }

    private function yearFor(?string $makeYear): ?int
    {
        if (! $makeYear) {
            return null;
        }
        if (preg_match('/(19|20)\d{2}/', $makeYear, $mm)) {
            return (int) $mm[0];
        }

        return null;
    }

    private function locationFor(string $raw): ?Location
    {
        $name = $this->normalizeLocation($raw);
        if (! $name) {
            return null;
        }
        $type = preg_match('/\b(yd|yard)\b/i', $name) ? 'yard' : 'jobsite';

        return Location::firstOrCreate(
            ['slug' => Str::slug($name)],
            ['name' => $name, 'type' => $type, 'active' => true]
        );
    }

    private function normalizeLocation(string $raw): ?string
    {
        // limpia notas: toma la primera frase corta que parezca ubicación
        $raw = trim($raw);
        if ($raw === '') {
            return null;
        }
        // descarta si es una nota larga sin lugar reconocible
        $map = [
            '/broadview/i' => 'Broadview Yd.',
            '/blount/i' => 'Blount Rd.',
            '/\bwpb\b/i' => 'WPB Yd.',
            '/atlantic/i' => 'Atlantic Yd.',
            '/davie/i' => 'Davie Yd.',
            '/mc\.?\s*nab/i' => 'McNab',
            '/douglas/i' => 'Douglas Rd.',
            '/turnpike/i' => 'Turnpike',
            '/pompano/i' => 'Pompano Bch',
            '/rapid milling/i' => 'Rapid Milling & Paving',
        ];
        foreach ($map as $re => $canonical) {
            if (preg_match($re, $raw)) {
                return $canonical;
            }
        }

        // si no matchea un lugar conocido, no crea ubicación basura
        return null;
    }

    private function hourmeterStatus(array $m): string
    {
        $text = strtolower(($m['location_raw'] ?? '').' '.($m['description'] ?? ''));
        if (($m['latest_reading']['note'] ?? null) === 'no_info') {
            return 'no_info';
        }
        if (str_contains($text, 'hrmeter broken') || str_contains($text, 'hourmeter broken') || str_contains($text, 'meter broken')) {
            return 'broken';
        }
        if (str_contains($text, 'rplcd hourmeter') || str_contains($text, 'replaced hourmeter') || str_contains($text, 'rplcd hour')) {
            return 'replaced';
        }

        return 'ok';
    }

    private function hoursAdjustment(string $raw): int
    {
        if (preg_match('/add\s+([\d,]+)\s+to\s+current/i', $raw, $mm)) {
            return (int) str_replace(',', '', $mm[1]);
        }

        return 0;
    }

    private function buildNotes(array $m): ?string
    {
        $notes = [];
        $raw = trim($m['location_raw'] ?? '');
        // conserva la nota original si trae contexto (más allá del nombre del lugar)
        if ($raw !== '' && ! $this->normalizeLocation($raw)) {
            $notes[] = $raw;
        } elseif ($raw !== '' && strlen($raw) > 25) {
            $notes[] = $raw;
        }

        return $notes ? implode(' | ', $notes) : null;
    }

    private function date(?string $d): ?string
    {
        if (! $d) {
            return null;
        }
        if (! preg_match('#^(\d{1,2})/(\d{1,2})/(\d{2,4})$#', trim($d), $mm)) {
            return null;
        }
        [$all, $mo, $da, $yr] = $mm;
        $yr = (int) $yr;
        if ($yr < 100) {
            $yr += 2000;
        }
        if ($mo < 1 || $mo > 12 || $da < 1 || $da > 31) {
            return null;
        }

        return sprintf('%04d-%02d-%02d', $yr, $mo, $da);
    }

    private function cleanNapa(?string $napa): ?string
    {
        if (! $napa) {
            return null;
        }
        // descarta falsos positivos ("Commercial" del renglón de batería)
        if (preg_match('/^(commercial|na|n)$/i', $napa)) {
            return null;
        }

        return $napa;
    }

    private function partCategory(string $label): string
    {
        $l = strtolower($label);

        return match (true) {
            str_contains($l, 'oil filter') => 'oil_filter',
            str_contains($l, 'primary') || str_contains($l, 'prim.') => 'fuel_primary',
            str_contains($l, 'secondary') || str_contains($l, 'sec.') => 'fuel_secondary',
            str_contains($l, 'in-line') || str_contains($l, 'in line') => 'fuel_inline',
            str_contains($l, 'inner air') => 'air_inner',
            str_contains($l, 'outer air') => 'air_outer',
            str_contains($l, 'hydraulic') => 'hydraulic',
            str_contains($l, 'transmission') || str_contains($l, 'hst') => 'transmission',
            str_contains($l, 'crankcase') || str_contains($l, 'kccv') || str_contains($l, 'fumes') => 'crankcase',
            str_contains($l, 'a/c') || str_contains($l, 'ac ') => 'ac_filter',
            str_contains($l, 'def') || str_contains($l, 'particulate') => 'emissions',
            str_contains($l, 'alternator') || str_contains($l, 'starter') || str_contains($l, 'battery') => 'electrical',
            str_contains($l, 'belt') => 'belt',
            str_contains($l, 'water pump') => 'water_pump',
            str_contains($l, 'cutting edge') => 'cutting_edge',
            str_contains($l, 'tire') => 'tires',
            str_contains($l, 'bucket') || str_contains($l, 'tool adapter') => 'attachment',
            default => 'other',
        };
    }
}

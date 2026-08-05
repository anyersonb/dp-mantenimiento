<?php

namespace Tests\Feature\Management;

use App\Filament\Resources\MachineResource\Pages\ListMachines;
use App\Models\Machine;
use App\Models\MachineCategory;
use App\Models\User;
use App\Services\Reports\CategoryInventoryReportBuilder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Centinela de los estados de máquina.
 *
 * Nace de un defecto real encontrado el 2026-08-05 al armar el reporte de
 * inventario por categoría: el enum de `machines.status` tiene **cinco** valores
 * y había **dos listas escritas a mano** —el filtro de la tabla de máquinas y el
 * desglose del reporte— que enumeraban solo cuatro. La que faltaba era `unknown`,
 * y en la flota real ahí viven **29 de las 99 máquinas**.
 *
 * Lo que eso provocaba, medido en pantalla:
 *
 *   - el filtro de estado **no podía encontrar** esas 29 máquinas;
 *   - el pie del reporte decía `Total 101` y el desglose sumaba `72`, **sin
 *     ningún aviso**. Un total que no cuadra con su propio desglose es peor que
 *     un error visible: el lector suma, no le da, y ya no sabe qué creer del
 *     resto del reporte.
 *
 * Este archivo cierra las dos puertas: que la constante siga al enum de la base,
 * y que el desglose del reporte siempre sume el total.
 */
class MachineStatusOptionsAreCompleteTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Este corre en CUALQUIER motor, porque lee el enum del archivo de migración
     * y no de la base. Hace falta: el test de abajo, que consulta la base de
     * verdad, **se saltea en SQLite**, o sea que en la corrida del día a día no
     * habría ninguna red y la deriva solo aparecería en el gate de cierre de
     * etapa. Comprobado quitando `unknown` de la constante: en SQLite los cuatro
     * tests pasaban igual.
     */
    public function test_the_constant_matches_the_enum_declared_in_the_migration(): void
    {
        $file = collect(glob(database_path('migrations/*create_machines_table.php')))->first();

        $this->assertNotNull($file, 'No se encontró la migración que crea machines.');

        $source = file_get_contents($file);

        $this->assertSame(
            1,
            preg_match("/enum\('status',\s*\[([^\]]+)\]/", $source, $m),
            'No se pudo leer el enum de status en la migración: cambió su forma y este centinela quedó ciego.'
        );

        preg_match_all("/'([^']+)'/", $m[1], $values);

        $fromMigration = $values[1];
        sort($fromMigration);
        $fromCode = Machine::STATUSES;
        sort($fromCode);

        $this->assertSame(
            $fromMigration,
            $fromCode,
            'Machine::STATUSES se desincronizó del enum de la migración. Si agregaste un estado, '
            .'agregalo a la constante y a lang/{es,en}/fleet.php como status_<valor>.'
        );
    }

    public function test_the_constant_matches_the_enum_declared_in_the_database(): void
    {
        // Solo tiene sentido contra MySQL, que es donde vive el enum de verdad.
        // En SQLite la columna es un varchar sin lista y no hay nada que comparar
        // — otro caso de la tabla de diferencias del CLAUDE.md.
        if (DB::connection()->getDriverName() !== 'mysql') {
            $this->markTestSkipped('El enum solo existe en MySQL; en SQLite la columna no declara valores.');
        }

        $type = DB::selectOne(
            'SELECT COLUMN_TYPE AS t FROM information_schema.columns
             WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ?',
            ['machines', 'status'],
        )?->t;

        $this->assertNotNull($type, 'No se pudo leer el tipo de machines.status.');

        preg_match_all("/'([^']+)'/", (string) $type, $matches);
        $fromDatabase = $matches[1];

        sort($fromDatabase);
        $fromCode = Machine::STATUSES;
        sort($fromCode);

        $this->assertSame(
            $fromDatabase,
            $fromCode,
            'Machine::STATUSES se desincronizó del enum de la base. Si agregaste un estado, '
            .'agregalo a la constante y a lang/{es,en}/fleet.php como status_<valor>.'
        );
    }

    public function test_every_status_has_a_label_in_both_languages(): void
    {
        foreach (Machine::STATUSES as $status) {
            foreach (['es', 'en'] as $locale) {
                $key = 'fleet.status_'.$status;
                $this->assertNotSame(
                    $key,
                    __($key, [], $locale),
                    "Falta la traducción {$key} en {$locale}: el estado saldría crudo en pantalla."
                );
            }
        }
    }

    public function test_the_machine_table_status_filter_offers_every_status(): void
    {
        // Se pregunta a la PÁGINA real, no a un componente de mentira: es la que
        // el usuario abre, y es donde se vio el hueco.
        $this->seed(RolesAndPermissionsSeeder::class);
        $admin = User::where('email', 'admin@dp.local')->firstOrFail();

        $filters = Livewire::actingAs($admin)
            ->test(ListMachines::class)
            ->instance()
            ->getTable()
            ->getFilters();

        $filter = $filters['status'] ?? null;

        $this->assertNotNull($filter, 'Desapareció el filtro de estado de la tabla de máquinas.');

        $offered = array_keys($filter->getOptions());
        sort($offered);
        $expected = Machine::STATUSES;
        sort($expected);

        $this->assertSame($expected, $offered, 'El filtro de estado no ofrece todos los estados posibles.');
    }

    public function test_the_category_report_breakdown_always_adds_up_to_the_total(): void
    {
        $excavators = MachineCategory::create([
            'name' => 'Excavator', 'slug' => 'excavator-'.uniqid(), 'default_service_interval' => 500,
        ]);

        // Una máquina por CADA estado posible. Con la lista de cuatro, la de
        // `unknown` desaparecía del desglose y este test fallaba: 5 contra 4.
        foreach (Machine::STATUSES as $i => $status) {
            Machine::create([
                'id_code' => 'ST-'.$i,
                'machine_category_id' => $excavators->id,
                'status' => $status,
                'hourmeter_status' => 'ok',
                'current_hours' => 100,
                'last_service_hours' => 0,
                'service_interval_hours' => 500,
                'hours_adjustment' => 0,
            ]);
        }

        $report = CategoryInventoryReportBuilder::build();
        $row = collect($report['rows'])->firstWhere('category_raw', 'Excavator');

        $breakdown = array_sum(array_map(fn (string $s) => $row[$s], $report['statuses']));

        $this->assertSame(count(Machine::STATUSES), $row['total']);
        $this->assertSame($row['total'], $breakdown, 'El desglose por estado no suma el total de la categoría.');

        $totalBreakdown = array_sum(array_map(fn (string $s) => $report['totals'][$s], $report['statuses']));
        $this->assertSame($report['totals']['total'], $totalBreakdown, 'El desglose del pie no suma el total general.');
    }
}

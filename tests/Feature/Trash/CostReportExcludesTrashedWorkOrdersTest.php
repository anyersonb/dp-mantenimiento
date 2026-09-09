<?php

namespace Tests\Feature\Trash;

use App\Filament\Widgets\FleetCostOverview;
use App\Models\Location;
use App\Models\Machine;
use App\Models\WorkOrder;
use App\Models\WorkOrderPart;
use App\Services\Reports\CostReportBuilder;
use App\Services\Reports\CostReportFilters;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Papelera (Lote A): una orden de trabajo en la papelera es dinero que ya no
 * es real para el reporte de costos — un reporte que la sigue sumando le
 * miente al lector sin que pueda notarlo (mismo criterio que
 * `CostReportBuilder`, ver su docblock).
 *
 * `CostReportBuilder::query()` y `FleetCostOverview` arman su consulta con
 * `WorkOrder::query()` (Eloquent, no `DB::table`), así que en cuanto
 * `WorkOrder` tiene `SoftDeletes` el scope global ya las excluye solo — este
 * test es la prueba de que efectivamente lo hace, no una suposición.
 */
class CostReportExcludesTrashedWorkOrdersTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);
    }

    private function machine(string $idCode): Machine
    {
        $location = Location::create(['name' => 'Cost Yard', 'slug' => 'cost-yard-'.uniqid()]);

        return Machine::create([
            'id_code' => $idCode,
            'status' => 'active',
            'hourmeter_status' => 'ok',
            'current_location_id' => $location->id,
        ]);
    }

    private function completedWorkOrderWithParts(Machine $machine, float $unitCost): WorkOrder
    {
        $workOrder = WorkOrder::create([
            'code' => 'WO-CT'.random_int(10000, 99999),
            'machine_id' => $machine->id,
            'type' => 'corrective',
            'status' => 'completed',
            'opened_at' => '2026-08-10',
            'completed_at' => '2026-08-10',
            'parts_cost' => $unitCost,
        ]);

        WorkOrderPart::create([
            'work_order_id' => $workOrder->id,
            'part_number' => 'P-1',
            'quantity' => 1,
            'unit_cost' => $unitCost,
        ]);

        return $workOrder;
    }

    public function test_the_cost_report_excludes_a_trashed_work_order(): void
    {
        $machine = $this->machine('CT010');

        $vivo = $this->completedWorkOrderWithParts($machine, 100.00);
        $borrado = $this->completedWorkOrderWithParts($machine, 900.00);
        $borrado->delete();

        $this->assertSoftDeleted('work_orders', ['id' => $borrado->id]);

        $filters = CostReportFilters::fromArray(['from' => '2026-08-01', 'to' => '2026-08-31']);
        $report = CostReportBuilder::build($filters);

        $this->assertSame(
            100.0,
            $report['totals']['parts_total'],
            'La OT en la papelera (900.00) no debe sumar al total del reporte de costos.'
        );
        $this->assertSame(1, $report['totals']['work_order_count']);

        $codes = collect($report['machines'][0]['work_orders'])->pluck('code');
        $this->assertTrue($codes->contains($vivo->code));
        $this->assertFalse($codes->contains($borrado->code), 'La OT borrada no debe aparecer en el detalle del reporte.');
    }

    public function test_the_fleet_cost_overview_widget_excludes_a_trashed_work_order(): void
    {
        $machine = $this->machine('CT020');

        $this->completedWorkOrderWithParts($machine, 50.00);
        $borrado = $this->completedWorkOrderWithParts($machine, 950.00);
        $borrado->delete();

        $widget = new FleetCostOverview;
        $stats = (fn () => $this->getStats())->call($widget);

        // El primer Stat es el costo total; se compara contra el VALOR
        // formateado ('$50.00'), que es lo que de verdad ve el usuario.
        $this->assertSame('$50.00', $stats[0]->getValue());
    }
}

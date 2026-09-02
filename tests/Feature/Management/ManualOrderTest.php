<?php

namespace Tests\Feature\Management;

use App\Filament\Resources\LocationResource\Pages\ListLocations;
use App\Filament\Resources\MachineResource\Pages\EditMachine;
use App\Filament\Resources\MachineResource\RelationManagers\PartsRelationManager as MachinePartsRelationManager;
use App\Filament\Resources\WorkOrderResource\Pages\EditWorkOrder;
use App\Filament\Resources\WorkOrderResource\RelationManagers\PartsRelationManager as WorkOrderPartsRelationManager;
use App\Models\Location;
use App\Models\Machine;
use App\Models\MachinePart;
use App\Models\User;
use App\Models\WorkOrder;
use App\Models\WorkOrderPart;
use App\Services\Reports\CostReportBuilder;
use App\Services\Reports\CostReportFilters;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Orden manual (arrastrable), lote 2026-09-01.
 *
 * Lo que estos tests protegen:
 *
 *   1. Un registro nuevo nace con su propio sort_order (al final del grupo
 *      que corresponda) y no en 0 empatado con todos los demás — si esto
 *      fallara, el orden manual solo existiría para lo que ya tenía el
 *      backfill de la migración.
 *   2. El orden manual de las líneas de repuestos de una OT se refleja en
 *      CostReportBuilder (y por lo tanto en pantalla, PDF y Excel).
 *   3. El botón de reordenar respeta el mismo permiso que ya autoriza
 *      editar ese recurso — quien solo puede ver, no reordena, ni siquiera
 *      llamando el método de Livewire directamente.
 */
class ManualOrderTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);
    }

    private function machine(): Machine
    {
        return Machine::create([
            'id_code' => 'MO-'.random_int(1000, 9999),
            'status' => 'active',
            'hourmeter_status' => 'ok',
            'current_hours' => 500,
            'last_service_hours' => 100,
            'service_interval_hours' => 500,
            'hours_adjustment' => 0,
        ]);
    }

    private function workOrder(Machine $machine): WorkOrder
    {
        return WorkOrder::create([
            'code' => 'WO-'.random_int(10000, 99999),
            'machine_id' => $machine->id,
            'type' => 'corrective',
            'status' => 'open',
            'opened_at' => now()->toDateString(),
        ]);
    }

    private function user(string $email): User
    {
        return User::where('email', $email)->firstOrFail();
    }

    /* ------------------------------------------------------------------ *
     * 1. Asignación automática al crear.
     * ------------------------------------------------------------------ */

    public function test_new_work_order_parts_get_the_next_sort_order_reset_per_work_order(): void
    {
        $woA = $this->workOrder($this->machine());
        $woB = $this->workOrder($this->machine());

        $a1 = WorkOrderPart::create(['work_order_id' => $woA->id, 'quantity' => 1, 'unit_cost' => 10]);
        $a2 = WorkOrderPart::create(['work_order_id' => $woA->id, 'quantity' => 1, 'unit_cost' => 20]);
        // Distinta OT: numera desde 1, no sigue la secuencia de $woA.
        $b1 = WorkOrderPart::create(['work_order_id' => $woB->id, 'quantity' => 1, 'unit_cost' => 30]);

        $this->assertSame(1, $a1->fresh()->sort_order);
        $this->assertSame(2, $a2->fresh()->sort_order);
        $this->assertSame(1, $b1->fresh()->sort_order);
    }

    public function test_new_locations_get_the_next_global_sort_order(): void
    {
        $first = Location::create(['name' => 'Yard 1', 'slug' => 'yard-1-'.uniqid()]);
        $second = Location::create(['name' => 'Yard 2', 'slug' => 'yard-2-'.uniqid()]);

        $this->assertSame($first->fresh()->sort_order + 1, $second->fresh()->sort_order);
    }

    /* ------------------------------------------------------------------ *
     * 2. CostReportBuilder respeta el orden manual de las líneas.
     * ------------------------------------------------------------------ */

    public function test_cost_report_builder_lists_parts_in_manual_order_not_creation_order(): void
    {
        $machine = $this->machine();
        $wo = WorkOrder::create([
            'code' => 'WO-'.random_int(10000, 99999),
            'machine_id' => $machine->id,
            'type' => 'corrective',
            'status' => 'completed',
            'opened_at' => '2026-08-10',
            'completed_at' => '2026-08-10',
        ]);

        // Se crean en orden Z, A, M; se reordenan a A, M, Z.
        $z = WorkOrderPart::create(['work_order_id' => $wo->id, 'part_number' => 'Z', 'quantity' => 1, 'unit_cost' => 1]);
        $a = WorkOrderPart::create(['work_order_id' => $wo->id, 'part_number' => 'A', 'quantity' => 1, 'unit_cost' => 1]);
        $m = WorkOrderPart::create(['work_order_id' => $wo->id, 'part_number' => 'M', 'quantity' => 1, 'unit_cost' => 1]);

        $a->update(['sort_order' => 1]);
        $m->update(['sort_order' => 2]);
        $z->update(['sort_order' => 3]);

        $filters = CostReportFilters::fromArray(['from' => '2026-08-01', 'to' => '2026-08-31']);
        $report = CostReportBuilder::build($filters);

        $partNumbers = array_column($report['machines'][0]['work_orders'][0]['parts'], 'part_number');

        $this->assertSame(['A', 'M', 'Z'], $partNumbers);
    }

    /* ------------------------------------------------------------------ *
     * 3. El reordenamiento respeta el permiso de edición del recurso.
     * ------------------------------------------------------------------ */

    public function test_a_role_without_execute_work_order_cannot_reorder_work_order_parts(): void
    {
        // gerencia: view_fleet, view_costs, move_fleet, view_reports — sin
        // execute_work_order.
        $wo = $this->workOrder($this->machine());
        $first = WorkOrderPart::create(['work_order_id' => $wo->id, 'quantity' => 1, 'unit_cost' => 10]);
        $second = WorkOrderPart::create(['work_order_id' => $wo->id, 'quantity' => 1, 'unit_cost' => 20]);

        Livewire::actingAs($this->user('gerencia@dp.local'))
            ->test(WorkOrderPartsRelationManager::class, [
                'ownerRecord' => $wo,
                'pageClass' => EditWorkOrder::class,
            ])
            ->call('reorderTable', [$second->getKey(), $first->getKey()]);

        // Sin autorización, reorderTable() no debe tocar nada: se conserva el
        // orden que tenían (1, 2).
        $this->assertSame(1, $first->fresh()->sort_order);
        $this->assertSame(2, $second->fresh()->sort_order);
    }

    public function test_the_workshop_with_execute_work_order_can_reorder_work_order_parts(): void
    {
        $wo = $this->workOrder($this->machine());
        $first = WorkOrderPart::create(['work_order_id' => $wo->id, 'quantity' => 1, 'unit_cost' => 10]);
        $second = WorkOrderPart::create(['work_order_id' => $wo->id, 'quantity' => 1, 'unit_cost' => 20]);

        Livewire::actingAs($this->user('taller@dp.local'))
            ->test(WorkOrderPartsRelationManager::class, [
                'ownerRecord' => $wo,
                'pageClass' => EditWorkOrder::class,
            ])
            ->call('reorderTable', [$second->getKey(), $first->getKey()]);

        $this->assertSame(2, $first->fresh()->sort_order);
        $this->assertSame(1, $second->fresh()->sort_order);
    }

    public function test_a_role_without_manage_machines_cannot_reorder_the_machine_parts_catalog(): void
    {
        $machine = $this->machine();
        $first = MachinePart::create(['machine_id' => $machine->id, 'label' => 'A']);
        $second = MachinePart::create(['machine_id' => $machine->id, 'label' => 'B']);

        Livewire::actingAs($this->user('taller@dp.local'))
            ->test(MachinePartsRelationManager::class, [
                'ownerRecord' => $machine,
                'pageClass' => EditMachine::class,
            ])
            ->call('reorderTable', [$second->getKey(), $first->getKey()]);

        $this->assertSame(1, $first->fresh()->sort_order);
        $this->assertSame(2, $second->fresh()->sort_order);
    }

    public function test_a_role_without_manage_machines_cannot_reorder_locations(): void
    {
        $first = Location::create(['name' => 'Yard A', 'slug' => 'yard-a-'.uniqid()]);
        $second = Location::create(['name' => 'Yard B', 'slug' => 'yard-b-'.uniqid()]);

        Livewire::actingAs($this->user('taller@dp.local'))
            ->test(ListLocations::class)
            ->call('reorderTable', [$second->getKey(), $first->getKey()]);

        $this->assertSame(1, $first->fresh()->sort_order);
        $this->assertSame(2, $second->fresh()->sort_order);
    }

    public function test_the_administrator_can_reorder_locations(): void
    {
        $first = Location::create(['name' => 'Yard A', 'slug' => 'yard-a-'.uniqid()]);
        $second = Location::create(['name' => 'Yard B', 'slug' => 'yard-b-'.uniqid()]);

        Livewire::actingAs($this->user('admin@dp.local'))
            ->test(ListLocations::class)
            ->call('reorderTable', [$second->getKey(), $first->getKey()]);

        $this->assertSame(2, $first->fresh()->sort_order);
        $this->assertSame(1, $second->fresh()->sort_order);
    }
}

<?php

namespace Tests\Feature\WorkOrder;

use App\Filament\Resources\WorkOrderResource\Pages\EditWorkOrder;
use App\Filament\Resources\WorkOrderResource\RelationManagers\ChecklistResultsRelationManager;
use App\Filament\Resources\WorkOrderResource\RelationManagers\PartsRelationManager;
use App\Models\Alert;
use App\Models\HorometerReading;
use App\Models\Location;
use App\Models\Machine;
use App\Models\User;
use App\Models\WorkOrder;
use App\Models\WorkOrderPart;
use App\Services\WorkOrderCompletionService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class WorkOrderExecutionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);
    }

    protected function machine(array $overrides = []): Machine
    {
        $location = Location::create(['name' => 'Test Yard', 'slug' => 'test-yard-'.uniqid()]);

        return Machine::create(array_merge([
            'id_code' => 'TST-'.random_int(1000, 9999),
            'status' => 'active',
            'current_location_id' => $location->id,
            'current_hours' => 520,
            'last_service_hours' => 100,
            'service_interval_hours' => 500,
            'hours_adjustment' => 0,
            'remaining_hours' => 80,
        ], $overrides));
    }

    protected function workOrder(Machine $machine, array $overrides = []): WorkOrder
    {
        return WorkOrder::create(array_merge([
            'code' => 'WO-'.random_int(1000, 9999),
            'machine_id' => $machine->id,
            'type' => 'preventive',
            'status' => 'open',
            'priority' => 'normal',
            'hours_at_open' => $machine->current_hours,
            'opened_at' => now()->toDateString(),
        ], $overrides));
    }

    /* ------------------------------------------------------------------ *
     * 1) Checklist: alert_detail obligatorio cuando result=alert
     * ------------------------------------------------------------------ */

    public function test_checklist_relation_manager_rejects_an_alert_result_without_a_detail(): void
    {
        $admin = User::where('email', 'admin@dp.local')->firstOrFail();
        $workOrder = $this->workOrder($this->machine());

        Livewire::actingAs($admin)
            ->test(ChecklistResultsRelationManager::class, [
                'ownerRecord' => $workOrder,
                'pageClass' => EditWorkOrder::class,
            ])
            ->callTableAction('create', data: [
                'label' => 'Brakes - Service',
                'result' => 'alert',
                'alert_detail' => null,
            ])
            ->assertHasTableActionErrors(['alert_detail']);

        $this->assertDatabaseMissing('checklist_results', ['work_order_id' => $workOrder->id]);
    }

    public function test_checklist_relation_manager_accepts_an_alert_with_detail_and_notifies_the_administrator(): void
    {
        $admin = User::where('email', 'admin@dp.local')->firstOrFail();
        $machine = $this->machine();
        $workOrder = $this->workOrder($machine);

        Livewire::actingAs($admin)
            ->test(ChecklistResultsRelationManager::class, [
                'ownerRecord' => $workOrder,
                'pageClass' => EditWorkOrder::class,
            ])
            ->callTableAction('create', data: [
                'label' => 'Brakes - Parking',
                'result' => 'alert',
                'alert_detail' => 'Parking brake does not hold on incline.',
            ])
            ->assertHasNoTableActionErrors();

        $this->assertDatabaseHas('checklist_results', [
            'work_order_id' => $workOrder->id,
            'result' => 'alert',
        ]);

        // La OT notifica al administrador creando una Alert(type=checklist) abierta.
        $this->assertDatabaseHas('alerts', [
            'machine_id' => $machine->id,
            'type' => 'checklist',
            'status' => 'open',
        ]);
    }

    public function test_checklist_relation_manager_allows_ok_results_without_a_detail(): void
    {
        $admin = User::where('email', 'admin@dp.local')->firstOrFail();
        $workOrder = $this->workOrder($this->machine());

        Livewire::actingAs($admin)
            ->test(ChecklistResultsRelationManager::class, [
                'ownerRecord' => $workOrder,
                'pageClass' => EditWorkOrder::class,
            ])
            ->callTableAction('create', data: [
                'label' => 'Belts and Hoses',
                'result' => 'ok',
                'alert_detail' => null,
            ])
            ->assertHasNoTableActionErrors();

        $this->assertDatabaseHas('checklist_results', [
            'work_order_id' => $workOrder->id,
            'result' => 'ok',
        ]);
    }

    /* ------------------------------------------------------------------ *
     * 2) Partes: recálculo de parts_cost
     * ------------------------------------------------------------------ */

    public function test_adding_and_removing_parts_recalculates_the_work_order_parts_cost(): void
    {
        $workOrder = $this->workOrder($this->machine());

        $part1 = WorkOrderPart::create([
            'work_order_id' => $workOrder->id,
            'part_number' => 'OIL-123',
            'quantity' => 2,
            'unit_cost' => 15.50,
        ]);

        $this->assertEquals(31.00, (float) $workOrder->refresh()->parts_cost);

        WorkOrderPart::create([
            'work_order_id' => $workOrder->id,
            'part_number' => 'FLT-456',
            'quantity' => 1,
            'unit_cost' => 9.99,
        ]);

        $this->assertEquals(40.99, round((float) $workOrder->refresh()->parts_cost, 2));

        $part1->delete();

        $this->assertEquals(9.99, (float) $workOrder->refresh()->parts_cost);
    }

    /* ------------------------------------------------------------------ *
     * 3) Cierre de OT: reinicia el servicio de la máquina y resuelve la alerta
     * ------------------------------------------------------------------ */

    public function test_completing_a_preventive_work_order_resets_machine_service_and_resolves_the_open_service_alert(): void
    {
        $machine = $this->machine();

        $alert = Alert::create([
            'machine_id' => $machine->id,
            'type' => 'service',
            'title' => 'Service due soon: '.$machine->id_code,
            'status' => 'open',
            'remaining_hours' => 80,
        ]);

        $workOrder = $this->workOrder($machine, ['completed_at' => now()->toDateString()]);

        WorkOrderCompletionService::complete($workOrder->fresh('machine'));

        $machine->refresh();
        $this->assertSame($machine->service_interval_hours, $machine->remaining_hours);
        $this->assertSame(520, $machine->last_service_hours);
        $this->assertNotNull($machine->last_service_date);

        $this->assertSame('resolved', $alert->refresh()->status);

        $this->assertDatabaseHas('horometer_readings', [
            'machine_id' => $machine->id,
            'source' => 'workshop',
            'hours' => 520,
        ]);
    }

    public function test_completing_a_corrective_work_order_does_not_touch_the_machine_service_cycle(): void
    {
        $machine = $this->machine();

        $workOrder = $this->workOrder($machine, [
            'type' => 'corrective',
            'completed_at' => now()->toDateString(),
        ]);

        WorkOrderCompletionService::complete($workOrder->fresh('machine'));

        $machine->refresh();
        // Sin cambios: una OT correctiva no reinicia el ciclo de servicio preventivo.
        $this->assertSame(80, $machine->remaining_hours);
        $this->assertSame(100, $machine->last_service_hours);
    }

    public function test_completing_a_work_order_twice_does_not_duplicate_the_horometer_reading(): void
    {
        $machine = $this->machine();
        $workOrder = $this->workOrder($machine, ['completed_at' => now()->toDateString()]);

        WorkOrderCompletionService::complete($workOrder->fresh('machine'));
        WorkOrderCompletionService::complete($workOrder->fresh('machine'));

        $this->assertSame(
            1,
            HorometerReading::where('machine_id', $machine->id)
                ->where('source', 'workshop')
                ->count()
        );
    }

    /* ------------------------------------------------------------------ *
     * 4) Costos ocultos sin permiso view_costs
     * ------------------------------------------------------------------ */

    public function test_parts_cost_columns_are_hidden_without_the_view_costs_permission(): void
    {
        $workOrder = $this->workOrder($this->machine());

        WorkOrderPart::create([
            'work_order_id' => $workOrder->id,
            'part_number' => 'OIL-123',
            'quantity' => 1,
            'unit_cost' => 20,
        ]);

        // Usuario sin ningún rol/permiso asignado.
        $userWithoutCosts = User::factory()->create(['active' => true]);

        Livewire::actingAs($userWithoutCosts)
            ->test(PartsRelationManager::class, [
                'ownerRecord' => $workOrder,
                'pageClass' => EditWorkOrder::class,
            ])
            ->assertCanNotRenderTableColumn('unit_cost')
            ->assertCanNotRenderTableColumn('subtotal');
    }

    public function test_parts_cost_columns_are_visible_with_the_view_costs_permission(): void
    {
        $workOrder = $this->workOrder($this->machine());

        WorkOrderPart::create([
            'work_order_id' => $workOrder->id,
            'part_number' => 'OIL-123',
            'quantity' => 1,
            'unit_cost' => 20,
        ]);

        $taller = User::where('email', 'taller@dp.local')->firstOrFail();

        Livewire::actingAs($taller)
            ->test(PartsRelationManager::class, [
                'ownerRecord' => $workOrder,
                'pageClass' => EditWorkOrder::class,
            ])
            ->assertCanRenderTableColumn('unit_cost')
            ->assertCanRenderTableColumn('subtotal');
    }
}

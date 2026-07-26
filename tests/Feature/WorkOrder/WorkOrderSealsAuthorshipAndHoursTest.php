<?php

namespace Tests\Feature\WorkOrder;

use App\Exceptions\CannotCompleteWorkOrder;
use App\Filament\Resources\WorkOrderResource\Pages\CreateWorkOrder;
use App\Filament\Resources\WorkOrderResource\Pages\EditWorkOrder;
use App\Filament\Resources\WorkOrderResource\Pages\ListWorkOrders;
use App\Models\HorometerReading;
use App\Models\Location;
use App\Models\Machine;
use App\Models\User;
use App\Models\WorkOrder;
use App\Services\WorkOrderCompletionService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Hallazgo E6-08.
 *
 * Medido en base de datos en la Sesión 1: la OT creada desde el formulario del
 * panel quedaba con `opened_by` y `hours_at_open` en NULL, y la lectura creada
 * desde el relation manager con `recorded_by` en NULL. La acción "Crear OT" de
 * una alerta y el camino de campo sí los sellaban — otra vez la regla en un
 * camino y no en los demás.
 *
 * Y el fondo del problema: `WorkOrderCompletionService` resolvía las horas con
 * `current_hours ?? hours_at_open` y, con las dos en NULL, seguía adelante sin
 * registrar `last_service_hours` pero reiniciando `remaining_hours`. Eso alcanza
 * a las 41 máquinas de la flota real sin horómetro cargado (34 sin
 * `current_hours` + 7 en revisión con horas).
 */
class WorkOrderSealsAuthorshipAndHoursTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);
    }

    private function machine(array $overrides = []): Machine
    {
        $location = Location::create(['name' => 'Yard', 'slug' => 'yard-'.uniqid()]);

        return Machine::create(array_merge([
            'id_code' => 'SEAL-'.random_int(1000, 9999),
            'status' => 'active',
            'hourmeter_status' => 'ok',
            'current_location_id' => $location->id,
            'current_hours' => 800,
            'last_service_hours' => 500,
            'service_interval_hours' => 500,
            'hours_adjustment' => 0,
        ], $overrides));
    }

    private function user(string $email): User
    {
        return User::where('email', $email)->firstOrFail();
    }

    /* ------------------------------------------------------------------ *
     * El sello, en los tres caminos de escritura.
     * ------------------------------------------------------------------ */

    public function test_the_panel_form_stamps_who_opened_the_work_order_and_the_hours(): void
    {
        $machine = $this->machine(['current_hours' => 1234]);
        $responsable = $this->user('responsable@dp.local');

        Livewire::actingAs($responsable)
            ->test(CreateWorkOrder::class)
            ->fillForm([
                'code' => 'SEAL-OT-01',
                'machine_id' => $machine->id,
                'type' => 'preventive',
                'status' => 'open',
                'priority' => 'normal',
                'opened_at' => now()->toDateString(),
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertDatabaseHas('work_orders', [
            'code' => 'SEAL-OT-01',
            'opened_by' => $responsable->id,
            'hours_at_open' => 1234,
        ]);
    }

    public function test_an_explicit_value_wins_over_the_stamp(): void
    {
        $machine = $this->machine(['current_hours' => 1234]);
        $otro = $this->user('admin@dp.local');

        $this->actingAs($this->user('responsable@dp.local'));

        WorkOrder::create([
            'code' => 'SEAL-OT-02',
            'machine_id' => $machine->id,
            'type' => 'preventive',
            'status' => 'open',
            'priority' => 'normal',
            'opened_by' => $otro->id,
            'hours_at_open' => 999,
            'opened_at' => now()->toDateString(),
        ]);

        // El observer rellena, no sobreescribe: la acción de la alerta, un
        // seeder o una importación siguen mandando.
        $this->assertDatabaseHas('work_orders', [
            'code' => 'SEAL-OT-02',
            'opened_by' => $otro->id,
            'hours_at_open' => 999,
        ]);
    }

    public function test_the_hours_field_of_the_form_is_used_when_the_machine_has_none(): void
    {
        $machine = $this->machine(['current_hours' => null, 'last_service_hours' => null]);
        $responsable = $this->user('responsable@dp.local');

        Livewire::actingAs($responsable)
            ->test(CreateWorkOrder::class)
            ->fillForm([
                'code' => 'SEAL-OT-03',
                'machine_id' => $machine->id,
                'type' => 'preventive',
                'status' => 'open',
                'priority' => 'normal',
                'hours_at_open' => 4321,
                'opened_at' => now()->toDateString(),
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertDatabaseHas('work_orders', [
            'code' => 'SEAL-OT-03',
            'hours_at_open' => 4321,
            'opened_by' => $responsable->id,
        ]);
    }

    public function test_a_reading_created_from_the_panel_stamps_who_recorded_it(): void
    {
        $machine = $this->machine();
        $responsable = $this->user('responsable@dp.local');

        $this->actingAs($responsable);

        HorometerReading::create([
            'machine_id' => $machine->id,
            'hours' => 900,
            'read_at' => now()->toDateString(),
            'source' => 'manual',
        ]);

        $this->assertDatabaseHas('horometer_readings', [
            'machine_id' => $machine->id,
            'hours' => 900,
            'recorded_by' => $responsable->id,
        ]);
    }

    /* ------------------------------------------------------------------ *
     * El fondo: sin horas no hay cierre.
     * ------------------------------------------------------------------ */

    public function test_completing_without_any_hours_is_rejected_and_changes_nothing(): void
    {
        $machine = $this->machine([
            'current_hours' => null,
            'last_service_hours' => null,
            'remaining_hours' => null,
        ]);

        $workOrder = WorkOrder::create([
            'code' => 'SEAL-OT-04',
            'machine_id' => $machine->id,
            'type' => 'preventive',
            'status' => 'in_progress',
            'priority' => 'normal',
            'hours_at_open' => null,
            'opened_at' => now()->toDateString(),
        ]);

        $this->assertFalse(WorkOrderCompletionService::canComplete($workOrder->fresh('machine')));

        try {
            WorkOrderCompletionService::complete($workOrder->fresh('machine'));
            $this->fail('Cerrar sin ninguna fuente de horas tenía que ser rechazado.');
        } catch (CannotCompleteWorkOrder $e) {
            $this->assertSame($workOrder->id, $e->workOrder->id);
        }

        // Lo que importa: NADA cambió. En particular remaining_hours NO se
        // reinició al intervalo, que era el comportamiento silencioso anterior.
        $machine->refresh();
        $this->assertNull($machine->last_service_hours);
        $this->assertNull($machine->last_service_date);
        $this->assertNull($machine->remaining_hours);
        $this->assertDatabaseMissing('horometer_readings', ['machine_id' => $machine->id]);
    }

    public function test_the_shop_gets_a_warning_instead_of_a_closed_work_order(): void
    {
        $machine = $this->machine([
            'current_hours' => null,
            'last_service_hours' => null,
            'remaining_hours' => null,
        ]);

        $workOrder = WorkOrder::create([
            'code' => 'SEAL-OT-05',
            'machine_id' => $machine->id,
            'type' => 'preventive',
            'status' => 'in_progress',
            'priority' => 'normal',
            'hours_at_open' => null,
            'opened_at' => now()->toDateString(),
        ]);

        Livewire::actingAs($this->user('taller@dp.local'))
            ->test(ListWorkOrders::class)
            ->callTableAction('complete', $workOrder)
            ->assertNotified(__('wo.cannot_complete_no_hours'));

        // La OT sigue abierta: el rechazo es antes de tocar el estado.
        $this->assertDatabaseHas('work_orders', ['id' => $workOrder->id, 'status' => 'in_progress']);
        $this->assertNull($machine->refresh()->remaining_hours);
    }

    public function test_the_edit_form_refuses_to_close_it_too(): void
    {
        $machine = $this->machine([
            'current_hours' => null,
            'last_service_hours' => null,
            'remaining_hours' => null,
        ]);

        $workOrder = WorkOrder::create([
            'code' => 'SEAL-OT-06',
            'machine_id' => $machine->id,
            'type' => 'preventive',
            'status' => 'in_progress',
            'priority' => 'normal',
            'hours_at_open' => null,
            'opened_at' => now()->toDateString(),
        ]);

        Livewire::actingAs($this->user('admin@dp.local'))
            ->test(EditWorkOrder::class, ['record' => $workOrder->getKey()])
            ->fillForm(['status' => 'completed'])
            ->call('save')
            ->assertNotified(__('wo.cannot_complete_no_hours'));

        $this->assertDatabaseHas('work_orders', ['id' => $workOrder->id, 'status' => 'in_progress']);
        $this->assertNull($machine->refresh()->remaining_hours);
    }

    public function test_filling_the_hours_in_the_same_save_lets_it_close(): void
    {
        $machine = $this->machine([
            'current_hours' => null,
            'last_service_hours' => null,
            'remaining_hours' => null,
        ]);

        $workOrder = WorkOrder::create([
            'code' => 'SEAL-OT-07',
            'machine_id' => $machine->id,
            'type' => 'preventive',
            'status' => 'in_progress',
            'priority' => 'normal',
            'hours_at_open' => null,
            'opened_at' => now()->toDateString(),
        ]);

        Livewire::actingAs($this->user('admin@dp.local'))
            ->test(EditWorkOrder::class, ['record' => $workOrder->getKey()])
            ->fillForm(['status' => 'completed', 'hours_at_open' => 2500, 'completed_at' => now()->toDateString()])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertDatabaseHas('work_orders', ['id' => $workOrder->id, 'status' => 'completed', 'hours_at_open' => 2500]);

        // Y el cierre registró el servicio: horas, fecha y lectura de cierre.
        $machine->refresh();
        $this->assertSame(2500, (int) $machine->last_service_hours);
        $this->assertNotNull($machine->last_service_date);
        $this->assertDatabaseHas('horometer_readings', [
            'machine_id' => $machine->id,
            'hours' => 2500,
            'source' => 'workshop',
        ]);
    }

    public function test_a_corrective_work_order_still_closes_without_hours(): void
    {
        // El rechazo es del ciclo de servicio preventivo. Una correctiva no
        // reinicia nada, así que no puede quedar bloqueada por esto.
        $machine = $this->machine(['current_hours' => null, 'last_service_hours' => null, 'remaining_hours' => null]);

        $workOrder = WorkOrder::create([
            'code' => 'SEAL-OT-08',
            'machine_id' => $machine->id,
            'type' => 'corrective',
            'status' => 'in_progress',
            'priority' => 'normal',
            'opened_at' => now()->toDateString(),
        ]);

        $this->assertTrue(WorkOrderCompletionService::canComplete($workOrder->fresh('machine')));

        Livewire::actingAs($this->user('taller@dp.local'))
            ->test(ListWorkOrders::class)
            ->callTableAction('complete', $workOrder);

        $this->assertDatabaseHas('work_orders', ['id' => $workOrder->id, 'status' => 'completed']);
    }

    public function test_the_normal_case_keeps_working(): void
    {
        $machine = $this->machine(['current_hours' => 1500, 'last_service_hours' => 1000]);

        $workOrder = WorkOrder::create([
            'code' => 'SEAL-OT-09',
            'machine_id' => $machine->id,
            'type' => 'preventive',
            'status' => 'in_progress',
            'priority' => 'normal',
            'opened_at' => now()->toDateString(),
        ]);

        Livewire::actingAs($this->user('taller@dp.local'))
            ->test(ListWorkOrders::class)
            ->callTableAction('complete', $workOrder);

        $machine->refresh();
        $this->assertSame(1500, (int) $machine->last_service_hours);
        $this->assertSame(500, (int) $machine->remaining_hours);
        $this->assertDatabaseHas('horometer_readings', [
            'machine_id' => $machine->id,
            'hours' => 1500,
            'source' => 'workshop',
        ]);
    }
}

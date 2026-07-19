<?php

namespace Tests\Feature\WorkOrder;

use App\Filament\Resources\AlertResource\Pages\ListAlerts;
use App\Models\Alert;
use App\Models\Location;
use App\Models\Machine;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class AlertToWorkOrderTest extends TestCase
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

    public function test_create_work_order_action_opens_a_work_order_and_acknowledges_the_alert(): void
    {
        $responsable = User::where('email', 'responsable@dp.local')->firstOrFail();
        $machine = $this->machine();

        $alert = Alert::create([
            'machine_id' => $machine->id,
            'type' => 'service',
            'title' => 'Service due soon: '.$machine->id_code,
            'status' => 'open',
            'remaining_hours' => 80,
        ]);

        Livewire::actingAs($responsable)
            ->test(ListAlerts::class)
            ->callTableAction('create_work_order', $alert);

        $this->assertDatabaseHas('work_orders', [
            'machine_id' => $machine->id,
            'type' => 'preventive',
            'status' => 'open',
            'opened_by' => $responsable->id,
        ]);

        $this->assertSame('acknowledged', $alert->refresh()->status);
        $this->assertNotNull($alert->acknowledged_by);
    }

    /**
     * Ejercita explícitamente el ciclo mount -> modal de confirmación -> confirm,
     * en dos pasos separados (no el helper compuesto callTableAction), para dejar
     * cubierto el flujo real de la UI: (1) mountTableAction por sí solo debe montar
     * la acción y NO ejecutar nada todavía (la OT no existe, la alerta sigue open);
     * (2) callMountedTableAction (equivalente a pulsar "Confirm" en el modal) recién
     * ahí crea la OT, marca la alerta como acknowledged y dispara el redirect.
     */
    public function test_mounting_then_confirming_the_action_creates_the_work_order_and_redirects(): void
    {
        $responsable = User::where('email', 'responsable@dp.local')->firstOrFail();
        $machine = $this->machine();

        $alert = Alert::create([
            'machine_id' => $machine->id,
            'type' => 'service',
            'title' => 'Service due soon: '.$machine->id_code,
            'status' => 'open',
            'remaining_hours' => 80,
        ]);

        $test = Livewire::actingAs($responsable)->test(ListAlerts::class);

        // Paso 1: solo montar (equivalente al click que abre el modal). Debe quedar
        // "mounted" y todavía NO debe haber creado nada ni tocado la alerta.
        $test->mountTableAction('create_work_order', $alert->getKey())
            ->assertSet('mountedTableActions', ['create_work_order']);

        $this->assertDatabaseMissing('work_orders', ['machine_id' => $machine->id]);
        $this->assertSame('open', $alert->refresh()->status);

        // Paso 2: confirmar (equivalente a pulsar "Confirm" en el modal).
        $test->callMountedTableAction();

        $workOrder = \App\Models\WorkOrder::where('machine_id', $machine->id)->first();

        $this->assertNotNull($workOrder, 'La OT debería haberse creado al confirmar la acción.');
        $this->assertSame('preventive', $workOrder->type);
        $this->assertSame('open', $workOrder->status);
        $this->assertSame($responsable->id, $workOrder->opened_by);

        $this->assertSame('acknowledged', $alert->refresh()->status);
        $this->assertNotNull($alert->acknowledged_by);

        // El redirect a la edición de la OT nueva quedó encolado en el componente.
        $test->assertRedirect(route('filament.admin.resources.work-orders.edit', ['record' => $workOrder]));
    }

    public function test_create_work_order_action_is_hidden_for_a_role_without_the_permission(): void
    {
        // "responsable_mantenimiento" sí puede ver el listado de alertas (canViewAny),
        // pero si no tuviera el permiso create_work_order ni fuera administrador,
        // la acción de fila debe permanecer oculta.
        Role::where('name', 'responsable_mantenimiento')->first()->revokePermissionTo('create_work_order');

        $responsable = User::where('email', 'responsable@dp.local')->firstOrFail();
        $machine = $this->machine();

        $alert = Alert::create([
            'machine_id' => $machine->id,
            'type' => 'service',
            'title' => 'Service due soon: '.$machine->id_code,
            'status' => 'open',
            'remaining_hours' => 80,
        ]);

        Livewire::actingAs($responsable)
            ->test(ListAlerts::class)
            ->assertTableActionHidden('create_work_order', $alert);
    }
}

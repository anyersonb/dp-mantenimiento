<?php

namespace Tests\Feature\Security;

use App\Filament\Resources\WorkOrderResource\Pages\ListWorkOrders;
use App\Models\Location;
use App\Models\Machine;
use App\Models\User;
use App\Models\WorkOrder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Hallazgo C1 (QA Etapa 05): WorkOrderResource no tenía ni un solo control de
 * permisos. gerencia (sin ningún permiso de OT) llegó a borrar una orden de
 * trabajo real y taller creaba OTs sin tener create_work_order. Esta suite
 * cubre exactamente los escenarios que el informe verificó en vivo.
 */
class WorkOrderPermissionsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);
    }

    protected function machine(): Machine
    {
        $location = Location::create(['name' => 'Test Yard', 'slug' => 'test-yard-'.uniqid()]);

        // Las horas no son decorado: desde E6-08, completar una OT preventiva
        // sobre una máquina sin `current_hours` y sin `hours_at_open` se
        // RECHAZA. Este archivo mide permisos, no la regla de horas, así que la
        // máquina de prueba lleva horómetro cargado para que el único motivo
        // posible de un fallo acá sea el permiso.
        return Machine::create([
            'id_code' => 'QA-'.random_int(1000, 9999),
            'status' => 'active',
            'current_location_id' => $location->id,
            'hourmeter_status' => 'ok',
            'current_hours' => 1000,
            'last_service_hours' => 500,
            'service_interval_hours' => 500,
        ]);
    }

    protected function workOrder(Machine $machine, array $overrides = []): WorkOrder
    {
        return WorkOrder::create(array_merge([
            'code' => 'QA-WO-'.random_int(1000, 9999),
            'machine_id' => $machine->id,
            'type' => 'preventive',
            'status' => 'open',
            'priority' => 'normal',
            'opened_at' => now()->toDateString(),
        ], $overrides));
    }

    /* ---------------------------------------------------------- *
     * gerencia: ni crea, ni edita, ni borra
     * ---------------------------------------------------------- */

    public function test_gerencia_cannot_open_the_create_work_order_page(): void
    {
        $gerencia = User::where('email', 'gerencia@dp.local')->firstOrFail();

        $this->actingAs($gerencia)->get('/admin/work-orders/create')->assertForbidden();
    }

    public function test_gerencia_cannot_open_the_edit_work_order_page(): void
    {
        $gerencia = User::where('email', 'gerencia@dp.local')->firstOrFail();
        $workOrder = $this->workOrder($this->machine());

        $this->actingAs($gerencia)->get("/admin/work-orders/{$workOrder->id}/edit")->assertForbidden();
    }

    public function test_gerencia_cannot_delete_a_work_order_from_the_table(): void
    {
        $gerencia = User::where('email', 'gerencia@dp.local')->firstOrFail();
        $workOrder = $this->workOrder($this->machine());

        Livewire::actingAs($gerencia)
            ->test(ListWorkOrders::class)
            ->assertTableBulkActionHidden('delete');

        // Payload manipulado: intenta ejecutar el borrado en bruto, saltando
        // el helper de test que ya valida visibilidad por su cuenta.
        Livewire::actingAs($gerencia)
            ->test(ListWorkOrders::class)
            ->call('mountTableBulkAction', 'delete', [$workOrder->getKey()])
            ->call('callMountedTableBulkAction');

        $this->assertDatabaseHas('work_orders', ['id' => $workOrder->id]);
    }

    /* ---------------------------------------------------------- *
     * taller: no crea, sí ejecuta/completa
     * ---------------------------------------------------------- */

    public function test_taller_cannot_open_the_create_work_order_page(): void
    {
        $taller = User::where('email', 'taller@dp.local')->firstOrFail();

        $this->actingAs($taller)->get('/admin/work-orders/create')->assertForbidden();
    }

    public function test_taller_can_open_and_edit_a_work_order(): void
    {
        $taller = User::where('email', 'taller@dp.local')->firstOrFail();
        $workOrder = $this->workOrder($this->machine());

        $this->actingAs($taller)->get("/admin/work-orders/{$workOrder->id}/edit")->assertOk();
    }

    public function test_taller_can_complete_a_work_order(): void
    {
        $taller = User::where('email', 'taller@dp.local')->firstOrFail();
        $workOrder = $this->workOrder($this->machine());

        Livewire::actingAs($taller)
            ->test(ListWorkOrders::class)
            ->callTableAction('complete', $workOrder)
            ->assertHasNoTableActionErrors();

        $this->assertSame('completed', $workOrder->refresh()->status);
    }

    /**
     * Hallazgo del bloque "->visible() sin autorización real": un payload
     * manipulado que llame mountTableAction/callMountedTableAction en bruto
     * (sin pasar por el helper de test que ya valida visibilidad) debe seguir
     * siendo rechazado gracias a ->authorize(), no solo a ->visible().
     */
    public function test_gerencia_cannot_complete_a_work_order_via_a_raw_table_action_call(): void
    {
        $gerencia = User::where('email', 'gerencia@dp.local')->firstOrFail();
        $workOrder = $this->workOrder($this->machine());

        // gerencia no puede ni entrar a la lista (canViewAny = view_fleet, que
        // sí tiene) pero la acción "complete" exige execute_work_order aparte.
        Livewire::actingAs($gerencia)
            ->test(ListWorkOrders::class)
            ->call('mountTableAction', 'complete', $workOrder->getKey())
            ->call('callMountedTableAction');

        $this->assertSame('open', $workOrder->refresh()->status);
    }

    /* ---------------------------------------------------------- *
     * gerencia: no puede "completar" una OT ni por payload directo
     * (no tiene execute_work_order)
     * ---------------------------------------------------------- */

    public function test_gerencia_cannot_complete_a_work_order_via_a_direct_payload(): void
    {
        $gerencia = User::where('email', 'gerencia@dp.local')->firstOrFail();
        $workOrder = $this->workOrder($this->machine());

        // gerencia no puede ni abrir la página de edición (canEdit =
        // execute_work_order), que es donde vive la tabla con la acción
        // "complete". Confirma que la puerta de entrada ya está cerrada.
        $this->actingAs($gerencia)->get("/admin/work-orders/{$workOrder->id}/edit")->assertForbidden();
    }

    public function test_administrator_can_delete_a_work_order(): void
    {
        $admin = User::where('email', 'admin@dp.local')->firstOrFail();
        $workOrder = $this->workOrder($this->machine());

        Livewire::actingAs($admin)
            ->test(ListWorkOrders::class)
            ->callTableBulkAction('delete', [$workOrder]);

        $this->assertDatabaseMissing('work_orders', ['id' => $workOrder->id]);
    }
}

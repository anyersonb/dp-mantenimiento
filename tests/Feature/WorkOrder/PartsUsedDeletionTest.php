<?php

namespace Tests\Feature\WorkOrder;

use App\Filament\Resources\WorkOrderResource\Pages\EditWorkOrder;
use App\Filament\Resources\WorkOrderResource\RelationManagers\PartsRelationManager;
use App\Models\Location;
use App\Models\Machine;
use App\Models\User;
use App\Models\WorkOrder;
use App\Models\WorkOrderPart;
use Database\Seeders\RolesAndPermissionsSeeder;
use Filament\Tables\Actions\DeleteAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Reporte de cliente (2026-09-14): "en parts used quiero eliminar alguno y no
 * puedo eliminarlo". El diagnóstico (ver PR) descartó que el borrado esté
 * roto: `WorkOrderPart::delete()` y la acción de Filament ya soft-borraban
 * bien y recalculaban `parts_cost` (WorkOrderExecutionTest ya lo cubría vía
 * `->delete()` directo). Lo que sí estaba roto era la EXPERIENCIA: el botón
 * de eliminar se OCULTABA sin ninguna explicación cuando la OT estaba
 * cerrada o cuando el usuario no tenía `execute_work_order`, y eso se vive
 * como "está roto" y no como "no podés por X motivo".
 *
 * Esta suite prueba el camino completo a través de la UI de Filament
 * (callTableAction, no `->delete()` a pelo) para las cuatro garantías que
 * pidió el brief:
 *
 *   1. Borrado permitido en OT abierta con permiso -> recalcula parts_cost.
 *   2. Borrado bloqueado (deshabilitado + motivo) en OT cerrada.
 *   3. Borrado bloqueado (deshabilitado + motivo) sin el permiso.
 *   4. El registro borrado deja de listarse.
 *
 * Los casos 2 y 3 además intentan disparar la acción igual
 * (`callTableAction`) para probar que "deshabilitado" no es solo cosmético:
 * `isDisabled()` corta `mountTableAction()` del lado servidor (ver
 * `Filament\Actions\Concerns\InteractsWithActions::mountAction()`), así que
 * el registro tiene que seguir intacto en la base después del intento.
 *
 * Seguimiento del mismo reporte (2026-09-14): "explicar el bloqueo no es
 * darle salida". Se auditó si existe un camino real para reabrir una OT
 * cerrada -SÍ existe- antes de construir nada nuevo: cualquier usuario con
 * `execute_work_order` puede editar la OT desde el formulario estándar de
 * WorkOrderResource y volver `status` a un valor abierto (sin acción
 * "Reabrir" dedicada, sin permiso nuevo). `test_reopening_a_closed_work_order_*`
 * prueba ese camino de punta a punta, y
 * `test_a_role_without_execute_work_order_cannot_reopen_a_closed_work_order`
 * prueba que quien no llega a editar la OT tampoco tiene ese camino.
 */
class PartsUsedDeletionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);
    }

    private function machine(): Machine
    {
        $location = Location::create(['name' => 'PUD Yard', 'slug' => 'pud-yard-'.uniqid()]);

        return Machine::create([
            'id_code' => 'PUD-'.random_int(1000, 9999),
            'status' => 'active',
            'hourmeter_status' => 'ok',
            'current_location_id' => $location->id,
            'current_hours' => 500,
            'last_service_hours' => 100,
            'service_interval_hours' => 500,
            'hours_adjustment' => 0,
            'remaining_hours' => 100,
        ]);
    }

    private function workOrder(string $status = 'open'): WorkOrder
    {
        return WorkOrder::create([
            'code' => 'WO-'.random_int(10000, 99999),
            'machine_id' => $this->machine()->id,
            'type' => 'corrective',
            'status' => $status,
            'priority' => 'normal',
            'hours_at_open' => 500,
            'opened_at' => now()->toDateString(),
        ]);
    }

    private function part(WorkOrder $workOrder): WorkOrderPart
    {
        return WorkOrderPart::create([
            'work_order_id' => $workOrder->id,
            'part_number' => 'PUD-FLT-01',
            'description' => 'Filtro de aceite',
            'quantity' => 2,
            'unit_cost' => 15,
        ]);
    }

    private function user(string $email): User
    {
        return User::where('email', $email)->firstOrFail();
    }

    public function test_the_workshop_can_delete_a_part_via_the_ui_action_on_an_open_work_order_and_cost_is_recalculated(): void
    {
        $workOrder = $this->workOrder('open');
        $part = $this->part($workOrder);

        $this->assertEquals(30.0, (float) $workOrder->refresh()->parts_cost);

        Livewire::actingAs($this->user('taller@dp.local'))
            ->test(PartsRelationManager::class, [
                'ownerRecord' => $workOrder,
                'pageClass' => EditWorkOrder::class,
            ])
            ->assertTableActionVisible('delete', $part)
            ->assertTableActionEnabled('delete', $part)
            ->callTableAction('delete', $part)
            ->assertHasNoTableActionErrors();

        $this->assertSoftDeleted('work_order_parts', ['id' => $part->id]);
        $this->assertEquals(0.0, (float) $workOrder->refresh()->parts_cost);
    }

    public function test_deletion_is_disabled_with_a_reason_when_the_work_order_is_closed(): void
    {
        $workOrder = $this->workOrder('completed');
        $part = $this->part($workOrder);

        $component = Livewire::actingAs($this->user('taller@dp.local'))
            ->test(PartsRelationManager::class, [
                'ownerRecord' => $workOrder,
                'pageClass' => EditWorkOrder::class,
            ]);

        $component
            ->assertTableActionVisible('delete', $part)
            ->assertTableActionDisabled('delete', $part)
            ->assertTableActionExists(
                'delete',
                fn (DeleteAction $action): bool => $action->getTooltip() === __('wo.delete_blocked_closed_order'),
                record: $part,
            );

        // Deshabilitado no es solo cosmético: el intento de invocarlo igual
        // no debe borrar nada (isDisabled() corta mountTableAction()).
        $component->callTableAction('delete', $part);

        $this->assertDatabaseHas('work_order_parts', ['id' => $part->id, 'deleted_at' => null]);
    }

    public function test_deletion_is_disabled_with_a_reason_when_the_user_lacks_the_permission(): void
    {
        // gerencia tiene view_fleet, view_costs, move_fleet y view_reports,
        // pero NO execute_work_order (matriz de RolesAndPermissionsSeeder).
        $workOrder = $this->workOrder('open');
        $part = $this->part($workOrder);

        $component = Livewire::actingAs($this->user('gerencia@dp.local'))
            ->test(PartsRelationManager::class, [
                'ownerRecord' => $workOrder,
                'pageClass' => EditWorkOrder::class,
            ]);

        $component
            ->assertTableActionVisible('delete', $part)
            ->assertTableActionDisabled('delete', $part)
            ->assertTableActionExists(
                'delete',
                fn (DeleteAction $action): bool => $action->getTooltip() === __('wo.delete_blocked_no_permission'),
                record: $part,
            );

        $component->callTableAction('delete', $part);

        $this->assertDatabaseHas('work_order_parts', ['id' => $part->id, 'deleted_at' => null]);
    }

    public function test_a_deleted_part_disappears_from_the_table_listing(): void
    {
        $workOrder = $this->workOrder('open');
        $part = $this->part($workOrder);
        $taller = $this->user('taller@dp.local');

        Livewire::actingAs($taller)
            ->test(PartsRelationManager::class, [
                'ownerRecord' => $workOrder,
                'pageClass' => EditWorkOrder::class,
            ])
            ->callTableAction('delete', $part);

        Livewire::actingAs($taller)
            ->test(PartsRelationManager::class, [
                'ownerRecord' => $workOrder,
                'pageClass' => EditWorkOrder::class,
            ])
            ->assertCanNotSeeTableRecords([$part]);
    }

    /**
     * La salida real para el caso "cerré la OT por error con un repuesto mal
     * cargado": editar la OT (no hace falta permiso nuevo, ni acción
     * dedicada) y devolver su estado a uno abierto. Reabrir queda en el log
     * de actividad porque WorkOrder::getActivitylogOptions() ya audita
     * `status` con logOnlyDirty().
     */
    public function test_reopening_a_closed_work_order_through_its_edit_form_unblocks_the_delete_action(): void
    {
        $workOrder = $this->workOrder('completed');
        $part = $this->part($workOrder);
        $taller = $this->user('taller@dp.local');

        Livewire::actingAs($taller)
            ->test(EditWorkOrder::class, ['record' => $workOrder->getKey()])
            ->fillForm(['status' => 'open'])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertDatabaseHas('work_orders', ['id' => $workOrder->id, 'status' => 'open']);

        Livewire::actingAs($taller)
            ->test(PartsRelationManager::class, [
                'ownerRecord' => $workOrder->fresh(),
                'pageClass' => EditWorkOrder::class,
            ])
            ->assertTableActionVisible('delete', $part)
            ->assertTableActionEnabled('delete', $part)
            ->callTableAction('delete', $part)
            ->assertHasNoTableActionErrors();

        $this->assertSoftDeleted('work_order_parts', ['id' => $part->id]);
    }

    /**
     * gerencia no tiene execute_work_order: WorkOrderResource::canEdit()
     * ya se lo niega (hallazgo C1), así que tampoco tiene el camino de
     * reapertura por edición. No es una restricción nueva de este fix.
     */
    public function test_a_role_without_execute_work_order_cannot_reopen_a_closed_work_order(): void
    {
        $workOrder = $this->workOrder('completed');

        $this->actingAs($this->user('gerencia@dp.local'))
            ->get("/admin/work-orders/{$workOrder->id}/edit")
            ->assertForbidden();

        $this->assertDatabaseHas('work_orders', ['id' => $workOrder->id, 'status' => 'completed']);
    }
}

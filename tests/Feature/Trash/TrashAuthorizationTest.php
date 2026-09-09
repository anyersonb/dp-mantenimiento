<?php

namespace Tests\Feature\Trash;

use App\Filament\Resources\WorkOrderResource;
use App\Filament\Resources\WorkOrderResource\Pages\ListWorkOrders;
use App\Models\Location;
use App\Models\Machine;
use App\Models\User;
use App\Models\WorkOrder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use PHPUnit\Framework\AssertionFailedError;
use ReflectionMethod;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Papelera (Lote A) — autorización REAL, no solo que el botón no se
 * renderice. `taller` no tiene ninguno de los tres permisos nuevos
 * (`view_trash_work_orders` / `restore_work_orders` / `force_delete_work_orders`
 * — solo `administrador` los tiene, ver RolesAndPermissionsSeeder), así que
 * sirve para probar el gate real: se intenta EJECUTAR la acción (no solo se
 * mira si el botón está oculto) y se comprueba que el estado en la base no
 * cambió.
 */
class TrashAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);
    }

    private function admin(): User
    {
        return User::where('email', 'admin@dp.local')->firstOrFail();
    }

    private function taller(): User
    {
        return User::where('email', 'taller@dp.local')->firstOrFail();
    }

    private function trashedWorkOrder(): WorkOrder
    {
        $location = Location::create(['name' => 'Auth Yard', 'slug' => 'auth-yard-'.uniqid()]);

        $machine = Machine::create([
            'id_code' => 'AU-'.random_int(1000, 9999),
            'status' => 'active',
            'hourmeter_status' => 'ok',
            'current_location_id' => $location->id,
        ]);

        $workOrder = WorkOrder::create([
            'code' => 'WO-AU-'.random_int(1000, 9999),
            'machine_id' => $machine->id,
            'type' => 'corrective',
            'status' => 'open',
            'priority' => 'normal',
            'opened_at' => now()->toDateString(),
        ]);

        $workOrder->delete();

        return $workOrder->fresh();
    }

    /* ------------------------------------------------------------------ *
     * canX() estático: lo que gobierna tanto la Action como el navigation.
     * ------------------------------------------------------------------ */

    public function test_only_the_administrator_passes_the_papelera_gates(): void
    {
        $workOrder = $this->trashedWorkOrder();

        $this->actingAs($this->taller());
        $this->assertFalse(WorkOrderResource::canRestore($workOrder));
        $this->assertFalse(WorkOrderResource::canRestoreAny());
        $this->assertFalse(WorkOrderResource::canForceDelete($workOrder));
        $this->assertFalse(WorkOrderResource::canForceDeleteAny());

        $this->actingAs($this->admin());
        $this->assertTrue(WorkOrderResource::canRestore($workOrder));
        $this->assertTrue(WorkOrderResource::canRestoreAny());
        $this->assertTrue(WorkOrderResource::canForceDelete($workOrder));
        $this->assertTrue(WorkOrderResource::canForceDeleteAny());
    }

    /* ------------------------------------------------------------------ *
     * El botón: oculto para quien no tiene el permiso.
     * ------------------------------------------------------------------ */

    public function test_the_restore_and_force_delete_actions_are_hidden_without_the_permission(): void
    {
        $workOrder = $this->trashedWorkOrder();

        // Sin ->filterTable('trashed', ...): el helper de Filament para
        // fijar el estado de un filtro exige que el filtro exista VISIBLE
        // para el usuario actual (getFilter() sin withHidden), así que no
        // se puede usar para simular a alguien que no lo ve. No hace falta:
        // se le pasa el Model ya resuelto, y assertTableActionHidden()/
        // callTableAction() evalúan la acción para ESE registro sin importar
        // si aparece en la página actual de la tabla.
        Livewire::actingAs($this->taller())
            ->test(ListWorkOrders::class)
            ->assertTableActionHidden('restore', $workOrder)
            ->assertTableActionHidden('forceDelete', $workOrder);
    }

    public function test_the_trashed_filter_itself_is_hidden_without_view_trash_permission(): void
    {
        Livewire::actingAs($this->taller())
            ->test(ListWorkOrders::class)
            ->assertTableFilterHidden('trashed');

        Livewire::actingAs($this->admin())
            ->test(ListWorkOrders::class)
            ->assertTableFilterVisible('trashed');
    }

    /**
     * La parte que de verdad importa: EJECUTAR la acción como quien no tiene
     * el permiso no debe restaurar nada, aunque se la invoque directo (no
     * solo comprobar que el botón no aparece).
     *
     * `callTableAction()` del propio Filament rechaza con un assertion
     * failure el intentar invocar una acción que no está `visible()` (que
     * incluye `isAuthorized()` — ver `HasPapeleraActions`): eso EN SÍ MISMO
     * es la prueba de que el framework corta en el servidor y no solo en el
     * render, así que se captura ese rechazo y además se confirma que la OT
     * sigue exactamente como estaba.
     */
    public function test_executing_restore_without_the_permission_does_not_restore_the_record(): void
    {
        $workOrder = $this->trashedWorkOrder();

        $blocked = false;

        try {
            Livewire::actingAs($this->taller())
                ->test(ListWorkOrders::class)
                ->callTableAction('restore', $workOrder);
        } catch (AssertionFailedError) {
            $blocked = true;
        }

        $this->assertTrue($blocked, 'Filament debe impedir invocar la acción "restore": no está autorizada para este rol.');

        // Un rol sin restore_work_orders no puede restaurar, ni siquiera
        // invocando la acción directo: la OT sigue en la papelera.
        $this->assertSoftDeleted('work_orders', ['id' => $workOrder->id]);
    }

    public function test_executing_force_delete_without_the_permission_does_not_delete_the_record(): void
    {
        $workOrder = $this->trashedWorkOrder();

        $blocked = false;

        try {
            Livewire::actingAs($this->taller())
                ->test(ListWorkOrders::class)
                ->callTableAction('forceDelete', $workOrder);
        } catch (AssertionFailedError) {
            $blocked = true;
        }

        $this->assertTrue($blocked, 'Filament debe impedir invocar la acción "forceDelete": no está autorizada para este rol.');

        $this->assertDatabaseHas('work_orders', ['id' => $workOrder->id]);
    }

    /**
     * Un permiso que todavía no existe en la base (ventana de despliegue) no
     * puede dejar la acción DISPONIBLE por accidente: tiene que quedar OCULTA.
     *
     * Se simula quitando el permiso al ROL `administrador` (no al usuario
     * directo: `administrador` recibe estos permisos por su ROL, así que
     * revocárselos a la cuenta de prueba de forma directa sería un no-op —
     * `can()` seguiría resolviendo true por el rol— y el test pasaría sin
     * probar nada). El efecto neto es el mismo que "el permiso no existe"
     * desde el punto de vista de `Auth::user()->can(...)`.
     */
    public function test_a_role_without_the_permission_never_fails_open(): void
    {
        $administradorRole = Role::where('name', 'administrador')->firstOrFail();
        $administradorRole->revokePermissionTo('restore_work_orders');
        $administradorRole->revokePermissionTo('force_delete_work_orders');
        $administradorRole->revokePermissionTo('view_trash_work_orders');
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $workOrder = $this->trashedWorkOrder();
        $admin = $this->admin(); // instancia fresca, con el rol ya sin esos permisos

        $this->actingAs($admin);
        $this->assertFalse(WorkOrderResource::canRestore($workOrder));
        $this->assertFalse(WorkOrderResource::canForceDelete($workOrder));

        Livewire::actingAs($admin)
            ->test(ListWorkOrders::class)
            ->assertTableFilterHidden('trashed');
    }

    /**
     * Verificado contra el paquete instalado (Filament v3.3.54, no v4):
     * `Filters\Concerns\InteractsWithTableQuery::apply()` vuelve a evaluar
     * `isHidden()` (que evalúa el usuario autenticado ACTUAL) antes de tocar
     * la consulta, así que ni siquiera manipulando el estado guardado del
     * filtro se puede activar `withTrashed()`/`onlyTrashed()` sin el permiso.
     */
    public function test_the_trashed_filter_query_is_a_noop_when_hidden(): void
    {
        $this->actingAs($this->taller());

        $method = new ReflectionMethod(WorkOrderResource::class, 'papeleraTrashedFilter');
        $method->setAccessible(true);
        $filter = $method->invoke(null);

        $this->assertTrue($filter->isHidden(), 'Sin el permiso, el filtro tiene que evaluarse como oculto.');

        $before = WorkOrder::query()->toSql();
        $after = $filter->apply(WorkOrder::query(), ['value' => true])->toSql();

        $this->assertSame($before, $after, 'Un filtro oculto no debe alterar la consulta aunque su estado diga "con papelera".');
    }
}

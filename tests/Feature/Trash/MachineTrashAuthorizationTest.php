<?php

namespace Tests\Feature\Trash;

use App\Filament\Resources\MachineResource;
use App\Filament\Resources\MachineResource\Pages\ListMachines;
use App\Models\Location;
use App\Models\Machine;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use PHPUnit\Framework\AssertionFailedError;
use Tests\TestCase;

/**
 * Segunda tarea del lote: QA verificó con clic real que un rol `taller` NO
 * ve el filtro de papelera ni las acciones de restaurar/eliminar
 * definitivamente para MÁQUINAS, pero no tenía forma de forjar el payload
 * de Livewire para probar el BYPASS (disparar la acción por petición
 * directa, sin pasar por el botón). Esto completa esa verificación —
 * equivalente exacto de `TrashAuthorizationTest` (que ya cubre
 * WorkOrderResource) para `MachineResource`.
 *
 * `callTableAction()` sobre una acción no autorizada para el usuario actual
 * lanza `AssertionFailedError` ANTES de correr el closure (el propio
 * harness de Filament revienta al intentar invocar una acción que no está
 * `visible()` — que incluye `isAuthorized()`, ver
 * `App\Filament\Concerns\HasPapeleraActions`): esa excepción ES la prueba
 * de que el servidor corta la acción, no solo el render. Se captura y
 * además se confirma que el estado en la base no cambió.
 */
class MachineTrashAuthorizationTest extends TestCase
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

    private function trashedMachine(): Machine
    {
        $location = Location::create(['name' => 'Auth Yard', 'slug' => 'auth-yard-'.uniqid()]);

        $machine = Machine::create([
            'id_code' => 'AU-'.random_int(100000, 999999),
            'status' => 'active',
            'hourmeter_status' => 'ok',
            'current_location_id' => $location->id,
        ]);

        $machine->delete();

        return $machine->fresh();
    }

    /* ------------------------------------------------------------------ *
     * canX() estático: lo que gobierna tanto la Action como el navigation.
     * ------------------------------------------------------------------ */

    public function test_only_the_administrator_passes_the_papelera_gates(): void
    {
        $machine = $this->trashedMachine();

        $this->actingAs($this->taller());
        $this->assertFalse(MachineResource::canRestore($machine));
        $this->assertFalse(MachineResource::canRestoreAny());
        $this->assertFalse(MachineResource::canForceDelete($machine));
        $this->assertFalse(MachineResource::canForceDeleteAny());

        $this->actingAs($this->admin());
        $this->assertTrue(MachineResource::canRestore($machine));
        $this->assertTrue(MachineResource::canRestoreAny());
        $this->assertTrue(MachineResource::canForceDelete($machine));
        $this->assertTrue(MachineResource::canForceDeleteAny());
    }

    /* ------------------------------------------------------------------ *
     * El botón: oculto para quien no tiene el permiso.
     * ------------------------------------------------------------------ */

    public function test_the_restore_and_force_delete_actions_are_hidden_without_the_permission(): void
    {
        $machine = $this->trashedMachine();

        Livewire::actingAs($this->taller())
            ->test(ListMachines::class)
            ->assertTableActionHidden('restore', $machine)
            ->assertTableActionHidden('forceDelete', $machine);
    }

    public function test_the_trashed_filter_itself_is_hidden_without_view_trash_permission(): void
    {
        Livewire::actingAs($this->taller())
            ->test(ListMachines::class)
            ->assertTableFilterHidden('trashed');

        Livewire::actingAs($this->admin())
            ->test(ListMachines::class)
            ->assertTableFilterVisible('trashed');
    }

    /**
     * El bypass real: invocar la acción DIRECTO (no solo comprobar que el
     * botón no aparece) sin el permiso `restore_machines`.
     */
    public function test_executing_restore_without_the_permission_does_not_restore_the_record(): void
    {
        $machine = $this->trashedMachine();

        $blocked = false;

        try {
            Livewire::actingAs($this->taller())
                ->test(ListMachines::class)
                ->callTableAction('restore', $machine);
        } catch (AssertionFailedError) {
            $blocked = true;
        }

        $this->assertTrue($blocked, 'Filament debe impedir invocar la acción "restore": no está autorizada para este rol.');
        $this->assertSoftDeleted('machines', ['id' => $machine->id]);
    }

    /**
     * El bypass real: invocar "eliminar definitivamente" DIRECTO sin el
     * permiso `force_delete_machines` — ni siquiera con el código de
     * confirmación correcto en el payload, porque el gate corta ANTES de
     * llegar al formulario/acción.
     */
    public function test_executing_force_delete_without_the_permission_does_not_delete_the_record(): void
    {
        $machine = $this->trashedMachine();

        $blocked = false;

        try {
            Livewire::actingAs($this->taller())
                ->test(ListMachines::class)
                ->callTableAction('forceDelete', $machine, data: ['confirm_value' => $machine->id_code]);
        } catch (AssertionFailedError) {
            $blocked = true;
        }

        $this->assertTrue($blocked, 'Filament debe impedir invocar la acción "forceDelete": no está autorizada para este rol.');
        $this->assertDatabaseHas('machines', ['id' => $machine->id]);
    }
}

<?php

namespace Tests\Feature\Security;

use App\Filament\Resources\RoleResource;
use App\Filament\Resources\RoleResource\Pages\CreateRole;
use App\Filament\Resources\RoleResource\Pages\EditRole;
use App\Filament\Resources\RoleResource\Pages\ListRoles;
use App\Filament\Resources\UserResource\Pages\EditUser;
use App\Filament\Resources\UserResource\Pages\ListUsers;
use App\Models\User;
use App\Support\AccessControl;
use App\Support\AdministrationGuard;
use Database\Seeders\RolesAndPermissionsSeeder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Las cuatro puertas al mismo desenlace: el panel cerrado para todo el mundo,
 * en la unica instancia que existe, sin forma de volver desde el panel.
 *
 * Nace de la auditoria de seguridad del 2026-08-26 sobre el commit c4737d55.
 * Ese commit saco el control de acceso de los nombres de rol y lo puso en
 * permisos, y con eso volvio borrable lo que antes era indestructible. La red
 * que traia cubria UNA de las cuatro puertas:
 *
 *   1. borrar un rol                      -> estaba cubierta
 *   2. desmarcarle permisos a un rol      -> hallazgo 1 (critico)
 *   3. desactivar al ultimo administrador -> hallazgo 3 (alto)
 *   4. quitarle el rol a esa cuenta       -> misma familia
 *
 * Mas dos formas de saltarse la red de la puerta 1: mandar un `reassign_to`
 * que el desplegable nunca ofrecio (hallazgo 2, critico) y recrear un nombre
 * de rol reservado para que la red legado lo reviva (hallazgo 6).
 *
 * Cada test de aca deja de pasar si se quita el arreglo que lo cubre.
 */
class AdministrationCannotBeStrandedTest extends TestCase
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

    private function alguienAdministra(): bool
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        return AdministrationGuard::isReachable();
    }

    /** Puerta 2. Antes, un clic cerraba el panel para siempre. */
    public function test_unchecking_the_panel_permission_is_repaired_instead_of_locking_everyone_out(): void
    {
        $rol = Role::where('name', 'administrador')->firstOrFail();
        $this->assertTrue($this->alguienAdministra(), 'precondicion');

        $sinPanel = $rol->permissions->where('name', '!=', 'access_panel')->pluck('id')->all();

        Livewire::actingAs($this->admin())
            ->test(EditRole::class, ['record' => $rol->getKey()])
            ->fillForm(['permissions' => $sinPanel])
            ->call('save')
            ->assertHasNoFormErrors();

        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $rol->refresh()->load('permissions');

        $this->assertTrue($rol->permissions->contains('name', 'access_panel'), 'la salvaguarda repuso access_panel');
        $this->assertTrue($this->alguienAdministra(), 'y el sistema sigue teniendo quien lo administre');

        $admin = $this->admin();
        $admin->unsetRelation('roles');
        $this->assertTrue($admin->canAccessPanel(Filament::getPanel('admin')));
    }

    /**
     * Y la otra mitad: la salvaguarda NO se mete cuando no hace falta. Sin
     * esto, un "repone siempre" pasaria el test de arriba sin ser un arreglo.
     */
    public function test_the_safeguard_keeps_its_hands_off_a_role_that_is_not_the_last_way_in(): void
    {
        $rol = Role::where('name', 'gerencia')->firstOrFail();

        Livewire::actingAs($this->admin())
            ->test(EditRole::class, ['record' => $rol->getKey()])
            ->fillForm(['permissions' => [Permission::where('name', 'view_fleet')->firstOrFail()->id]])
            ->call('save')
            ->assertHasNoFormErrors();

        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $rol->refresh()->load('permissions');

        $this->assertFalse($rol->permissions->contains('name', 'access_panel'), 'a gerencia no se le repuso nada');
        $this->assertCount(1, $rol->permissions);
    }

    /** Puerta 1, salto A: el destino que el desplegable nunca ofrece. */
    public function test_the_reassign_target_cannot_be_the_role_being_deleted(): void
    {
        $rol = Role::where('name', 'administrador')->firstOrFail();

        Livewire::actingAs($this->admin())
            ->test(ListRoles::class)
            ->callTableAction('delete', $rol, ['reassign_to' => $rol->getKey()])
            ->assertHasTableActionErrors(['reassign_to']);

        $this->assertNotNull(Role::find($rol->getKey()), 'el rol sigue en pie');
        $this->assertTrue($this->admin()->fresh()->hasRole('administrador'));
        $this->assertTrue($this->alguienAdministra());
    }

    /** Puerta 1, salto B: un id que no existe dejaba cuentas sin ningun rol. */
    public function test_a_bogus_reassign_target_is_rejected(): void
    {
        $rol = Role::where('name', 'taller')->firstOrFail();
        $victima = $rol->users()->firstOrFail();

        Livewire::actingAs($this->admin())
            ->test(ListRoles::class)
            ->callTableAction('delete', $rol, ['reassign_to' => 999999])
            ->assertHasTableActionErrors(['reassign_to']);

        $this->assertNotNull(Role::find($rol->getKey()));
        $victima->unsetRelation('roles');
        $this->assertCount(1, $victima->roles, 'la cuenta conserva su rol');
    }

    /**
     * La invariante no puede vivir solo en el formulario: el servicio tambien
     * la defiende. Es lo que separa "el navegador no te deja" de "no se puede".
     */
    public function test_the_service_itself_refuses_to_leave_accounts_with_no_role(): void
    {
        $rol = Role::where('name', 'taller')->firstOrFail();
        $this->assertGreaterThan(0, RoleResource::assignedUserCount($rol));

        $this->expectException(\InvalidArgumentException::class);
        RoleResource::deleteAndReassign($rol, null);
    }

    /** Puerta 3. Era el paso previo que apagaba la red entera. */
    public function test_the_last_administrator_cannot_be_deactivated(): void
    {
        $admin = $this->admin();

        Livewire::actingAs($admin)
            ->test(EditUser::class, ['record' => $admin->getKey()])
            ->fillForm(['active' => false])
            ->call('save')
            ->assertHasFormErrors(['active']);

        $this->assertTrue($admin->fresh()->active, 'sigue activa');
        $this->assertTrue($this->alguienAdministra());
    }

    /** Y con un segundo administrador, desactivar al primero SI se puede. */
    public function test_an_administrator_can_be_deactivated_when_another_one_remains(): void
    {
        $suplente = User::factory()->create(['email' => 'suplente@dp.local', 'active' => true]);
        $suplente->assignRole(Role::where('name', 'administrador')->firstOrFail());
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $admin = $this->admin();

        Livewire::actingAs($admin)
            ->test(EditUser::class, ['record' => $admin->getKey()])
            ->fillForm(['active' => false])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertFalse($admin->fresh()->active);
    }

    /** Puerta 4. */
    public function test_the_last_administrator_cannot_lose_the_role_that_administers(): void
    {
        $admin = $this->admin();
        $foreman = Role::where('name', 'foreman')->firstOrFail();

        Livewire::actingAs($admin)
            ->test(EditUser::class, ['record' => $admin->getKey()])
            ->fillForm(['roles' => [$foreman->id]])
            ->call('save')
            ->assertHasFormErrors(['roles']);

        $this->assertTrue($admin->fresh()->hasRole('administrador'));
        $this->assertTrue($this->alguienAdministra());
    }

    /**
     * Puerta 5 (auditoría de seguridad del lote de papelera, 2026-09-09):
     * borrar (soft delete) al último administrador, desde las tres puertas
     * que existen para hacerlo — fila y masiva en `UserResource`, cabecera
     * de `EditUser` — ninguna consultaba `AdministrationGuard` antes de este
     * fix; cada una traía su propio `hidden()`/`reject()` contra uno mismo.
     *
     * Para que el escenario sea alcanzable de verdad (y no redundante con el
     * bloqueo "no sobre uno mismo" que ya existía): el actor de estos tests
     * tiene `manage_users` —alcanza para llegar a `UserResource`— pero NO
     * `access_panel`. `AdministrationGuard::REQUIRED` exige los DOS permisos
     * juntos para "administrar", así que este actor —a diferencia de un
     * administrador de verdad— NO cuenta en `survives()`. Es el caso real en
     * el que borrar al único que sí administra (el `$admin` sembrado, con el
     * rol 'administrador') SÍ dejaría el sistema sin nadie que lo administre.
     */
    private function actorWithManageUsersButNoPanelAccess(): User
    {
        $rol = Role::create(['name' => 'gestor_sin_panel', 'guard_name' => 'web']);
        $rol->givePermissionTo('manage_users');

        $actor = User::factory()->create(['email' => 'gestor-sin-panel@dp.local', 'active' => true]);
        $actor->assignRole($rol);
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        return $actor;
    }

    public function test_the_row_delete_action_is_not_authorized_when_it_would_strand_administration(): void
    {
        $admin = $this->admin();
        $actor = $this->actorWithManageUsersButNoPanelAccess();

        $this->assertTrue(
            AdministrationGuard::deletingUserWouldStrand($admin),
            'precondición: admin es el único que administra de verdad'
        );

        Livewire::actingAs($actor)
            ->test(ListUsers::class)
            ->assertTableActionHidden('delete', $admin);

        $this->assertNotNull(User::find($admin->getKey()), 'sigue en pie');
        $this->assertTrue($this->alguienAdministra());
    }

    /**
     * La otra mitad: la salvaguarda no se mete cuando no hace falta. Sin
     * esto, "siempre oculto" pasaría el test de arriba sin ser un arreglo.
     */
    public function test_the_row_delete_action_still_works_on_a_deletion_that_would_not_strand_administration(): void
    {
        $admin = $this->admin();
        $taller = Role::where('name', 'taller')->firstOrFail()->users()->firstOrFail();

        Livewire::actingAs($admin)
            ->test(ListUsers::class)
            ->callTableAction('delete', $taller);

        $this->assertSoftDeleted('users', ['id' => $taller->id]);
    }

    public function test_the_bulk_delete_action_skips_only_the_record_that_would_strand_administration(): void
    {
        $admin = $this->admin();
        $taller = Role::where('name', 'taller')->firstOrFail()->users()->firstOrFail();
        $actor = $this->actorWithManageUsersButNoPanelAccess();

        Livewire::actingAs($actor)
            ->test(ListUsers::class)
            ->callTableBulkAction('delete', [$admin, $taller]);

        $this->assertNotNull(User::find($admin->getKey()), 'el administrador se salta, no se borra');
        $this->assertSoftDeleted('users', ['id' => $taller->id]);
        $this->assertTrue($this->alguienAdministra());
    }

    public function test_the_edit_user_header_delete_action_is_not_authorized_when_it_would_strand_administration(): void
    {
        $admin = $this->admin();
        $actor = $this->actorWithManageUsersButNoPanelAccess();

        Livewire::actingAs($actor)
            ->test(EditUser::class, ['record' => $admin->getKey()])
            ->assertActionHidden('delete');

        $this->assertNotNull(User::find($admin->getKey()));
    }

    /** Hallazgo 6: el nombre liberado no se puede reclamar desde la pantalla. */
    public function test_a_reserved_role_name_cannot_be_recreated(): void
    {
        $admin = $this->admin();
        $original = Role::where('name', 'administrador')->firstOrFail();

        $clon = Role::create(['name' => 'admin_clon', 'guard_name' => 'web']);
        $clon->syncPermissions($original->permissions);
        $admin->assignRole($clon);
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        Livewire::actingAs($admin)
            ->test(ListRoles::class)
            ->callTableAction('delete', $original, ['reassign_to' => $clon->getKey()]);

        $this->assertNull(Role::where('name', 'administrador')->first(), 'el nombre quedo libre');

        Livewire::actingAs($admin)
            ->test(CreateRole::class)
            ->fillForm([
                'name' => 'administrador',
                'permissions' => [Permission::where('name', 'view_fleet')->firstOrFail()->id],
            ])
            ->call('create')
            ->assertHasFormErrors(['name']);

        $this->assertNull(Role::where('name', 'administrador')->first(), 'y sigue libre');
    }

    /**
     * Hallazgo 6, la raiz: la resolucion por nombre solo se enciende cuando NO
     * existe NINGUNO de los cinco permisos, que es la firma de la ventana de
     * despliegue. Un estado a medias no la reactiva.
     */
    public function test_a_half_migrated_state_does_not_re_enable_name_based_access(): void
    {
        $this->assertFalse(AccessControl::legacyFallbackIsActive(), 'con todo migrado, apagada');

        Permission::where('name', 'access_panel')->delete();
        Permission::where('name', 'delete_machines')->delete();
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $this->assertFalse(
            AccessControl::legacyFallbackIsActive(),
            'faltando dos de cinco sigue apagada: un rol vacio con nombre reservado no concede nada'
        );

        Permission::whereIn('name', array_keys(AccessControl::LEGACY_ROLE_FALLBACK))->delete();
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $this->assertTrue(
            AccessControl::legacyFallbackIsActive(),
            'faltando los cinco si se enciende: es la ventana de despliegue real'
        );
    }

    /**
     * El corrimiento del limite que trajo este trabajo, fijado a proposito:
     * `manage_users` dejo de ser "gestiona usuarios" y paso a repartir el
     * acceso al panel. Es la funcionalidad pedida, no un defecto, pero si
     * alguna vez deja de ser cierto conviene que sea una decision y no un
     * accidente.
     */
    public function test_manage_users_can_hand_panel_access_to_a_new_role(): void
    {
        $rol = Role::where('name', 'operador_cisterna')->firstOrFail();
        $operario = $rol->users()->firstOrFail();
        $panel = Filament::getPanel('admin');

        $this->assertFalse($operario->canAccessPanel($panel), 'antes no entraba');

        $rol->givePermissionTo('access_panel');
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $operario->unsetRelation('roles');
        $this->assertTrue($operario->canAccessPanel($panel), 'con el permiso, entra');
    }
}

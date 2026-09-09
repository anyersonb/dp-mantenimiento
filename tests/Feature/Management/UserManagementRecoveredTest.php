<?php

namespace Tests\Feature\Management;

use App\Filament\Resources\UserResource\Pages\CreateUser;
use App\Filament\Resources\UserResource\Pages\EditUser;
use App\Filament\Resources\UserResource\Pages\ListUsers;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * RECREADO el 2026-09-09 durante el lote de Papelera (Lote A).
 *
 * El archivo original, `tests/Feature/Management/UserManagementTest.php`,
 * desapareció del árbol de trabajo por su cuenta (sin `rm`, sin `git`) y
 * quedó en un estado "delete pending" de NTFS: `git status` lo marca `D`,
 * Windows confirma que no existe (`Test-Path` → false), pero recrear un
 * archivo con ESE mismo nombre falla con "Permission denied" tanto desde
 * `git checkout` como desde `touch`/`New-Item` directo — el mismo síntoma
 * que ya se había documentado antes en este entorno. El contenido es
 * IDÉNTICO al original (recuperado de `git show HEAD:...`); solo cambian el
 * nombre del archivo y de la clase, para no perder esta cobertura mientras
 * el bloqueo del nombre original sigue vivo. Si en algún momento
 * `UserManagementTest.php` vuelve a poder crearse, este archivo puede
 * fusionarse de nuevo con ese nombre y borrarse el duplicado.
 *
 * CRUD de usuarios + asignación de roles (Spatie), gestionable solo por
 * quien tiene el permiso "manage_users" (rol administrador).
 */
class UserManagementRecoveredTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);
    }

    public function test_users_resource_boots_for_administrator(): void
    {
        $admin = User::where('email', 'admin@dp.local')->firstOrFail();

        $this->actingAs($admin)->get('/admin/users')->assertOk();
    }

    public function test_users_resource_is_forbidden_for_a_role_without_manage_users(): void
    {
        $taller = User::where('email', 'taller@dp.local')->firstOrFail();

        $this->actingAs($taller)->get('/admin/users')->assertForbidden();
    }

    public function test_administrator_can_create_a_user_with_a_role(): void
    {
        $admin = User::where('email', 'admin@dp.local')->firstOrFail();
        $foremanRole = Role::where('name', 'foreman')->firstOrFail();

        Livewire::actingAs($admin)
            ->test(CreateUser::class)
            ->fillForm([
                'name' => 'Nuevo Foreman',
                'email' => 'nuevo.foreman@dp.local',
                'password' => 'password123',
                'password_confirmation' => 'password123',
                'locale' => 'es',
                'active' => true,
                'roles' => [$foremanRole->id],
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $user = User::where('email', 'nuevo.foreman@dp.local')->firstOrFail();

        $this->assertTrue($user->hasRole('foreman'));
        $this->assertTrue(Hash::check('password123', $user->password));
    }

    public function test_creating_a_user_requires_matching_password_confirmation(): void
    {
        $admin = User::where('email', 'admin@dp.local')->firstOrFail();
        $foremanRole = Role::where('name', 'foreman')->firstOrFail();

        Livewire::actingAs($admin)
            ->test(CreateUser::class)
            ->fillForm([
                'name' => 'Password Mismatch',
                'email' => 'mismatch@dp.local',
                'password' => 'password123',
                'password_confirmation' => 'somethingelse',
                'locale' => 'es',
                'roles' => [$foremanRole->id],
            ])
            ->call('create')
            ->assertHasFormErrors(['password']);

        $this->assertDatabaseMissing('users', ['email' => 'mismatch@dp.local']);
    }

    public function test_administrator_can_edit_a_user_without_changing_the_password(): void
    {
        $admin = User::where('email', 'admin@dp.local')->firstOrFail();
        $target = User::where('email', 'foreman@dp.local')->firstOrFail();
        $originalHash = $target->password;

        Livewire::actingAs($admin)
            ->test(EditUser::class, ['record' => $target->getKey()])
            ->fillForm([
                'name' => 'Foreman Renombrado',
                'password' => '',
                'password_confirmation' => '',
            ])
            ->call('save')
            ->assertHasNoFormErrors();

        $target->refresh();

        $this->assertSame('Foreman Renombrado', $target->name);
        $this->assertSame($originalHash, $target->password);
    }

    public function test_a_user_cannot_delete_their_own_account_from_the_table(): void
    {
        $admin = User::where('email', 'admin@dp.local')->firstOrFail();

        Livewire::actingAs($admin)
            ->test(ListUsers::class)
            ->assertTableActionHidden('delete', $admin);
    }
}

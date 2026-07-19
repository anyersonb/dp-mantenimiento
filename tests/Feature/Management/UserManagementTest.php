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
 * CRUD de usuarios + asignación de roles (Spatie), gestionable solo por
 * quien tiene el permiso "manage_users" (rol administrador).
 */
class UserManagementTest extends TestCase
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

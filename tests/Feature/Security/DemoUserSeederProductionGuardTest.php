<?php

namespace Tests\Feature\Security;

use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Hallazgo 4 (auditoría 2026-09-18, CRÍTICO preexistente):
 * `RolesAndPermissionsSeeder` creaba 7 cuentas (incluida `admin@dp.local`)
 * con `Hash::make('password')` cableado, corrió en producción, y el
 * repositorio fue público con ese archivo adentro.
 *
 * Dos redes, probadas por separado:
 *   1. En producción, la sección de USUARIOS nunca corre (roles y permisos
 *      sí). No depende de quién invoque el seeder.
 *   2. Fuera de producción, la clave sale de `config('accounts.demo_seed_password')`
 *      (env `DEMO_SEED_PASSWORD`) — nunca del literal 'password'. El test de
 *      comportamiento en testing verifica esto comparando contra la config,
 *      no contra el string 'password' a secas, para que un cambio futuro del
 *      default de testing no invalide el test sin razón.
 */
class DemoUserSeederProductionGuardTest extends TestCase
{
    use RefreshDatabase;

    public function test_production_skips_demo_users_but_still_seeds_roles_and_permissions(): void
    {
        $this->app['env'] = 'production';

        Log::spy();

        // Se invoca el seeder DIRECTO (no `$this->seed()`, que pasa por el
        // comando `db:seed`) porque en 'production' Laravel dispara su
        // propia confirmación interactiva (ConfirmableTrait) antes de correr
        // el comando — una capa distinta a la que este test verifica. Acá
        // interesa el guard DENTRO de RolesAndPermissionsSeeder::run(), no el
        // de la consola.
        (new RolesAndPermissionsSeeder)->run();

        $this->assertSame(0, User::count(), 'no debe sembrar ninguna cuenta demo en producción');
        $this->assertTrue(Role::where('name', 'administrador')->exists(), 'roles sí deben aplicarse en producción');
        $this->assertTrue(Permission::where('name', 'view_field_reports')->exists(), 'permisos sí deben aplicarse en producción');

        Log::shouldHaveReceived('warning')
            ->withArgs(fn (string $message): bool => str_contains($message, 'production'))
            ->once();
    }

    public function test_outside_production_it_seeds_demo_users_with_the_configured_password_not_a_literal(): void
    {
        // phpunit.xml fija DEMO_SEED_PASSWORD=password para que la suite no
        // cambie de comportamiento; se lee de la config, no se asume el
        // string a secas.
        $configuredPassword = config('accounts.demo_seed_password');
        $this->assertNotEmpty($configuredPassword, 'la config debe traer un default en testing');

        $this->seed(RolesAndPermissionsSeeder::class);

        $admin = User::where('email', 'admin@dp.local')->firstOrFail();
        $this->assertTrue(Hash::check($configuredPassword, $admin->password));
    }

    /**
     * Falsación: si la config cambia, la cuenta sembrada usa la clave NUEVA,
     * no un literal 'password' cableado en el seeder.
     */
    public function test_changing_the_configured_password_changes_what_gets_seeded(): void
    {
        config(['accounts.demo_seed_password' => 'another-testing-password']);

        $this->seed(RolesAndPermissionsSeeder::class);

        $admin = User::where('email', 'admin@dp.local')->firstOrFail();
        $this->assertTrue(Hash::check('another-testing-password', $admin->password));
        $this->assertFalse(Hash::check('password', $admin->password));
    }

    public function test_it_refuses_to_seed_demo_users_when_no_password_is_configured(): void
    {
        config(['accounts.demo_seed_password' => null]);

        $this->seed(RolesAndPermissionsSeeder::class);

        $this->assertSame(0, User::count());
        $this->assertTrue(Role::where('name', 'administrador')->exists());
    }
}

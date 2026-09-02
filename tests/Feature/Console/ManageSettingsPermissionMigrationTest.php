<?php

namespace Tests\Feature\Console;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Hallazgo menor de seguridad, 2026-09-01: la migración
 * `2026_09_01_100100_add_manage_settings_permission.php` resolvía el rol
 * `administrador` con `Role::where('name', ...)->first()` y, si el rol
 * había sido renombrado (justo el commit base de este lote —9a2a7eb4—
 * volvió los roles renombrables), el `?->givePermissionTo()` terminaba en
 * verde sin asignar nada. Es fail-closed y recuperable (RolePermission
 * BaselineSeeder reconverge la matriz después), pero se desplegaba en
 * silencio. El fix agrega un warning; este test prueba que aparece y que
 * la migración sigue sin fallar.
 */
class ManageSettingsPermissionMigrationTest extends TestCase
{
    use RefreshDatabase;

    private function runMigration(): void
    {
        $migration = require database_path('migrations/2026_09_01_100100_add_manage_settings_permission.php');

        $migration->up();
    }

    public function test_it_warns_but_does_not_fail_when_the_administrador_role_was_renamed(): void
    {
        // La migración ya corrió una vez (vía RefreshDatabase) antes de que
        // ningún rol exista todavía, así que empezamos desde un estado
        // limpio y previsible: creamos el rol con OTRO nombre, simulando
        // que alguien lo renombró desde la pantalla de Roles.
        Role::create(['name' => 'administrador-renombrado', 'guard_name' => 'web']);

        Log::spy();

        $this->runMigration();

        Log::shouldHaveReceived('warning')
            ->withArgs(fn (string $message): bool => str_contains($message, 'manage_settings') && str_contains($message, 'administrador'))
            ->once();

        $this->assertTrue(
            Permission::where('name', 'manage_settings')->exists(),
            'el permiso debe crearse igual, aunque no se pueda asignar a ningún rol'
        );

        $this->assertFalse(
            Role::where('name', 'administrador-renombrado')->first()->hasPermissionTo('manage_settings'),
            'no hay ningún rol al que asignarle el permiso: no debería tenerlo nadie'
        );
    }

    public function test_it_assigns_the_permission_silently_when_the_role_exists(): void
    {
        Role::create(['name' => 'administrador', 'guard_name' => 'web']);

        Log::spy();

        $this->runMigration();

        Log::shouldNotHaveReceived('warning');

        $this->assertTrue(
            Role::where('name', 'administrador')->first()->hasPermissionTo('manage_settings')
        );
    }
}

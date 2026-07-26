<?php

namespace Tests\Feature\Security;

use Database\Seeders\RolePermissionBaselineSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Test centinela — Etapa 06.
 *
 * Nace de un incidente real: durante una prueba exploratoria se le quitó a
 * mano un permiso al rol `taller` para verificar un comportamiento y, por una
 * caída del navegador, quedó sin restaurar 4 horas hasta que alguien lo notó.
 * Este test compara `role_has_permissions` contra la matriz esperada
 * (`RolePermissionBaselineSeeder::MATRIX` — la misma que usa el seeder de
 * restauración, para no tener dos copias que se puedan desincronizar) y
 * falla nombrando exactamente el rol y el permiso de más o de menos, para
 * que la próxima vez la deriva se note en la suite, no cuatro horas después.
 *
 * Si falla: correr
 *   php artisan db:seed --class=RolePermissionBaselineSeeder
 * para reconverger la matriz sin tocar usuarios ni otras tablas.
 *
 * Correrlo solo:
 *   php artisan test tests/Feature/Security/RolePermissionMatrixSentinelTest.php
 */
class RolePermissionMatrixSentinelTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);
    }

    public function test_role_has_permissions_matches_the_expected_matrix_exactly(): void
    {
        $failures = [];

        foreach (RolePermissionBaselineSeeder::MATRIX as $roleName => $expectedPermissions) {
            $role = Role::where('name', $roleName)->first();

            if (! $role) {
                $failures[] = "el rol {$roleName} no existe en la base de datos";

                continue;
            }

            $current = $role->permissions()->pluck('name')->all();

            foreach (array_diff($expectedPermissions, $current) as $missing) {
                $failures[] = "al rol {$roleName} le falta el permiso {$missing}";
            }

            foreach (array_diff($current, $expectedPermissions) as $extra) {
                $failures[] = "el rol {$roleName} tiene de más el permiso {$extra} (no está en la matriz)";
            }
        }

        $knownRoles = array_keys(RolePermissionBaselineSeeder::MATRIX);
        foreach (Role::whereNotIn('name', $knownRoles)->pluck('name') as $unexpectedRole) {
            $failures[] = "existe el rol {$unexpectedRole}, que no está contemplado en la matriz esperada";
        }

        $this->assertSame(
            [],
            $failures,
            "La matriz de permisos se desvió de lo esperado:\n - ".implode("\n - ", $failures)
        );
    }
}

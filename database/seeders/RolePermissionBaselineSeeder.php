<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Red de seguridad de la matriz de permisos — Etapa 06.
 *
 * Nace de un incidente real: durante una prueba exploratoria se le quitó a
 * mano un permiso al rol `taller` para verificar un comportamiento y, por una
 * caída del navegador, quedó sin restaurar 4 horas. Este seeder reafirma la
 * matriz completa de `RolesAndPermissionsSeeder` (fuente de verdad de los 15
 * permisos × 7 roles) de forma que se pueda correr en cualquier momento, en
 * producción, sin depender de SQL manual ni del navegador.
 *
 * Es CONVERGENTE, no aditivo: agrega el permiso que falte en un rol y quita
 * el que sobre, hasta que `role_has_permissions` coincide exactamente con
 * MATRIX. Es IDEMPOTENTE: correrlo dos veces seguidas no duplica filas ni
 * cambia nada en la segunda corrida. NO toca usuarios, `model_has_roles`,
 * permisos, ni ninguna otra tabla — solo `role_has_permissions`.
 *
 * La matriz vive acá, en MATRIX, como única fuente compartida con
 * tests/Feature/Security/RolePermissionMatrixSentinelTest.php para que
 * seeder y test no puedan desincronizarse entre sí.
 *
 * Correrlo:
 *   php artisan db:seed --class=RolePermissionBaselineSeeder
 *
 * A propósito NO está registrado en DatabaseSeeder: no debe dispararse en un
 * `db:seed` completo, solo cuando alguien lo invoque explícitamente para
 * restaurar la matriz.
 */
class RolePermissionBaselineSeeder extends Seeder
{
    /**
     * Matriz esperada de 41 permisos × 7 roles. Debe coincidir siempre con
     * `RolesAndPermissionsSeeder::run()` — si esa matriz cambia, actualizar
     * también acá.
     *
     * @var array<string, array<int, string>>
     */
    public const MATRIX = [
        'administrador' => [
            'view_fleet', 'manage_machines', 'view_costs', 'manage_users',
            'verify_data', 'manage_quotes', 'create_work_order', 'execute_work_order',
            'log_horometer', 'log_fuel', 'field_report', 'confirm_location',
            'move_fleet', 'view_reports', 'view_audit_log',
            'access_panel', 'view_alerts', 'delete_machines', 'delete_work_orders',
            'receive_alerts_digest', 'manage_settings',
            'view_trash_machines', 'restore_machines', 'force_delete_machines',
            'view_trash_work_orders', 'restore_work_orders', 'force_delete_work_orders',
            'view_trash_quotes', 'restore_quotes', 'force_delete_quotes',
            'view_trash_locations', 'restore_locations', 'force_delete_locations',
            'view_trash_machine_categories', 'restore_machine_categories', 'force_delete_machine_categories',
            'view_trash_makes', 'restore_makes', 'force_delete_makes',
            'view_trash_users', 'restore_users', 'force_delete_users',
        ],
        'responsable_mantenimiento' => [
            'view_fleet', 'manage_machines', 'view_costs', 'create_work_order',
            'view_reports', 'view_audit_log',
            'access_panel', 'view_alerts',
        ],
        'foreman' => [
            'view_fleet', 'log_horometer', 'field_report', 'confirm_location',
        ],
        'operador_cisterna' => [
            'view_fleet', 'log_horometer', 'log_fuel',
        ],
        'personal_mantenimiento' => [
            'view_fleet', 'log_horometer', 'field_report',
        ],
        'taller' => [
            'view_fleet', 'view_costs', 'execute_work_order', 'log_horometer',
            'access_panel',
        ],
        'gerencia' => [
            'view_fleet', 'view_costs', 'move_fleet', 'view_reports',
            'access_panel',
        ],
    ];

    public function run(): void
    {
        $changed = false;

        foreach (self::MATRIX as $roleName => $expectedPermissions) {
            $role = Role::where('name', $roleName)->first();

            if (! $role) {
                $this->command?->error(
                    "RolePermissionBaselineSeeder: el rol '{$roleName}' no existe en la base de "
                    .'datos — no se puede corregir su matriz. Corré RolesAndPermissionsSeeder primero.'
                );

                continue;
            }

            $current = $role->permissions()->pluck('name')->all();

            $toAdd = array_values(array_diff($expectedPermissions, $current));
            $toRemove = array_values(array_diff($current, $expectedPermissions));

            if ($toAdd === [] && $toRemove === []) {
                continue;
            }

            $changed = true;

            foreach ($toAdd as $permissionName) {
                $permission = Permission::where('name', $permissionName)->first();

                if (! $permission) {
                    $this->command?->error(
                        "RolePermissionBaselineSeeder: el permiso '{$permissionName}' no existe — "
                        .'no se puede asignar a '.$roleName.'. Corré RolesAndPermissionsSeeder primero.'
                    );

                    continue;
                }

                $role->givePermissionTo($permission);
                $this->command?->warn("[{$roleName}] permiso agregado: {$permissionName}");
            }

            foreach ($toRemove as $permissionName) {
                $role->revokePermissionTo($permissionName);
                $this->command?->warn("[{$roleName}] permiso quitado: {$permissionName}");
            }
        }

        if (! $changed) {
            $this->command?->info('RolePermissionBaselineSeeder: la matriz de permisos ya estaba correcta. Sin cambios.');

            return;
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $this->command?->info('RolePermissionBaselineSeeder: matriz corregida y caché de permisos limpiada.');
    }
}

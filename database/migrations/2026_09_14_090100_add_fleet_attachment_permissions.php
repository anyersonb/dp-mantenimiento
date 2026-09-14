<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Módulo de Complementos (Attachments) — ver spec-complementos-dp.md, "Permisos
 * — las SEIS puertas".
 *
 * Seis permisos nuevos: `view_attachments`, `manage_attachments`,
 * `delete_attachments`, `view_trash_attachments`, `restore_attachments`,
 * `force_delete_attachments`. Se reparten a los MISMOS roles que ya tienen
 * `view_fleet` / `manage_machines` / `delete_machines` respectivamente —
 * decisión ya tomada en la spec, no un reparto nuevo.
 *
 * En una migración y no solo en RolesAndPermissionsSeeder, mismo motivo que
 * `2026_09_08_120100_add_papelera_permissions.php`: ese seeder ya corrió en
 * producción, así que en un deploy por FTP (sin re-seed) el módulo nacería
 * sin permisos —invisible en el menú, 403 para todo el mundo— hasta que
 * alguien lo recuerde a mano. Es idempotente (`firstOrCreate` +
 * `givePermissionTo` sobre lo que falte) y fail-closed: sin el permiso en la
 * base, `Auth::user()?->can(...) ?? false` deniega hasta que esta migración
 * corra.
 */
return new class extends Migration
{
    /**
     * @return array<int, string>
     */
    public static function permissions(): array
    {
        return [
            'view_attachments',
            'manage_attachments',
            'delete_attachments',
            'view_trash_attachments',
            'restore_attachments',
            'force_delete_attachments',
        ];
    }

    /**
     * Roles que reciben cada permiso, calcado de a quién le da hoy el
     * permiso equivalente de Máquinas (view_fleet / manage_machines /
     * delete_machines) en RolesAndPermissionsSeeder::$matrix.
     *
     * @return array<string, array<int, string>>
     */
    public static function roleAssignments(): array
    {
        return [
            // view_fleet hoy: administrador, responsable_mantenimiento, foreman,
            // operador_cisterna, personal_mantenimiento, taller, gerencia (todos).
            'view_attachments' => [
                'administrador', 'responsable_mantenimiento', 'foreman',
                'operador_cisterna', 'personal_mantenimiento', 'taller', 'gerencia',
            ],
            // manage_machines hoy: administrador, responsable_mantenimiento.
            'manage_attachments' => ['administrador', 'responsable_mantenimiento'],
            // delete_machines hoy: solo administrador (vía LEGACY_ROLE_FALLBACK).
            'delete_attachments' => ['administrador'],
            // Papelera: igual que el resto de recursos del Lote A, solo administrador.
            'view_trash_attachments' => ['administrador'],
            'restore_attachments' => ['administrador'],
            'force_delete_attachments' => ['administrador'],
        ];
    }

    public function up(): void
    {
        DB::transaction(function () {
            foreach (self::permissions() as $permissionName) {
                Permission::firstOrCreate(['name' => $permissionName, 'guard_name' => 'web']);
            }

            foreach (self::roleAssignments() as $permissionName => $roleNames) {
                foreach ($roleNames as $roleName) {
                    $role = Role::where('name', $roleName)->first();

                    if (! $role) {
                        // Fail-closed y recuperable (RolePermissionBaselineSeeder
                        // reconverge la matriz después): el rol pudo haber sido
                        // renombrado o borrado desde la pantalla de Roles.
                        $summary = "[fleet_attachments permissions migration] el rol '{$roleName}' no existe — "
                            ."no recibió '{$permissionName}'. Corré RolePermissionBaselineSeeder para reconverger.";

                        Log::warning($summary);

                        if (app()->runningInConsole()) {
                            fwrite(STDOUT, "  {$summary}\n");
                        }

                        continue;
                    }

                    $role->givePermissionTo($permissionName);
                }
            }
        });

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        Permission::whereIn('name', self::permissions())->delete();

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};

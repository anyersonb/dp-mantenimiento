<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Papelera (Lote A): 21 permisos nuevos, granulares por recurso —ver papelera,
 * restaurar, eliminar definitivamente— para Machine, WorkOrder, Quote,
 * Location, MachineCategory, Make y User. Se asignan EXCLUSIVAMENTE a
 * `administrador`, por decisión ya tomada por el cliente (no cambia quién
 * manda a la papelera: eso lo sigue decidiendo el permiso de borrado que ya
 * existe de cada recurso).
 *
 * `Role` queda FUERA a propósito (ver el informe del lote): el borrado de
 * roles ya es seguro tal como está —con reasignación obligatoria de usuarios—
 * y "restaurar" un rol reviviría una matriz de permisos con cero garantías
 * sobre a quién se le vuelve a asignar, que es justo lo que el borrado actual
 * fue diseñado para evitar.
 *
 * En una migración y no en RolesAndPermissionsSeeder, mismo motivo que
 * `2026_09_01_100100_add_manage_settings_permission.php`: ese seeder ya corrió
 * en producción y recrearía las cuentas demo que se hayan borrado. Es
 * idempotente (`firstOrCreate` + `givePermissionTo` sobre lo que falte) y
 * fail-closed: sin el permiso en la base, `Auth::user()?->can(...) ?? false`
 * deniega hasta que esta migración corra.
 */
return new class extends Migration
{
    /**
     * @return array<int, string>
     */
    public static function permissions(): array
    {
        $recursos = [
            'machines',
            'work_orders',
            'quotes',
            'locations',
            'machine_categories',
            'makes',
            'users',
        ];

        $permisos = [];

        foreach ($recursos as $recurso) {
            $permisos[] = 'view_trash_'.$recurso;
            $permisos[] = 'restore_'.$recurso;
            $permisos[] = 'force_delete_'.$recurso;
        }

        return $permisos;
    }

    public function up(): void
    {
        DB::transaction(function () {
            foreach (self::permissions() as $permissionName) {
                Permission::firstOrCreate(['name' => $permissionName, 'guard_name' => 'web']);
            }

            $role = Role::where('name', 'administrador')->first();

            if (! $role) {
                // Fail-closed y recuperable (RolePermissionBaselineSeeder
                // reconverge la matriz después): el rol pudo haber sido
                // renombrado desde la pantalla de Roles. Mismo hallazgo de
                // seguridad que la migración de manage_settings.
                $summary = "[papelera migration] el rol 'administrador' no existe (¿fue renombrado?) — "
                    .'ningún rol recibió los permisos de papelera. Corré RolePermissionBaselineSeeder para reconverger.';

                Log::warning($summary);

                if (app()->runningInConsole()) {
                    fwrite(STDOUT, "  {$summary}\n");
                }

                return;
            }

            $role->givePermissionTo(self::permissions());
        });

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        Permission::whereIn('name', self::permissions())->delete();

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};

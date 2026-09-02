<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Permiso nuevo `manage_settings`: quién puede editar la Configuración del
 * panel (hoy, solo la tasa de impuesto de repuestos). Se da a `administrador`
 * exactamente como el resto de los permisos "de administración total"
 * (manage_users, manage_quotes, ...).
 *
 * En una migración y no en RolesAndPermissionsSeeder a propósito: ese seeder
 * ya corrió una vez en producción y volver a dispararlo recrearía las cuentas
 * demo que se hayan borrado (mismo motivo documentado en
 * 2026_08_25_100000_add_access_control_permissions.php).
 *
 * Este permiso NO entra en la red legado de App\Support\AccessControl: no
 * reemplaza ningún chequeo por nombre de rol preexistente, es una capacidad
 * nueva. Sin permiso, `Auth::user()?->can('manage_settings') ?? false`
 * deniega (fail-closed) hasta que esta migración corra.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::transaction(function () {
            $permission = Permission::firstOrCreate(['name' => 'manage_settings', 'guard_name' => 'web']);

            $role = Role::where('name', 'administrador')->first();
            $role?->givePermissionTo($permission);
        });

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        Permission::where('name', 'manage_settings')->delete();

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};

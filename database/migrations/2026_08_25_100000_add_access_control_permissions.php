<?php

use App\Support\AccessControl;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Crea los cuatro permisos que reemplazan a los chequeos por nombre de rol y
 * se los da EXACTAMENTE a los roles que hoy los tienen de hecho.
 *
 * Esto es lo que cierra la ventana de despliegue descrita en App\Support\
 * AccessControl: hasta que esta migración corre, la app resuelve esos cuatro
 * permisos por la lista de nombres de siempre; a partir de acá, por permiso.
 * El estado visible del sistema no cambia ni un ápice — cambia QUIÉN decide,
 * no QUIÉN puede. Lo que se gana es que a partir de ahora eso se edita desde
 * la pantalla de Roles en vez de tocando código.
 *
 * Se hace en una migración y no en `RolesAndPermissionsSeeder` a propósito:
 * ese seeder también crea los siete usuarios demo, y en esta instancia ya se
 * corrió una vez. Volver a dispararlo en producción para conseguir cuatro
 * permisos recrearía cuentas demo que se hayan borrado.
 *
 * Es idempotente: `firstOrCreate` + `givePermissionTo` sobre lo que falte.
 */
return new class extends Migration
{
    public function up(): void
    {
        // La transaccion NO es ceremonia. Desde que la red legado exige que
        // falten LOS CINCO permisos para activarse, un estado a medias --el
        // permiso creado pero todavia sin repartir-- seria lo peor de los dos
        // mundos: la red apagada y nadie con el permiso, o sea el panel
        // cerrado para todos. Son puros INSERT: la transaccion los cubre.
        DB::transaction(function () {
            foreach (AccessControl::LEGACY_ROLE_FALLBACK as $permission => $roleNames) {
                Permission::firstOrCreate(['name' => $permission, 'guard_name' => 'web']);

                foreach ($roleNames as $roleName) {
                    $role = Role::where('name', $roleName)->first();

                    // Si el rol no está (base recién creada, o alguien ya lo
                    // borró) no se inventa: la matriz la reconverge después
                    // RolePermissionBaselineSeeder, que para eso existe.
                    $role?->givePermissionTo($permission);
                }
            }
        });

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        Permission::whereIn('name', array_keys(AccessControl::LEGACY_ROLE_FALLBACK))->delete();

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};

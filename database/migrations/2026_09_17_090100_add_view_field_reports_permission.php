<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Permiso `view_field_reports` — pantalla `FieldReportResource` en /admin.
 *
 * Hoy los reportes de campo (`field_reports`) no aparecen en NINGUNA pantalla
 * del panel: el operario los envía desde /field/report y ahí se quedan, sin
 * que nadie los lea ni reciba aviso. Este permiso es la puerta de esa
 * pantalla nueva, y se reparte a los MISMOS cuatro roles que hoy tienen
 * `access_panel` (administrador, responsable_mantenimiento, taller,
 * gerencia) — el mismo reparto que documenta
 * App\Support\AccessControl::LEGACY_ROLE_FALLBACK['access_panel'].
 *
 * En una MIGRACIÓN y no solo en RolesAndPermissionsSeeder, mismo motivo que
 * `2026_09_14_090100_add_fleet_attachment_permissions.php`: ese seeder ya
 * corrió en producción (y además siembra usuarios demo — ver el incidente de
 * `admin@dp.local`, PROHIBIDO volver a dispararlo ahí). Sin esta migración,
 * en un deploy por FTP (sin re-seed) el permiso no existiría hasta que
 * alguien lo recuerde a mano.
 *
 * Corrección (hallazgo 5, auditoría 2026-09-18): esto NO cae a
 * LEGACY_ROLE_FALLBACK['view_field_reports'] mientras la migración no corrió.
 * AccessControl::legacyFallbackIsActive() solo activa la red cuando NINGUNO
 * de los permisos de la matriz existe todavía, y en producción `access_panel`
 * ya existe desde agosto de 2026 — así que la red está apagada para TODA la
 * matriz, este permiso incluido. El comportamiento real en la ventana de
 * despliegue es fail-closed: AccessControl::allows() deniega a todo el mundo
 * hasta que la migración corre. Es el lado seguro (nadie ve la pantalla de
 * más), pero es distinto de lo que este comentario afirmaba antes.
 *
 * Idempotente (`firstOrCreate` + `givePermissionTo` sobre lo que falte).
 */
return new class extends Migration
{
    public const PERMISSION = 'view_field_reports';

    /**
     * @return array<int, string>
     */
    public static function roleNames(): array
    {
        return ['administrador', 'responsable_mantenimiento', 'taller', 'gerencia'];
    }

    public function up(): void
    {
        DB::transaction(function () {
            Permission::firstOrCreate(['name' => self::PERMISSION, 'guard_name' => 'web']);

            foreach (self::roleNames() as $roleName) {
                $role = Role::where('name', $roleName)->first();

                if (! $role) {
                    // Fail-closed y recuperable (RolePermissionBaselineSeeder
                    // reconverge la matriz después): el rol pudo haber sido
                    // renombrado o borrado desde la pantalla de Roles.
                    $summary = "[view_field_reports migration] el rol '{$roleName}' no existe — "
                        .'no recibió el permiso. Corré RolePermissionBaselineSeeder para reconverger.';

                    Log::warning($summary);

                    if (app()->runningInConsole()) {
                        fwrite(STDOUT, "  {$summary}\n");
                    }

                    continue;
                }

                $role->givePermissionTo(self::PERMISSION);
            }
        });

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        Permission::where('name', self::PERMISSION)->delete();

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};

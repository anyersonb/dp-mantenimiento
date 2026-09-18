<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Permiso `view_field_report_location` — acota QUIÉN ve la sección de
 * ubicación GPS (coordenadas + enlace al mapa) dentro del detalle de un
 * reporte de campo (`FieldReportResource`).
 *
 * Hallazgo 2 (auditoría 2026-09-18): la posición exacta del operario
 * (`decimal(10,7)`, precisión de centímetros) con su nombre y hora, visible a
 * los cuatro roles con `view_field_reports` y buscable por reportero, permite
 * a cualquiera de esos roles reconstruir el recorrido físico de un compañero
 * día por día. Eso es seguimiento de personal, no mantenimiento de flota.
 *
 * DECISIÓN YA TOMADA (no es una ampliación libre de `view_field_reports`):
 * reparto MÁS ANGOSTO, solo administrador y responsable_mantenimiento — los
 * mismos dos que ya administran la flota y el personal en
 * App\Support\AccessControl::LEGACY_ROLE_FALLBACK. `taller` y `gerencia`
 * siguen viendo el reporte completo (máquina, estado, notas, horómetro,
 * reportero) vía `view_field_reports`, pero sin esta sección. El badge
 * "Con ubicación / Sin ubicación" del listado NO depende de este permiso:
 * saber que el reporte trae ubicación no revela dónde estaba nadie.
 *
 * En una MIGRACIÓN y no solo en RolesAndPermissionsSeeder, mismo motivo que
 * `2026_09_17_090100_add_view_field_reports_permission.php`: ese seeder ya
 * corrió en producción (y además sembró usuarios demo — ver el incidente de
 * `admin@dp.local`, PROHIBIDO volver a dispararlo ahí). Sin esta migración,
 * en un deploy por FTP (sin re-seed) el permiso no existiría hasta que
 * alguien lo recuerde a mano.
 *
 * Corrección (mismo defecto de docblock que
 * `2026_09_17_090100_add_view_field_reports_permission.php`, hallazgo 5 de
 * esa auditoría — reapareció copiado acá): esto NO cae a
 * LEGACY_ROLE_FALLBACK['view_field_report_location'] mientras la migración no
 * corrió. AccessControl::legacyFallbackIsActive() solo activa la red cuando
 * NINGUNO de los permisos de la matriz existe todavía, y en producción
 * `access_panel` ya existe desde agosto de 2026 — así que la red está apagada
 * para TODA la matriz, este permiso incluido. El comportamiento real en la
 * ventana de despliegue es fail-closed: AccessControl::allows() deniega a
 * todo el mundo (incluidos administrador y responsable_mantenimiento) hasta
 * que la migración corre. Es el lado seguro (nadie ve la ubicación de más),
 * pero es distinto de lo que este comentario afirmaba antes.
 *
 * Idempotente (`firstOrCreate` + `givePermissionTo` sobre lo que falte).
 */
return new class extends Migration
{
    public const PERMISSION = 'view_field_report_location';

    /**
     * @return array<int, string>
     */
    public static function roleNames(): array
    {
        return ['administrador', 'responsable_mantenimiento'];
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
                    $summary = "[view_field_report_location migration] el rol '{$roleName}' no existe — "
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

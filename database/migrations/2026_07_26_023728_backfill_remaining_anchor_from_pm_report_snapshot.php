<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * ============================================================================
 *  NO EJECUTAR sin autorización del jefe. Ver el conteo/detalle primero con:
 *
 *      php artisan horometer:audit-remaining
 *
 *  (App\Console\Commands\AuditRemainingHours — solo lectura, no toca nada).
 * ============================================================================
 *
 * Backfill del ancla (regla-horometro.md, sec. 5): fija
 * remaining_anchor_hours / remaining_anchor_at_hours a partir del snapshot
 * hoy confiable de cada máquina (remaining_hours + current_hours), para que
 * la próxima lectura de campo descuente desde ahí en vez de recalcular
 * desde cero con el clásico.
 *
 * Alcance deliberadamente conservador — solo hourmeter_status = 'ok':
 *   - 'broken' / 'no_info': su remaining_hours ya es NULL, no hay nada
 *     confiable que anclar (PJ001).
 *   - 'replaced': anclar el remaining_hours actual (a menudo un residuo del
 *     horómetro viejo, ej. MS-TEMP-01 en 500 con current_hours=1) sería
 *     enseñar como verificado un número sin sentido. Esas máquinas deben
 *     re-anclarse vía el evento de reemplazo
 *     (App\Services\HourmeterReplacementService), no por este backfill.
 *   - LD032: fuera de alcance por decisión del jefe (sec. 4 del spec) — sus
 *     números ya son coherentes y no se reinterpreta una nota manual
 *     modificando data real.
 *
 * Se ancla contra el remaining_hours guardado tal cual está hoy, incluidas
 * las filas que el audit marca "desalineado_con_regla_nueva" (EX023, LD023,
 * LD027, PW009, RL017): esa es intencionalmente la filosofía de la regla —
 * el dato del PM report manda sobre un recálculo en vivo — así que anclar
 * congela el snapshot confiable en lugar de sustituirlo por el clásico.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('machines')
            ->where('hourmeter_status', 'ok')
            ->where('id_code', '!=', 'LD032')
            ->whereNotNull('remaining_hours')
            ->whereNotNull('current_hours')
            ->whereNull('remaining_anchor_hours')
            ->update([
                'remaining_anchor_hours' => DB::raw('remaining_hours'),
                'remaining_anchor_at_hours' => DB::raw('current_hours'),
            ]);
    }

    public function down(): void
    {
        // Solo revierte lo que este backfill pudo haber puesto: no toca
        // anclas fijadas después por el importador o por un reemplazo real.
        DB::table('machines')
            ->where('hourmeter_status', 'ok')
            ->where('id_code', '!=', 'LD032')
            ->update([
                'remaining_anchor_hours' => null,
                'remaining_anchor_at_hours' => null,
            ]);
    }
};

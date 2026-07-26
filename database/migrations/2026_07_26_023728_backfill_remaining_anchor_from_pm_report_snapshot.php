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
 * REVISIÓN (2026-07-25, decisión del jefe tras revisar el primer intento):
 * la versión anterior de este archivo filtraba por hourmeter_status='ok' y
 * excluía LD032, y el "valor con la regla nueva" del audit se comparaba
 * contra el cálculo clásico — pero HOY NINGUNA máquina tiene ancla, así que
 * ese clásico ignora que el remaining_hours guardado es precisamente el dato
 * verificado a mano del PM Service Report (para EX023, LD023, LD027, PW009:
 * la diferencia con el clásico NO es corrupción, es la razón de ser del
 * ancla). Sobrescribir esos remaining_hours habría repetido el error de A4
 * en la dirección contraria. Este backfill YA NO TOCA remaining_hours en
 * ninguna fila: solo siembra el ancla a partir del valor que ya está.
 *
 * Alcance: remaining_hours Y current_hours no nulos, EXCEPTO:
 *   - MS-TEMP-01: remaining_hours=500 es un residuo sin sentido del
 *     horómetro viejo (current_hours=1 contra last_service_hours=2800,
 *     escalas incompatibles). Anclarlo enseñaría como verificado un número
 *     inventado. Se reporta en deuda-detectada.md para que el cliente lo
 *     confirme; se re-ancla como corresponde vía el evento de reemplazo
 *     (App\Services\HourmeterReplacementService), no por este backfill.
 *   - RL017: current_hours es NULL (no tiene ninguna lectura), así que ya
 *     queda fuera del filtro sin necesidad de excluirlo a mano; su
 *     remaining_hours=500 es el mismo tipo de residuo sin sentido y se
 *     reporta igual en deuda-detectada.md.
 *   - PJ001: remaining_hours ya es NULL, no hay nada que anclar.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('machines')
            ->whereNotNull('remaining_hours')
            ->whereNotNull('current_hours')
            ->where('id_code', '!=', 'MS-TEMP-01')
            ->whereNull('remaining_anchor_hours')
            ->update([
                'remaining_anchor_hours' => DB::raw('remaining_hours'),
                'remaining_anchor_at_hours' => DB::raw('current_hours'),
            ]);
    }

    public function down(): void
    {
        // Mismo filtro que up(): solo limpia lo que este backfill pudo haber
        // sembrado, sin tocar anclas fijadas después por el importador o por
        // un evento de reemplazo real. remaining_hours nunca se tocó, así
        // que no hay nada que restaurar ahí.
        DB::table('machines')
            ->whereNotNull('remaining_hours')
            ->whereNotNull('current_hours')
            ->where('id_code', '!=', 'MS-TEMP-01')
            ->update([
                'remaining_anchor_hours' => null,
                'remaining_anchor_at_hours' => null,
            ]);
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Las dos columnas que el reporte de costos necesita y que la OT no guardaba
 * (detectado al armar el módulo de reportes, 2026-08-05).
 *
 * **`completed_by` — quién hizo el mantenimiento.** La OT tenía `opened_by`
 * (quién la abrió) y `assigned_to` (a quién se le asignó), y ninguna de las dos
 * responde la pregunta del cliente. `assigned_to` es una intención: se puede
 * reasignar a mitad del trabajo, y el que cierra la OT puede ser el
 * administrador desde el panel. Un reporte que dijera "lo hizo X" leyendo
 * `assigned_to` estaría atribuyendo trabajo a la persona equivocada con cara de
 * dato bueno — el mismo vicio que la definición de N1 de este proyecto viene
 * persiguiendo.
 *
 * **`location_id` — dónde se hizo.** La OT no guardaba ubicación: el único dato
 * disponible era `machines.current_location_id`, o sea **dónde está la máquina
 * ahora**. Y mover máquinas entre obras es una función del sistema
 * (permiso `move_fleet`), así que en cuanto la máquina se mueve, el histórico de
 * servicios empieza a mentir hacia atrás. Se sella al abrir la OT y queda
 * congelado ahí.
 *
 * Las dos quedan nullable y **sin backfill**: las OT que ya existen no tienen de
 * dónde sacar el dato (no hay asiento de bitácora que lo reconstruya de forma
 * confiable) y rellenarlas con la ubicación actual sería fabricar exactamente el
 * dato equivocado que motivó la columna. En el reporte se muestran como "sin
 * registrar".
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('work_orders', function (Blueprint $table) {
            $table->foreignId('completed_by')->nullable()->after('assigned_to')
                ->constrained('users')->nullOnDelete();

            $table->foreignId('location_id')->nullable()->after('completed_by')
                ->constrained('locations')->nullOnDelete();

            // El reporte acota por periodo antes que por cualquier otra cosa.
            // `location_id` y `completed_by` ya quedan indexadas por su clave
            // foránea, así que agregarles un índice acá solo duplicaría.
            $table->index(['completed_at']);
        });
    }

    public function down(): void
    {
        Schema::table('work_orders', function (Blueprint $table) {
            $table->dropIndex(['completed_at']);
            $table->dropForeign(['location_id']);
            $table->dropForeign(['completed_by']);
            $table->dropColumn(['location_id', 'completed_by']);
        });
    }
};

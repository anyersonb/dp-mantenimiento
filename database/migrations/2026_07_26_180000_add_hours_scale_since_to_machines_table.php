<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Frontera de escala del horómetro (regla-horometro.md, sec. 2.2).
 *
 * Nace del recálculo completo que exige el hallazgo E6-01/E6-02: al recalcular
 * `current_hours` desde las lecturas sobrevivientes hay que saber DÓNDE empieza
 * la escala vigente. Tras un reemplazo físico de horómetro, las lecturas
 * anteriores están en una escala que ya no es comparable —el contador volvió a
 * arrancar— y un recálculo que las incluyera devolvería la máquina a la escala
 * vieja, que es peor que el defecto original.
 *
 * NULL = no hubo reemplazo, se consideran todas las lecturas.
 *
 * Se deja en NULL para las filas existentes a propósito: hoy hay una sola
 * máquina con `hourmeter_status='replaced'` (MS-TEMP-01), con una única lectura
 * de 1 h y `current_hours=1`, así que incluirla o excluirla da el mismo
 * resultado. No se toca dato real sin necesidad.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('machines', function (Blueprint $table) {
            $table->date('hours_scale_since')
                ->nullable()
                ->after('remaining_anchor_at_hours')
                ->comment('Fecha desde la que rige la escala actual del horometro (la fija un reemplazo). NULL = todas las lecturas cuentan.');
        });
    }

    public function down(): void
    {
        Schema::table('machines', function (Blueprint $table) {
            $table->dropColumn('hours_scale_since');
        });
    }
};

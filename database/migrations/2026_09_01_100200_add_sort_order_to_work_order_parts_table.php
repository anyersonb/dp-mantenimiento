<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Orden manual (arrastrable) de las líneas de repuestos de una orden de
 * trabajo. Es la superficie PRIORITARIA del lote de reordenamiento: el orden
 * que quede acá lo respeta CostReportBuilder y por lo tanto la pantalla, el
 * PDF y el Excel del reporte de costos (WorkOrder::parts() ordena por esta
 * columna).
 *
 * El backfill se REINICIA POR ORDEN DE TRABAJO (no es una secuencia global):
 * cada OT numera sus propias líneas 1..n en el orden que ya tenían (id
 * ascendente = orden de carga). Dejar todo en 0 haría que el "orden manual"
 * existiera de nombre pero no en los datos: la primera línea de cada OT
 * empataría con todas las demás.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('work_order_parts', function (Blueprint $table) {
            $table->unsignedInteger('sort_order')->default(0)->after('unit_cost');
        });

        $sequenceByWorkOrder = [];

        DB::table('work_order_parts')
            ->orderBy('work_order_id')
            ->orderBy('id')
            ->get(['id', 'work_order_id'])
            ->each(function (object $row) use (&$sequenceByWorkOrder) {
                $sequenceByWorkOrder[$row->work_order_id] = ($sequenceByWorkOrder[$row->work_order_id] ?? 0) + 1;

                DB::table('work_order_parts')
                    ->where('id', $row->id)
                    ->update(['sort_order' => $sequenceByWorkOrder[$row->work_order_id]]);
            });
    }

    public function down(): void
    {
        Schema::table('work_order_parts', function (Blueprint $table) {
            $table->dropColumn('sort_order');
        });
    }
};

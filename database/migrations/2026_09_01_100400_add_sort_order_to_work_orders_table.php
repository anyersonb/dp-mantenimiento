<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Orden manual del listado de órdenes de trabajo (`WorkOrderResource`).
 * Backfill GLOBAL por `id` (orden de creación), igual que los catálogos.
 *
 * OJO: el `defaultSort('created_at', 'desc')` del listado NO se toca en este
 * lote — ver la nota en WorkOrderResource::table(). Reordenar manualmente
 * queda disponible (botón de reordenar + drag), pero la vista normal del
 * listado sigue mostrando lo más reciente primero. Decisión reportada a
 * Anyerson, no resuelta en silencio.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('work_orders', function (Blueprint $table) {
            $table->unsignedInteger('sort_order')->default(0)->after('resolution');
        });

        $sequence = 0;

        DB::table('work_orders')
            ->orderBy('id')
            ->pluck('id')
            ->each(function ($id) use (&$sequence) {
                $sequence++;

                DB::table('work_orders')->where('id', $id)->update(['sort_order' => $sequence]);
            });
    }

    public function down(): void
    {
        Schema::table('work_orders', function (Blueprint $table) {
            $table->dropColumn('sort_order');
        });
    }
};

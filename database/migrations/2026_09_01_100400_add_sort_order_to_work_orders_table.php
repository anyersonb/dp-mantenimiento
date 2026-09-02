<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Orden manual del listado de órdenes de trabajo (`WorkOrderResource`).
 *
 * El requisito (Anyerson, 2026-09-01) es que al desplegar esto NADIE note
 * ningún cambio hasta que decida arrastrar: la pantalla tiene que verse
 * EXACTAMENTE igual que hoy, con las OT más nuevas arriba.
 *
 * Por eso el backfill NO sigue `id` ascendente (eso pondría la más VIEJA
 * arriba): ordena por `created_at DESC` — con `id DESC` para desempatar un
 * timestamp repetido — y asigna sort_order ASCENDENTE en ese recorrido, así
 * que la OT más nueva queda en 1 y la más vieja en el número más alto.
 *
 * El motivo de que "más nueva = número más BAJO" (y no al revés, que sería
 * más intuitivo a primera vista) es que Filament, mientras el modo arrastrar
 * está activo, SIEMPRE ordena el reorderColumn ASCENDENTE
 * (`Filament\Tables\Concerns\CanSortRecords::isTableReordering()`), sin
 * importar qué diga `defaultSort()`. Para que la pantalla no "salte" al
 * activar o desactivar el modo arrastrar, `WorkOrderResource::table()` tiene
 * que usar el mismo sentido ascendente siempre — ver ese archivo y
 * `WorkOrder::manualOrderPrepend()` (una OT nueva nace en 1, no al final).
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
            ->orderByDesc('created_at')
            ->orderByDesc('id')
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

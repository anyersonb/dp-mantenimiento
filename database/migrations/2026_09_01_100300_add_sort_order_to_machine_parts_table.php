<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Orden manual del catálogo de repuestos por máquina (`MachineResource\
 * RelationManagers\PartsRelationManager`, sobre `machine_parts`). No existe
 * un Resource maestro aparte: el catálogo vive anidado dentro de cada
 * máquina, así que "reordenar el catálogo" es reordenar esta tabla.
 *
 * Backfill GLOBAL (una sola secuencia creciente por `id`, sin reiniciar por
 * máquina) — a diferencia de `work_order_parts`, que reinicia por OT. Como el
 * RelationManager siempre filtra por la máquina dueña, un valor global sigue
 * dando un orden total correcto dentro de cada subconjunto.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('machine_parts', function (Blueprint $table) {
            $table->unsignedInteger('sort_order')->default(0)->after('detail');
        });

        $sequence = 0;

        DB::table('machine_parts')
            ->orderBy('id')
            ->pluck('id')
            ->each(function ($id) use (&$sequence) {
                $sequence++;

                DB::table('machine_parts')->where('id', $id)->update(['sort_order' => $sequence]);
            });
    }

    public function down(): void
    {
        Schema::table('machine_parts', function (Blueprint $table) {
            $table->dropColumn('sort_order');
        });
    }
};

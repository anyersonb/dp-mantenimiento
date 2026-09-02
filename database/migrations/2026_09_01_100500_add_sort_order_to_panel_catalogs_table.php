<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Orden manual de los catálogos simples del panel: obras (`locations`),
 * tipos de máquina (`machine_categories`) y marcas (`makes`). Se excluyen a
 * propósito ActivityResource/AlertResource (bitácoras, no catálogos) y
 * RoleResource/UserResource (superficie de seguridad, fuera de este lote).
 *
 * Backfill GLOBAL por `id` en cada tabla, igual criterio que
 * `machine_parts`/`work_orders`: no hay agrupación que reiniciar, cada
 * catálogo es una sola lista.
 */
return new class extends Migration
{
    private const TABLES = ['locations', 'machine_categories', 'makes'];

    public function up(): void
    {
        foreach (self::TABLES as $tableName) {
            Schema::table($tableName, function (Blueprint $table) {
                $table->unsignedInteger('sort_order')->default(0);
            });

            $sequence = 0;

            DB::table($tableName)
                ->orderBy('id')
                ->pluck('id')
                ->each(function ($id) use (&$sequence, $tableName) {
                    $sequence++;

                    DB::table($tableName)->where('id', $id)->update(['sort_order' => $sequence]);
                });
        }
    }

    public function down(): void
    {
        foreach (self::TABLES as $tableName) {
            Schema::table($tableName, function (Blueprint $table) {
                $table->dropColumn('sort_order');
            });
        }
    }
};

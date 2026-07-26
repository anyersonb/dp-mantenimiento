<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Borrado suave en `machines` (hallazgo E6-05, critico).
 *
 * El borrado duro destruia la historia completa de la maquina: todas las FK que
 * apuntan a `machines` son ON DELETE CASCADE, asi que se iban con ella sus
 * ordenes de trabajo, sus lecturas, sus alertas, sus partes y —con las OT— los
 * costos. El dialogo solo preguntaba "Are you sure you would like to do this?".
 *
 * Con `deleted_at`, dar de baja una maquina deja de ser destructivo: la fila
 * sobrevive, las OT y los costos siguen consultables, y la cascada de la base
 * de datos no se dispara porque no hay DELETE.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('machines', function (Blueprint $table) {
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::table('machines', function (Blueprint $table) {
            $table->dropSoftDeletes();
        });
    }
};

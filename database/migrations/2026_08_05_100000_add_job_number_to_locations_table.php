<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Número de trabajo de la obra (pedido del cliente, 2026-08-05): cada obra /
 * job site lleva su propio identificador de trabajo y **no se repite**.
 *
 * La columna queda **nullable con índice único**, y el "obligatorio" vive en el
 * formulario (`required()` en LocationResource). No es una contradicción, es una
 * decisión:
 *
 *   - `NOT NULL` exigiría rellenar las obras que ya existen, y el único valor
 *     que yo podría poner es uno inventado. Un número de trabajo falso es peor
 *     que un campo vacío: alguien lo va a usar para facturar o para buscar el
 *     expediente, y no va a existir. El vacío se ve; el inventado no.
 *   - En MySQL y en SQLite un índice único **permite varios NULL**, así que la
 *     unicidad de los números que sí están cargados queda garantizada por la
 *     base, no solo por la validación del formulario.
 *
 * O sea: nadie puede guardar una obra nueva sin número ni repetir uno existente,
 * y las obras viejas quedan visiblemente pendientes hasta que DP pase su número.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('locations', function (Blueprint $table) {
            $table->string('job_number')->nullable()->unique()->after('slug');
        });
    }

    public function down(): void
    {
        Schema::table('locations', function (Blueprint $table) {
            // El índice único se nombra solo (locations_job_number_unique) y hay
            // que soltarlo antes de la columna o MySQL se queja.
            $table->dropUnique(['job_number']);
            $table->dropColumn('job_number');
        });
    }
};

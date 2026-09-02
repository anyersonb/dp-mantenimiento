<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Configuración persistida en base de datos (clave/valor con tipo).
 *
 * Nace del impuesto de repuestos de Florida (2026-09-01): la tasa tiene que
 * ser editable desde el panel y NO puede vivir en `config/`, porque en
 * producción el config cache queda congelado tras el deploy y ya complicó
 * reactivar un módulo entero (flag `features.quotes`, ver
 * App\Support\AccessControl para el mismo problema con permisos).
 *
 * `App\Models\Setting::get()/set()` son el único punto de lectura/escritura;
 * la caché se invalida en el observer, no acá.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('settings', function (Blueprint $table) {
            $table->id();
            $table->string('key')->unique();
            $table->text('value')->nullable();
            $table->string('type')->default('string'); // string|integer|float|boolean|json
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('settings');
    }
};

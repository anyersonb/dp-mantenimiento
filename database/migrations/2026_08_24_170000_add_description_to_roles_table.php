<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Descripción libre por rol (pedido de la clienta 2026-08-24: "al crear cada
 * rol debe poder describir para qué es ese rol").
 *
 * Es nullable a propósito: los siete roles del sistema NO la necesitan cargada,
 * porque su descripción viene traducida de `lang/{es,en}/roles.php` —así se ve
 * en los dos idiomas sin que nadie la escriba dos veces—. Esta columna es para
 * los roles que crea la clienta desde el panel, que no tienen traducción
 * posible. La lectura siempre pasa por Role::descriptionText(), que resuelve
 * primero la traducción y cae a esta columna.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('roles', function (Blueprint $table) {
            $table->text('description')->nullable()->after('name');
        });
    }

    public function down(): void
    {
        Schema::table('roles', function (Blueprint $table) {
            $table->dropColumn('description');
        });
    }
};

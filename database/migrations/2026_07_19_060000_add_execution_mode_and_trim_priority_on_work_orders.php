<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Modalidad de ejecución: en taller o en sitio (on-site).
        Schema::table('work_orders', function (Blueprint $table) {
            $table->enum('execution_mode', ['workshop', 'onsite'])
                ->nullable()
                ->default('workshop')
                ->after('assigned_to');
        });

        // Prioridad pasa de 4 a 3 niveles: se elimina 'low' (Baja).
        DB::statement("UPDATE work_orders SET priority = 'normal' WHERE priority = 'low'");
        DB::statement("ALTER TABLE work_orders MODIFY priority ENUM('normal', 'high', 'urgent') NOT NULL DEFAULT 'normal'");
    }

    public function down(): void
    {
        DB::statement("ALTER TABLE work_orders MODIFY priority ENUM('low', 'normal', 'high', 'urgent') NOT NULL DEFAULT 'normal'");

        Schema::table('work_orders', function (Blueprint $table) {
            $table->dropColumn('execution_mode');
        });
    }
};

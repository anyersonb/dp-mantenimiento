<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Pedido del cliente (2026-09-22): una OT puede quedar asociada a un
     * reporte de campo, pero NUNCA de forma obligatoria — un mantenimiento
     * preventivo por horómetro no nace de ningún reporte. `nullOnDelete()`
     * porque si el reporte de campo se borra, la OT sigue siendo historial
     * válido de mantenimiento; solo pierde el enlace.
     */
    public function up(): void
    {
        Schema::table('work_orders', function (Blueprint $table) {
            $table->foreignId('field_report_id')->nullable()->after('machine_id')
                ->constrained()->nullOnDelete();

            $table->index(['field_report_id']);
        });
    }

    public function down(): void
    {
        Schema::table('work_orders', function (Blueprint $table) {
            $table->dropConstrainedForeignId('field_report_id');
        });
    }
};

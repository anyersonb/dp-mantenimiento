<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A4 — persiste el "ancla verificada" de remaining_hours (regla en
 * qa-etapa05/regla-horometro.md, sec. 5). El PM Service Report (o un evento de
 * reemplazo de horómetro) fija el par (remaining_anchor_hours,
 * remaining_anchor_at_hours); una lectura de campo posterior descuenta desde
 * ahí en vez de recalcular desde cero. Solo se añaden columnas: sin backfill
 * aquí (ver migración de backfill aparte, sin ejecutar).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('machines', function (Blueprint $table) {
            $table->integer('remaining_anchor_hours')->nullable()->after('remaining_hours');
            $table->unsignedInteger('remaining_anchor_at_hours')->nullable()->after('remaining_anchor_hours');
        });
    }

    public function down(): void
    {
        Schema::table('machines', function (Blueprint $table) {
            $table->dropColumn(['remaining_anchor_hours', 'remaining_anchor_at_hours']);
        });
    }
};

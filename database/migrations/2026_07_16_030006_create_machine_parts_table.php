<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('machine_parts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('machine_id')->constrained()->cascadeOnDelete();
            $table->string('category')->nullable();              // oil_filter, fuel_primary, air_inner, hydraulic, ...
            $table->string('label');                             // etiqueta original del info book
            $table->string('oem_number')->nullable();
            $table->string('napa_number')->nullable();
            $table->unsignedInteger('change_interval_hours')->nullable(); // 500/1000/2000/4000
            $table->text('detail')->nullable();                  // texto crudo del renglón
            $table->timestamps();

            $table->index(['machine_id', 'category']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('machine_parts');
    }
};

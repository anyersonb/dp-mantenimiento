<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('machines', function (Blueprint $table) {
            $table->id();
            $table->string('id_code')->unique();                 // EX002, LD009, ...
            $table->foreignId('machine_category_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('make_id')->nullable()->constrained()->nullOnDelete();
            $table->string('model')->nullable();
            $table->string('serial')->nullable();
            $table->string('serial_type')->nullable();           // S/N | PIN | VIN
            $table->smallInteger('year')->nullable();
            $table->text('description')->nullable();             // raw description del PM report
            $table->foreignId('current_location_id')->nullable()->constrained('locations')->nullOnDelete();

            // Estado operativo
            $table->enum('status', ['active', 'not_in_service', 'down', 'inactive', 'unknown'])->default('active');
            $table->enum('hourmeter_status', ['ok', 'broken', 'no_info', 'replaced'])->default('ok');
            $table->integer('hours_adjustment')->default(0);     // p.ej. "Add 5714 to current hrs"

            // Horómetro / servicio
            $table->unsignedInteger('current_hours')->nullable();
            $table->date('current_hours_date')->nullable();
            $table->unsignedInteger('last_service_hours')->nullable();
            $table->date('last_service_date')->nullable();
            $table->unsignedInteger('service_interval_hours')->default(500);
            $table->integer('remaining_hours')->nullable();      // snapshot del PM report (referencia)

            // Ficha técnica (Machinery Info Book)
            $table->string('engine_model')->nullable();
            $table->string('engine_serial')->nullable();
            $table->string('electrical_system')->nullable();
            $table->string('battery_cca')->nullable();
            $table->string('tires')->nullable();
            $table->longText('spec_sheet')->nullable();          // bloque crudo del info book

            // Control de datos
            $table->boolean('needs_review')->default(false);
            $table->string('review_note')->nullable();
            $table->text('notes')->nullable();

            $table->timestamps();

            $table->index(['status']);
            $table->index(['machine_category_id']);
            $table->index(['current_location_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('machines');
    }
};

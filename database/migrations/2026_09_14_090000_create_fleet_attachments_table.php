<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Módulo de Complementos (Attachments) — ver spec-complementos-dp.md.
 *
 * `FleetAttachment` es un registro AUTÓNOMO: no lleva `machine_id`, no hay
 * relación ni historial de montaje con `machines`. Se pareció a propósito a
 * `machines` en su forma (mismos campos de identificación/estado/ficha
 * técnica/imágenes) para que el panel se sienta hermano, pero la tabla en sí
 * no tiene ninguna FK hacia `machines`.
 *
 * `documents` es propio de este módulo (Máquinas no lo tiene): adjuntos como
 * manuales o certificados del complemento, distintos de `image`/`gallery`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fleet_attachments', function (Blueprint $table) {
            $table->id();

            // Identificación
            $table->string('id_code', 50)->unique();
            $table->string('name')->nullable();
            $table->string('type')->nullable();
            $table->foreignId('make_id')->nullable()->constrained()->nullOnDelete();
            $table->string('model')->nullable();
            $table->string('serial')->nullable();
            $table->string('serial_type')->nullable(); // S/N | PIN | VIN
            $table->smallInteger('year')->nullable();
            $table->foreignId('current_location_id')->nullable()->constrained('locations')->nullOnDelete();
            $table->text('description')->nullable();

            // Estado
            $table->enum('status', ['active', 'not_in_service', 'down', 'inactive', 'unknown'])->default('active');
            $table->date('acquisition_date')->nullable();
            $table->string('condition_note')->nullable();

            // Técnico (genérico, texto libre)
            $table->string('weight')->nullable();
            $table->string('dimensions')->nullable();
            $table->string('compatibility')->nullable();
            $table->longText('spec_sheet')->nullable();

            // Imágenes
            $table->string('image')->nullable();
            $table->json('gallery')->nullable();

            // Documentos (propio de este módulo)
            $table->json('documents')->nullable();

            // Control de datos
            $table->boolean('needs_review')->default(false);
            $table->string('review_note')->nullable();
            $table->text('notes')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->index(['status']);
            $table->index(['type']);
            $table->index(['make_id']);
            $table->index(['current_location_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fleet_attachments');
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('alerts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('machine_id')->constrained()->cascadeOnDelete();
            // service = faltan <=100h para el próximo servicio; checklist = alerta desde un checklist
            $table->enum('type', ['service', 'checklist', 'hourmeter', 'other'])->default('service');
            $table->string('title');
            $table->text('message')->nullable();
            $table->integer('remaining_hours')->nullable();
            $table->enum('status', ['open', 'acknowledged', 'resolved'])->default('open');
            $table->foreignId('acknowledged_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('notified_at')->nullable();        // correo enviado a administradores
            $table->timestamps();

            $table->index(['status', 'type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('alerts');
    }
};

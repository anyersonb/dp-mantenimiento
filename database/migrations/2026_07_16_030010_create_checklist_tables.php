<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Checklist ÚNICO para toda la maquinaria (no por marca)
        Schema::create('checklist_templates', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->boolean('active')->default(true);
            $table->timestamps();
        });

        Schema::create('checklist_template_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('checklist_template_id')->constrained()->cascadeOnDelete();
            $table->string('section')->nullable();
            $table->string('label');
            $table->unsignedInteger('sort')->default(0);
            $table->timestamps();
        });

        // Resultado del checklist ejecutado dentro de una orden de trabajo
        Schema::create('checklist_results', function (Blueprint $table) {
            $table->id();
            $table->foreignId('work_order_id')->constrained()->cascadeOnDelete();
            $table->foreignId('checklist_template_item_id')->nullable()->constrained()->nullOnDelete();
            $table->string('label');
            $table->enum('result', ['ok', 'alert', 'na'])->default('ok');
            // Si arroja alerta -> detalle obligatorio explicando el motivo (validado en el form)
            $table->text('alert_detail')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('checklist_results');
        Schema::dropIfExists('checklist_template_items');
        Schema::dropIfExists('checklist_templates');
    }
};

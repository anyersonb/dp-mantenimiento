<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('work_orders', function (Blueprint $table) {
            $table->id();
            $table->string('code')->unique();                    // WO-0001
            $table->foreignId('machine_id')->constrained()->cascadeOnDelete();
            $table->enum('type', ['preventive', 'corrective', 'inspection'])->default('preventive');
            $table->string('service_tier')->nullable();          // 500 / 1000 / 2000 / 4000
            $table->enum('status', ['open', 'assigned', 'in_progress', 'completed', 'cancelled'])->default('open');
            $table->enum('priority', ['low', 'normal', 'high', 'urgent'])->default('normal');

            $table->foreignId('opened_by')->nullable()->constrained('users')->nullOnDelete();       // Responsable
            $table->foreignId('assigned_to')->nullable()->constrained('users')->nullOnDelete();      // Taller/Técnico
            $table->unsignedInteger('hours_at_open')->nullable();

            $table->date('opened_at')->nullable();
            $table->date('completed_at')->nullable();
            $table->decimal('labor_hours', 6, 2)->nullable();    // horas invertidas (aprox.)
            $table->decimal('parts_cost', 10, 2)->nullable();    // solo visible a Taller/Admin/Gerencia
            $table->text('description')->nullable();
            $table->text('resolution')->nullable();
            $table->timestamps();

            $table->index(['status']);
            $table->index(['machine_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('work_orders');
    }
};

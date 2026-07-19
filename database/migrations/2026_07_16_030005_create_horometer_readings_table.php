<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('horometer_readings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('machine_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('hours');
            $table->date('read_at');
            // origen del dato — quién lo capturó (roles de campo / taller / import / manual)
            $table->enum('source', ['fuel', 'maintenance', 'foreman', 'workshop', 'manual', 'import'])->default('import');
            $table->foreignId('recorded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->decimal('gallons', 8, 2)->nullable();        // abastecimiento (operador de cisterna)
            $table->string('note')->nullable();
            $table->boolean('verified')->default(true);          // verificado por administrador
            $table->timestamps();

            $table->index(['machine_id', 'read_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('horometer_readings');
    }
};

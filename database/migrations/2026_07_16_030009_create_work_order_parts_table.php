<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('work_order_parts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('work_order_id')->constrained()->cascadeOnDelete();
            $table->foreignId('machine_part_id')->nullable()->constrained('machine_parts')->nullOnDelete();
            $table->string('part_number')->nullable();
            $table->string('description')->nullable();
            $table->decimal('quantity', 8, 2)->default(1);
            $table->decimal('unit_cost', 10, 2)->nullable();     // para comparar precios de repuestos
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('work_order_parts');
    }
};

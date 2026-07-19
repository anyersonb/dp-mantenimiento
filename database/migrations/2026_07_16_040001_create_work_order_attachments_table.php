<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('work_order_attachments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('work_order_id')->constrained()->cascadeOnDelete();
            $table->enum('type', ['photo', 'invoice'])->default('photo');
            $table->string('path');
            $table->string('original_name')->nullable();
            $table->timestamps();

            $table->index(['work_order_id', 'type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('work_order_attachments');
    }
};

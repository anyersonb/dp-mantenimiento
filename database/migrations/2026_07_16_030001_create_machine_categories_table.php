<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('machine_categories', function (Blueprint $table) {
            $table->id();
            $table->string('name');                 // Excavator, Wheel Loader, ...
            $table->string('prefix')->nullable();    // EX, LD, RL, ...
            $table->string('slug')->unique();
            $table->string('icon')->nullable();      // heroicon name
            $table->unsignedInteger('default_service_interval')->default(500); // hours
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('machine_categories');
    }
};

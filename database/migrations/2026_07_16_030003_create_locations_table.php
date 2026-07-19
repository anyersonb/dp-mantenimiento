<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('locations', function (Blueprint $table) {
            $table->id();
            $table->string('name');                       // Broadview yd, Blount Rd, WPB Yd, ...
            $table->string('slug')->unique();
            $table->enum('type', ['yard', 'jobsite'])->default('jobsite');
            $table->string('address')->nullable();
            $table->decimal('latitude', 10, 7)->nullable();   // geolocalización (Foreman)
            $table->decimal('longitude', 10, 7)->nullable();
            $table->boolean('active')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('locations');
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('machines', function (Blueprint $table) {
            $table->string('oil_capacity')->nullable()->after('tires');   // texto verbatim del info book (galones, tipo, ubicación del tapón)
            $table->string('image')->nullable()->after('spec_sheet');     // path (disk "public") de la imagen principal
            $table->json('gallery')->nullable()->after('image');         // paths (disk "public") de imágenes adicionales
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('machines', function (Blueprint $table) {
            $table->dropColumn(['oil_capacity', 'image', 'gallery']);
        });
    }
};

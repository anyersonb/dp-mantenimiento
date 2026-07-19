<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Administrador adjunta cotizaciones y las comparte por link
        Schema::create('quotes', function (Blueprint $table) {
            $table->id();
            $table->string('title');
            $table->foreignId('machine_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('work_order_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('file_path')->nullable();
            $table->decimal('amount', 12, 2)->nullable();
            $table->string('vendor')->nullable();
            $table->string('share_token', 64)->unique();         // link público compartible
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('quotes');
    }
};

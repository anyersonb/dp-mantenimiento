<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('locale', 5)->default('en')->after('email');   // en | es
            $table->string('phone')->nullable()->after('locale');
            $table->boolean('active')->default(true)->after('phone');
            $table->foreignId('location_id')->nullable()->after('active') // obra asignada (roles de campo)
                ->constrained('locations')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropConstrainedForeignId('location_id');
            $table->dropColumn(['locale', 'phone', 'active']);
        });
    }
};

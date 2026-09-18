<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tabla estándar de notificaciones de Laravel (canal `database`).
 *
 * Es el ÚNICO almacén del módulo de notificaciones (Etapa "Reportes de
 * campo" / notificaciones internas): la campanita de Filament en `/admin` y
 * la bandeja propia de `/field` leen las DOS de esta misma tabla — ver
 * App\Support\Notifications\NotificationRegistry y
 * App\Livewire\Field\NotificationsInbox. No se crea un segundo almacén para
 * la app de campo.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notifications', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('type');
            $table->morphs('notifiable');
            $table->text('data');
            $table->timestamp('read_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notifications');
    }
};

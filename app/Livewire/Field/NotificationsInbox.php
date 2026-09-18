<?php

namespace App\Livewire\Field;

use Illuminate\Support\Facades\Auth;
use Livewire\Component;

/**
 * Bandeja de notificaciones de /field. Lee la MISMA tabla `notifications`
 * que la campanita de /admin (ver App\Support\Notifications\
 * NotificationRegistry) — no hay un segundo almacén para la app de campo.
 *
 * Cualquier persona autenticada en /field puede entrar a ver SUS PROPIAS
 * notificaciones (no depende de un permiso puntual: lo que decide si a
 * alguien le llega algo es el registro de notificaciones, no esta pantalla).
 */
class NotificationsInbox extends Component
{
    public function markAsRead(string $id): void
    {
        Auth::user()->notifications()->where('id', $id)->first()?->markAsRead();
    }

    public function markAllAsRead(): void
    {
        Auth::user()->unreadNotifications->markAsRead();
    }

    public function render()
    {
        $notifications = Auth::user()->notifications()->latest()->limit(50)->get();

        return view('livewire.field.notifications-inbox', [
            'notifications' => $notifications,
        ])->layout('components.layouts.field', ['title' => __('field.notifications_title')]);
    }
}

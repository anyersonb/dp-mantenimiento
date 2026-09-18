<?php

namespace App\Notifications\Contracts;

use Illuminate\Database\Eloquent\Model;

/**
 * Lo único que un evento nuevo del registro tiene que implementar: cómo se ve
 * la notificación para el modelo que la disparó.
 *
 * Ver App\Support\Notifications\NotificationRegistry para el registro y cómo
 * se suma un evento nuevo.
 */
interface BuildsNotificationPayload
{
    /**
     * Array en formato Filament (title/body/icon/color/actions/...), listo
     * para guardarse tal cual en `notifications.data`. Se arma con
     * `Filament\Notifications\Notification::make()->...->getDatabaseMessage()`
     * (no `->send()` ni `->sendToDatabase()`: eso encolaría el envío — ver
     * App\Notifications\SynchronousDatabaseNotification).
     *
     * @return array<string, mixed>
     */
    public function build(Model $subject): array;
}

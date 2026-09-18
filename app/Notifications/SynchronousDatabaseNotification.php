<?php

namespace App\Notifications;

use Illuminate\Notifications\Notification;

/**
 * Entrega un array ya armado (formato Filament: title/body/icon/color/
 * actions/format) por el canal `database`, en el MISMO request.
 *
 * Por qué no `Filament\Notifications\Notification::sendToDatabase()`
 * directo: esa clase envuelve el array en
 * `Filament\Notifications\DatabaseNotification`, que implementa
 * `ShouldQueue`. En este proyecto `QUEUE_CONNECTION=database` en producción
 * (ver `.env`) y no hay un worker de colas corriendo —el hosting de DP no
 * tiene SSH ni proceso persistente (ver `reference_dp_hosting`), solo el cron
 * de `schedule:run` que ya usa `alerts:scan`—. Una notificación en cola ahí
 * se queda para siempre en la tabla `jobs` y nunca llega a `notifications`:
 * un aviso "enviado" que nadie ve nunca, en silencio.
 *
 * Esta clase reutiliza el MISMO array que arma
 * `Filament\Notifications\Notification::getDatabaseMessage()` (lo que
 * renderiza la campanita del panel) pero sin heredar `ShouldQueue`, así que
 * `Illuminate\Notifications\ChannelManager` la entrega de forma síncrona sin
 * importar la conexión de colas configurada.
 *
 * Ver App\Support\Notifications\NotificationRegistry, que es quien arma el
 * array y llama `$user->notify()` con esta clase.
 */
class SynchronousDatabaseNotification extends Notification
{
    /**
     * @param  array<string, mixed>  $data
     */
    public function __construct(private readonly array $data) {}

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['database'];

        // Para sumar correo el día que el cliente lo pida: agregar 'mail'
        // acá y un método toMail() abajo. Es el único sitio que hay que
        // tocar para encender ese canal — nada más de este módulo cambia.
    }

    /**
     * @return array<string, mixed>
     */
    public function toDatabase(object $notifiable): array
    {
        return $this->data;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return $this->data;
    }
}

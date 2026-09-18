<?php

namespace App\Support\Notifications;

use App\Notifications\Contracts\BuildsNotificationPayload;
use App\Notifications\FieldReport\NeedsAttentionNotificationBuilder;
use App\Notifications\SynchronousDatabaseNotification;
use App\Support\AccessControl;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Log;

/**
 * Registro declarativo de notificaciones internas — canal `database`
 * únicamente, cero correo (pedido explícito del cliente: "por ahora que no
 * mande a correos pero sí que exista un módulo de notificaciones para cada
 * persona de acuerdo a su rol").
 *
 * Cada entrada dice tres cosas:
 *
 *   - `permission`: el permiso Spatie que define el destinatario. NUNCA un
 *     nombre de rol — si la clienta crea un rol nuevo mañana con este
 *     permiso, recibe el aviso sin que nadie toque código (mismo principio
 *     que App\Support\AccessControl).
 *   - `builder`: la clase que arma el contenido (título/cuerpo/ícono) para
 *     el modelo que disparó el evento. Ver
 *     App\Notifications\Contracts\BuildsNotificationPayload.
 *   - `surfaces`: informativo — documenta en qué pantalla(s) importa este
 *     aviso (panel / campo / ambas). Las dos leen la MISMA tabla
 *     `notifications`; esto no cambia dónde se guarda, solo documenta la
 *     intención para quien lea el registro.
 *
 * CÓMO SUMAR UN EVENTO NUEVO: agregar una entrada acá + una clase que
 * implemente BuildsNotificationPayload. Nada más. El disparo (desde el
 * Observer que corresponda) llama `NotificationRegistry::dispatch()` con la
 * clave del evento — no hay una tubería nueva por evento.
 */
class NotificationRegistry
{
    /**
     * @var array<string, array{permission: string, builder: class-string<BuildsNotificationPayload>, surfaces: array<int, string>}>
     */
    private const EVENTS = [
        'field_report.needs_attention' => [
            'permission' => 'view_field_reports',
            'builder' => NeedsAttentionNotificationBuilder::class,
            // Hoy los cuatro roles con view_field_reports tienen también
            // access_panel, así que en la práctica esto sale por /admin. Se
            // documenta 'field' igual porque un rol nuevo con este permiso
            // pero sin access_panel lo vería en su bandeja de /field, y el
            // mecanismo ya lo soporta sin cambios.
            'surfaces' => ['panel', 'field'],
        ],
    ];

    /**
     * Notifica a todos los usuarios ACTIVOS que tengan el permiso de este
     * evento, salvo `$excludeUserId` (para que el propio autor del reporte
     * no se notifique a sí mismo).
     */
    public static function dispatch(string $event, Model $subject, ?int $excludeUserId = null): void
    {
        $config = self::EVENTS[$event] ?? null;

        if ($config === null) {
            throw new \InvalidArgumentException("Evento de notificación desconocido: {$event}");
        }

        $recipients = AccessControl::activeUsersWith($config['permission'])
            ->reject(fn ($user) => $excludeUserId !== null && $user->id === $excludeUserId);

        if ($recipients->isEmpty()) {
            // Hallazgo 1 (auditoría 2026-09-18): en la ventana de despliegue por
            // FTP (permiso todavía sin migrar), AccessControl::activeUsersWith()
            // puede devolver cero filas y un aviso crítico se perdía sin dejar
            // rastro. Mismo criterio que la migración
            // 2026_09_17_090100_add_view_field_reports_permission.php cuando un
            // rol de su reparto no existe: se deja constancia en el log en vez
            // de fallar en silencio.
            Log::warning('notification_registry.no_recipients', [
                'event' => $event,
                'subject_type' => $subject::class,
                'subject_id' => $subject->getKey(),
                'permission' => $config['permission'],
            ]);

            return;
        }

        /** @var BuildsNotificationPayload $builder */
        $builder = app($config['builder']);
        $payload = $builder->build($subject);

        foreach ($recipients as $recipient) {
            $recipient->notify(new SynchronousDatabaseNotification($payload));
        }
    }

    /**
     * @return array<int, string>
     */
    public static function events(): array
    {
        return array_keys(self::EVENTS);
    }
}

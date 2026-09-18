<?php

namespace App\Observers;

use App\Models\FieldReport;
use App\Models\Setting;
use App\Support\Notifications\NotificationRegistry;

/**
 * Dispara el aviso de "reporte de campo que necesita atención".
 *
 * Vive en el OBSERVER del modelo y no en `ReportForm::save()` a propósito
 * (mismo criterio que el resto de observers de este archivo): así un reporte
 * creado por cualquier otro camino —un import, un comando de consola, un
 * futuro endpoint de API— también notifica, sin que cada autor tenga que
 * acordarse de llamar a esto.
 *
 * Regla de negocio (pedido del cliente):
 *   - `critical`  -> notifica SIEMPRE.
 *   - `ok`        -> NUNCA notifica (un aviso por cada reporte rutinario
 *                    entrena a la gente a ignorar la campanita).
 *   - `attention` -> notifica SOLO si la Configuración lo tiene encendido
 *                    (ver Setting::get(self::NOTIFY_ON_ATTENTION_KEY)).
 *
 * El propio autor del reporte NUNCA se notifica a sí mismo (se excluye por
 * `reported_by` en el registro).
 */
class FieldReportObserver
{
    /**
     * Clave en `settings` (tipo boolean) que enciende el aviso para
     * reportes en `attention`. Editable desde App\Filament\Pages\Configuration.
     * Por defecto apagado: hasta que alguien lo prenda a propósito, solo lo
     * crítico interrumpe.
     */
    public const NOTIFY_ON_ATTENTION_KEY = 'field_report_notify_on_attention';

    public static function notifiesOnAttention(): bool
    {
        return (bool) Setting::get(self::NOTIFY_ON_ATTENTION_KEY, false);
    }

    public function created(FieldReport $fieldReport): void
    {
        $shouldNotify = match ($fieldReport->condition) {
            'critical' => true,
            'attention' => self::notifiesOnAttention(),
            default => false,
        };

        if (! $shouldNotify) {
            return;
        }

        NotificationRegistry::dispatch(
            'field_report.needs_attention',
            $fieldReport,
            excludeUserId: $fieldReport->reported_by,
        );
    }
}

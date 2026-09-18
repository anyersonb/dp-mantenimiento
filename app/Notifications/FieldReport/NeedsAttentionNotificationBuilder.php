<?php

namespace App\Notifications\FieldReport;

use App\Filament\Resources\FieldReportResource;
use App\Models\FieldReport;
use App\Notifications\Contracts\BuildsNotificationPayload;
use Filament\Notifications\Actions\Action;
use Filament\Notifications\Notification as FilamentNotification;
use Illuminate\Database\Eloquent\Model;

/**
 * Payload del único evento cableado hoy: un reporte de campo en `critical`
 * (siempre) o `attention` (si la Configuración lo enciende) llega a quien
 * tiene `view_field_reports`.
 *
 * Disparado por App\Observers\FieldReportObserver — quien decide SI
 * corresponde notificar (crítico siempre, atención según Setting). Esta
 * clase solo arma el CONTENIDO del aviso.
 */
class NeedsAttentionNotificationBuilder implements BuildsNotificationPayload
{
    /**
     * @param  FieldReport  $subject
     */
    public function build(Model $subject): array
    {
        $isCritical = $subject->condition === 'critical';
        $machineLabel = $subject->machine?->id_code ?? '—';
        $reporterName = $subject->reporter?->name ?? __('field_reports.unknown_reporter');

        $notification = FilamentNotification::make()
            ->title(__(
                $isCritical ? 'field_reports.notification_title_critical' : 'field_reports.notification_title_attention',
                ['machine' => $machineLabel]
            ))
            ->body(__('field_reports.notification_body', ['reporter' => $reporterName]))
            ->icon($isCritical ? 'heroicon-o-exclamation-triangle' : 'heroicon-o-exclamation-circle')
            ->iconColor($isCritical ? 'danger' : 'warning')
            ->actions([
                Action::make('view')
                    ->label(__('field_reports.notification_action'))
                    ->url(FieldReportResource::getUrl('index'))
                    ->markAsRead(),
            ]);

        // Claves propias además de las de Filament: las usa el badge de
        // navegación de FieldReportResource para contar "críticos sin
        // atender" sin depender de un campo de estado que field_reports no
        // tiene — se cuenta por notificaciones NO LEÍDAS de este evento.
        return $notification->getDatabaseMessage() + [
            'event' => 'field_report.needs_attention',
            'condition' => $subject->condition,
            'field_report_id' => $subject->id,
        ];
    }
}

<?php

namespace App\Support;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;

/**
 * Bitácora de la papelera (Lote A): un único punto que registra los tres
 * eventos —enviado a la papelera, restaurado, eliminado definitivamente— con
 * quién lo hizo y cuándo, siguiendo la misma convención clave+parámetros que
 * `App\Support\LocalizedText` ya usa en el resto del proyecto (hallazgo E6-10):
 * se guarda la clave de idioma, nunca la frase ya traducida.
 *
 * Para los modelos que YA tienen `Spatie\Activitylog\Traits\LogsActivity`
 * (Machine, WorkOrder): esa traza ya registra el evento `deleted` de forma
 * automática al mandar a la papelera (un soft delete SIGUE disparando
 * `deleted`), y `restored` en cuanto el modelo también usa `SoftDeletes` —ver
 * `LogsActivity::eventsToBeRecorded()`—. Lo único que esos dos modelos no
 * distinguen por sí solos es la eliminación DEFINITIVA (que también dispara el
 * `deleted` genérico por dentro de `forceDelete()`), así que acá se registra
 * ESE evento explícito para los siete recursos por igual, y se deja pasar el
 * `deleted`/`restored` automático para los que ya lo tenían.
 *
 * Para los modelos que NO tenían el trait (Quote, Location, MachineCategory,
 * Make, User) se registran los tres eventos de punta a punta —ver
 * `App\Models\Concerns\LogsPapeleraActivity`, que es quien llama a esta clase.
 */
class TrashActivityLogger
{
    public static function trashed(Model $record, string $label): void
    {
        static::log($record, 'trashed', 'mgmt.trash_log_trashed', $label);
    }

    public static function restored(Model $record, string $label): void
    {
        static::log($record, 'restored', 'mgmt.trash_log_restored', $label);
    }

    public static function forceDeleted(Model $record, string $label): void
    {
        static::log($record, 'force_deleted', 'mgmt.trash_log_force_deleted', $label);
    }

    private static function log(Model $record, string $event, string $messageKey, string $label): void
    {
        activity()
            ->performedOn($record)
            ->causedBy(Auth::user())
            ->event($event)
            ->log(LocalizedText::of($messageKey, ['label' => $label])->encode());
    }

    /**
     * Corre `$callback` con el logging automático de LogsActivity apagado en
     * `$record`, y lo repone al salir (incluso si `$callback` lanza). Se usa
     * para que `restore()`/`forceDelete()` en un modelo que YA tiene
     * LogsActivity no dupliquen el asiento automático con el explícito de
     * arriba cuando ambos aplicarían al mismo evento.
     */
    public static function withoutModelLogging(Model $record, callable $callback): mixed
    {
        $canToggle = method_exists($record, 'disableLogging') && method_exists($record, 'enableLogging');

        if ($canToggle) {
            $record->disableLogging();
        }

        try {
            return $callback();
        } finally {
            if ($canToggle) {
                $record->enableLogging();
            }
        }
    }
}

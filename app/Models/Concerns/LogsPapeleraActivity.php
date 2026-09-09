<?php

namespace App\Models\Concerns;

use App\Support\TrashActivityLogger;

/**
 * Bitácora de papelera (Lote A) a nivel de modelo: registra "enviado a la
 * papelera", "restaurado" y "eliminado definitivamente" en `activity_log`,
 * con quién y cuándo (ver App\Support\TrashActivityLogger).
 *
 * Requiere `Illuminate\Database\Eloquent\SoftDeletes` en el mismo modelo (los
 * eventos `restored`/`forceDeleted` los define ese trait).
 *
 * Si el modelo YA usa `Spatie\Activitylog\Traits\LogsActivity` (Machine,
 * WorkOrder), ese trait registra 'deleted' y —al combinarse con SoftDeletes—
 * 'restored' automáticamente por su cuenta (ver
 * `LogsActivity::eventsToBeRecorded()`), así que acá se deja pasar para no
 * duplicar el asiento. Lo único que ninguno de los dos caminos distingue es
 * la eliminación DEFINITIVA —`forceDelete()` dispara el mismo `deleted`
 * genérico por dentro—, así que ESE evento se registra siempre, para los
 * siete recursos por igual.
 */
trait LogsPapeleraActivity
{
    protected static function bootLogsPapeleraActivity(): void
    {
        static::deleted(function ($model) {
            if ($model->isForceDeleting()) {
                return;
            }

            if (method_exists($model, 'getActivitylogOptions')) {
                return;
            }

            TrashActivityLogger::trashed($model, $model->papeleraLabel());
        });

        static::restored(function ($model) {
            if (method_exists($model, 'getActivitylogOptions')) {
                return;
            }

            TrashActivityLogger::restored($model, $model->papeleraLabel());
        });

        static::forceDeleted(function ($model) {
            TrashActivityLogger::forceDeleted($model, $model->papeleraLabel());
        });
    }

    /**
     * Cómo identificar este registro en la bitácora ("EX010", "WO-0042",
     * "Cotización #12", el nombre de la obra...).
     */
    abstract public function papeleraLabel(): string;
}

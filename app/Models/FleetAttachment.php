<?php

namespace App\Models;

use App\Models\Concerns\LogsPapeleraActivity;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Storage;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * Complemento de flota (attachment): cucharón, martillo, garra, etc. Registro
 * AUTÓNOMO — a propósito NO tiene `machine_id` ni relación con `Machine`, sin
 * historial de montaje/desmontaje (ver spec-complementos-dp.md, "Decisiones
 * ya tomadas"). Nombrado `FleetAttachment` y no `Attachment` a propósito: ya
 * existe `WorkOrderAttachment` (archivo adjunto de una OT) y el vocabulario
 * se presta a confusión si se comparte el nombre corto.
 */
class FleetAttachment extends Model
{
    use LogsActivity, LogsPapeleraActivity, SoftDeletes;

    public function papeleraLabel(): string
    {
        return (string) $this->id_code;
    }

    /**
     * Cascada manual + borrado de archivos físicos al eliminar DEFINITIVAMENTE
     * (papelera → forceDelete). Copiado del patrón de `Machine::booted()`:
     * vive en el modelo, no en el Resource, para que cualquier camino que
     * llegue al borrado definitivo —panel, comando, tinker— lo dispare igual.
     *
     * A diferencia de Machine, este registro no tiene hijos con FK propia
     * (es independiente, sin OT ni lecturas), así que la única limpieza que
     * hace falta es la de sus propios archivos: `image` y `gallery` viven en
     * disk('public'); `documents` vive en disk('local') (privado) desde el
     * fix del hallazgo Alto de la auditoría post 01e6a24e — antes vivía
     * también en 'public' sin ninguna capa de autorización. Sin esta
     * limpieza quedarían huérfanos y descargables para siempre.
     */
    protected static function booted(): void
    {
        static::forceDeleting(function (FleetAttachment $attachment) {
            if ($attachment->image && Storage::disk('public')->exists($attachment->image)) {
                Storage::disk('public')->delete($attachment->image);
            }

            foreach ((array) $attachment->gallery as $path) {
                if ($path && Storage::disk('public')->exists($path)) {
                    Storage::disk('public')->delete($path);
                }
            }

            foreach ((array) $attachment->documents as $path) {
                if ($path && Storage::disk('local')->exists($path)) {
                    Storage::disk('local')->delete($path);
                }
            }
        });
    }

    // $guarded = [] deja todas las columnas asignables en masa, igual que Machine.
    protected $guarded = [];

    protected $casts = [
        'year' => 'integer',
        'acquisition_date' => 'date',
        'needs_review' => 'boolean',
        'gallery' => 'array',
        'documents' => 'array',
        'document_names' => 'array',
    ];

    /**
     * Mismos 5 valores que `Machine::STATUSES`, en el mismo orden (pedido
     * explícito de la spec: "exactamente los mismos 5 valores").
     */
    public const STATUSES = ['active', 'not_in_service', 'down', 'inactive', 'unknown'];

    /**
     * Tipos de complemento — lista simple y ampliable en una línea. Las
     * etiquetas legibles salen de `lang` (`fleet.attachment_type_bucket`, etc.).
     */
    public const TYPES = [
        'bucket', 'hammer', 'grapple', 'auger', 'broom',
        'ripper', 'fork', 'blade', 'compactor', 'other',
    ];

    public function getActivitylogOptions(): LogOptions
    {
        // `documents` entra a propósito (hallazgo Bajo de la auditoría de
        // seguridad post 01e6a24e): adjuntar o quitar un documento es lo más
        // sensible de este módulo y antes no quedaba ningún rastro.
        return LogOptions::defaults()
            ->logOnly(['id_code', 'status', 'current_location_id', 'documents'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs();
    }

    /* ----------------------- Relations ----------------------- */

    public function make(): BelongsTo
    {
        return $this->belongsTo(Make::class);
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class, 'current_location_id');
    }
}

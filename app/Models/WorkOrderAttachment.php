<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Storage;

class WorkOrderAttachment extends Model
{
    /**
     * SoftDeletes (Papelera, Lote A): mandar un adjunto a la papelera —solo o
     * en cascada con su OT— NO borra el archivo del disco privado. Igual que
     * el resto de la papelera: la fila sobrevive, el archivo se queda donde
     * está, y solo la eliminación DEFINITIVA lo borra de verdad (evento
     * `forceDeleted`, más abajo), y solo DESPUÉS de que el registro se fue.
     */
    use SoftDeletes;

    protected $guarded = [];

    protected static function booted(): void
    {
        static::forceDeleted(function (WorkOrderAttachment $attachment) {
            if ($attachment->path && Storage::disk('local')->exists($attachment->path)) {
                Storage::disk('local')->delete($attachment->path);
            }
        });
    }

    public function workOrder(): BelongsTo
    {
        return $this->belongsTo(WorkOrder::class);
    }
}

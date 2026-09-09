<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class ChecklistResult extends Model
{
    /**
     * SoftDeletes (Papelera, Lote A): ver `App\Models\WorkOrder::booted()` —
     * la OT marca/desmarca sus resultados de checklist EN CASCADA al mandarse
     * a la papelera o restaurarse, porque el `cascadeOnDelete()` de la FK no
     * se dispara con un soft delete.
     */
    use SoftDeletes;

    protected $guarded = [];

    public function workOrder(): BelongsTo
    {
        return $this->belongsTo(WorkOrder::class);
    }

    public function templateItem(): BelongsTo
    {
        return $this->belongsTo(ChecklistTemplateItem::class, 'checklist_template_item_id');
    }
}

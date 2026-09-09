<?php

namespace App\Models;

use App\Models\Concerns\HasManualOrder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class WorkOrderPart extends Model
{
    /**
     * SoftDeletes (Papelera, Lote A): necesario para que `WorkOrder` pueda
     * marcar/desmarcar sus líneas de repuestos EN CASCADA sin que un
     * `cascadeOnDelete()` a nivel de FK (que no se dispara con un soft
     * delete) las deje huérfanas. Ver `App\Models\WorkOrder::booted()`.
     */
    use HasManualOrder, SoftDeletes;

    protected $guarded = [];

    /** El orden manual se reinicia por orden de trabajo, no es global. */
    protected function manualOrderScopeColumn(): ?string
    {
        return 'work_order_id';
    }

    protected $casts = [
        'quantity' => 'decimal:2',
        'unit_cost' => 'decimal:2',
    ];

    public function workOrder(): BelongsTo
    {
        return $this->belongsTo(WorkOrder::class);
    }

    public function machinePart(): BelongsTo
    {
        return $this->belongsTo(MachinePart::class);
    }
}

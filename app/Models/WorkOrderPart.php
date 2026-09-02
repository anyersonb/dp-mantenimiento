<?php

namespace App\Models;

use App\Models\Concerns\HasManualOrder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WorkOrderPart extends Model
{
    use HasManualOrder;

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

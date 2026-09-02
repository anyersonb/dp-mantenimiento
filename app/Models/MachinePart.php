<?php

namespace App\Models;

use App\Models\Concerns\HasManualOrder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MachinePart extends Model
{
    use HasManualOrder;

    protected $guarded = [];

    protected $casts = [
        'change_interval_hours' => 'integer',
    ];

    public function machine(): BelongsTo
    {
        return $this->belongsTo(Machine::class);
    }
}

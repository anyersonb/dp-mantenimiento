<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class HorometerReading extends Model
{
    protected $guarded = [];

    protected $casts = [
        'read_at' => 'date',
        'hours' => 'integer',
        'gallons' => 'decimal:2',
        'verified' => 'boolean',
    ];

    public function machine(): BelongsTo
    {
        return $this->belongsTo(Machine::class);
    }

    public function recorder(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }
}

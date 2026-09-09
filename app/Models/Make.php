<?php

namespace App\Models;

use App\Models\Concerns\HasManualOrder;
use App\Models\Concerns\LogsPapeleraActivity;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Make extends Model
{
    /** SoftDeletes (Papelera, Lote A). `machines.make_id` es `nullOnDelete`, no cascada. */
    use HasManualOrder, LogsPapeleraActivity, SoftDeletes;

    public function papeleraLabel(): string
    {
        return (string) $this->name;
    }

    protected $guarded = [];

    public function machines(): HasMany
    {
        return $this->hasMany(Machine::class);
    }
}

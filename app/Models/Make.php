<?php

namespace App\Models;

use App\Models\Concerns\HasManualOrder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Make extends Model
{
    use HasManualOrder;

    protected $guarded = [];

    public function machines(): HasMany
    {
        return $this->hasMany(Machine::class);
    }
}

<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Make extends Model
{
    protected $guarded = [];

    public function machines(): HasMany
    {
        return $this->hasMany(Machine::class);
    }
}

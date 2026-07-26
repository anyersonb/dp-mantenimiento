<?php

namespace App\Models;

use App\Casts\AsLocalizedText;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Alert extends Model
{
    protected $guarded = [];

    /**
     * Hallazgo E6-10: `title` y `message` se guardaban ya traducidos, así que el
     * idioma de la alerta quedaba congelado en el del que la disparó. Las 6
     * alertas de la base están en inglés y el administrador trabaja en español.
     * Ahora se guarda clave + parámetros y se renderiza en el idioma del que lee.
     */
    protected $casts = [
        'notified_at' => 'datetime',
        'remaining_hours' => 'integer',
        'title' => AsLocalizedText::class,
        'message' => AsLocalizedText::class,
    ];

    public function machine(): BelongsTo
    {
        return $this->belongsTo(Machine::class);
    }
}

<?php

namespace App\Models;

use App\Models\Concerns\LogsPapeleraActivity;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class Quote extends Model
{
    /**
     * SoftDeletes (Papelera, Lote A). Mandar una cotización a la papelera
     * apaga su link público al toque (el scope excluye la fila de
     * `Quote::where('share_token', $token)->firstOrFail()` en routes/web.php,
     * sin tocar esa ruta) y NO borra el archivo del disco privado — solo la
     * eliminación definitiva lo hace, y solo después de que el registro se fue.
     */
    use LogsPapeleraActivity, SoftDeletes;

    public function papeleraLabel(): string
    {
        return (string) $this->title;
    }

    protected $guarded = [];

    protected $casts = [
        'amount' => 'decimal:2',
        'expires_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::creating(function (Quote $quote) {
            $quote->share_token ??= Str::random(48);
        });

        static::forceDeleted(function (Quote $quote) {
            if ($quote->file_path && Storage::disk('local')->exists($quote->file_path)) {
                Storage::disk('local')->delete($quote->file_path);
            }
        });
    }

    public function machine(): BelongsTo
    {
        return $this->belongsTo(Machine::class);
    }

    public function workOrder(): BelongsTo
    {
        return $this->belongsTo(WorkOrder::class);
    }

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    public function getShareUrlAttribute(): string
    {
        return url('/quotes/'.$this->share_token);
    }
}

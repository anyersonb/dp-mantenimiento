<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

class WorkOrder extends Model
{
    use LogsActivity;

    /**
     * Estados en los que la OT ya no admite cambios destructivos: el trabajo
     * terminó (o se canceló) y sus adjuntos, checklist y repuestos son el
     * respaldo de lo que se hizo y de lo que se cobró.
     *
     * Única definición de "cerrada" del sistema. Ver el trait
     * App\Filament\Concerns\DeletesOnlyWhileWorkOrderIsOpen.
     */
    public const CLOSED_STATUSES = ['completed', 'cancelled'];

    protected $guarded = [];

    protected $casts = [
        'opened_at' => 'date',
        'completed_at' => 'date',
        'labor_hours' => 'decimal:2',
        'parts_cost' => 'decimal:2',
    ];

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['code', 'status', 'assigned_to', 'completed_by', 'parts_cost'])
            ->logOnlyDirty();
    }

    /**
     * Prefijo de los códigos que genera el sistema. Los tecleados a mano
     * pueden ser cualquier cosa (`QA-OT-01`, `OT-CLIENTE-7`); esos no entran en
     * la numeración automática.
     */
    public const CODE_PREFIX = 'WO-';

    /**
     * Siguiente código libre, garantizado libre.
     *
     * Hallazgo E6-09: antes esto era `'WO-'.str_pad(max('id') + 1, 4, '0')`, y
     * eso está mal por dos motivos medidos en la Sesión 1:
     *
     *   - `max(id) + 1` **no es el id que va a tener la fila**: con `max(id)=14`
     *     la propuesta fue `WO-0015` y la fila quedó con id 16. El número no
     *     identificaba nada.
     *   - **no estaba garantizado libre**: alcanza que alguien haya tecleado a
     *     mano un código con el mismo patrón —que es el patrón del cliente—
     *     para que la propuesta choque. Y al chocar caía en E6-07: un 500 mudo.
     *
     * Ahora se numera sobre el sufijo de los códigos existentes (no sobre el id)
     * y se avanza hasta encontrar uno que no exista. La concurrencia real la
     * cubren la validación `->unique()` del formulario y el reintento de quien
     * crea sin formulario.
     */
    public static function nextCode(): string
    {
        $ultimo = static::query()
            ->where('code', 'like', self::CODE_PREFIX.'%')
            ->selectRaw('MAX(CAST(SUBSTRING(code, '.(strlen(self::CODE_PREFIX) + 1).') AS UNSIGNED)) as n')
            ->value('n');

        $numero = (int) $ultimo;

        do {
            $numero++;
            $candidato = self::CODE_PREFIX.str_pad((string) $numero, 4, '0', STR_PAD_LEFT);
        } while (static::query()->where('code', $candidato)->exists());

        return $candidato;
    }

    public function isClosed(): bool
    {
        return in_array($this->status, self::CLOSED_STATUSES, true);
    }

    public function isOpen(): bool
    {
        return ! $this->isClosed();
    }

    public function machine(): BelongsTo
    {
        return $this->belongsTo(Machine::class);
    }

    public function opener(): BelongsTo
    {
        return $this->belongsTo(User::class, 'opened_by');
    }

    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    /**
     * Quién ejecutó el cierre de la OT. No confundir con `assignee()`: ver el
     * comentario de App\Observers\WorkOrderObserver::saving().
     */
    public function completer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'completed_by');
    }

    /**
     * La obra donde se hizo el trabajo, congelada al abrir la OT. No es lo mismo
     * que `machine->location`, que es dónde está la máquina ahora.
     */
    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }

    public function parts(): HasMany
    {
        return $this->hasMany(WorkOrderPart::class);
    }

    public function checklistResults(): HasMany
    {
        return $this->hasMany(ChecklistResult::class);
    }

    public function attachments(): HasMany
    {
        return $this->hasMany(WorkOrderAttachment::class);
    }
}

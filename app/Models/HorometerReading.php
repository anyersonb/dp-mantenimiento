<?php

namespace App\Models;

use App\Casts\AsLocalizedText;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

class HorometerReading extends Model
{
    use LogsActivity;

    protected $guarded = [];

    protected $casts = [
        'read_at' => 'date',
        'hours' => 'integer',
        'gallons' => 'decimal:2',
        'verified' => 'boolean',
        // Hallazgo E6-10: la nota que escribe el SISTEMA (el cierre de una OT,
        // el importador del PM report) se guardaba ya traducida. Las que
        // escribe una persona pasan de largo sin tocarse: no son traducibles.
        'note' => AsLocalizedText::class,
    ];

    /**
     * Hallazgo E6-04 (Etapa 06): editar o borrar una lectura de horómetro a
     * mano NO dejaba ningún rastro. Ni el valor anterior: no había asiento.
     * Verificado contando `activity_log` antes y después de tres operaciones
     * reales desde el panel, con resultado cero en las tres.
     *
     * Importa porque las lecturas son el dato del que dependen
     * `remaining_hours`, el ancla del PM report y las alertas de servicio: si
     * un servicio se adelanta o se atrasa porque alguien tocó una lectura,
     * había que poder reconstruir por qué, quién y desde qué valor.
     *
     * `logAll()` guarda el bloque `old` completo en el update, así que el
     * asiento conserva el valor anterior de horas, fecha, origen y nota — que
     * es exactamente lo que faltaba.
     *
     * **Dónde leer el valor anterior según el evento** (`LogsActivity.php:329-331`
     * de Spatie): en el borrado, Spatie **mueve** `attributes` a `old` y borra
     * `attributes`. O sea que un asiento de `deleted` guarda lo que había en
     * `properties['old']`, no en `properties['attributes']`. Vale anotarlo: el
     * test se escribió primero contra `attributes` y fallaba con
     * `Undefined array key`, y la conclusión apresurada fue culpar a
     * `logOnlyDirty()`, que no tenía nada que ver.
     */
    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logAll()
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs();
    }

    public function machine(): BelongsTo
    {
        return $this->belongsTo(Machine::class);
    }

    public function recorder(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }
}

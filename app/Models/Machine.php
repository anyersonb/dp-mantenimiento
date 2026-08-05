<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

class Machine extends Model
{
    /**
     * SoftDeletes desde el hallazgo E6-05 (crítico): el borrado duro destruía
     * en cascada las OT, las lecturas, las alertas, las partes y —con las OT—
     * los costos históricos de la máquina. Con `deleted_at` la fila sobrevive y
     * la cascada de la base no se dispara, porque no hay DELETE.
     *
     * El camino real de baja NO es borrar: es `status = 'inactive'`, o la
     * acción "descartar" para las máquinas en revisión.
     */
    use LogsActivity, SoftDeletes;

    // $guarded = [] ya deja todas las columnas (incluidas oil_capacity/image/gallery)
    // asignables en masa; no se define $fillable aparte para no restringir el resto
    // de altas/ediciones existentes (FleetSeeder, MachineResource, etc.) a una sola lista.
    protected $guarded = [];

    protected $casts = [
        'current_hours_date' => 'date',
        'last_service_date' => 'date',
        // Frontera de escala del horómetro (E6-13): las lecturas anteriores a
        // esta fecha son de la escala vieja y no cuentan para el recálculo.
        'hours_scale_since' => 'date',
        'needs_review' => 'boolean',
        'current_hours' => 'integer',
        'last_service_hours' => 'integer',
        'service_interval_hours' => 'integer',
        'remaining_hours' => 'integer',
        'remaining_anchor_hours' => 'integer',
        'remaining_anchor_at_hours' => 'integer',
        'hours_adjustment' => 'integer',
        'gallery' => 'array',
    ];

    // Umbral de alerta: faltan <= 100 h para el próximo servicio
    public const ALERT_THRESHOLD = 100;

    /**
     * Los estados posibles de una máquina, en el mismo orden y con los mismos
     * valores que el enum de la columna `machines.status`.
     *
     * **Existe porque la lista ya se desincronizó dos veces.** Encontrado el
     * 2026-08-05 armando el reporte por categoría: el enum tiene cinco estados y
     * tanto el filtro de la tabla de máquinas como el primer borrador del reporte
     * enumeraban solo cuatro, dejando `unknown` afuera. En la flota real eso son
     * **29 máquinas de 99**: el filtro no podía encontrarlas y el desglose del
     * reporte sumaba 72 sobre un total de 101 sin avisar de nada.
     *
     * Toda pantalla que ofrezca estados o los desglose lee de acá, y
     * `MachineStatusOptionsAreCompleteTest` compara esta constante contra el enum
     * real de la base para que un estado nuevo no vuelva a quedarse sin pantalla.
     */
    public const STATUSES = ['active', 'not_in_service', 'down', 'inactive', 'unknown'];

    /**
     * Recuento de lo que se destruiría si esta máquina se borrara de verdad.
     *
     * Alimenta el diálogo de confirmación (hallazgo E6-05): todas las FK que
     * apuntan a `machines` son ON DELETE CASCADE, así que un borrado duro se
     * lleva la historia entera y el diálogo anterior no lo decía.
     *
     * @return array<string, int>
     */
    public function destructionSummary(): array
    {
        return [
            'work_orders' => $this->workOrders()->count(),
            'readings' => $this->readings()->count(),
            'alerts' => $this->alerts()->count(),
            'parts' => $this->parts()->count(),
        ];
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['id_code', 'status', 'current_hours', 'current_location_id', 'last_service_hours'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs();
    }

    /* ----------------------- Relations ----------------------- */

    public function category(): BelongsTo
    {
        return $this->belongsTo(MachineCategory::class, 'machine_category_id');
    }

    public function make(): BelongsTo
    {
        return $this->belongsTo(Make::class);
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class, 'current_location_id');
    }

    public function readings(): HasMany
    {
        return $this->hasMany(HorometerReading::class)->latest('read_at');
    }

    public function parts(): HasMany
    {
        return $this->hasMany(MachinePart::class);
    }

    public function workOrders(): HasMany
    {
        return $this->hasMany(WorkOrder::class);
    }

    public function alerts(): HasMany
    {
        return $this->hasMany(Alert::class);
    }

    public function fieldReports(): HasMany
    {
        return $this->hasMany(FieldReport::class);
    }

    /* --------------------- Computed logic --------------------- */

    /**
     * Horas restantes al próximo servicio, calculadas en vivo cuando hay datos;
     * si no, cae al snapshot importado del PM report.
     */
    public function getComputedRemainingHoursAttribute(): ?int
    {
        // Prioriza el valor del PM Service Report (verificado a mano por el equipo DP).
        // Se recalcula en vivo solo cuando el reporte no trajo el dato pero sí hay lecturas.
        if ($this->remaining_hours !== null) {
            return $this->remaining_hours;
        }

        return $this->calculateRemainingHours();
    }

    /**
     * Única implementación de la regla de remaining_hours (regla-horometro.md,
     * sec. 2.1 — hallazgo A4). HorometerReadingObserver y
     * getComputedRemainingHoursAttribute() llaman aquí; no debe existir una
     * tercera copia de esta fórmula en ningún otro lado.
     *
     * 1. Un horómetro roto o sin información nunca publica un valor calculado.
     * 2. Con ancla verificada (fijada por el PM Service Report o por un evento
     *    de reemplazo), se descuenta desde ahí: nunca se recalcula desde cero.
     * 3. Sin ancla, cae al cálculo clásico, y solo si es válido (misma escala:
     *    last_service_hours <= current_hours).
     * 4. Si nada de lo anterior aplica, NULL ("desconocido"): nunca un número
     *    inventado.
     *
     * `hours_adjustment` no entra en esta fórmula (describe horas reales
     * acumuladas para reventa/garantías, no la ventana de servicio).
     */
    public function calculateRemainingHours(): ?int
    {
        if (in_array($this->hourmeter_status, ['broken', 'no_info'], true)) {
            return null;
        }

        if (
            $this->current_hours !== null
            && $this->remaining_anchor_hours !== null
            && $this->remaining_anchor_at_hours !== null
        ) {
            return $this->remaining_anchor_hours - ($this->current_hours - $this->remaining_anchor_at_hours);
        }

        if (
            $this->current_hours !== null
            && $this->last_service_hours !== null
            && $this->last_service_hours <= $this->current_hours
        ) {
            $used = $this->current_hours - $this->last_service_hours;

            return $this->service_interval_hours - $used;
        }

        return null;
    }

    /**
     * RECÁLCULO COMPLETO de `current_hours` y `remaining_hours` desde el ancla
     * más las lecturas sobrevivientes. Nada incremental.
     *
     * Nace de los hallazgos E6-01 y E6-02 (Etapa 06): el observer solo
     * implementaba `created()` y hacía una actualización incremental
     * (`if reading > current`), así que editar o borrar una lectura desde el
     * panel dejaba la máquina apuntando a un valor que ya no tenía respaldo —
     * en el caso del borrado, a una lectura inexistente.
     *
     * Reglas, en este orden:
     *
     * 1. Solo cuentan las lecturas de la ESCALA VIGENTE. Si hubo un reemplazo
     *    de horómetro (`hours_scale_since`), las anteriores quedan fuera: el
     *    contador arrancó de nuevo y no son comparables (sec. 2.2).
     * 2. `current_hours` = la lectura más alta de esa escala, **nunca por
     *    debajo del ancla verificada** (`remaining_anchor_at_hours`). El ancla
     *    la fijó el PM Service Report o un reemplazo, a mano, y no se
     *    desmiente por una lectura borrada.
     * 3. Sin lecturas en la escala vigente, `current_hours` **no se toca**: 34
     *    máquinas reales no tienen ninguna lectura y su valor viene del reporte.
     *    Recalcular a cero sería destruir dato verificado.
     * 4. `remaining_hours` sale siempre de `calculateRemainingHours()`, que
     *    sigue siendo la única implementación de la regla.
     *
     * `$allowLowering` distingue las dos situaciones que parecen la misma y no
     * lo son:
     *
     *   - **Alta de una lectura** (`false`): se está AGREGANDO evidencia. Una
     *     lectura más baja que el valor de la máquina no puede bajarlo, porque
     *     ese valor puede venir del PM Service Report —verificado a mano— y
     *     ninguna lectura nueva lo desmiente. Es la tolerancia que el
     *     importador ya dependía y que `AlertsEngineTest` protege.
     *   - **Edición o borrado** (`true`): se está QUITANDO o cambiando la
     *     evidencia que sostenía el valor, así que la máquina tiene que poder
     *     bajar. Sin esto, borrar la última lectura deja el horómetro huérfano,
     *     que es el hallazgo E6-02.
     *
     * @return bool true si algo cambió (y por lo tanto se guardó).
     */
    public function recalculateHoursFromReadings(bool $allowLowering = false): bool
    {
        $nuevos = $this->computeHoursFromReadings($allowLowering);

        $this->current_hours = $nuevos['current_hours'];
        $this->current_hours_date = $nuevos['current_hours_date'];
        $this->remaining_hours = $nuevos['remaining_hours'];

        if (! $this->isDirty(['current_hours', 'current_hours_date', 'remaining_hours'])) {
            return false;
        }

        $this->save();

        return true;
    }

    /**
     * La regla, sin efectos: calcula y devuelve, no guarda. Existe para que el
     * comando de reparación pueda simular sin escribir usando ESTA misma
     * implementación y no una copia — la duplicación de la fórmula ya causó el
     * hallazgo A4 una vez.
     *
     * @return array{current_hours: int|null, current_hours_date: mixed, remaining_hours: int|null}
     */
    public function computeHoursFromReadings(bool $allowLowering = false): array
    {
        $readings = $this->readings()
            ->when($this->hours_scale_since, fn ($q) => $q->where('read_at', '>=', $this->hours_scale_since))
            ->orderByDesc('hours')
            ->orderByDesc('read_at')
            ->get(['hours', 'read_at']);

        $highest = $readings->first();

        $current = $this->current_hours;
        $date = $this->current_hours_date;

        if ($highest !== null) {
            $anchorFloor = $this->remaining_anchor_at_hours;

            if (! $allowLowering && $current !== null && $current > $highest->hours) {
                // Alta de una lectura más baja: no baja nada.
                $anchorFloor = max((int) $anchorFloor, (int) $current);
            }

            if ($anchorFloor !== null && $anchorFloor > $highest->hours) {
                // El ancla verificada manda: se conserva su valor y la fecha
                // que ya tenía la máquina, no la de una lectura más baja.
                $current = $anchorFloor;
            } else {
                $current = $highest->hours;
                $date = $highest->read_at;
            }
        } elseif ($this->remaining_anchor_at_hours !== null) {
            // Sin lecturas pero con ancla: se cae al valor verificado en vez de
            // quedarse con el de una lectura borrada, que es el defecto E6-02
            // repitiéndose en el último paso. Se conserva la fecha existente
            // porque el ancla no trae una.
            //
            // Verificado contra la base real antes de escribir esto: de las 34
            // máquinas sin ninguna lectura, CERO tienen ancla, así que esta
            // rama no puede alterar ningún valor de producción.
            $current = $this->remaining_anchor_at_hours;
        }

        // remaining_hours sale de calculateRemainingHours() —única
        // implementación de la fórmula— evaluada con el current candidato, en un
        // espejo, para no ensuciar el modelo cuando esto es una simulación.
        $espejo = clone $this;
        $espejo->current_hours = $current;
        $restantes = $espejo->calculateRemainingHours();

        // Un número no se convierte en NULL por un recálculo que agrega
        // evidencia. Hay máquinas cuyo `remaining_hours` viene del PM Service
        // Report y la regla no puede reproducirlo porque falta el ancla o falta
        // la lectura: MS-TEMP-01 (horómetro reemplazado, sin ancla que relacione
        // las dos escalas) y RL017 (sin ninguna lectura), las dos con 500 h que
        // **el reporte del cliente escribe explícitamente**. Vaciarlas sería
        // borrar un dato verificado y dejar la pantalla en "—".
        //
        // Al editar o borrar una lectura (`allowLowering = true`) sí se permite,
        // porque ahí se está quitando la evidencia que sostenía el valor.
        if (! $allowLowering && $restantes === null && $this->remaining_hours !== null) {
            $restantes = $this->remaining_hours;
        }

        return [
            'current_hours' => $current,
            'current_hours_date' => $date,
            'remaining_hours' => $restantes,
        ];
    }

    public function getIsDueSoonAttribute(): bool
    {
        $r = $this->computed_remaining_hours;

        return $r !== null && $r <= self::ALERT_THRESHOLD;
    }

    public function getIsOverdueAttribute(): bool
    {
        $r = $this->computed_remaining_hours;

        return $r !== null && $r <= 0;
    }

    /** Semáforo para la UI. */
    public function getServiceStatusAttribute(): string
    {
        if (! $this->isOperational()) {
            return 'inactive';
        }
        $r = $this->computed_remaining_hours;
        if ($r === null) {
            return 'unknown';
        }
        if ($r <= 0) {
            return 'overdue';
        }
        if ($r <= self::ALERT_THRESHOLD) {
            return 'due_soon';
        }

        return 'ok';
    }

    public function isOperational(): bool
    {
        return $this->status === 'active';
    }

    /* --------------------- Management (Stage 04) --------------------- */

    /**
     * Costo acumulado de mantenimiento (repuestos de OTs completadas).
     * No incluye labor_hours porque el proyecto no tiene tarifa/hora definida.
     */
    public function getMaintenanceCostAttribute(): float
    {
        return (float) $this->workOrders()
            ->where('status', 'completed')
            ->sum('parts_cost');
    }

    /** Número de OTs completadas para esta máquina. */
    public function getServiceCountAttribute(): int
    {
        return $this->workOrders()->where('status', 'completed')->count();
    }
}

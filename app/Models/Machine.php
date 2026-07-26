<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

class Machine extends Model
{
    use LogsActivity;

    // $guarded = [] ya deja todas las columnas (incluidas oil_capacity/image/gallery)
    // asignables en masa; no se define $fillable aparte para no restringir el resto
    // de altas/ediciones existentes (FleetSeeder, MachineResource, etc.) a una sola lista.
    protected $guarded = [];

    protected $casts = [
        'current_hours_date' => 'date',
        'last_service_date' => 'date',
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

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
        if ($this->current_hours !== null && $this->last_service_hours !== null) {
            $used = ($this->current_hours + $this->hours_adjustment) - $this->last_service_hours;

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

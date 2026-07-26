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
            ->logOnly(['code', 'status', 'assigned_to', 'parts_cost'])
            ->logOnlyDirty();
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

<?php

namespace App\Services;

use App\Models\Alert;
use App\Models\HorometerReading;
use App\Models\WorkOrder;

class WorkOrderCompletionService
{
    /**
     * Al completar una OT preventiva:
     *  - la máquina reinicia su ciclo de servicio (last_service_hours/date, remaining_hours)
     *  - se resuelven las alertas de servicio abiertas de esa máquina
     *  - se registra una lectura de horómetro (source=workshop) con las horas de cierre
     *
     * Idempotente: puede llamarse más de una vez sin generar efectos duplicados
     * relevantes (los valores de máquina se recalculan al mismo resultado y las
     * alertas ya resueltas simplemente no cambian).
     */
    public static function complete(WorkOrder $workOrder): void
    {
        $machine = $workOrder->machine;

        if (! $machine || $workOrder->type !== 'preventive') {
            return;
        }

        $hours = $machine->current_hours ?? $workOrder->hours_at_open;
        $serviceDate = $workOrder->completed_at ?? now()->toDateString();

        if ($hours !== null) {
            $machine->last_service_hours = $hours;
        }
        $machine->last_service_date = $serviceDate;
        // El servicio reinicia el ciclo: horas usadas = 0.
        $machine->remaining_hours = $machine->service_interval_hours;
        $machine->save();

        Alert::query()
            ->where('machine_id', $machine->id)
            ->where('type', 'service')
            ->where('status', 'open')
            ->update(['status' => 'resolved']);

        if ($hours === null) {
            return;
        }

        // Evita duplicar la lectura si complete() se invoca más de una vez para la misma OT
        // (p. ej. acción "Completar" + guardado posterior del formulario con status=completed).
        $alreadyLogged = HorometerReading::query()
            ->where('machine_id', $machine->id)
            ->where('source', 'workshop')
            ->where('note', __('wo.service_reset_note', ['code' => $workOrder->code]))
            ->exists();

        if ($alreadyLogged) {
            return;
        }

        HorometerReading::create([
            'machine_id' => $machine->id,
            'hours' => $hours,
            'read_at' => $serviceDate,
            'source' => 'workshop',
            'recorded_by' => $workOrder->assigned_to,
            'verified' => true,
            'note' => __('wo.service_reset_note', ['code' => $workOrder->code]),
        ]);
    }
}

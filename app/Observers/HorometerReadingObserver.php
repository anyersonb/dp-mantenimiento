<?php

namespace App\Observers;

use App\Models\Alert;
use App\Models\HorometerReading;
use App\Models\Machine;

class HorometerReadingObserver
{
    /**
     * Cada vez que se registra una lectura de horómetro (combustible, reporte de
     * campo, foreman, taller o carga manual) se recalcula el estado de la máquina
     * y, si corresponde, se dispara una alerta de servicio.
     */
    public function created(HorometerReading $reading): void
    {
        $machine = $reading->machine;

        if (! $machine) {
            return;
        }

        $isNewerReading = $machine->current_hours === null || $reading->hours > $machine->current_hours;

        if (! $isNewerReading) {
            return;
        }

        $machine->current_hours = $reading->hours;
        $machine->current_hours_date = $reading->read_at;

        if ($machine->last_service_hours !== null) {
            $used = ($machine->current_hours + $machine->hours_adjustment) - $machine->last_service_hours;
            $machine->remaining_hours = $machine->service_interval_hours - $used;
        }

        $machine->save();

        $this->maybeRaiseServiceAlert($machine);
    }

    protected function maybeRaiseServiceAlert(Machine $machine): void
    {
        if ($machine->remaining_hours === null || $machine->remaining_hours > Machine::ALERT_THRESHOLD) {
            return;
        }

        $hasOpenAlert = Alert::query()
            ->where('machine_id', $machine->id)
            ->where('type', 'service')
            ->where('status', 'open')
            ->exists();

        if ($hasOpenAlert) {
            return;
        }

        Alert::create([
            'machine_id' => $machine->id,
            'type' => 'service',
            'title' => __('alerts.auto_title', ['machine' => $machine->id_code]),
            'message' => __('alerts.auto_message', [
                'machine' => $machine->id_code,
                'hours' => $machine->remaining_hours,
            ]),
            'remaining_hours' => $machine->remaining_hours,
            'status' => 'open',
        ]);
    }
}

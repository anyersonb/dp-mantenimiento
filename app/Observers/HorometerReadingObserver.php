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
     *
     * Lecturas menores a la actual se toleran en silencio aquí (no tocan la
     * máquina): el importador del PM Service Report depende de este descarte
     * silencioso porque el Excel del cliente trae filas desordenadas. El
     * rechazo explícito al usuario (hallazgo M4) vive en los componentes
     * Livewire de app/Livewire/Field/, que validan ANTES de crear el registro.
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
        $machine->remaining_hours = $this->calculateRemaining($machine);

        $machine->save();

        $this->maybeRaiseServiceAlert($machine);
    }

    /**
     * Regla del ancla y descuento (qa-etapa05/regla-horometro.md, sec. 2.1):
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
     * acumuladas para reventa/garantías, no la ventana de servicio; aplicarlo
     * de un solo lado era el defecto original).
     */
    protected function calculateRemaining(Machine $machine): ?int
    {
        if (in_array($machine->hourmeter_status, ['broken', 'no_info'], true)) {
            return null;
        }

        if ($machine->remaining_anchor_hours !== null && $machine->remaining_anchor_at_hours !== null) {
            return $machine->remaining_anchor_hours - ($machine->current_hours - $machine->remaining_anchor_at_hours);
        }

        if ($machine->last_service_hours !== null && $machine->last_service_hours <= $machine->current_hours) {
            $used = $machine->current_hours - $machine->last_service_hours;

            return $machine->service_interval_hours - $used;
        }

        return null;
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

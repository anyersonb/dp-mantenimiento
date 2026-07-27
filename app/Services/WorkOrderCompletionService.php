<?php

namespace App\Services;

use App\Exceptions\CannotCompleteWorkOrder;
use App\Models\Alert;
use App\Models\HorometerReading;
use App\Models\WorkOrder;
use App\Rules\CoherentHorometerReading;
use App\Support\LocalizedText;

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

        $hours = static::serviceHours($workOrder);
        $serviceDate = $workOrder->completed_at ?? now()->toDateString();

        // Hallazgo E6-08. Sin horas no hay cierre: reiniciar el ciclo sin
        // registrar a qué horas se hizo el servicio deja a la máquina
        // declarándose "recién servida" sobre nada. Antes se seguía adelante en
        // silencio; ahora se rechaza y el usuario recibe el motivo.
        if ($hours === null) {
            throw CannotCompleteWorkOrder::withoutHours($workOrder);
        }

        $machine->last_service_hours = $hours;
        $machine->last_service_date = $serviceDate;
        // El servicio reinicia el ciclo: horas usadas = 0.
        $machine->remaining_hours = $machine->service_interval_hours;
        $machine->save();

        Alert::query()
            ->where('machine_id', $machine->id)
            ->where('type', 'service')
            ->where('status', 'open')
            ->update(['status' => 'resolved']);

        // Evita duplicar la lectura si complete() se invoca más de una vez para la misma OT
        // (p. ej. acción "Completar" + guardado posterior del formulario con status=completed).
        //
        // La identidad NO puede ser el texto de la nota: desde E6-10 la nota se
        // guarda como clave + parámetros, y comparar contra la frase traducida
        // dejaría de encontrarla en cuanto cambiara el idioma del que cierra la
        // OT, duplicando la lectura. Se compara por lo que de verdad define "la
        // misma lectura de cierre": misma máquina, mismo origen, misma fecha y
        // las mismas horas.
        // Hallazgo E6-13, sexto camino de escritura de horómetro. Hoy estas horas
        // salen de `current_hours`, que ya es el máximo de las lecturas, así que
        // no deberían poder contradecir el historial. Pero "no debería poder" es
        // exactamente el argumento que dejó pasar A8: se consulta la MISMA regla
        // compartida y, si algún día la premisa cambia (una escala nueva, un
        // `hours_at_open` cargado a mano por debajo del historial), el cierre se
        // rechaza con motivo en vez de escribir una lectura imposible.
        $problema = CoherentHorometerReading::problem($machine, $hours, (string) $serviceDate);

        if ($problema !== null) {
            throw CannotCompleteWorkOrder::withIncoherentHours(
                $workOrder,
                $hours,
                __($problema['key'], $problema['params']),
            );
        }

        $alreadyLogged = HorometerReading::query()
            ->where('machine_id', $machine->id)
            ->where('source', 'workshop')
            ->whereDate('read_at', $serviceDate)
            ->where('hours', $hours)
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
            'note' => LocalizedText::of('wo.service_reset_note', ['code' => $workOrder->code]),
        ]);
    }

    /**
     * Las horas con las que se cierra el servicio, o null si no hay ninguna
     * fuente. Es una sola implementación para que la UI pueda preguntar ANTES
     * de tocar el estado de la OT y `complete()` decidir con lo mismo: si la
     * pregunta y la ejecución usaran fórmulas distintas volveríamos al vicio
     * que ya arreglamos en el comando de recálculo.
     */
    public static function serviceHours(WorkOrder $workOrder): ?int
    {
        $horas = $workOrder->machine?->current_hours ?? $workOrder->hours_at_open;

        return $horas === null ? null : (int) $horas;
    }

    /**
     * ¿Se puede completar esta OT? Solo las preventivas exigen horas: una
     * correctiva o una inspección no reinician ningún ciclo de servicio.
     */
    public static function canComplete(WorkOrder $workOrder): bool
    {
        if ($workOrder->machine === null || $workOrder->type !== 'preventive') {
            return true;
        }

        return static::serviceHours($workOrder) !== null;
    }
}

<?php

namespace App\Observers;

use App\Models\Alert;
use App\Models\HorometerReading;
use App\Models\Machine;
use App\Support\LocalizedText;
use Illuminate\Support\Facades\Auth;

class HorometerReadingObserver
{
    /**
     * Cualquier cambio en el historial de lecturas —alta, edición o borrado—
     * rehace el estado de la máquina desde cero y, si corresponde, dispara la
     * alerta de servicio.
     *
     * Hallazgos E6-01 y E6-02 (Etapa 06): antes esta clase implementaba
     * ÚNICAMENTE `created()`, y lo hacía de forma incremental
     * (`if ($reading->hours > $machine->current_hours)`). Consecuencias
     * verificadas en base de datos, no supuestas:
     *
     *   - Editar una lectura no recalculaba nada. Si el valor nuevo superaba
     *     el de la máquina, el panel quedaba atrasado sin avisar.
     *   - Borrar la última lectura dejaba `current_hours` y
     *     `current_hours_date` apuntando a una lectura que ya no existía.
     *
     * La regla vive en `Machine::recalculateHoursFromReadings()` y es completa,
     * no incremental: se recalcula desde el ancla más las lecturas
     * sobrevivientes de la escala vigente.
     *
     * Lo que NO cambia: el importador del PM Service Report sigue tolerando
     * filas desordenadas del Excel del cliente, porque el descarte silencioso
     * ya no es necesario —el recálculo completo se queda con la más alta— y el
     * importador escribe sus propios valores después.
     */
    /**
     * Hallazgo E6-08: las lecturas creadas desde el relation manager del panel
     * quedaban sin `recorded_by`, mientras el camino de campo y el cierre de OT
     * sí lo sellan. Solo rellena lo que viene vacío: quien pasa el autor
     * explícito manda.
     */
    public function creating(HorometerReading $reading): void
    {
        if ($reading->recorded_by === null && Auth::id() !== null) {
            $reading->recorded_by = Auth::id();
        }
    }

    public function created(HorometerReading $reading): void
    {
        // Alta: se agrega evidencia, no se quita. Una lectura más baja que el
        // valor de la máquina no puede bajarlo (ver el docblock de
        // Machine::recalculateHoursFromReadings).
        $this->syncMachine($reading, allowLowering: false);
    }

    public function updated(HorometerReading $reading): void
    {
        $this->syncMachine($reading, allowLowering: true);
    }

    public function deleted(HorometerReading $reading): void
    {
        $this->syncMachine($reading, allowLowering: true);
    }

    protected function syncMachine(HorometerReading $reading, bool $allowLowering): void
    {
        // En `deleted` la relación sigue resolviendo: machine_id está en el
        // modelo aunque la fila ya no esté en la tabla de lecturas.
        $machine = $reading->machine;

        if (! $machine) {
            return;
        }

        $machine->recalculateHoursFromReadings($allowLowering);

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
            'title' => LocalizedText::of('alerts.auto_title', ['machine' => $machine->id_code]),
            'message' => LocalizedText::of('alerts.auto_message', [
                'machine' => $machine->id_code,
                'hours' => $machine->remaining_hours,
            ]),
            'remaining_hours' => $machine->remaining_hours,
            'status' => 'open',
        ]);
    }
}

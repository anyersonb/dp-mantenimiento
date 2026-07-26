<?php

namespace App\Livewire\Field\Concerns;

use App\Models\Machine;
use App\Rules\CoherentHorometerReading;

/**
 * Adaptador entre la regla compartida y los componentes de campo.
 *
 * La regla vive en `App\Rules\CoherentHorometerReading` y la consumen los
 * cuatro caminos de escritura: los tres componentes de campo (por acá) y el
 * relation manager del panel (por `->rule()` en el formulario). Antes estaba
 * copiada tres veces y no existía en el panel, que es de donde salió el
 * hallazgo E6-03.
 */
trait RejectsIncoherentReadings
{
    protected function isRegressiveReading(): bool
    {
        $problem = CoherentHorometerReading::problem(
            Machine::find($this->machineId),
            $this->hours === null || $this->hours === '' ? null : (int) round((float) $this->hours),
        );

        if ($problem === null) {
            return false;
        }

        $this->addError('hours', __($problem['key'], $problem['params']));

        return true;
    }
}

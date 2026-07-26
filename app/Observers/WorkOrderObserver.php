<?php

namespace App\Observers;

use App\Models\WorkOrder;
use Illuminate\Support\Facades\Auth;

/**
 * Hallazgo E6-08: el formulario del panel creaba órdenes de trabajo con
 * `opened_by` y `hours_at_open` en NULL, mientras la acción "Crear OT" de una
 * alerta sí las sellaba. El sello va acá y no en la página de crear **por la
 * lección de A8/C3/E6-03**: una regla que vive en un solo camino deja los otros
 * afuera, y ya van cuatro hallazgos de esa misma forma en este proyecto.
 *
 * Solo rellena lo que viene vacío: quien pasa el valor explícito (la acción de
 * la alerta, un seeder, un test) manda.
 */
class WorkOrderObserver
{
    public function creating(WorkOrder $workOrder): void
    {
        if ($workOrder->opened_by === null && Auth::id() !== null) {
            $workOrder->opened_by = Auth::id();
        }

        if ($workOrder->hours_at_open === null) {
            // `machine` puede no estar cargada todavía; se resuelve por relación.
            $workOrder->hours_at_open = $workOrder->machine?->current_hours;
        }
    }
}

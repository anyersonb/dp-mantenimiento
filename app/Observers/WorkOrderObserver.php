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
 *
 * 2026-08-05, para el reporte de costos: se agregan dos sellos más, `location_id`
 * al abrir y `completed_by` al cerrar. Van en el observer por el mismo motivo —
 * hay tres caminos que completan una OT (el formulario con `status=completed`, la
 * acción "Completar" de la tabla, y cualquier `update()` de código) y una regla
 * puesta en uno solo volvería a dejar los otros dos afuera.
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

        // Dónde se hace el trabajo, congelado al abrir la OT. NO se lee después
        // desde `machines.current_location_id`: mover máquinas entre obras es una
        // función del sistema (permiso `move_fleet`), así que leer la ubicación
        // actual haría que el histórico de servicios cambiara de obra hacia atrás
        // cada vez que la máquina se mueve.
        if ($workOrder->location_id === null) {
            $workOrder->location_id = $workOrder->machine?->current_location_id;
        }
    }

    /**
     * `saving` y no `updating`: así queda cubierta también la OT que nace ya
     * completada (un import, un seeder, una carga histórica), que con `updating`
     * se escaparía.
     */
    public function saving(WorkOrder $workOrder): void
    {
        if ($workOrder->status !== 'completed' || ! $workOrder->isDirty('status')) {
            return;
        }

        // Quién hizo el mantenimiento. `assigned_to` NO sirve para esto: es una
        // intención que se puede reasignar a mitad del trabajo, y el que cierra
        // puede ser el administrador desde el panel. Acá se sella quién ejecutó
        // el cierre de verdad.
        //
        // Sin sesión (consola, cola, importador) queda en NULL a propósito: el
        // reporte lo muestra como "sin registrar", que es la verdad. Caer a
        // `assigned_to` sería fabricar justo la atribución equivocada que esta
        // columna vino a evitar.
        if ($workOrder->completed_by === null && Auth::id() !== null) {
            $workOrder->completed_by = Auth::id();
        }

        // El reporte de costos acota por periodo sobre `completed_at`. Una OT
        // completada sin fecha de cierre no aparecería en ningún mes: quedaría
        // fuera del reporte con el trabajo hecho y el costo cargado. El
        // formulario permite dejar el campo vacío, así que el piso va acá.
        if ($workOrder->completed_at === null) {
            $workOrder->completed_at = now()->toDateString();
        }
    }
}

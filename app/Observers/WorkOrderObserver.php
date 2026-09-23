<?php

namespace App\Observers;

use App\Models\FieldReport;
use App\Models\WorkOrder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

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
        $this->guardFieldReportBelongsToMachine($workOrder);

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

    /**
     * Blindaje de integridad para `field_report_id`, independiente del
     * permiso o del formulario. El Select de `WorkOrderResource` ya rechaza
     * un reporte que no es de la máquina elegida con una regla `exists(...)
     * ->where('machine_id', ...)`, pero `WorkOrder` usa `$guarded = []`
     * (igual que `Machine`, ver `MachineObserver`) así que esa regla NO
     * alcanza a un `create()`/`update()` directo (tinker, un job, un payload
     * manipulado). Acá es la única barrera que cubre TODOS los caminos.
     *
     * A diferencia de `MachineObserver::needs_review`, acá no hay "valor
     * anterior correcto" al que volver: un `field_report_id` que no
     * pertenece a la máquina es un dato roto sin importar de dónde vino, así
     * que se limpia a null en vez de revertirse.
     */
    private function guardFieldReportBelongsToMachine(WorkOrder $workOrder): void
    {
        if ($workOrder->field_report_id === null) {
            return;
        }

        $belongs = FieldReport::query()
            ->whereKey($workOrder->field_report_id)
            ->where('machine_id', $workOrder->machine_id)
            ->exists();

        if (! $belongs) {
            // Vuelta 2 (2026-09-22), hallazgo Bajo 3 de seguridad: antes esto
            // anulaba en silencio. Si alguien manipula el payload no quedaba
            // ningún rastro. El id que se rechaza va en el log ANTES de
            // limpiarlo — después de la línea siguiente ya se perdió.
            //
            // `work_order_id` sale null en una OT que todavía se está
            // creando (saving() corre ANTES del INSERT, antes de que la
            // base asigne el id): se suma `work_order_code`, que sí está
            // disponible en ese momento (el form siempre lo manda, ver
            // WorkOrder::nextCode()), para no perder la referencia en ese
            // caso.
            Log::warning('work_order.field_report_mismatch_cleared', [
                'work_order_id' => $workOrder->getKey(),
                'work_order_code' => $workOrder->code,
                'machine_id' => $workOrder->machine_id,
                'rejected_field_report_id' => $workOrder->field_report_id,
                'user_id' => Auth::id(),
            ]);

            $workOrder->field_report_id = null;
        }
    }
}

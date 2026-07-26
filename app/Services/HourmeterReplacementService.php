<?php

namespace App\Services;

use App\Models\Machine;
use App\Models\User;
use App\Support\LocalizedText;
use Illuminate\Support\Facades\DB;

/**
 * Reemplazo físico de horómetro (regla-horometro.md, sec. 2.2). No es una
 * lectura: el contador vuelve a empezar en una escala que no es comparable
 * con la anterior. Este servicio registra el evento, re-ancla el seguimiento
 * de servicio a la escala nueva y deja todo en un evento propio de la
 * bitácora (no un "updated" más).
 */
class HourmeterReplacementService
{
    public function replace(
        Machine $machine,
        int $oldFinalHours,
        int $newInitialHours,
        ?string $note,
        ?User $causer,
    ): Machine {
        return DB::transaction(function () use ($machine, $oldFinalHours, $newInitialHours, $note, $causer) {
            // "hours_adjustment" sigue significando "súmale esto a current_hours
            // para saber las horas reales acumuladas" (valor de reventa,
            // garantías, informes — sec. 2.1). Se recalcula para que ese total
            // no se pierda al reiniciar el contador en la escala nueva.
            $machine->hours_adjustment = $oldFinalHours + $machine->hours_adjustment - $newInitialHours;

            $machine->hourmeter_status = 'replaced';
            $machine->current_hours = $newInitialHours;
            $machine->current_hours_date = now();

            // Re-ancla el seguimiento de servicio en la escala nueva: a partir
            // de aquí last_service_hours se expresa en la lectura del
            // horómetro nuevo, y el ancla de remaining_hours arranca en el
            // intervalo completo (no hay forma honesta de heredar el
            // remaining anterior a través de un cambio de escala).
            $machine->last_service_hours = $newInitialHours;
            $machine->last_service_date = now();
            $machine->remaining_anchor_hours = $machine->service_interval_hours;
            $machine->remaining_anchor_at_hours = $newInitialHours;
            $machine->remaining_hours = $machine->service_interval_hours;

            // El reemplazo debe quedar como su propio evento en la bitácora,
            // no como un "updated" genérico más (sec. 2.2): se suprime el log
            // automático de Machine (LogsActivity) para este guardado puntual
            // y se deja únicamente el evento manual de abajo.
            $machine->disableLogging()->save();
            $machine->enableLogging();

            activity()
                ->performedOn($machine)
                ->causedBy($causer)
                ->event('hourmeter_replaced')
                ->withProperties([
                    'old_final_hours' => $oldFinalHours,
                    'new_initial_hours' => $newInitialHours,
                    'note' => $note,
                ])
                ->log(LocalizedText::of('mgmt.hourmeter_replaced_log', [
                    'machine' => $machine->id_code,
                    'old' => $oldFinalHours,
                    'new' => $newInitialHours,
                ])->encode());

            return $machine;
        });
    }
}

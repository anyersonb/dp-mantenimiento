<?php

namespace App\Observers;

use App\Models\Machine;
use Illuminate\Support\Facades\Auth;

class MachineObserver
{
    /**
     * Hallazgo A3 (QA Etapa 05): responsable_mantenimiento, que no tiene el
     * permiso verify_data, apagaba needs_review desde el formulario normal de
     * edición de la máquina. La acción "Aprobar datos" ya validaba el
     * permiso; el campo suelto del form, no.
     *
     * Machine usa $guarded = [] (ver comentario en el modelo), así que ocultar
     * o deshabilitar el campo en MachineResource::form() no alcanza como
     * defensa real: un payload manipulado igual llega a Model::save(). Este
     * observer es la única barrera que cubre TODOS los caminos de escritura
     * (panel, tinker vía request autenticado, cualquier otro form futuro),
     * revirtiendo el cambio si quien está autenticado no tiene verify_data.
     *
     * Sin usuario autenticado (seeders, importador del PM Service Report,
     * comandos artisan) se permite sin más: son procesos de confianza fuera
     * del panel, no la vía que reporta A3.
     */
    public function saving(Machine $machine): void
    {
        if (! $machine->isDirty('needs_review')) {
            return;
        }

        $user = Auth::user();

        if (! $user) {
            return;
        }

        if ($user->can('verify_data')) {
            return;
        }

        $machine->needs_review = $machine->exists
            ? $machine->getOriginal('needs_review')
            : false;
    }
}

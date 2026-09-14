<?php

namespace App\Filament\Concerns;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;

/**
 * Regla única de borrado para los registros relacionados de una orden de
 * trabajo: adjuntos, resultados de checklist y líneas de repuesto.
 *
 *   OT abierta  -> borra quien tiene execute_work_order.
 *   OT cerrada  -> NADIE borra. Tampoco el administrador.
 *
 * El motivo es que el borrado no se gobierna por rol sino por ESTADO: una vez
 * que la OT está completada o cancelada, sus adjuntos son el respaldo de lo
 * que se hizo y de lo que se cobró. Que el técnico que ejecutó el trabajo
 * pueda borrar su propia factura mientras la OT está abierta es parte del
 * flujo (se equivocó de archivo, la vuelve a subir); que pueda borrarla
 * después de cerrada es destruir evidencia.
 *
 * Vive en un trait y no copiado en los tres relation managers a propósito: es
 * la tercera vez en este proyecto que un defecto aparece por tener la misma
 * regla escrita en un solo camino y no en todos (C3, A4, y la validación de
 * lectura regresiva). Una regla, un lugar.
 *
 * Nota para el PermissionSentinelTest: los `can*()` de abajo son propiedad de
 * la clase que usa el trait (PHP aplana los traits), y el centinela lee el
 * archivo del trait al buscar el marcador de permiso. Por eso el `->can()`
 * tiene que quedar acá, visible, y no delegado a otro método.
 */
trait DeletesOnlyWhileWorkOrderIsOpen
{
    protected function canDelete(Model $record): bool
    {
        return $this->workOrderIsOpen() && (Auth::user()?->can('execute_work_order') ?? false);
    }

    protected function canDeleteAny(): bool
    {
        return $this->workOrderIsOpen() && (Auth::user()?->can('execute_work_order') ?? false);
    }

    private function workOrderIsOpen(): bool
    {
        $owner = $this->getOwnerRecord();

        // isOpen() vive en el modelo WorkOrder: única definición de "cerrada".
        return method_exists($owner, 'isOpen') ? $owner->isOpen() : false;
    }

    /**
     * Motivo legible de por qué el borrado está bloqueado para ESTE registro,
     * o null si sí se puede borrar. `canDelete()` sigue siendo la única
     * fuente de verdad del booleano (no se toca ni se duplica su chequeo);
     * esto solo decide QUÉ mensaje mostrar cuando da false.
     *
     * Nace del reporte de cliente "en parts used quiero eliminar alguno y no
     * puedo eliminarlo" (2026-09-14): la acción de borrar, al no estar
     * autorizada, se OCULTABA sin explicación — el usuario no podía saber si
     * era por su rol o porque la OT ya había cerrado. Ver el uso de este
     * método junto a ->disabled()/->tooltip() en cada RelationManager: la
     * acción ahora se ve siempre, pero deshabilitada con el motivo real
     * cuando corresponde.
     */
    protected function deletionBlockedReason(Model $record): ?string
    {
        if ($this->canDelete($record)) {
            return null;
        }

        return $this->workOrderIsOpen()
            ? __('wo.delete_blocked_no_permission')
            : __('wo.delete_blocked_closed_order');
    }
}

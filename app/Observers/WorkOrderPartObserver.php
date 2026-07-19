<?php

namespace App\Observers;

use App\Models\WorkOrderPart;

class WorkOrderPartObserver
{
    /**
     * Cada vez que se agrega/edita/borra una parte usada en una OT, se recalcula
     * work_order.parts_cost como la suma de subtotales (quantity * unit_cost).
     * Esto permite al taller comparar precios de repuestos sin tocar el total a mano.
     */
    public function saved(WorkOrderPart $part): void
    {
        $this->recalculate($part);
    }

    public function deleted(WorkOrderPart $part): void
    {
        $this->recalculate($part);
    }

    protected function recalculate(WorkOrderPart $part): void
    {
        $workOrder = $part->workOrder()->first();

        if (! $workOrder) {
            return;
        }

        $total = $workOrder->parts()->get()
            ->sum(fn (WorkOrderPart $p) => (float) $p->quantity * (float) $p->unit_cost);

        // Update normal (no "quietly"): work_order.parts_cost está en el logOnly() de
        // WorkOrder, así que el cambio de costo queda registrado en la bitácora.
        $workOrder->update(['parts_cost' => $total]);
    }
}

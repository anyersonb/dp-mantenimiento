<?php

namespace App\Exceptions;

use App\Models\WorkOrder;
use RuntimeException;

/**
 * Hallazgo E6-08. El cierre de una OT preventiva no puede seguir adelante sin
 * saber a qué horas se hizo el servicio.
 *
 * Antes, `WorkOrderCompletionService` resolvía las horas con la cadena
 * `current_hours ?? hours_at_open` y, si las dos venían en NULL, seguía igual:
 * **no** guardaba `last_service_hours`, **no** registraba la lectura de cierre,
 * pero **sí** reiniciaba `remaining_hours` al intervalo completo. La máquina
 * quedaba "recién servida" sin ningún registro de a qué horas, y eso alcanzaba
 * a las 41 máquinas de la flota sin horómetro cargado.
 */
class CannotCompleteWorkOrder extends RuntimeException
{
    public function __construct(public readonly WorkOrder $workOrder, string $message)
    {
        parent::__construct($message);
    }

    public static function withoutHours(WorkOrder $workOrder): self
    {
        return new self($workOrder, __('wo.cannot_complete_no_hours_body', [
            'machine' => $workOrder->machine?->id_code ?? '—',
        ]));
    }

    /**
     * Hallazgo E6-13: las horas del cierre también pasan por la regla de
     * coherencia compartida. Un cierre no puede escribir una lectura que
     * contradiga el historial de la máquina.
     */
    public static function withIncoherentHours(WorkOrder $workOrder, int $hours, string $motivo): self
    {
        return new self($workOrder, __('wo.cannot_complete_incoherent_hours_body', [
            'machine' => $workOrder->machine?->id_code ?? '—',
            'hours' => $hours,
            'reason' => $motivo,
        ]));
    }
}

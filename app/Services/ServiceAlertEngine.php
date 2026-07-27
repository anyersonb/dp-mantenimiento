<?php

namespace App\Services;

use App\Models\Alert;
use App\Models\Machine;
use App\Support\LocalizedText;

/**
 * Motor de alerta de servicio: única implementación de "esta máquina cruzó el
 * umbral y hay que avisar".
 *
 * Hallazgo E6-15. Vivía dentro de `HorometerReadingObserver`, así que solo se
 * evaluaba cuando nacía, se editaba o se borraba una lectura. Cualquier otro
 * camino que cambiara `remaining_hours` dejaba a la máquina cruzando el umbral
 * **sin alerta**.
 *
 * Se detectó con datos reales, no razonándolo: al importar el PM report del
 * 24/07/2026, EX027 quedó con exactamente 100 h restantes —el umbral— y sin
 * alerta. El motivo es el orden: la lectura de 400 h se crea primero y dispara
 * el motor con el ancla vieja (179 h restantes, por encima del umbral), y recién
 * después el importador escribe el ancla del reporte, que la baja a 100. Nadie
 * volvía a preguntar.
 *
 * Es el mismo patrón de C3 / A4 / E6-03 / E6-13 —la regla en un solo camino—
 * aplicado ahora a las alertas en vez del horómetro.
 */
class ServiceAlertEngine
{
    /**
     * @return bool true si levantó una alerta nueva.
     */
    public static function evaluate(Machine $machine): bool
    {
        if ($machine->remaining_hours === null || $machine->remaining_hours > Machine::ALERT_THRESHOLD) {
            return false;
        }

        $yaAbierta = Alert::query()
            ->where('machine_id', $machine->id)
            ->where('type', 'service')
            ->where('status', 'open')
            ->exists();

        if ($yaAbierta) {
            return false;
        }

        Alert::create([
            'machine_id' => $machine->id,
            'type' => 'service',
            // E6-10: clave + parámetros, se renderiza en el idioma del que lee.
            'title' => LocalizedText::of('alerts.auto_title', ['machine' => $machine->id_code]),
            'message' => LocalizedText::of('alerts.auto_message', [
                'machine' => $machine->id_code,
                'hours' => $machine->remaining_hours,
            ]),
            'remaining_hours' => $machine->remaining_hours,
            'status' => 'open',
        ]);

        return true;
    }
}

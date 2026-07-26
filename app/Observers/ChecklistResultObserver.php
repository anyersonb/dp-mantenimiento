<?php

namespace App\Observers;

use App\Models\Alert;
use App\Models\ChecklistResult;
use App\Support\LocalizedText;

class ChecklistResultObserver
{
    /**
     * Si un ítem del checklist se marca como "alert", se notifica al administrador
     * creando (o actualizando) una Alert(type=checklist) para esa máquina/OT.
     * No se duplica: se identifica por título (incluye el código de la OT) y status=open.
     */
    public function saved(ChecklistResult $result): void
    {
        $this->syncAlert($result);
    }

    protected function syncAlert(ChecklistResult $result): void
    {
        $workOrder = $result->workOrder()->with('machine')->first();

        if (! $workOrder || ! $workOrder->machine) {
            return;
        }

        $machine = $workOrder->machine;

        $alertItems = $workOrder->checklistResults()->where('result', 'alert')->get();

        if ($alertItems->isEmpty()) {
            return;
        }

        // E6-10: clave + parametros, no la frase ya traducida.
        $title = LocalizedText::of('alerts.checklist_title', [
            'machine' => $machine->id_code,
            'code' => $workOrder->code,
        ]);

        $message = $alertItems
            ->map(fn (ChecklistResult $item) => '- '.$item->label.': '.($item->alert_detail ?: '—'))
            ->implode("\n");

        // La búsqueda de la alerta ya abierta va contra lo GUARDADO, que ahora
        // es el sobre de la clave y no la frase renderizada. Comparar contra el
        // texto traducido dejaría de encontrarla en cuanto cambiara el idioma
        // del que ejecuta, y se duplicarían las alertas.
        $existing = Alert::query()
            ->where('machine_id', $machine->id)
            ->where('type', 'checklist')
            ->where('title', $title->encode())
            ->where('status', 'open')
            ->first();

        if ($existing) {
            $existing->update(['message' => $message]);

            return;
        }

        Alert::create([
            'machine_id' => $machine->id,
            'type' => 'checklist',
            'title' => $title,
            'message' => $message,
            'status' => 'open',
        ]);
    }
}

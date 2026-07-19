<?php

namespace App\Observers;

use App\Models\Alert;
use App\Models\ChecklistResult;

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

        $title = __('alerts.checklist_title', [
            'machine' => $machine->id_code,
            'code' => $workOrder->code,
        ]);

        $message = $alertItems
            ->map(fn (ChecklistResult $item) => '- '.$item->label.': '.($item->alert_detail ?: '—'))
            ->implode("\n");

        $existing = Alert::query()
            ->where('machine_id', $machine->id)
            ->where('type', 'checklist')
            ->where('title', $title)
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

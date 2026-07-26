<?php

namespace App\Filament\Resources\WorkOrderResource\Pages;

use App\Filament\Resources\WorkOrderResource;
use App\Services\WorkOrderCompletionService;
use Filament\Actions;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;

class EditWorkOrder extends EditRecord
{
    protected static string $resource = WorkOrderResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make(),
        ];
    }

    /**
     * Hallazgo E6-08: el corte va ANTES de guardar. Si se dejara pasar el
     * guardado y se rechazara después, la OT quedaría en "completada" con la
     * máquina sin servicio registrado — peor que el defecto original.
     *
     * Se evalúa sobre un espejo del registro con los valores del formulario,
     * porque el usuario puede estar completando `hours_at_open` en el mismo
     * guardado que cierra la OT. La decisión la toma `canComplete()`, la misma
     * que usa `complete()`: una sola implementación de la regla.
     */
    protected function beforeSave(): void
    {
        $nuevoEstado = $this->data['status'] ?? null;

        if ($nuevoEstado !== 'completed' || $this->record->status === 'completed') {
            return;
        }

        $espejo = clone $this->record;
        $espejo->fill([
            'type' => $this->data['type'] ?? $this->record->type,
            'machine_id' => $this->data['machine_id'] ?? $this->record->machine_id,
            'hours_at_open' => $this->data['hours_at_open'] ?? null,
        ]);
        $espejo->unsetRelation('machine');

        if (WorkOrderCompletionService::canComplete($espejo)) {
            return;
        }

        Notification::make()
            ->title(__('wo.cannot_complete_no_hours'))
            ->body(__('wo.cannot_complete_no_hours_body', ['machine' => $espejo->machine?->id_code ?? '—']))
            ->warning()
            ->persistent()
            ->send();

        $this->halt();
    }

    /**
     * Si el usuario cambia el status a "completed" directamente desde el formulario
     * (en vez de usar la acción "Completar" de la tabla), dispara la misma lógica de
     * cierre: reinicia el ciclo de servicio de la máquina y resuelve su alerta.
     */
    protected function afterSave(): void
    {
        if ($this->record->wasChanged('status') && $this->record->status === 'completed') {
            WorkOrderCompletionService::complete($this->record->fresh('machine'));
        }
    }
}

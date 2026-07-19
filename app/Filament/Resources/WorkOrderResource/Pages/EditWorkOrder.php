<?php

namespace App\Filament\Resources\WorkOrderResource\Pages;

use App\Filament\Resources\WorkOrderResource;
use App\Services\WorkOrderCompletionService;
use Filament\Actions;
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

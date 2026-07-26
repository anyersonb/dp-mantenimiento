<?php

namespace App\Filament\Resources\MachineResource\Pages;

use App\Filament\Resources\MachineResource;
use App\Models\Machine;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditMachine extends EditRecord
{
    protected static string $resource = MachineResource::class;

    protected function getHeaderActions(): array
    {
        return [
            // Hallazgo E6-05: el diálogo decía únicamente "¿Seguro que querés
            // hacer esto?" para una acción que arrastraba en cascada las
            // órdenes de trabajo, las lecturas, las alertas y los costos de la
            // máquina. Ahora enumera los conteos reales antes de preguntar, y
            // el borrado es suave (SoftDeletes) y solo de administrador.
            Actions\DeleteAction::make()
                ->modalHeading(fn (Machine $record) => __('fleet.delete_heading', ['machine' => $record->id_code]))
                ->modalDescription(fn (Machine $record) => MachineResource::deletionWarning($record))
                ->modalSubmitActionLabel(__('fleet.delete_confirm_button')),
        ];
    }
}

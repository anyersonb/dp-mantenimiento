<?php

namespace App\Filament\Resources\FleetAttachmentResource\Pages;

use App\Filament\Resources\FleetAttachmentResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditFleetAttachment extends EditRecord
{
    protected static string $resource = FleetAttachmentResource::class;

    protected function getHeaderActions(): array
    {
        return [
            // Sin modalHeading/modalDescription a medida (a diferencia de
            // EditMachine): un complemento es independiente, sin OT ni
            // lecturas que perder, así que el aviso genérico de Filament ya
            // dice lo que corresponde.
            Actions\DeleteAction::make(),
        ];
    }
}

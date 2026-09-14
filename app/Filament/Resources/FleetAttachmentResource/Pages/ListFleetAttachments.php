<?php

namespace App\Filament\Resources\FleetAttachmentResource\Pages;

use App\Filament\Resources\FleetAttachmentResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;

class ListFleetAttachments extends ListRecords
{
    protected static string $resource = FleetAttachmentResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make(),
        ];
    }
}

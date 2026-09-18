<?php

namespace App\Filament\Resources\FieldReportResource\Pages;

use App\Filament\Resources\FieldReportResource;
use Filament\Resources\Pages\ListRecords;

class ListFieldReports extends ListRecords
{
    protected static string $resource = FieldReportResource::class;

    protected function getHeaderActions(): array
    {
        // Los reportes los crea el operario desde /field/report; no se dan de
        // alta a mano desde el panel.
        return [];
    }
}

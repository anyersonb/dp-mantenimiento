<?php

namespace App\Filament\Resources\MachineResource\Pages;

use App\Filament\Resources\MachineResource;
use Filament\Actions;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Support\Facades\Auth;

class ListMachines extends ListRecords
{
    protected static string $resource = MachineResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\Action::make('export_pdf')
                ->label(__('mgmt.export_pdf'))
                ->icon('heroicon-o-document-arrow-down')
                ->color('gray')
                ->url(fn () => route('reports.fleet.pdf'))
                ->openUrlInNewTab()
                ->visible(fn () => Auth::user()?->can('view_reports') ?? false),
            Actions\Action::make('export_excel')
                ->label(__('mgmt.export_excel'))
                ->icon('heroicon-o-table-cells')
                ->color('gray')
                ->url(fn () => route('reports.fleet.xlsx'))
                ->openUrlInNewTab()
                ->visible(fn () => Auth::user()?->can('view_reports') ?? false),
            Actions\CreateAction::make(),
        ];
    }
}

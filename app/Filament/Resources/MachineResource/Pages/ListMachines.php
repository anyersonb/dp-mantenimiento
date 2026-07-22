<?php

namespace App\Filament\Resources\MachineResource\Pages;

use App\Filament\Resources\MachineResource;
use App\Services\PmServiceReportImporter;
use Filament\Actions;
use Filament\Forms;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Support\Facades\Auth;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;

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
            Actions\Action::make('import_pm_report')
                ->label(__('mgmt.import_pm_report'))
                ->icon('heroicon-o-arrow-up-tray')
                ->color('gray')
                ->visible(fn () => Auth::user()?->can('manage_machines') ?? false)
                ->modalHeading(__('mgmt.import_pm_report_modal_heading'))
                ->modalDescription(__('mgmt.import_pm_report_modal_description'))
                ->modalSubmitActionLabel(__('mgmt.import_pm_report_submit'))
                ->form([
                    Forms\Components\FileUpload::make('file')
                        ->label(__('mgmt.import_pm_report_file_label'))
                        ->required()
                        ->storeFiles(false)
                        ->acceptedFileTypes([
                            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                            'application/vnd.ms-excel',
                        ])
                        ->helperText(__('mgmt.import_pm_report_file_help')),
                ])
                ->action(function (array $data) {
                    /** @var TemporaryUploadedFile $file */
                    $file = $data['file'];

                    $result = app(PmServiceReportImporter::class)->import(
                        $file->getRealPath(),
                        Auth::user(),
                        $file->getClientOriginalName(),
                    );

                    // La bitácora (activity_log) queda registrada dentro del
                    // propio importador, junto con el resto de la lógica de
                    // negocio (así también queda cubierta cuando se invoca
                    // fuera del panel, ej. en tests o un comando artisan).
                    $updatedCount = count($result['updated']);
                    $unmatchedCount = count($result['unmatched']);
                    $warningsCount = count($result['warnings']);

                    $bodyLines = [
                        __('mgmt.import_pm_report_summary', [
                            'updated' => $updatedCount,
                            'unmatched' => $unmatchedCount,
                            'warnings' => $warningsCount,
                        ]),
                    ];

                    if ($unmatchedCount > 0) {
                        $ids = array_column($result['unmatched'], 'id_code');
                        $shown = array_slice($ids, 0, 15);
                        $list = implode(', ', $shown);
                        if (count($ids) > count($shown)) {
                            $list .= '…';
                        }
                        $bodyLines[] = __('mgmt.import_pm_report_unmatched_list', ['ids' => $list]);
                    }

                    $notification = Notification::make()
                        ->title(__('mgmt.import_pm_report_notif_title'))
                        ->body(implode("\n", $bodyLines))
                        ->persistent();

                    ($unmatchedCount > 0 || $warningsCount > 0) ? $notification->warning() : $notification->success();

                    $notification->send();
                }),
            Actions\CreateAction::make(),
        ];
    }
}

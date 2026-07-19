<?php

namespace App\Filament\Resources;

use App\Filament\Resources\AlertResource\Pages;
use App\Models\Alert;
use App\Models\WorkOrder;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Auth;

class AlertResource extends Resource
{
    protected static ?string $model = Alert::class;

    protected static ?string $navigationIcon = 'heroicon-o-bell-alert';

    protected static ?int $navigationSort = 3;

    public static function getNavigationLabel(): string
    {
        return __('alerts.alerts');
    }

    public static function getModelLabel(): string
    {
        return __('alerts.alert');
    }

    public static function getPluralModelLabel(): string
    {
        return __('alerts.alerts');
    }

    public static function getNavigationGroup(): ?string
    {
        return __('fleet.group_operations');
    }

    public static function getNavigationBadge(): ?string
    {
        $count = static::getModel()::where('status', 'open')->count();

        return $count > 0 ? (string) $count : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'danger';
    }

    /**
     * Solo administrador / responsable de mantenimiento gestionan alertas
     * (taller y gerencia también entran al panel pero no a este recurso).
     */
    public static function canViewAny(): bool
    {
        return Auth::user()?->hasAnyRole(['administrador', 'responsable_mantenimiento']) ?? false;
    }

    public static function form(Form $form): Form
    {
        return $form->schema([]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('machine.id_code')
                    ->label(__('fleet.machines'))->badge()->searchable()->sortable(),
                Tables\Columns\TextColumn::make('type')
                    ->label(__('alerts.type'))->badge()
                    ->formatStateUsing(fn ($state) => __('alerts.type_'.$state))
                    ->color(fn ($state) => match ($state) {
                        'service' => 'warning',
                        'checklist' => 'info',
                        'hourmeter' => 'gray',
                        default => 'gray',
                    }),
                Tables\Columns\TextColumn::make('title')
                    ->label(__('alerts.title'))->wrap()->searchable(),
                Tables\Columns\TextColumn::make('remaining_hours')
                    ->label(__('fleet.remaining_hours'))->badge()
                    ->formatStateUsing(fn ($state) => $state === null ? '—' : number_format($state).' h')
                    ->color(fn ($state) => $state !== null && $state <= 0 ? 'danger' : 'warning')
                    ->sortable(),
                Tables\Columns\TextColumn::make('status')
                    ->label(__('alerts.status'))->badge()
                    ->formatStateUsing(fn ($state) => __('alerts.status_'.$state))
                    ->color(fn ($state) => match ($state) {
                        'open' => 'danger',
                        'acknowledged' => 'warning',
                        'resolved' => 'success',
                        default => 'gray',
                    })
                    ->sortable(),
                Tables\Columns\TextColumn::make('notified_at')
                    ->label(__('alerts.notified_at'))->dateTime()->placeholder('—')->sortable(),
                Tables\Columns\TextColumn::make('created_at')
                    ->label(__('alerts.created_at'))->dateTime()->sortable(),
            ])
            ->defaultSort('created_at', 'desc')
            ->filters([
                Tables\Filters\SelectFilter::make('status')->label(__('alerts.status'))->options([
                    'open' => __('alerts.status_open'),
                    'acknowledged' => __('alerts.status_acknowledged'),
                    'resolved' => __('alerts.status_resolved'),
                ]),
                Tables\Filters\SelectFilter::make('type')->label(__('alerts.type'))->options([
                    'service' => __('alerts.type_service'),
                    'checklist' => __('alerts.type_checklist'),
                    'hourmeter' => __('alerts.type_hourmeter'),
                    'other' => __('alerts.type_other'),
                ]),
            ])
            ->actions([
                Tables\Actions\Action::make('acknowledge')
                    ->label(__('alerts.acknowledge'))
                    ->icon('heroicon-o-eye')
                    ->color('warning')
                    ->visible(fn (Alert $record) => $record->status === 'open')
                    ->requiresConfirmation()
                    ->action(fn (Alert $record) => $record->update([
                        'status' => 'acknowledged',
                        'acknowledged_by' => Auth::id(),
                    ])),
                Tables\Actions\Action::make('resolve')
                    ->label(__('alerts.resolve'))
                    ->icon('heroicon-o-check-circle')
                    ->color('success')
                    ->visible(fn (Alert $record) => $record->status !== 'resolved')
                    ->requiresConfirmation()
                    ->action(fn (Alert $record) => $record->update(['status' => 'resolved'])),
                Tables\Actions\Action::make('create_work_order')
                    ->label(__('alerts.create_work_order'))
                    ->icon('heroicon-o-wrench-screwdriver')
                    ->color('primary')
                    ->visible(fn (Alert $record) => $record->status !== 'resolved'
                        && $record->machine
                        && (Auth::user()?->can('create_work_order') || Auth::user()?->hasRole('administrador')))
                    ->requiresConfirmation()
                    ->modalDescription(__('alerts.create_work_order_confirm'))
                    ->action(function (Alert $record, Tables\Actions\Action $action) {
                        $machine = $record->machine;

                        $workOrder = WorkOrder::create([
                            'code' => 'WO-'.str_pad((string) (WorkOrder::max('id') + 1), 4, '0', STR_PAD_LEFT),
                            'machine_id' => $machine->id,
                            'type' => 'preventive',
                            'service_tier' => $machine->service_interval_hours,
                            'status' => 'open',
                            'priority' => $record->type === 'service' && $record->remaining_hours !== null && $record->remaining_hours <= 0
                                ? 'urgent'
                                : 'normal',
                            'opened_by' => Auth::id(),
                            'opened_at' => now()->toDateString(),
                            'hours_at_open' => $machine->current_hours,
                        ]);

                        $record->update([
                            'status' => 'acknowledged',
                            'acknowledged_by' => Auth::id(),
                        ]);

                        $action->redirect(WorkOrderResource::getUrl('edit', ['record' => $workOrder]));
                    }),
            ])
            ->bulkActions([])
            ->emptyStateHeading(__('alerts.empty_heading'))
            ->emptyStateDescription(__('alerts.empty_desc'));
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListAlerts::route('/'),
        ];
    }
}

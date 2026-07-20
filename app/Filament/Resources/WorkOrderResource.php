<?php

namespace App\Filament\Resources;

use App\Filament\Resources\WorkOrderResource\Pages;
use App\Filament\Resources\WorkOrderResource\RelationManagers;
use App\Models\WorkOrder;
use App\Services\WorkOrderCompletionService;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Auth;

class WorkOrderResource extends Resource
{
    protected static ?string $model = WorkOrder::class;

    protected static ?string $navigationIcon = 'heroicon-o-clipboard-document-list';

    protected static ?int $navigationSort = 2;

    public static function getNavigationLabel(): string
    {
        return __('wo.work_orders');
    }

    public static function getModelLabel(): string
    {
        return __('wo.work_order');
    }

    public static function getPluralModelLabel(): string
    {
        return __('wo.work_orders');
    }

    public static function getNavigationGroup(): ?string
    {
        return __('fleet.group_operations');
    }

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make()->columns(3)->schema([
                Forms\Components\TextInput::make('code')->label(__('wo.code'))
                    ->default(fn () => 'WO-'.str_pad((string) (WorkOrder::max('id') + 1), 4, '0', STR_PAD_LEFT))
                    ->required()->maxLength(50),
                Forms\Components\Select::make('machine_id')->label(__('fleet.machines'))
                    ->relationship('machine', 'id_code')->searchable()->preload()->required(),
                Forms\Components\Select::make('type')->label(__('wo.type'))->options([
                    'preventive' => __('wo.preventive'), 'corrective' => __('wo.corrective'), 'inspection' => __('wo.inspection'),
                ])->default('preventive')->required(),
                Forms\Components\Select::make('service_tier')->label(__('wo.service_tier'))
                    ->options([500 => '500 h', 1000 => '1000 h', 2000 => '2000 h', 4000 => '4000 h']),
                Forms\Components\Select::make('status')->label(__('fleet.status'))->options([
                    'open' => __('wo.open'), 'assigned' => __('wo.assigned'), 'in_progress' => __('wo.in_progress'),
                    'completed' => __('wo.completed'), 'cancelled' => __('wo.cancelled'),
                ])->default('open')->required(),
                Forms\Components\Select::make('priority')->label(__('wo.priority'))->options([
                    'normal' => __('wo.normal'), 'high' => __('wo.high'), 'urgent' => __('wo.urgent'),
                ])->default('normal')->required(),
                Forms\Components\Select::make('assigned_to')->label(__('wo.assigned_to'))
                    ->relationship('assignee', 'name')->searchable()->preload(),
                Forms\Components\Radio::make('execution_mode')->label(__('wo.execution_mode'))->options([
                    'workshop' => __('wo.workshop'), 'onsite' => __('wo.onsite'),
                ])->default('workshop')->inline()->inlineLabel(false),
                Forms\Components\DatePicker::make('opened_at')->label(__('wo.opened_at'))->default(now()),
                Forms\Components\DatePicker::make('completed_at')->label(__('wo.completed_at')),
            ]),
            Forms\Components\Section::make(__('wo.execution'))->columns(2)->schema([
                Forms\Components\TextInput::make('labor_hours')->label(__('wo.labor_hours'))->numeric()->suffix('h'),
                Forms\Components\TextInput::make('parts_cost')->label(__('wo.parts_cost'))->numeric()->prefix('$')
                    ->helperText(__('wo.cost_help'))
                    ->disabled()
                    ->dehydrated(false)
                    ->visible(fn () => Auth::user()?->can('view_costs') ?? false),
                Forms\Components\Textarea::make('description')->label(__('fleet.description'))
                    ->helperText(__('wo.description_help'))->columnSpanFull(),
                Forms\Components\Textarea::make('resolution')->label(__('wo.resolution'))->columnSpanFull(),
            ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('code')->label(__('wo.code'))->searchable()->sortable()->weight('bold'),
                Tables\Columns\TextColumn::make('machine.id_code')->label(__('fleet.machines'))->badge()->searchable(),
                Tables\Columns\TextColumn::make('type')->label(__('wo.type'))->badge()
                    ->formatStateUsing(fn ($state) => __('wo.'.$state)),
                Tables\Columns\TextColumn::make('status')->label(__('fleet.status'))->badge()
                    ->formatStateUsing(fn ($state) => __('wo.'.$state))
                    ->color(fn ($state) => match ($state) {
                        'completed' => 'success', 'cancelled' => 'gray', 'in_progress' => 'info',
                        'assigned' => 'warning', default => 'primary',
                    }),
                Tables\Columns\TextColumn::make('priority')->label(__('wo.priority'))->badge()
                    ->formatStateUsing(fn ($state) => __('wo.'.$state))
                    ->color(fn ($state) => match ($state) {
                        'urgent' => 'danger', 'high' => 'warning', default => 'gray',
                    }),
                Tables\Columns\TextColumn::make('execution_mode')->label(__('wo.execution_mode'))->badge()
                    ->formatStateUsing(fn ($state) => $state ? __('wo.'.$state) : '—')
                    ->color(fn ($state) => $state === 'onsite' ? 'info' : 'gray'),
                Tables\Columns\TextColumn::make('assignee.name')->label(__('wo.assigned_to'))->placeholder('—'),
                Tables\Columns\TextColumn::make('opened_at')->label(__('wo.opened_at'))->date()->sortable(),
            ])
            ->defaultSort('created_at', 'desc')
            ->filters([
                Tables\Filters\SelectFilter::make('status')->label(__('fleet.status'))->options([
                    'open' => __('wo.open'), 'assigned' => __('wo.assigned'), 'in_progress' => __('wo.in_progress'),
                    'completed' => __('wo.completed'), 'cancelled' => __('wo.cancelled'),
                ]),
                Tables\Filters\SelectFilter::make('type')->label(__('wo.type'))->options([
                    'preventive' => __('wo.preventive'), 'corrective' => __('wo.corrective'), 'inspection' => __('wo.inspection'),
                ]),
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
                Tables\Actions\Action::make('complete')
                    ->label(__('wo.complete'))
                    ->icon('heroicon-o-check-circle')
                    ->color('success')
                    ->visible(fn (WorkOrder $record) => ! in_array($record->status, ['completed', 'cancelled'], true))
                    ->requiresConfirmation()
                    ->modalDescription(__('wo.complete_confirm'))
                    ->action(function (WorkOrder $record) {
                        $record->update([
                            'status' => 'completed',
                            'completed_at' => $record->completed_at ?? now()->toDateString(),
                        ]);

                        WorkOrderCompletionService::complete($record->fresh('machine'));
                    }),
            ])
            ->bulkActions([Tables\Actions\BulkActionGroup::make([Tables\Actions\DeleteBulkAction::make()])])
            ->emptyStateHeading(__('wo.empty_heading'))
            ->emptyStateDescription(__('wo.empty_desc'));
    }

    public static function getRelations(): array
    {
        return [
            RelationManagers\ChecklistResultsRelationManager::class,
            RelationManagers\PartsRelationManager::class,
            RelationManagers\AttachmentsRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListWorkOrders::route('/'),
            'create' => Pages\CreateWorkOrder::route('/create'),
            'edit' => Pages\EditWorkOrder::route('/{record}/edit'),
        ];
    }
}

<?php

namespace App\Filament\Resources;

use App\Filament\Resources\MachineResource\Pages;
use App\Filament\Resources\MachineResource\RelationManagers;
use App\Models\Location;
use App\Models\Machine;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;

class MachineResource extends Resource
{
    protected static ?string $model = Machine::class;

    protected static ?string $navigationIcon = 'heroicon-o-truck';

    protected static ?int $navigationSort = 1;

    public static function getNavigationLabel(): string
    {
        return __('fleet.machines');
    }

    public static function getModelLabel(): string
    {
        return __('fleet.machine');
    }

    public static function getPluralModelLabel(): string
    {
        return __('fleet.machines');
    }

    public static function getNavigationGroup(): ?string
    {
        return __('fleet.group_fleet');
    }

    /*
     * Permisos: TODOS los roles del panel ven la flota (view_fleet), pero solo
     * quienes tienen manage_machines (administrador + responsable_mantenimiento)
     * pueden crear/editar/borrar. Taller y Gerencia son de solo lectura aquí.
     */
    public static function canViewAny(): bool
    {
        return Auth::user()?->can('view_fleet') ?? false;
    }

    public static function canView(Model $record): bool
    {
        return Auth::user()?->can('view_fleet') ?? false;
    }

    public static function canCreate(): bool
    {
        return Auth::user()?->can('manage_machines') ?? false;
    }

    public static function canEdit(Model $record): bool
    {
        return Auth::user()?->can('manage_machines') ?? false;
    }

    public static function canDelete(Model $record): bool
    {
        return Auth::user()?->can('manage_machines') ?? false;
    }

    public static function canDeleteAny(): bool
    {
        return Auth::user()?->can('manage_machines') ?? false;
    }

    public static function getNavigationBadge(): ?string
    {
        // muestra cuántas máquinas están próximas a servicio (<=100h)
        $count = static::getModel()::where('status', 'active')->get()
            ->filter(fn (Machine $m) => $m->is_due_soon)->count();

        return $count > 0 ? (string) $count : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'warning';
    }

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make(__('fleet.identification'))
                ->columns(3)
                ->schema([
                    Forms\Components\TextInput::make('id_code')
                        ->label(__('fleet.id_code'))->required()->maxLength(50),
                    Forms\Components\Select::make('machine_category_id')
                        ->label(__('fleet.category'))
                        ->relationship('category', 'name')->searchable()->preload(),
                    Forms\Components\Select::make('make_id')
                        ->label(__('fleet.make'))
                        ->relationship('make', 'name')->searchable()->preload()->createOptionForm([
                            Forms\Components\TextInput::make('name')->required(),
                            Forms\Components\Hidden::make('slug'),
                        ]),
                    Forms\Components\TextInput::make('model')->label(__('fleet.model')),
                    Forms\Components\TextInput::make('serial')->label(__('fleet.serial')),
                    Forms\Components\Select::make('serial_type')
                        ->label(__('fleet.serial_type'))
                        ->options(['S/N' => 'S/N', 'PIN' => 'PIN', 'VIN' => 'VIN']),
                    Forms\Components\TextInput::make('year')->numeric()->label(__('fleet.year')),
                    Forms\Components\Select::make('current_location_id')
                        ->label(__('fleet.location'))
                        ->relationship('location', 'name')->searchable()->preload(),
                    Forms\Components\Textarea::make('description')
                        ->label(__('fleet.description'))->columnSpanFull()->rows(2),
                ]),

            Forms\Components\Section::make(__('fleet.status_service'))
                ->columns(3)
                ->schema([
                    Forms\Components\Select::make('status')
                        ->label(__('fleet.status'))
                        ->required()
                        ->options([
                            'active' => __('fleet.status_active'),
                            'not_in_service' => __('fleet.status_not_in_service'),
                            'down' => __('fleet.status_down'),
                            'inactive' => __('fleet.status_inactive'),
                            'unknown' => __('fleet.status_unknown'),
                        ])->default('active'),
                    Forms\Components\Select::make('hourmeter_status')
                        ->label(__('fleet.hourmeter_status'))
                        ->required()
                        ->options([
                            'ok' => 'OK',
                            'broken' => __('fleet.hm_broken'),
                            'no_info' => __('fleet.hm_no_info'),
                            'replaced' => __('fleet.hm_replaced'),
                        ])->default('ok'),
                    Forms\Components\TextInput::make('hours_adjustment')
                        ->label(__('fleet.hours_adjustment'))->numeric()->default(0),
                    Forms\Components\TextInput::make('current_hours')
                        ->label(__('fleet.current_hours'))->numeric()->suffix('h'),
                    Forms\Components\DatePicker::make('current_hours_date')
                        ->label(__('fleet.current_hours_date')),
                    Forms\Components\TextInput::make('service_interval_hours')
                        ->label(__('fleet.service_interval'))->numeric()->default(500)->suffix('h'),
                    Forms\Components\TextInput::make('last_service_hours')
                        ->label(__('fleet.last_service_hours'))->numeric()->suffix('h'),
                    Forms\Components\DatePicker::make('last_service_date')
                        ->label(__('fleet.last_service_date')),
                    Forms\Components\TextInput::make('remaining_hours')
                        ->label(__('fleet.remaining_hours'))->numeric()->suffix('h')
                        ->helperText(__('fleet.remaining_help')),
                ]),

            Forms\Components\Section::make(__('fleet.technical'))
                ->columns(3)->collapsed()
                ->schema([
                    Forms\Components\TextInput::make('engine_model')->label(__('fleet.engine_model')),
                    Forms\Components\TextInput::make('engine_serial')->label(__('fleet.engine_serial')),
                    Forms\Components\TextInput::make('electrical_system')->label(__('fleet.electrical')),
                    Forms\Components\TextInput::make('battery_cca')->label('CCA'),
                    Forms\Components\TextInput::make('tires')->label(__('fleet.tires')),
                    Forms\Components\TextInput::make('oil_capacity')->label(__('fleet.oil_capacity'))->columnSpanFull(),
                    Forms\Components\Textarea::make('spec_sheet')
                        ->label(__('fleet.spec_sheet'))->columnSpanFull()->rows(12)
                        ->helperText(__('fleet.spec_sheet_help')),
                ]),

            Forms\Components\Section::make(__('fleet.images'))
                ->columns(2)->collapsed()
                ->schema([
                    Forms\Components\FileUpload::make('image')
                        ->label(__('fleet.image'))
                        ->image()
                        ->imageEditor()
                        ->disk('public')
                        ->directory('machines/images'),
                    Forms\Components\FileUpload::make('gallery')
                        ->label(__('fleet.gallery'))
                        ->image()
                        ->multiple()
                        ->reorderable()
                        ->disk('public')
                        ->directory('machines/gallery'),
                ]),

            Forms\Components\Section::make(__('fleet.data_control'))
                ->columns(3)
                ->schema([
                    Forms\Components\Toggle::make('needs_review')->label(__('fleet.needs_review')),
                    Forms\Components\TextInput::make('review_note')->label(__('fleet.review_note'))->columnSpan(2),
                    Forms\Components\Textarea::make('notes')->label(__('fleet.notes'))->columnSpanFull()->rows(2),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('id_code')
                    ->label(__('fleet.id_code'))->searchable()->sortable()->weight('bold'),
                Tables\Columns\TextColumn::make('category.name')
                    ->label(__('fleet.category'))->badge()->sortable()->toggleable(),
                Tables\Columns\TextColumn::make('make.name')
                    ->label(__('fleet.make'))->sortable()->toggleable(),
                Tables\Columns\TextColumn::make('model')->label(__('fleet.model'))->searchable()->toggleable(),
                Tables\Columns\TextColumn::make('location.name')
                    ->label(__('fleet.location'))->badge()->color('gray')->sortable(),
                Tables\Columns\TextColumn::make('current_hours')
                    ->label(__('fleet.current_hours'))->numeric()->sortable()
                    ->formatStateUsing(fn ($state, Machine $r) => $state !== null ? number_format($state).' h' : '—'),
                Tables\Columns\TextColumn::make('computed_remaining_hours')
                    ->label(__('fleet.remaining_hours'))
                    ->badge()
                    ->formatStateUsing(fn ($state) => $state === null ? '—' : number_format($state).' h')
                    ->color(fn (Machine $r) => match ($r->service_status) {
                        'overdue' => 'danger',
                        'due_soon' => 'warning',
                        'ok' => 'success',
                        default => 'gray',
                    })
                    ->sortable(query: fn (Builder $q, string $dir) => $q->orderBy('remaining_hours', $dir)),
                Tables\Columns\TextColumn::make('status')
                    ->label(__('fleet.status'))->badge()
                    ->formatStateUsing(fn ($state) => __('fleet.status_'.$state))
                    ->color(fn ($state) => match ($state) {
                        'active' => 'success',
                        'down' => 'danger',
                        'not_in_service', 'inactive' => 'gray',
                        default => 'warning',
                    }),
                Tables\Columns\IconColumn::make('hourmeter_status')
                    ->label('HM')
                    ->icon(fn ($state) => match ($state) {
                        'ok' => 'heroicon-o-check-circle',
                        'broken' => 'heroicon-o-x-circle',
                        'no_info' => 'heroicon-o-question-mark-circle',
                        'replaced' => 'heroicon-o-arrow-path',
                        default => 'heroicon-o-minus',
                    })
                    ->color(fn ($state) => $state === 'ok' ? 'success' : 'warning')
                    ->toggleable(),
                Tables\Columns\IconColumn::make('needs_review')
                    ->label(__('fleet.review'))->boolean()
                    ->trueIcon('heroicon-o-flag')->falseIcon('')->trueColor('warning')->toggleable(),
            ])
            ->defaultSort('id_code')
            ->groups([
                Tables\Grouping\Group::make('category.name')->label(__('fleet.category')),
                Tables\Grouping\Group::make('location.name')->label(__('fleet.location')),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('machine_category_id')
                    ->label(__('fleet.category'))->relationship('category', 'name')->multiple()->preload(),
                Tables\Filters\SelectFilter::make('current_location_id')
                    ->label(__('fleet.location'))->relationship('location', 'name')->multiple()->preload(),
                Tables\Filters\SelectFilter::make('status')
                    ->label(__('fleet.status'))->options([
                        'active' => __('fleet.status_active'),
                        'not_in_service' => __('fleet.status_not_in_service'),
                        'down' => __('fleet.status_down'),
                        'inactive' => __('fleet.status_inactive'),
                    ]),
                Tables\Filters\Filter::make('due_soon')
                    ->label(__('fleet.due_soon'))->toggle()
                    ->query(fn (Builder $q) => $q->where('status', 'active')
                        ->whereNotNull('remaining_hours')
                        ->where('remaining_hours', '<=', Machine::ALERT_THRESHOLD)),
                Tables\Filters\TernaryFilter::make('needs_review')->label(__('fleet.needs_review')),
            ])
            ->actions([
                Tables\Actions\ViewAction::make(),
                Tables\Actions\EditAction::make(),
                Tables\Actions\Action::make('approve')
                    ->label(__('fleet.approve_data'))
                    ->icon('heroicon-o-check-badge')
                    ->color('success')
                    ->visible(fn (Machine $record) => $record->needs_review && (Auth::user()?->can('verify_data') ?? false))
                    ->requiresConfirmation()
                    ->modalDescription(__('fleet.approve_confirm'))
                    ->action(function (Machine $record) {
                        $record->update(['needs_review' => false]);

                        // Log manual: intencionalmente fuera de logOnly() del modelo
                        // (needs_review no está en el listOnly de Machine) para dejar
                        // una entrada explícita y legible del hecho de negocio "aprobado",
                        // en vez de un diff de atributos.
                        activity()
                            ->performedOn($record)
                            ->causedBy(Auth::user())
                            ->event('approved')
                            ->log('Datos verificados y aprobados');

                        Notification::make()->success()->title(__('fleet.approved_ok'))->send();
                    }),
                Tables\Actions\Action::make('move')
                    ->label(__('mgmt.move_machine'))
                    ->icon('heroicon-o-arrows-right-left')
                    ->color('info')
                    ->visible(fn () => Auth::user()?->can('move_fleet') ?? false)
                    ->form([
                        Forms\Components\Select::make('current_location_id')
                            ->label(__('mgmt.move_to'))
                            ->options(fn () => Location::query()->where('active', true)->pluck('name', 'id'))
                            ->searchable()
                            ->required(),
                    ])
                    ->requiresConfirmation()
                    ->modalDescription(__('mgmt.move_confirm'))
                    ->action(function (Machine $record, array $data) {
                        $record->update(['current_location_id' => $data['current_location_id']]);

                        Notification::make()->success()->title(__('mgmt.move_success'))->send();
                    }),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make(),
                    Tables\Actions\BulkAction::make('approveBulk')
                        ->label(__('fleet.approve_bulk'))
                        ->icon('heroicon-o-check-badge')
                        ->color('success')
                        ->visible(fn () => Auth::user()?->can('verify_data') ?? false)
                        ->requiresConfirmation()
                        ->modalDescription(__('fleet.approve_confirm'))
                        ->deselectRecordsAfterCompletion()
                        ->action(function (Collection $records) {
                            $user = Auth::user();
                            $approved = 0;

                            $records->each(function (Machine $machine) use ($user, &$approved) {
                                if (! $machine->needs_review) {
                                    return;
                                }

                                $machine->update(['needs_review' => false]);

                                activity()
                                    ->performedOn($machine)
                                    ->causedBy($user)
                                    ->event('approved')
                                    ->log('Datos verificados y aprobados');

                                $approved++;
                            });

                            Notification::make()->success()
                                ->title(__('fleet.approved_bulk_ok', ['count' => $approved]))
                                ->send();
                        }),
                    Tables\Actions\BulkAction::make('move')
                        ->label(__('mgmt.move_machines'))
                        ->icon('heroicon-o-arrows-right-left')
                        ->color('info')
                        ->visible(fn () => Auth::user()?->can('move_fleet') ?? false)
                        ->form([
                            Forms\Components\Select::make('current_location_id')
                                ->label(__('mgmt.move_to'))
                                ->options(fn () => Location::query()->where('active', true)->pluck('name', 'id'))
                                ->searchable()
                                ->required(),
                        ])
                        ->requiresConfirmation()
                        ->modalDescription(__('mgmt.move_confirm'))
                        ->deselectRecordsAfterCompletion()
                        ->action(function (Collection $records, array $data) {
                            $records->each(fn (Machine $machine) => $machine->update([
                                'current_location_id' => $data['current_location_id'],
                            ]));

                            Notification::make()->success()->title(__('mgmt.move_success_bulk'))->send();
                        }),
                ]),
            ]);
    }

    public static function getRelations(): array
    {
        return [
            RelationManagers\PartsRelationManager::class,
            RelationManagers\ReadingsRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListMachines::route('/'),
            'create' => Pages\CreateMachine::route('/create'),
            'view' => Pages\ViewMachine::route('/{record}'),
            'edit' => Pages\EditMachine::route('/{record}/edit'),
        ];
    }
}

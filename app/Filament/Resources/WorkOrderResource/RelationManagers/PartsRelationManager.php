<?php

namespace App\Filament\Resources\WorkOrderResource\RelationManagers;

use App\Filament\Concerns\DeletesOnlyWhileWorkOrderIsOpen;
use App\Models\MachinePart;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;

class PartsRelationManager extends RelationManager
{
    use DeletesOnlyWhileWorkOrderIsOpen;

    protected static string $relationship = 'parts';

    protected static ?string $recordTitleAttribute = 'part_number';

    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return __('wo.parts_used');
    }

    public function form(Form $form): Form
    {
        $machine = $this->getOwnerRecord()->machine;

        return $form->schema([
            Forms\Components\Select::make('machine_part_id')
                ->label(__('wo.catalog_part'))
                ->options(fn () => $machine
                    ? $machine->parts()->pluck('label', 'id')
                    : [])
                ->searchable()
                ->preload()
                ->live()
                ->afterStateUpdated(function ($state, Forms\Set $set) {
                    if (! $state) {
                        return;
                    }
                    $part = MachinePart::find($state);
                    if ($part) {
                        $set('part_number', $part->oem_number ?: $part->napa_number);
                        $set('description', $part->label);
                    }
                })
                ->helperText(__('wo.catalog_part_help')),
            Forms\Components\TextInput::make('part_number')
                ->label(__('wo.part_number'))
                ->maxLength(255),
            Forms\Components\TextInput::make('description')
                ->label(__('fleet.description'))
                ->maxLength(255)
                ->columnSpanFull(),
            Forms\Components\TextInput::make('quantity')
                ->label(__('wo.quantity'))
                ->numeric()
                ->default(1)
                ->required(),
            Forms\Components\TextInput::make('unit_cost')
                ->label(__('wo.unit_cost'))
                ->numeric()
                ->prefix('$')
                ->visible(fn () => Auth::user()?->can('view_costs') ?? false),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('part_number')
                    ->label(__('wo.part_number'))
                    ->searchable()
                    ->copyable()
                    ->placeholder('—'),
                Tables\Columns\TextColumn::make('description')
                    ->label(__('fleet.description'))
                    ->wrap()
                    ->searchable(),
                Tables\Columns\TextColumn::make('quantity')
                    ->label(__('wo.quantity'))
                    ->numeric(),
                Tables\Columns\TextColumn::make('unit_cost')
                    ->label(__('wo.unit_cost'))
                    ->money('usd')
                    ->visible(fn () => Auth::user()?->can('view_costs') ?? false),
                Tables\Columns\TextColumn::make('subtotal')
                    ->label(__('wo.subtotal'))
                    ->state(fn ($record) => (float) $record->quantity * (float) $record->unit_cost)
                    ->money('usd')
                    ->visible(fn () => Auth::user()?->can('view_costs') ?? false),
            ])
            // Orden manual (2026-09-01): prioritario del lote de reordenamiento
            // porque CostReportBuilder respeta este orden (WorkOrder::parts()
            // ya ordena por sort_order), así que lo que se arrastra acá es lo
            // que sale en la pantalla, el PDF y el Excel del reporte de costos.
            // Mismo permiso que edita las líneas: execute_work_order.
            ->reorderable('sort_order')
            ->authorizeReorder(fn () => Auth::user()?->can('execute_work_order') ?? false)
            ->headerActions([Tables\Actions\CreateAction::make()])
            ->actions([
                Tables\Actions\EditAction::make(),
                // ->authorize(fn () => true): no se oculta el botón (defecto
                // reportado por el cliente); canDelete() sigue siendo el
                // único gate real, movido a ->disabled(), con el motivo en
                // ->tooltip(). Ver el docblock de deletionBlockedReason().
                Tables\Actions\DeleteAction::make()
                    ->authorize(fn (): bool => true)
                    ->disabled(fn (Model $record): bool => $this->isReadOnly() || ! $this->canDelete($record))
                    ->tooltip(fn (Model $record): ?string => $this->deletionBlockedReason($record)),
            ])
            ->emptyStateHeading(__('wo.parts_empty_heading'))
            ->emptyStateDescription(__('wo.parts_empty_desc'));
    }

    /* ----------------------------------------------------------------- *
     * Autorizacion propia (hallazgo A8).
     *
     * Antes estas acciones no declaraban nada y quedaban a merced de la
     * autorizacion "heredada" de Filament, que sin Policy devuelve allow():
     * `$this->can('create')` -> `Filament\authorize(..., true)` -> sin
     * Policy -> Response::allow(). Este proyecto no tiene app/Policies, asi
     * que heredar era permitir. Lo unico que limitaba era el canEdit() del
     * Resource dueno, que no defiende una llamada directa al componente.
     * ----------------------------------------------------------------- */

    protected function canCreate(): bool
    {
        return Auth::user()?->can('execute_work_order') ?? false;
    }

    protected function canEdit(Model $record): bool
    {
        return Auth::user()?->can('execute_work_order') ?? false;
    }

    // canDelete()/canDeleteAny() los aporta el trait
    // DeletesOnlyWhileWorkOrderIsOpen: el borrado se gobierna por ESTADO de la
    // OT, no por rol. Con la OT cerrada no borra nadie.
}

<?php

namespace App\Filament\Resources\WorkOrderResource\RelationManagers;

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
            ->headerActions([Tables\Actions\CreateAction::make()])
            ->actions([
                Tables\Actions\EditAction::make(),
                Tables\Actions\DeleteAction::make(),
            ])
            ->emptyStateHeading(__('wo.parts_empty_heading'))
            ->emptyStateDescription(__('wo.parts_empty_desc'));
    }
}

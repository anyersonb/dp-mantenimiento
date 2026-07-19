<?php

namespace App\Filament\Resources\MachineResource\RelationManagers;

use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

class PartsRelationManager extends RelationManager
{
    protected static string $relationship = 'parts';

    protected static ?string $recordTitleAttribute = 'label';

    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return __('fleet.parts_catalog');
    }

    public function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\TextInput::make('label')->label(__('fleet.part'))->required()->columnSpanFull(),
            Forms\Components\Select::make('category')->label(__('fleet.part_category'))->options([
                'oil_filter' => 'Oil filter', 'fuel_primary' => 'Fuel filter (primary)',
                'fuel_secondary' => 'Fuel filter (secondary)', 'fuel_inline' => 'Fuel filter (in-line)',
                'air_inner' => 'Air filter (inner)', 'air_outer' => 'Air filter (outer)',
                'hydraulic' => 'Hydraulic', 'transmission' => 'Transmission', 'crankcase' => 'Crankcase',
                'ac_filter' => 'A/C filter', 'emissions' => 'Emissions/DEF', 'electrical' => 'Electrical',
                'belt' => 'Belt', 'water_pump' => 'Water pump', 'cutting_edge' => 'Cutting edge',
                'tires' => 'Tires', 'attachment' => 'Attachment', 'other' => 'Other',
            ]),
            Forms\Components\TextInput::make('oem_number')->label('OEM #'),
            Forms\Components\TextInput::make('napa_number')->label('NAPA #'),
            Forms\Components\TextInput::make('change_interval_hours')->label(__('fleet.change_interval'))->numeric()->suffix('h'),
            Forms\Components\Textarea::make('detail')->label(__('fleet.detail'))->columnSpanFull()->rows(2),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('label')->label(__('fleet.part'))->wrap()->searchable(),
                Tables\Columns\TextColumn::make('category')->label(__('fleet.part_category'))->badge()->toggleable(),
                Tables\Columns\TextColumn::make('oem_number')->label('OEM #')->searchable()->copyable(),
                Tables\Columns\TextColumn::make('napa_number')->label('NAPA #')->searchable()->copyable()->placeholder('—'),
                Tables\Columns\TextColumn::make('change_interval_hours')->label(__('fleet.change_interval'))
                    ->badge()->color('info')
                    ->formatStateUsing(fn ($state) => $state ? $state.' h' : '—')->sortable(),
            ])
            ->defaultSort('change_interval_hours')
            ->filters([
                Tables\Filters\SelectFilter::make('change_interval_hours')->label(__('fleet.change_interval'))
                    ->options([500 => '500 h', 1000 => '1000 h', 2000 => '2000 h', 4000 => '4000 h']),
            ])
            ->headerActions([Tables\Actions\CreateAction::make()])
            ->actions([Tables\Actions\EditAction::make(), Tables\Actions\DeleteAction::make()]);
    }
}

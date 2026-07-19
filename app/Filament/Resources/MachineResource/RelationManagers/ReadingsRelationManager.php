<?php

namespace App\Filament\Resources\MachineResource\RelationManagers;

use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

class ReadingsRelationManager extends RelationManager
{
    protected static string $relationship = 'readings';

    protected static ?string $recordTitleAttribute = 'hours';

    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return __('fleet.horometer_history');
    }

    public function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\TextInput::make('hours')->label(__('fleet.hours'))->numeric()->required()->suffix('h'),
            Forms\Components\DatePicker::make('read_at')->label(__('fleet.read_at'))->required()->default(now()),
            Forms\Components\Select::make('source')->label(__('fleet.source'))->options([
                'fuel' => __('fleet.src_fuel'), 'maintenance' => __('fleet.src_maintenance'),
                'foreman' => 'Foreman', 'workshop' => __('fleet.src_workshop'),
                'manual' => __('fleet.src_manual'), 'import' => 'Import',
            ])->default('manual')->required(),
            Forms\Components\TextInput::make('gallons')->label(__('fleet.gallons'))->numeric()->suffix('gal'),
            Forms\Components\Toggle::make('verified')->label(__('fleet.verified'))->default(true),
            Forms\Components\TextInput::make('note')->label(__('fleet.note'))->columnSpanFull(),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('read_at')->label(__('fleet.read_at'))->date()->sortable(),
                Tables\Columns\TextColumn::make('hours')->label(__('fleet.hours'))->numeric()->sortable()
                    ->formatStateUsing(fn ($state) => number_format($state).' h'),
                Tables\Columns\TextColumn::make('source')->label(__('fleet.source'))->badge(),
                Tables\Columns\TextColumn::make('gallons')->label(__('fleet.gallons'))
                    ->formatStateUsing(fn ($state) => $state ? number_format($state, 1).' gal' : '—'),
                Tables\Columns\IconColumn::make('verified')->label(__('fleet.verified'))->boolean(),
                Tables\Columns\TextColumn::make('note')->label(__('fleet.note'))->wrap()->toggleable(),
            ])
            ->defaultSort('read_at', 'desc')
            ->headerActions([Tables\Actions\CreateAction::make()])
            ->actions([Tables\Actions\EditAction::make(), Tables\Actions\DeleteAction::make()]);
    }
}

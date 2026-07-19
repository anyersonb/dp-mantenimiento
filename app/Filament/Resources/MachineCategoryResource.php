<?php

namespace App\Filament\Resources;

use App\Filament\Resources\MachineCategoryResource\Pages;
use App\Models\MachineCategory;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Support\Str;

class MachineCategoryResource extends Resource
{
    protected static ?string $model = MachineCategory::class;

    protected static ?string $navigationIcon = 'heroicon-o-squares-2x2';

    protected static ?int $navigationSort = 11;

    public static function getNavigationLabel(): string
    {
        return __('nav.categories');
    }

    public static function getModelLabel(): string
    {
        return __('nav.category_singular');
    }

    public static function getPluralModelLabel(): string
    {
        return __('nav.categories');
    }

    public static function getNavigationGroup(): ?string
    {
        return __('fleet.group_admin');
    }

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\TextInput::make('name')->label(__('fleet.category'))->required()
                ->live(onBlur: true)
                ->afterStateUpdated(fn ($state, Forms\Set $set) => $set('slug', Str::slug((string) $state))),
            Forms\Components\Hidden::make('slug'),
            Forms\Components\TextInput::make('prefix')->label(__('nav.prefix'))->maxLength(5),
            Forms\Components\TextInput::make('default_service_interval')->label(__('fleet.service_interval'))
                ->numeric()->default(500)->suffix('h'),
        ])->columns(2);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('name')->label(__('fleet.category'))->searchable()->sortable()->weight('bold'),
                Tables\Columns\TextColumn::make('prefix')->label(__('nav.prefix'))->badge(),
                Tables\Columns\TextColumn::make('default_service_interval')->label(__('fleet.service_interval'))
                    ->formatStateUsing(fn ($state) => $state.' h'),
                Tables\Columns\TextColumn::make('machines_count')->label(__('fleet.machines'))->counts('machines')->badge()->color('info'),
            ])
            ->defaultSort('name')
            ->actions([Tables\Actions\EditAction::make()])
            ->bulkActions([Tables\Actions\BulkActionGroup::make([Tables\Actions\DeleteBulkAction::make()])]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListMachineCategories::route('/'),
            'create' => Pages\CreateMachineCategory::route('/create'),
            'edit' => Pages\EditMachineCategory::route('/{record}/edit'),
        ];
    }
}

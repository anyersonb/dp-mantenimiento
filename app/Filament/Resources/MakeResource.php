<?php

namespace App\Filament\Resources;

use App\Filament\Resources\MakeResource\Pages;
use App\Models\Make;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Support\Str;

class MakeResource extends Resource
{
    protected static ?string $model = Make::class;

    protected static ?string $navigationIcon = 'heroicon-o-building-office-2';

    protected static ?int $navigationSort = 12;

    public static function getNavigationLabel(): string
    {
        return __('nav.makes');
    }

    public static function getModelLabel(): string
    {
        return __('nav.make_singular');
    }

    public static function getPluralModelLabel(): string
    {
        return __('nav.makes');
    }

    public static function getNavigationGroup(): ?string
    {
        return __('fleet.group_admin');
    }

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\TextInput::make('name')->label(__('fleet.make'))->required()
                ->live(onBlur: true)
                ->afterStateUpdated(fn ($state, Forms\Set $set) => $set('slug', Str::slug((string) $state))),
            Forms\Components\Hidden::make('slug'),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('name')->label(__('fleet.make'))->searchable()->sortable()->weight('bold'),
                Tables\Columns\TextColumn::make('machines_count')->label(__('fleet.machines'))->counts('machines')->badge()->color('info'),
            ])
            ->defaultSort('name')
            ->actions([Tables\Actions\EditAction::make()])
            ->bulkActions([Tables\Actions\BulkActionGroup::make([Tables\Actions\DeleteBulkAction::make()])]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListMakes::route('/'),
            'create' => Pages\CreateMake::route('/create'),
            'edit' => Pages\EditMake::route('/{record}/edit'),
        ];
    }
}

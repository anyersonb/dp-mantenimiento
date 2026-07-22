<?php

namespace App\Filament\Resources;

use App\Filament\Resources\LocationResource\Pages;
use App\Models\Location;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;

class LocationResource extends Resource
{
    protected static ?string $model = Location::class;

    protected static ?string $navigationIcon = 'heroicon-o-map-pin';

    protected static ?int $navigationSort = 10;

    public static function getNavigationLabel(): string
    {
        return __('nav.locations');
    }

    public static function getModelLabel(): string
    {
        return __('nav.location');
    }

    public static function getPluralModelLabel(): string
    {
        return __('nav.locations');
    }

    public static function getNavigationGroup(): ?string
    {
        return __('fleet.group_admin');
    }

    /* Solo lectura para todos los del panel (view_fleet); gestión solo con manage_machines. */
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

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\TextInput::make('name')->label(__('nav.location_name'))->required()
                ->live(onBlur: true)
                ->afterStateUpdated(fn ($state, Forms\Set $set) => $set('slug', Str::slug((string) $state))),
            Forms\Components\Hidden::make('slug'),
            Forms\Components\Select::make('type')->label(__('nav.type'))
                ->options(['yard' => __('nav.yard'), 'jobsite' => __('nav.jobsite')])->default('jobsite')->required(),
            Forms\Components\TextInput::make('address')->label(__('nav.address'))->columnSpanFull(),
            Forms\Components\TextInput::make('latitude')->label(__('nav.latitude'))->numeric(),
            Forms\Components\TextInput::make('longitude')->label(__('nav.longitude'))->numeric(),
            Forms\Components\Toggle::make('active')->label(__('nav.active'))->default(true),
        ])->columns(2);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('name')->label(__('nav.location_name'))->searchable()->sortable()->weight('bold'),
                Tables\Columns\TextColumn::make('type')->label(__('nav.type'))->badge()
                    ->formatStateUsing(fn ($state) => __('nav.'.$state)),
                Tables\Columns\TextColumn::make('machines_count')->label(__('fleet.machines'))->counts('machines')->badge()->color('info'),
                Tables\Columns\IconColumn::make('active')->label(__('nav.active'))->boolean(),
            ])
            ->defaultSort('name')
            ->actions([Tables\Actions\EditAction::make()])
            ->bulkActions([Tables\Actions\BulkActionGroup::make([Tables\Actions\DeleteBulkAction::make()])]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListLocations::route('/'),
            'create' => Pages\CreateLocation::route('/create'),
            'edit' => Pages\EditLocation::route('/{record}/edit'),
        ];
    }
}

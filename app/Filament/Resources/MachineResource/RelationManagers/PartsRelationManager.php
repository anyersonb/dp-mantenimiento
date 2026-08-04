<?php

namespace App\Filament\Resources\MachineResource\RelationManagers;

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

    protected static ?string $recordTitleAttribute = 'label';

    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return __('fleet.parts_catalog');
    }

    /**
     * Mismo defecto que en ReadingsRelationManager (E6-06): sin estos dos,
     * Filament deriva la etiqueta del nombre de la clase y salía el literal
     * "machine part" sin traducir en los botones y modales de esta sección.
     */
    protected static function getModelLabel(): ?string
    {
        return __('fleet.part');
    }

    protected static function getPluralModelLabel(): ?string
    {
        return __('fleet.parts');
    }

    /**
     * Categorías de repuesto, traducidas al idioma de quien lee.
     *
     * Las claves son los valores que se guardan en `machine_parts.category`
     * (las sembró MachineSpecSeeder al parsear el Info Book), así que
     * renombrarlas exige migración. Los textos viven en
     * `lang/{es,en}/parts.php`.
     *
     * OJO, defecto encontrado al revisar el español en producción
     * (2026-07-27): esta lista tenía 18 opciones de grano fino que **ningún
     * dato usa** y le faltaba `filter`, que es la que MachineSpecSeeder le pone
     * a **532 de las 1002 partes** (`MachineSpecSeeder::partCategory()` solo
     * produce filter/belt/attachment/electrical/other). No era solo cosmético:
     * al abrir una de esas 532 partes, el Select no encontraba su opción, el
     * campo salía vacío y al guardar borraba la categoría. Las cinco reales van
     * primero; las de grano fino se conservan porque sirven para clasificar a
     * mano, no porque las escriba el sistema. Cubierto por
     * TranslationParitySentinelTest.
     *
     * @return array<string, string>
     */
    public static function categoryOptions(): array
    {
        $keys = [
            // Las que realmente existen en la base (ver el seeder).
            'filter', 'belt', 'attachment', 'electrical', 'other',
            // Clasificación fina, solo para carga manual.
            'oil_filter', 'fuel_primary', 'fuel_secondary', 'fuel_inline',
            'air_inner', 'air_outer', 'hydraulic', 'transmission', 'crankcase',
            'ac_filter', 'emissions', 'water_pump', 'cutting_edge', 'tires',
        ];

        return array_combine($keys, array_map(fn (string $k) => __('parts.'.$k), $keys));
    }

    public function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\TextInput::make('label')->label(__('fleet.part'))->required()->columnSpanFull(),
            // Las 18 categorías estaban en inglés fijo acá dentro: el panel en
            // español las mostraba sin traducir. Las claves son los valores
            // guardados en machine_parts.category, no se tocan.
            Forms\Components\Select::make('category')->label(__('fleet.part_category'))
                ->options(self::categoryOptions()),
            Forms\Components\TextInput::make('oem_number')->label('OEM #'),
            Forms\Components\TextInput::make('napa_number')->label('NAPA #'),
            Forms\Components\TextInput::make('change_interval_hours')->label(__('fleet.change_interval'))->numeric()->minValue(0)->suffix('h'),
            Forms\Components\Textarea::make('detail')->label(__('fleet.detail'))->columnSpanFull()->rows(2),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('label')->label(__('fleet.part'))->wrap()->searchable(),
                // Sin formatStateUsing la tabla mostraba la clave cruda
                // ("oil_filter") como badge, en los dos idiomas.
                Tables\Columns\TextColumn::make('category')->label(__('fleet.part_category'))->badge()->toggleable()
                    ->formatStateUsing(fn ($state) => $state ? (self::categoryOptions()[$state] ?? $state) : '—'),
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

    /* ----------------------------------------------------------------- *
     * Autorización propia (hallazgo A8). Ver la nota extendida en
     * ReadingsRelationManager: sin Policy, la autorización heredada de un
     * relation manager es `Response::allow()`.
     * ----------------------------------------------------------------- */

    protected function canCreate(): bool
    {
        return Auth::user()?->can('manage_machines') ?? false;
    }

    protected function canEdit(Model $record): bool
    {
        return Auth::user()?->can('manage_machines') ?? false;
    }

    protected function canDelete(Model $record): bool
    {
        return Auth::user()?->can('manage_machines') ?? false;
    }

    protected function canDeleteAny(): bool
    {
        return Auth::user()?->can('manage_machines') ?? false;
    }
}

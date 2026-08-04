<?php

namespace App\Filament\Resources\MachineResource\RelationManagers;

use App\Rules\CoherentHorometerReading;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;

class ReadingsRelationManager extends RelationManager
{
    protected static string $relationship = 'readings';

    protected static ?string $recordTitleAttribute = 'hours';

    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return __('fleet.horometer_history');
    }

    /**
     * Sin estos dos, Filament arma la etiqueta desde el nombre de la clase del
     * modelo y le sale **"horometer reading"** en cualquier idioma: es el texto
     * que aparecía en "Create horometer reading", en el título del modal de
     * editar y en el de borrar (hallazgo E6-06, reportado por el cliente el
     * 2026-08-03 — además está mal escrito: la palabra es "hourmeter").
     */
    protected static function getModelLabel(): ?string
    {
        return __('fleet.reading_singular');
    }

    protected static function getPluralModelLabel(): ?string
    {
        return __('fleet.reading_plural');
    }

    public function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\TextInput::make('hours')->label(__('fleet.hours'))->numeric()->minValue(0)->required()->suffix('h')
                // Hallazgo E6-03: este camino no tenía NINGUNA validación de
                // coherencia, así que aceptaba un horómetro que baja con el
                // tiempo. La regla es la MISMA que usan los tres componentes de
                // campo — una regla, un lugar (App\Rules\CoherentHorometerReading).
                ->rule(function (Forms\Get $get, ?Model $record) {
                    return new CoherentHorometerReading(
                        $this->getOwnerRecord(),
                        $get('read_at') ? (string) $get('read_at') : null,
                        $record?->getKey(),
                    );
                }),
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

    /* ----------------------------------------------------------------- *
     * Autorización propia (hallazgo A8).
     *
     * Antes estas acciones no declaraban nada y quedaban a merced de la
     * autorización "heredada" de Filament, que sin Policy devuelve allow():
     * `$this->can('create')` → `Filament\authorize(..., true)` → sin Policy →
     * `Response::allow()`. Este proyecto no tiene `app/Policies`, así que
     * heredar era permitir. Lo único que limitaba era el `canEdit()` del
     * Resource dueño, que no defiende una llamada directa al componente
     * (probado en `RelationManagerWritePermissionTest`).
     *
     * Este relation manager escribe sobre el dato más sensible del sistema:
     * las lecturas de horómetro de las que dependen `remaining_hours`, el
     * ancla del PM report y las alertas de servicio.
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

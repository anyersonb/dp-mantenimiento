<?php

namespace App\Filament\Resources;

use App\Filament\Resources\FieldReportResource\Pages;
use App\Models\FieldReport;
use App\Support\AccessControl;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Form;
use Filament\Infolists\Components as InfolistComponents;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;

/**
 * Pantalla de solo lectura de los reportes de campo (`field_reports`).
 *
 * Nace de un hallazgo real: el operario reporta desde /field/report y el
 * dato se guardaba, pero no existía NINGUNA pantalla en el panel para
 * leerlo — el único rastro visible era el conteo en el diálogo de borrado de
 * una máquina (Machine::destructionSummary()). Un reporte 🔴 crítico no
 * llegaba a nadie.
 *
 * Solo lectura a propósito: los reportes los crea el operario en campo, no
 * se editan ni se borran desde acá (no hay ->canCreate()/->canEdit() que
 * habilitar; se sobreescriben explícitos en false, mismo patrón que
 * ActivityResource).
 *
 * Gate por PERMISO (`view_field_reports`), vía App\Support\AccessControl —
 * mismo mecanismo que AlertResource, no un `$user->can()` suelto.
 */
class FieldReportResource extends Resource
{
    protected static ?string $model = FieldReport::class;

    protected static ?string $navigationIcon = 'heroicon-o-clipboard-document-list';

    protected static ?int $navigationSort = 4;

    public static function getNavigationLabel(): string
    {
        return __('field_reports.nav');
    }

    public static function getModelLabel(): string
    {
        return __('field_reports.model_singular');
    }

    public static function getPluralModelLabel(): string
    {
        return __('field_reports.model_plural');
    }

    public static function getNavigationGroup(): ?string
    {
        return __('fleet.group_operations');
    }

    /**
     * Cuenta los reportes CRÍTICOS todavía sin atender, para quien la mira.
     *
     * `field_reports` no tiene un campo de estado (a diferencia de `Alert`),
     * así que "sin atender" se define acá como "notificación de este evento,
     * de este usuario, todavía no leída" — exactamente lo que el módulo de
     * notificaciones ya lleva la cuenta. Marcar la notificación como leída
     * (desde la campanita o la bandeja de /field) es lo que baja el número:
     * no hace falta un campo nuevo en la tabla para esto.
     */
    public static function getNavigationBadge(): ?string
    {
        $user = Auth::user();

        if ($user === null) {
            return null;
        }

        $count = $user->unreadNotifications()
            ->where('data->event', 'field_report.needs_attention')
            ->where('data->condition', 'critical')
            ->count();

        return $count > 0 ? (string) $count : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'danger';
    }

    public static function canViewAny(): bool
    {
        return AccessControl::allows(Auth::user(), 'view_field_reports');
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit(Model $record): bool
    {
        return false;
    }

    public static function canDelete(Model $record): bool
    {
        return false;
    }

    public static function canDeleteAny(): bool
    {
        return false;
    }

    public static function form(Form $form): Form
    {
        return $form->schema([]);
    }

    protected static function conditionColor(?string $state): string
    {
        return match ($state) {
            'critical' => 'danger',
            'attention' => 'warning',
            'ok' => 'success',
            default => 'gray',
        };
    }

    protected static function conditionLabel(?string $state): string
    {
        return $state !== null ? __('field_reports.condition_'.$state) : '—';
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('machine.id_code')
                    ->label(__('fleet.machine'))->badge()->searchable()->sortable(),
                Tables\Columns\TextColumn::make('location.name')
                    ->label(__('fleet.location'))->searchable()->sortable()
                    ->placeholder('—'),
                Tables\Columns\TextColumn::make('condition')
                    ->label(__('field_reports.column_condition'))
                    ->badge()
                    ->formatStateUsing(fn (?string $state) => static::conditionLabel($state))
                    ->color(fn (?string $state) => static::conditionColor($state))
                    ->sortable(),
                Tables\Columns\TextColumn::make('reporter.name')
                    ->label(__('field_reports.column_reporter'))
                    ->placeholder('—')
                    ->searchable(),
                Tables\Columns\TextColumn::make('hours')
                    ->label(__('field_reports.column_hours'))
                    ->formatStateUsing(fn ($state) => $state === null ? '—' : number_format($state).' h')
                    ->sortable(),
                Tables\Columns\TextColumn::make('location_status')
                    ->label(__('field_reports.column_location_status'))
                    ->badge()
                    ->getStateUsing(fn (FieldReport $record) => $record->latitude !== null && $record->longitude !== null
                        ? __('field_reports.location_yes')
                        : __('field_reports.location_no'))
                    ->color(fn (FieldReport $record) => $record->latitude !== null && $record->longitude !== null
                        ? 'success'
                        : 'gray'),
                Tables\Columns\TextColumn::make('created_at')
                    ->label(__('field_reports.column_date'))
                    ->dateTime()->sortable(),
            ])
            ->defaultSort('created_at', 'desc')
            ->filters([
                Tables\Filters\SelectFilter::make('machine')
                    ->label(__('fleet.machine'))
                    ->relationship('machine', 'id_code')
                    ->searchable(),
                Tables\Filters\SelectFilter::make('location')
                    ->label(__('fleet.location'))
                    ->relationship('location', 'name')
                    ->searchable(),
                // El de estado es el que más se usa (crítico, en un clic):
                // va primero y sin necesidad de buscar.
                Tables\Filters\SelectFilter::make('condition')
                    ->label(__('field_reports.filter_condition'))
                    ->options([
                        'critical' => __('field_reports.condition_critical'),
                        'attention' => __('field_reports.condition_attention'),
                        'ok' => __('field_reports.condition_ok'),
                    ]),
                Tables\Filters\Filter::make('created_at')
                    ->form([
                        DatePicker::make('from')->label(__('mgmt.date_from')),
                        DatePicker::make('until')->label(__('mgmt.date_until')),
                    ])
                    ->query(function (Builder $query, array $data) {
                        return $query
                            ->when($data['from'] ?? null, fn ($q, $date) => $q->whereDate('created_at', '>=', $date))
                            ->when($data['until'] ?? null, fn ($q, $date) => $q->whereDate('created_at', '<=', $date));
                    }),
            ])
            ->actions([
                Tables\Actions\ViewAction::make()
                    ->infolist([
                        InfolistComponents\Section::make()
                            ->columns(2)
                            ->schema([
                                InfolistComponents\TextEntry::make('machine.id_code')->label(__('fleet.machine')),
                                InfolistComponents\TextEntry::make('location.name')->label(__('fleet.location'))->placeholder('—'),
                                InfolistComponents\TextEntry::make('condition')
                                    ->label(__('field_reports.column_condition'))
                                    ->badge()
                                    ->formatStateUsing(fn (?string $state) => static::conditionLabel($state))
                                    ->color(fn (?string $state) => static::conditionColor($state)),
                                InfolistComponents\TextEntry::make('reporter.name')->label(__('field_reports.column_reporter'))->placeholder('—'),
                                InfolistComponents\TextEntry::make('hours')
                                    ->label(__('field_reports.column_hours'))
                                    ->formatStateUsing(fn ($state) => $state === null ? '—' : number_format($state).' h'),
                                InfolistComponents\TextEntry::make('created_at')->label(__('field_reports.column_date'))->dateTime(),
                                InfolistComponents\TextEntry::make('notes')
                                    ->label(__('field_reports.detail_notes'))
                                    ->placeholder(__('field_reports.detail_no_notes'))
                                    ->columnSpanFull(),
                            ]),
                        InfolistComponents\Section::make(__('field_reports.detail_location'))
                            // Hallazgo 2 (auditoría 2026-09-18): la ubicación GPS
                            // es seguimiento de personal, no mantenimiento de
                            // flota — se acota con su propio permiso, más angosto
                            // que view_field_reports (que ya abrió esta pantalla).
                            // taller/gerencia ven el resto del detalle igual.
                            ->visible(fn () => AccessControl::allows(Auth::user(), 'view_field_report_location'))
                            ->schema([
                                // Hallazgo del lote anterior: una celda vacía se
                                // puede leer como "todavía no cargó". Acá se
                                // distingue EXPLÍCITAMENTE con un texto, no con
                                // un espacio en blanco.
                                InfolistComponents\TextEntry::make('location_no')
                                    ->hiddenLabel()
                                    ->getStateUsing(fn () => __('field_reports.location_no'))
                                    ->color('gray')
                                    ->visible(fn (FieldReport $record) => $record->latitude === null || $record->longitude === null),
                                InfolistComponents\TextEntry::make('map_link')
                                    ->label(__('field_reports.detail_map_link'))
                                    ->getStateUsing(fn (FieldReport $record) => __('field_reports.detail_map_link'))
                                    ->url(fn (FieldReport $record) => "https://www.google.com/maps?q={$record->latitude},{$record->longitude}")
                                    ->openUrlInNewTab()
                                    ->color('primary')
                                    ->visible(fn (FieldReport $record) => $record->latitude !== null && $record->longitude !== null),
                            ]),
                        // Pedido del cliente (2026-09-22): OT abiertas a partir de
                        // este reporte. Solo lectura a propósito —FieldReportResource
                        // entero lo es (ver el docblock de la clase)—, sin ningún link
                        // de edición: no hace falta abrir escritura en un recurso de
                        // solo lectura para que sea útil saber qué OT salió de acá.
                        //
                        // Vuelta 2 (2026-09-22), hallazgo Bajo 2 de seguridad: la
                        // matriz de HOY hace que quien tiene view_field_reports
                        // tenga también view_fleet, pero los roles se editan desde
                        // el panel — un rol a medida con el primero y sin el
                        // segundo vería código y estado de OT ajenos a su alcance.
                        // Gate propio explícito en vez de confiar en la
                        // coincidencia de la matriz actual.
                        InfolistComponents\Section::make(__('field_reports.detail_work_orders'))
                            ->visible(fn (FieldReport $record) => AccessControl::allows(Auth::user(), 'view_fleet')
                                && $record->workOrders()->exists())
                            ->schema([
                                InfolistComponents\RepeatableEntry::make('workOrders')
                                    ->hiddenLabel()
                                    ->columns(2)
                                    ->schema([
                                        InfolistComponents\TextEntry::make('code')->hiddenLabel(),
                                        InfolistComponents\TextEntry::make('status')->hiddenLabel()->badge()
                                            ->formatStateUsing(fn (?string $state) => $state !== null ? __('wo.'.$state) : '—')
                                            ->color(fn (?string $state) => match ($state) {
                                                'completed' => 'success', 'cancelled' => 'gray', 'in_progress' => 'info',
                                                'assigned' => 'warning', default => 'primary',
                                            }),
                                    ]),
                            ]),
                    ]),
            ])
            ->bulkActions([])
            ->emptyStateHeading(__('field_reports.empty_heading'))
            ->emptyStateDescription(__('field_reports.empty_desc'));
    }

    public static function getEloquentQuery(): Builder
    {
        // Evita N+1: la tabla siempre pinta máquina, obra y reportero.
        return parent::getEloquentQuery()->with(['machine', 'location', 'reporter']);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListFieldReports::route('/'),
        ];
    }
}

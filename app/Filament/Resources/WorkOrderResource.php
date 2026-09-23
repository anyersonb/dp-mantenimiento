<?php

namespace App\Filament\Resources;

use App\Filament\Concerns\HasPapeleraActions;
use App\Filament\Resources\WorkOrderResource\Pages;
use App\Filament\Resources\WorkOrderResource\RelationManagers;
use App\Models\FieldReport;
use App\Models\WorkOrder;
use App\Services\WorkOrderCompletionService;
use App\Support\AccessControl;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rules\Exists;

class WorkOrderResource extends Resource
{
    use HasPapeleraActions;

    protected static ?string $model = WorkOrder::class;

    protected static ?string $navigationIcon = 'heroicon-o-clipboard-document-list';

    protected static ?int $navigationSort = 2;

    /*
     * Hallazgo C1 (QA Etapa 05): este Resource no tenía ni un solo control de
     * permisos y por eso gerencia llegó a borrar una orden de trabajo real y
     * taller creaba OTs sin tener create_work_order. Matriz aplicada:
     * - view_fleet: quien ve flota ve el listado/detalle de OT (todos los
     *   roles del panel lo tienen; el filtro real de costos vive en el form).
     * - create_work_order: abrir/asignar una OT (responsable_mantenimiento,
     *   administrador).
     * - execute_work_order: ejecutar/editar una OT ya abierta (taller,
     *   administrador) — le da uso real al permiso que antes era letra
     *   muerta (hallazgo A6).
     * - borrado: solo administrador, porque una OT es historial de
     *   mantenimiento (el activo que el cliente quiere sacar del Excel).
     */
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
        return Auth::user()?->can('create_work_order') ?? false;
    }

    public static function canEdit(Model $record): bool
    {
        return Auth::user()?->can('execute_work_order') ?? false;
    }

    /**
     * Borrar una orden de trabajo pasa a decidirse por el permiso
     * `delete_work_orders` en vez de por el nombre del rol. Hoy lo tiene solo
     * administrador --el mismo alcance de antes--, pero ahora es editable
     * desde la pantalla de Roles.
     */
    public static function canDelete(Model $record): bool
    {
        return AccessControl::allows(Auth::user(), 'delete_work_orders');
    }

    public static function canDeleteAny(): bool
    {
        return AccessControl::allows(Auth::user(), 'delete_work_orders');
    }

    /* --------------------- Papelera (Lote A) --------------------- */

    protected static function papeleraResourceKey(): string
    {
        return 'work_orders';
    }

    protected static function papeleraRecordLabel(Model $record): string
    {
        /** @var WorkOrder $record */
        return (string) $record->code;
    }

    public static function getNavigationLabel(): string
    {
        return __('wo.work_orders');
    }

    public static function getModelLabel(): string
    {
        return __('wo.work_order');
    }

    public static function getPluralModelLabel(): string
    {
        return __('wo.work_orders');
    }

    public static function getNavigationGroup(): ?string
    {
        return __('fleet.group_operations');
    }

    /**
     * Etiqueta del selector de reporte de campo: fecha + condición + quién lo
     * hizo, para que se pueda elegir entre varios reportes de la misma
     * máquina sin adivinar cuál es cuál.
     */
    protected static function fieldReportOptionLabel(FieldReport $report): string
    {
        $date = $report->created_at?->format('Y-m-d') ?? '—';
        $condition = __('field_reports.condition_'.$report->condition);
        $reporter = $report->reporter?->name ?? __('field_reports.unknown_reporter');

        return "{$date} — {$condition} — {$reporter}";
    }

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make()->columns(3)->schema([
                Forms\Components\TextInput::make('code')->label(__('wo.code'))
                    // Hallazgos E6-09 (el código salía de max(id)+1, que no es
                    // el id de la fila ni un valor libre garantizado) y E6-07
                    // (el duplicado era un 500 mudo, reproducido con QA-OT-01).
                    ->default(fn () => WorkOrder::nextCode())
                    ->required()->maxLength(50)
                    ->unique(ignoreRecord: true),
                Forms\Components\Select::make('machine_id')->label(__('fleet.machines'))
                    ->relationship('machine', 'id_code')->searchable()->preload()->required()
                    // Pedido del cliente (2026-09-22): el selector de reporte de
                    // campo solo ofrece los reportes de ESTA máquina. Si la
                    // máquina cambia, el reporte elegido deja de pertenecerle,
                    // así que se limpia acá mismo (ver field_report_id abajo).
                    ->live()
                    ->afterStateUpdated(fn (Forms\Set $set) => $set('field_report_id', null)),
                Forms\Components\Select::make('type')->label(__('wo.type'))->options([
                    'inspection' => __('wo.inspection'), 'preventive' => __('wo.preventive'), 'corrective' => __('wo.corrective'),
                ])->default('preventive')->required(),
                // Pedido del cliente (2026-09-22): el número de orden no es
                // mantenimiento por horómetro, es reparación correctiva o
                // upgrade. Se suman esas dos opciones a las cuatro que ya
                // existían; en una OT nueva creada a mano el default es
                // "repair". ->in() valida en el servidor que el valor
                // enviado sea una de las seis opciones — ver el docblock de
                // WorkOrder::serviceTierOptions() sobre por qué esto NO se
                // repite como restricción en el Observer.
                Forms\Components\Select::make('service_tier')->label(__('wo.service_tier'))
                    ->options(WorkOrder::serviceTierOptions())
                    ->default(WorkOrder::SERVICE_TIER_DEFAULT)
                    ->in(array_keys(WorkOrder::serviceTierOptions())),
                // Pedido del cliente (2026-09-22): la OT puede quedar asociada a
                // un reporte de campo, nunca de forma obligatoria. El desplegable
                // solo ofrece los reportes DE LA MÁQUINA elegida arriba, del más
                // reciente al más viejo, y se vacía si la máquina cambia.
                //
                // Gate por permiso: hoy los únicos roles que llegan a esta página
                // (create_work_order o execute_work_order) tienen TODOS
                // view_field_reports (ver RolesAndPermissionsSeeder::MATRIX), así
                // que no hay ningún caso real donde esto oculte el campo. Se deja
                // igual como cinturón de seguridad: si el día de mañana la matriz
                // de permisos se separa, quien no pueda ver reportes de campo no
                // debe poder enumerarlos a través de este selector.
                Forms\Components\Select::make('field_report_id')->label(__('wo.field_report'))
                    ->helperText(__('wo.field_report_help'))
                    ->visible(fn () => Auth::user()?->can('view_field_reports') ?? false)
                    ->searchable()
                    ->options(function (Forms\Get $get) {
                        $machineId = $get('machine_id');

                        if (blank($machineId)) {
                            return [];
                        }

                        return FieldReport::query()
                            ->where('machine_id', $machineId)
                            ->with('reporter')
                            ->orderByDesc('created_at')
                            ->get()
                            ->mapWithKeys(fn (FieldReport $report) => [
                                $report->id => static::fieldReportOptionLabel($report),
                            ])
                            ->all();
                    })
                    ->exists('field_reports', 'id', modifyRuleUsing: function (Exists $rule, Forms\Get $get) {
                        return $rule->where('machine_id', $get('machine_id'));
                    }),
                Forms\Components\Select::make('status')->label(__('fleet.status'))->options([
                    'open' => __('wo.open'), 'assigned' => __('wo.assigned'), 'in_progress' => __('wo.in_progress'),
                    'completed' => __('wo.completed'), 'cancelled' => __('wo.cancelled'),
                ])->default('open')->required(),
                Forms\Components\Select::make('priority')->label(__('wo.priority'))->options([
                    'normal' => __('wo.normal'), 'high' => __('wo.high'), 'urgent' => __('wo.urgent'),
                ])->default('normal')->required(),
                // Hallazgo E6-12: el desplegable ofrecía los 7 usuarios, así que
                // se podía asignar una OT a gerencia o al operador de cisterna,
                // que no pueden ejecutarla. El scope `permission()` de spatie
                // cubre tanto el permiso por rol como el asignado directo.
                Forms\Components\Select::make('assigned_to')->label(__('wo.assigned_to'))
                    ->helperText(__('wo.assigned_to_help'))
                    ->relationship(
                        'assignee',
                        'name',
                        fn (Builder $query) => $query->permission('execute_work_order')
                    )->searchable()->preload(),
                Forms\Components\Radio::make('execution_mode')->label(__('wo.execution_mode'))->options([
                    'workshop' => __('wo.workshop'), 'onsite' => __('wo.onsite'),
                ])->default('workshop')->inline()->inlineLabel(false),
                Forms\Components\DatePicker::make('opened_at')->label(__('wo.opened_at'))->default(now()),
                Forms\Components\DatePicker::make('completed_at')->label(__('wo.completed_at')),
                // Hallazgo E6-08: el formulario no tenía forma de registrar las
                // horas de apertura, y son la ÚNICA fuente para cerrar el
                // servicio de las 41 máquinas sin horómetro cargado. Si queda
                // vacío, el observer lo sella con las horas de la máquina.
                Forms\Components\TextInput::make('hours_at_open')
                    ->label(__('wo.hours_at_open'))
                    ->helperText(__('wo.hours_at_open_help'))
                    ->numeric()->minValue(0)->suffix('h'),
            ]),
            Forms\Components\Section::make(__('wo.execution'))->columns(2)->schema([
                Forms\Components\TextInput::make('labor_hours')->label(__('wo.labor_hours'))->numeric()->suffix('h'),
                Forms\Components\TextInput::make('parts_cost')->label(__('wo.parts_cost'))->numeric()->prefix('$')
                    ->helperText(__('wo.cost_help'))
                    ->disabled()
                    ->dehydrated(false)
                    ->visible(fn () => Auth::user()?->can('view_costs') ?? false),
                Forms\Components\Textarea::make('description')->label(__('fleet.description'))
                    ->helperText(__('wo.description_help'))->columnSpanFull(),
                Forms\Components\Textarea::make('resolution')->label(__('wo.resolution'))->columnSpanFull(),
            ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('code')->label(__('wo.code'))->searchable()->sortable()->weight('bold'),
                Tables\Columns\TextColumn::make('machine.id_code')->label(__('fleet.machines'))->badge()->searchable(),
                Tables\Columns\TextColumn::make('type')->label(__('wo.type'))->badge()
                    ->formatStateUsing(fn ($state) => __('wo.'.$state)),
                Tables\Columns\TextColumn::make('service_tier')->label(__('wo.service_tier'))
                    ->formatStateUsing(fn (?string $state) => WorkOrder::serviceTierLabel($state))
                    ->placeholder('—')
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('status')->label(__('fleet.status'))->badge()
                    ->formatStateUsing(fn ($state) => __('wo.'.$state))
                    ->color(fn ($state) => match ($state) {
                        'completed' => 'success', 'cancelled' => 'gray', 'in_progress' => 'info',
                        'assigned' => 'warning', default => 'primary',
                    }),
                Tables\Columns\TextColumn::make('priority')->label(__('wo.priority'))->badge()
                    ->formatStateUsing(fn ($state) => __('wo.'.$state))
                    ->color(fn ($state) => match ($state) {
                        'urgent' => 'danger', 'high' => 'warning', default => 'gray',
                    }),
                Tables\Columns\TextColumn::make('execution_mode')->label(__('wo.execution_mode'))->badge()
                    ->formatStateUsing(fn ($state) => $state ? __('wo.'.$state) : '—')
                    ->color(fn ($state) => $state === 'onsite' ? 'info' : 'gray'),
                Tables\Columns\TextColumn::make('assignee.name')->label(__('wo.assigned_to'))->placeholder('—'),
                Tables\Columns\TextColumn::make('opened_at')->label(__('wo.opened_at'))->date()->sortable(),
            ])
            /*
             * Orden manual (2026-09-01): defaultSort pasa a `sort_order`
             * ASCENDENTE — a propósito, no es un descuido de dirección.
             *
             * Filament, mientras el modo arrastrar está activo, SIEMPRE ordena
             * el reorderColumn ascendente (`CanSortRecords::isTableReordering()`),
             * sin importar qué diga defaultSort. Si acá se dejara `desc` (o
             * `created_at desc`), la pantalla "saltaría" de orden cada vez que
             * alguien activa o desactiva el botón de arrastrar, y el orden
             * recién arrastrado NO sobreviviría a un refresco — que fue
             * exactamente el defecto reportado.
             *
             * Por eso el backfill de la migración 2026_09_01_100400 asigna
             * sort_order=1 a la OT MÁS NUEVA (no a la más vieja) y
             * WorkOrder::manualOrderPrepend() hace que una OT nueva nazca en 1,
             * corriendo el resto. Con las dos cosas, `sort_order` ascendente
             * se ve IDÉNTICO a `created_at desc` mientras nadie arrastre nada,
             * y el arrastre persiste al recargar porque no hay ningún otro
             * criterio de orden compitiendo.
             */
            ->reorderable('sort_order')
            ->authorizeReorder(fn () => Auth::user()?->can('execute_work_order') ?? false)
            ->defaultSort('sort_order')
            ->filters([
                Tables\Filters\SelectFilter::make('status')->label(__('fleet.status'))->options([
                    'open' => __('wo.open'), 'assigned' => __('wo.assigned'), 'in_progress' => __('wo.in_progress'),
                    'completed' => __('wo.completed'), 'cancelled' => __('wo.cancelled'),
                ]),
                Tables\Filters\SelectFilter::make('type')->label(__('wo.type'))->options([
                    'inspection' => __('wo.inspection'), 'preventive' => __('wo.preventive'), 'corrective' => __('wo.corrective'),
                ]),
                static::papeleraTrashedFilter(),
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
                static::papeleraRestoreAction(),
                static::papeleraForceDeleteAction(),
                Tables\Actions\Action::make('complete')
                    ->label(__('wo.complete'))
                    ->icon('heroicon-o-check-circle')
                    ->color('success')
                    ->visible(fn (WorkOrder $record) => ! in_array($record->status, ['completed', 'cancelled'], true))
                    // Hallazgo A6: "completar" solo validaba el status, nunca el
                    // permiso. execute_work_order pasa a tener uso real aquí.
                    ->authorize(fn () => Auth::user()?->can('execute_work_order') ?? false)
                    ->requiresConfirmation()
                    ->modalDescription(__('wo.complete_confirm'))
                    ->action(function (WorkOrder $record, Tables\Actions\Action $action) {
                        // Hallazgo E6-08: se pregunta ANTES de tocar el estado.
                        // Rechazar después de marcar "completada" dejaría la OT
                        // cerrada y la máquina sin servicio registrado, que es
                        // peor que el defecto original.
                        if (! WorkOrderCompletionService::canComplete($record)) {
                            Notification::make()
                                ->title(__('wo.cannot_complete_no_hours'))
                                ->body(__('wo.cannot_complete_no_hours_body', ['machine' => $record->machine?->id_code ?? '—']))
                                ->warning()
                                ->persistent()
                                ->send();

                            $action->halt();
                        }

                        $record->update([
                            'status' => 'completed',
                            'completed_at' => $record->completed_at ?? now()->toDateString(),
                        ]);

                        WorkOrderCompletionService::complete($record->fresh('machine'));
                    }),
            ])
            ->bulkActions([Tables\Actions\BulkActionGroup::make([
                Tables\Actions\DeleteBulkAction::make(),
                static::papeleraRestoreBulkAction(),
                static::papeleraForceDeleteBulkAction(),
            ])])
            ->emptyStateHeading(__('wo.empty_heading'))
            ->emptyStateDescription(__('wo.empty_desc'));
    }

    public static function getRelations(): array
    {
        return [
            RelationManagers\ChecklistResultsRelationManager::class,
            RelationManagers\PartsRelationManager::class,
            RelationManagers\AttachmentsRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListWorkOrders::route('/'),
            'create' => Pages\CreateWorkOrder::route('/create'),
            'edit' => Pages\EditWorkOrder::route('/{record}/edit'),
        ];
    }
}

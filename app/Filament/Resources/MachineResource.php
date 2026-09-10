<?php

namespace App\Filament\Resources;

use App\Filament\Concerns\HasPapeleraActions;
use App\Filament\Resources\MachineResource\Pages;
use App\Filament\Resources\MachineResource\RelationManagers;
use App\Models\Location;
use App\Models\Machine;
use App\Rules\RejectsDangerousUploadExtensions;
use App\Services\HourmeterReplacementService;
use App\Support\AccessControl;
use App\Support\LocalizedText;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;

class MachineResource extends Resource
{
    use HasPapeleraActions;

    protected static ?string $model = Machine::class;

    protected static ?string $navigationIcon = 'heroicon-o-truck';

    protected static ?int $navigationSort = 1;

    public static function getNavigationLabel(): string
    {
        return __('fleet.machines');
    }

    public static function getModelLabel(): string
    {
        return __('fleet.machine');
    }

    public static function getPluralModelLabel(): string
    {
        return __('fleet.machines');
    }

    public static function getNavigationGroup(): ?string
    {
        return __('fleet.group_fleet');
    }

    /*
     * Permisos: TODOS los roles del panel ven la flota (view_fleet), pero solo
     * quienes tienen manage_machines (administrador + responsable_mantenimiento)
     * pueden crear/editar/borrar. Taller y Gerencia son de solo lectura aquí.
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
        return Auth::user()?->can('manage_machines') ?? false;
    }

    public static function canEdit(Model $record): bool
    {
        return Auth::user()?->can('manage_machines') ?? false;
    }

    /**
     * Hallazgo E6-05 (crítico): borrar una máquina se lleva en cascada sus
     * órdenes de trabajo, lecturas, alertas, partes y costos históricos. Con
     * `manage_machines` lo podía hacer también el responsable, y el diálogo no
     * advertía nada.
     *
     * Queda restringido al permiso `delete_machines` --que hoy tiene solo
     * administrador, igual que antes, pero que ahora se puede mover desde la
     * pantalla de Roles sin tocar codigo--, y aun así es un borrado suave
     * (`SoftDeletes`). El camino normal de baja es `status = 'inactive'` o la
     * acción "descartar" de las máquinas en revisión, que conservan la historia.
     */
    public static function canDelete(Model $record): bool
    {
        return AccessControl::allows(Auth::user(), 'delete_machines');
    }

    public static function canDeleteAny(): bool
    {
        return AccessControl::allows(Auth::user(), 'delete_machines');
    }

    /* --------------------- Papelera (Lote A) --------------------- */

    protected static function papeleraResourceKey(): string
    {
        return 'machines';
    }

    protected static function papeleraRecordLabel(Model $record): string
    {
        /** @var Machine $record */
        return (string) $record->id_code;
    }

    /**
     * Texto del diálogo del borrado SUAVE (papelera), con los conteos REALES
     * de historial que tiene la máquina hoy. Antes decía únicamente "¿Seguro
     * que querés hacer esto?".
     *
     * Hallazgo seguridad Bajo, 2026-09-09: comparte `destructionSummary()`
     * con el borrado DEFINITIVO (`papeleraForceDeleteWarning()`), pero el
     * texto de `fleet.delete_warning` NO puede describir un "borrado en
     * cascada" — un soft delete de `Machine` no toca a ninguno de estos
     * hijos (ninguno se archiva ni se pierde con ella; el FK
     * `cascadeOnDelete()` solo se dispara con un DELETE real). Al sumarle
     * `withTrashed()` al conteo de `work_orders` (hallazgo Alto de esta misma
     * auditoría), el modal empezó a contar OT ya archivadas dentro de una
     * frase que afirmaba perderlas "también, en cascada" — falso siempre,
     * más visible desde ese fix. `lang/{es,en}/fleet.php` ahora dicen lo que
     * de verdad pasa: se archiva, es reversible, el historial queda intacto.
     */
    public static function deletionWarning(Machine $record): string
    {
        $resumen = $record->destructionSummary();

        return __('fleet.delete_warning', [
            'machine' => $record->id_code,
            'work_orders' => $resumen['work_orders'],
            'readings' => $resumen['readings'],
            'alerts' => $resumen['alerts'],
            'parts' => $resumen['parts'],
            'field_reports' => $resumen['field_reports'],
        ]);
    }

    /* ------------- Papelera: impacto del borrado DEFINITIVO ------------- */

    /**
     * Hallazgo "papelera reabre E6-05": `papeleraForceDeleteAction()` llamaba
     * `forceDelete()` pelado. Las FK que apuntan a `machines` son todas
     * `cascadeOnDelete()`, así que sin este resumen y sin la confirmación
     * fuerte de abajo, un solo clic en la papelera destruía el historial
     * completo sin avisar nada (verificado en la base local: máquina con 1
     * OT + 1 lectura + 17 partes, las tres tablas quedaron en 0 tras el
     * force delete).
     */
    protected static function papeleraDestructionSummary(Model $record): ?array
    {
        /** @var Machine $record */
        return $record->destructionSummary();
    }

    protected static function papeleraForceDeleteWarning(Model $record, array $summary): string
    {
        /** @var Machine $record */
        return __('fleet.force_delete_warning', [
            'machine' => $record->id_code,
            'work_orders' => $summary['work_orders'] ?? 0,
            'readings' => $summary['readings'] ?? 0,
            'parts' => $summary['parts'] ?? 0,
            'alerts' => $summary['alerts'] ?? 0,
            'field_reports' => $summary['field_reports'] ?? 0,
        ]);
    }

    protected static function papeleraForceDeleteBulkWarning(Collection $records, array $summary): string
    {
        return __('fleet.force_delete_bulk_warning', [
            'count' => $records->count(),
            'work_orders' => $summary['work_orders'] ?? 0,
            'readings' => $summary['readings'] ?? 0,
            'parts' => $summary['parts'] ?? 0,
            'alerts' => $summary['alerts'] ?? 0,
            'field_reports' => $summary['field_reports'] ?? 0,
        ]);
    }

    /**
     * El administrador tiene que volver a teclear el código de la máquina
     * (el mismo que la identifica en el listado, ej. "CR001") para habilitar
     * el borrado definitivo cuando hay historial de por medio — un
     * `requiresConfirmation()` normal no alcanza para esto.
     */
    protected static function papeleraForceDeleteConfirmationValue(Model $record): ?string
    {
        /** @var Machine $record */
        return (string) $record->id_code;
    }

    /**
     * Hallazgo seguridad Medio, 2026-09-09: la cascada manual (OT + adjuntos
     * físicos) y el borrado de las fotos propias de la máquina se movieron a
     * `Machine::forceDeleting()` (mismo patrón que `WorkOrder::forceDeleting()`),
     * porque `App\Console\Commands\QaCleanup` llama `forceDelete()` DIRECTO
     * sobre la máquina, fuera del panel, y ese camino se saltea cualquier
     * hook que viva solo acá. Este método vuelve a ser el no-op por defecto
     * de `HasPapeleraActions` — no hace falta sobreescribirlo.
     */
    public static function getNavigationBadge(): ?string
    {
        // muestra cuántas máquinas están próximas a servicio (<=100h)
        $count = static::getModel()::where('status', 'active')->get()
            ->filter(fn (Machine $m) => $m->is_due_soon)->count();

        return $count > 0 ? (string) $count : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'warning';
    }

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make(__('fleet.identification'))
                ->columns(3)
                ->schema([
                    Forms\Components\TextInput::make('id_code')
                        ->label(__('fleet.id_code'))->required()->maxLength(50)
                        // Filament arma el :attribute del mensaje de validación
                        // con lcfirst(label), y con la etiqueta "ID" eso daba
                        // "El campo iD es obligatorio.". Se fija a mano.
                        ->validationAttribute(__('fleet.id_code'))
                        // Hallazgo E6-07: sin esto, un id_code repetido era un
                        // 500 mudo (verificado con QA-CIS-01 en la Sesión 1).
                        ->unique(ignoreRecord: true),
                    Forms\Components\Select::make('machine_category_id')
                        ->label(__('fleet.category'))
                        ->relationship('category', 'name')->searchable()->preload()
                        // El nombre canónico sigue siendo el de la base; lo que
                        // se muestra va en el idioma del usuario. Ver
                        // MachineCategory::displayName().
                        ->getOptionLabelFromRecordUsing(fn ($record) => $record->display_name),
                    Forms\Components\Select::make('make_id')
                        ->label(__('fleet.make'))
                        ->relationship('make', 'name')->searchable()->preload()->createOptionForm([
                            Forms\Components\TextInput::make('name')->required(),
                            Forms\Components\Hidden::make('slug'),
                        ]),
                    Forms\Components\TextInput::make('model')->label(__('fleet.model')),
                    Forms\Components\TextInput::make('serial')->label(__('fleet.serial')),
                    Forms\Components\Select::make('serial_type')
                        ->label(__('fleet.serial_type'))
                        ->options(['S/N' => 'S/N', 'PIN' => 'PIN', 'VIN' => 'VIN']),
                    Forms\Components\TextInput::make('year')->numeric()->label(__('fleet.year')),
                    Forms\Components\Select::make('current_location_id')
                        ->label(__('fleet.location'))
                        // Sin ->relationship(): ésa arma la búsqueda contra la
                        // columna `name` en SQL y `display_name` no existe ahí.
                        // Con ->getSearchResultsUsing() explícito, la búsqueda
                        // encuentra tanto por número de trabajo como por nombre.
                        ->options(fn () => Location::locationSelectOptions())
                        ->getSearchResultsUsing(fn (string $search) => Location::locationSelectOptions($search))
                        ->getOptionLabelUsing(fn ($value) => Location::find($value)?->display_name)
                        ->searchable()->preload(),
                    Forms\Components\Textarea::make('description')
                        ->label(__('fleet.description'))->columnSpanFull()->rows(2),
                ]),

            Forms\Components\Section::make(__('fleet.status_service'))
                ->columns(3)
                ->schema([
                    Forms\Components\Select::make('status')
                        ->label(__('fleet.status'))
                        ->required()
                        ->options([
                            'active' => __('fleet.status_active'),
                            'not_in_service' => __('fleet.status_not_in_service'),
                            'down' => __('fleet.status_down'),
                            'inactive' => __('fleet.status_inactive'),
                            'unknown' => __('fleet.status_unknown'),
                        ])->default('active'),
                    Forms\Components\Select::make('hourmeter_status')
                        ->label(__('fleet.hourmeter_status'))
                        ->required()
                        ->options([
                            'ok' => 'OK',
                            'broken' => __('fleet.hm_broken'),
                            'no_info' => __('fleet.hm_no_info'),
                            'replaced' => __('fleet.hm_replaced'),
                        ])->default('ok'),
                    Forms\Components\TextInput::make('hours_adjustment')
                        ->label(__('fleet.hours_adjustment'))->numeric()->default(0),
                    Forms\Components\TextInput::make('current_hours')
                        ->label(__('fleet.current_hours'))->numeric()->minValue(0)->suffix('h'),
                    Forms\Components\DatePicker::make('current_hours_date')
                        ->label(__('fleet.current_hours_date')),
                    Forms\Components\TextInput::make('service_interval_hours')
                        ->label(__('fleet.service_interval'))->numeric()->minValue(0)->default(500)->suffix('h'),
                    Forms\Components\TextInput::make('last_service_hours')
                        ->label(__('fleet.last_service_hours'))->numeric()->minValue(0)->suffix('h'),
                    Forms\Components\DatePicker::make('last_service_date')
                        ->label(__('fleet.last_service_date')),
                    Forms\Components\TextInput::make('remaining_hours')
                        ->label(__('fleet.remaining_hours'))->numeric()->suffix('h')
                        ->helperText(__('fleet.remaining_help')),
                ]),

            Forms\Components\Section::make(__('fleet.technical'))
                ->columns(3)->collapsed()
                ->schema([
                    Forms\Components\TextInput::make('engine_model')->label(__('fleet.engine_model')),
                    Forms\Components\TextInput::make('engine_serial')->label(__('fleet.engine_serial')),
                    Forms\Components\TextInput::make('electrical_system')->label(__('fleet.electrical')),
                    Forms\Components\TextInput::make('battery_cca')->label('CCA'),
                    Forms\Components\TextInput::make('tires')->label(__('fleet.tires')),
                    Forms\Components\TextInput::make('oil_capacity')->label(__('fleet.oil_capacity'))->columnSpanFull(),
                    Forms\Components\Textarea::make('spec_sheet')
                        ->label(__('fleet.spec_sheet'))->columnSpanFull()->rows(12)
                        ->helperText(__('fleet.spec_sheet_help')),
                ]),

            /*
             * Etapa 05 (auditoria A5): a diferencia de facturas/cotizaciones,
             * las fotos de maquina NO son evidencia de costos y no estan
             * cubiertas por view_costs en el brief del cliente. Se quedan en
             * disk('public') deliberadamente:
             * - Moverlas a disco privado exigiria una URL firmada/temporal
             *   para que el <img> del panel (ficha + galeria + FilePond de
             *   este mismo FileUpload) siga renderizando, y el disco "local"
             *   (driver local) no soporta temporaryUrl() como si soporta S3
             *   — habria que escribir un proxy autenticado para cada thumbnail,
             *   una complicacion real para un riesgo bajo (foto de un camion,
             *   no un dato del cliente ni una cifra de costo).
             * - Se refuerzan igual el tipo/tamano/extension permitidos, que
             *   es la parte de este hallazgo que si aplica a cualquier subida.
             */
            Forms\Components\Section::make(__('fleet.images'))
                ->columns(2)->collapsed()
                ->schema([
                    Forms\Components\FileUpload::make('image')
                        ->label(__('fleet.image'))
                        ->image()
                        ->imageEditor()
                        ->disk('public')
                        ->directory('machines/images')
                        ->maxSize(5120)
                        ->rule(new RejectsDangerousUploadExtensions(['png', 'jpg', 'jpeg', 'webp'])),
                    Forms\Components\FileUpload::make('gallery')
                        ->label(__('fleet.gallery'))
                        ->image()
                        ->multiple()
                        ->reorderable()
                        ->disk('public')
                        ->directory('machines/gallery')
                        ->maxSize(5120)
                        ->rule(new RejectsDangerousUploadExtensions(['png', 'jpg', 'jpeg', 'webp'])),
                ]),

            Forms\Components\Section::make(__('fleet.data_control'))
                ->columns(3)
                ->schema([
                    // Hallazgo A3: responsable_mantenimiento (tiene manage_machines
                    // pero no verify_data) apagaba needs_review desde este mismo
                    // form normal de edición. disabled() bloquea la UI y
                    // dehydrated() evita que el valor viaje de vuelta al modelo
                    // aunque se manipule el payload; el guardia real y de fondo
                    // (Machine usa $guarded = []) vive en MachineObserver::saving().
                    Forms\Components\Toggle::make('needs_review')
                        ->label(__('fleet.needs_review'))
                        ->disabled(fn () => ! (Auth::user()?->can('verify_data') ?? false))
                        ->dehydrated(fn () => Auth::user()?->can('verify_data') ?? false),
                    Forms\Components\TextInput::make('review_note')->label(__('fleet.review_note'))->columnSpan(2),
                    Forms\Components\Textarea::make('notes')->label(__('fleet.notes'))->columnSpanFull()->rows(2),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('id_code')
                    ->label(__('fleet.id_code'))->searchable()->sortable()->weight('bold'),
                Tables\Columns\TextColumn::make('category.name')
                    ->label(__('fleet.category'))->badge()->sortable()->toggleable()
                    ->formatStateUsing(fn ($state, Machine $record) => $record->category?->display_name ?? $state),
                Tables\Columns\TextColumn::make('make.name')
                    ->label(__('fleet.make'))->sortable()->toggleable(),
                Tables\Columns\TextColumn::make('model')->label(__('fleet.model'))->searchable()->toggleable(),
                Tables\Columns\TextColumn::make('location.name')
                    ->label(__('fleet.location'))->badge()->color('gray')->sortable()
                    ->formatStateUsing(fn ($state, Machine $record) => $record->location?->display_name ?? $state),
                Tables\Columns\TextColumn::make('current_hours')
                    ->label(__('fleet.current_hours'))->numeric()->sortable()
                    ->formatStateUsing(fn ($state, Machine $r) => $state !== null ? number_format($state).' h' : '—'),
                Tables\Columns\TextColumn::make('computed_remaining_hours')
                    ->label(__('fleet.remaining_hours'))
                    ->badge()
                    ->formatStateUsing(fn ($state) => $state === null ? '—' : number_format($state).' h')
                    ->color(fn (Machine $r) => match ($r->service_status) {
                        'overdue' => 'danger',
                        'due_soon' => 'warning',
                        'ok' => 'success',
                        default => 'gray',
                    })
                    ->sortable(query: fn (Builder $q, string $dir) => $q->orderBy('remaining_hours', $dir)),
                Tables\Columns\TextColumn::make('status')
                    ->label(__('fleet.status'))->badge()
                    ->formatStateUsing(fn ($state) => __('fleet.status_'.$state))
                    ->color(fn ($state) => match ($state) {
                        'active' => 'success',
                        'down' => 'danger',
                        'not_in_service', 'inactive' => 'gray',
                        default => 'warning',
                    }),
                Tables\Columns\IconColumn::make('hourmeter_status')
                    ->label('HM')
                    ->icon(fn ($state) => match ($state) {
                        'ok' => 'heroicon-o-check-circle',
                        'broken' => 'heroicon-o-x-circle',
                        'no_info' => 'heroicon-o-question-mark-circle',
                        'replaced' => 'heroicon-o-arrow-path',
                        default => 'heroicon-o-minus',
                    })
                    ->color(fn ($state) => $state === 'ok' ? 'success' : 'warning')
                    ->toggleable(),
                Tables\Columns\IconColumn::make('needs_review')
                    ->label(__('fleet.review'))->boolean()
                    ->trueIcon('heroicon-o-flag')->falseIcon('')->trueColor('warning')->toggleable(),
            ])
            ->defaultSort('id_code')
            ->groups([
                Tables\Grouping\Group::make('category.name')->label(__('fleet.category'))
                    ->getTitleFromRecordUsing(fn (Machine $record) => $record->category?->display_name ?? '—'),
                Tables\Grouping\Group::make('location.name')->label(__('fleet.location'))
                    ->getTitleFromRecordUsing(fn (Machine $record) => $record->location?->display_name ?? '—'),
            ])
            ->filters([
                // Filtro por número de máquina (pedido del cliente 2026-08-05).
                // La caja de búsqueda de la tabla ya encuentra por `id_code`,
                // pero la búsqueda no se combina con los demás filtros ni queda
                // registrada como filtro aplicado, y los reportes se arman
                // justamente a partir de los filtros. Acepta un fragmento, así
                // que "EX" trae todas las excavadoras y "EX010" solo esa.
                Tables\Filters\Filter::make('id_code')
                    ->form([
                        Forms\Components\TextInput::make('id_code')
                            ->label(__('fleet.machine_number'))
                            ->placeholder(__('fleet.machine_number_placeholder')),
                    ])
                    // Los parámetros se llaman `$query` y `$data` porque Filament
                    // los inyecta POR NOMBRE. Con `fn (Builder $q, array $data)`
                    // el filtro no filtraba nada y no daba ningún error: la tabla
                    // seguía mostrando las 99 máquinas como si el filtro no
                    // existiera. Lo cazó el test, no la pantalla.
                    ->query(function (Builder $query, array $data): Builder {
                        return $query->when(
                            filled($data['id_code'] ?? null),
                            fn (Builder $query) => $query->where('id_code', 'like', '%'.trim($data['id_code']).'%'),
                        );
                    })
                    ->indicateUsing(fn (array $data) => filled($data['id_code'] ?? null)
                        ? __('fleet.machine_number').': '.trim($data['id_code'])
                        : null),
                Tables\Filters\SelectFilter::make('machine_category_id')
                    ->label(__('fleet.category'))->relationship('category', 'name')->multiple()->preload()
                    ->getOptionLabelFromRecordUsing(fn ($record) => $record->display_name),
                Tables\Filters\SelectFilter::make('current_location_id')
                    ->label(__('fleet.location'))
                    ->options(fn () => Location::locationSelectOptions())
                    ->multiple()->preload(),
                // Las opciones salen de Machine::STATUSES y no de una lista escrita
                // a mano: así estaba y faltaba `unknown`, o sea que las 29 máquinas
                // en ese estado (de 99) no se podían encontrar con este filtro.
                Tables\Filters\SelectFilter::make('status')
                    ->label(__('fleet.status'))
                    ->multiple()
                    ->options(collect(Machine::STATUSES)
                        ->mapWithKeys(fn (string $s) => [$s => __('fleet.status_'.$s)])
                        ->all()),
                Tables\Filters\Filter::make('due_soon')
                    ->label(__('fleet.due_soon'))->toggle()
                    ->query(fn (Builder $q) => $q->where('status', 'active')
                        ->whereNotNull('remaining_hours')
                        ->where('remaining_hours', '<=', Machine::ALERT_THRESHOLD)),
                Tables\Filters\TernaryFilter::make('needs_review')->label(__('fleet.needs_review')),
                static::papeleraTrashedFilter(),
            ])
            ->actions([
                Tables\Actions\ViewAction::make(),
                Tables\Actions\EditAction::make(),
                static::papeleraRestoreAction(),
                static::papeleraForceDeleteAction(),
                Tables\Actions\Action::make('approve')
                    ->label(__('fleet.approve_data'))
                    ->icon('heroicon-o-check-badge')
                    ->color('success')
                    ->visible(fn (Machine $record) => $record->needs_review && (Auth::user()?->can('verify_data') ?? false))
                    // ->visible() controla el render; ->authorize() vuelve a
                    // exigir el permiso en el servidor al ejecutar la acción.
                    ->authorize(fn () => Auth::user()?->can('verify_data') ?? false)
                    ->requiresConfirmation()
                    ->modalDescription(__('fleet.approve_confirm'))
                    ->action(function (Machine $record) {
                        $record->update(['needs_review' => false]);

                        // Log manual: intencionalmente fuera de logOnly() del modelo
                        // (needs_review no está en el listOnly de Machine) para dejar
                        // una entrada explícita y legible del hecho de negocio "aprobado",
                        // en vez de un diff de atributos.
                        activity()
                            ->performedOn($record)
                            ->causedBy(Auth::user())
                            ->event('approved')
                            ->log(LocalizedText::of('mgmt.machine_approved_log')->encode());

                        Notification::make()->success()->title(__('fleet.approved_ok'))->send();
                    }),
                Tables\Actions\Action::make('discard')
                    ->label(__('fleet.discard_data'))
                    ->icon('heroicon-o-archive-box-x-mark')
                    ->color('warning')
                    // Simétrica de "aprobar": mismo permiso, mismo alcance (solo
                    // máquinas en revisión). Nace del hallazgo E6-05 — el cliente
                    // tenía que poder descartar las máquinas del Info Book que no
                    // existen, y la única vía disponible era borrarlas, que
                    // destruía su historia.
                    ->visible(fn (Machine $record) => $record->needs_review && (Auth::user()?->can('verify_data') ?? false))
                    ->authorize(fn () => Auth::user()?->can('verify_data') ?? false)
                    ->requiresConfirmation()
                    ->modalDescription(__('fleet.discard_confirm'))
                    ->form([
                        Forms\Components\Textarea::make('reason')
                            ->label(__('fleet.discard_reason'))
                            ->rows(2)
                            ->required(),
                    ])
                    ->action(function (Machine $record, array $data) {
                        // NO borra: la baja de una máquina conserva su historia.
                        $record->update([
                            'status' => 'inactive',
                            'needs_review' => false,
                        ]);

                        activity()
                            ->performedOn($record)
                            ->causedBy(Auth::user())
                            ->event('discarded')
                            ->withProperties(['reason' => $data['reason']])
                            ->log(LocalizedText::of('mgmt.machine_discarded_log', ['machine' => $record->id_code])->encode());

                        Notification::make()->success()->title(__('fleet.discarded_ok'))->send();
                    }),
                Tables\Actions\Action::make('move')
                    ->label(__('mgmt.move_machine'))
                    ->icon('heroicon-o-arrows-right-left')
                    ->color('info')
                    ->visible(fn () => Auth::user()?->can('move_fleet') ?? false)
                    // ->visible() controla el render; ->authorize() vuelve a
                    // exigir el permiso en el servidor al ejecutar la acción.
                    ->authorize(fn () => Auth::user()?->can('move_fleet') ?? false)
                    ->form([
                        Forms\Components\Select::make('current_location_id')
                            ->label(__('mgmt.move_to'))
                            ->options(fn () => Location::locationSelectOptions(activeOnly: true))
                            ->searchable()
                            ->required(),
                    ])
                    ->requiresConfirmation()
                    ->modalDescription(__('mgmt.move_confirm'))
                    ->action(function (Machine $record, array $data) {
                        $record->update(['current_location_id' => $data['current_location_id']]);

                        Notification::make()->success()->title(__('mgmt.move_success'))->send();
                    }),
                Tables\Actions\Action::make('replaceHourmeter')
                    ->label(__('fleet.replace_hourmeter'))
                    ->icon('heroicon-o-arrow-path')
                    ->color('warning')
                    ->visible(fn () => Auth::user()?->can('manage_machines') ?? false)
                    // ->visible() controla el render; ->authorize() vuelve a
                    // exigir el permiso en el servidor al ejecutar la acción.
                    ->authorize(fn () => Auth::user()?->can('manage_machines') ?? false)
                    ->form([
                        Forms\Components\TextInput::make('old_final_hours')
                            ->label(__('fleet.replace_hourmeter_old_hours'))
                            ->numeric()->minValue(0)->suffix('h')
                            ->default(fn (Machine $record) => $record->current_hours)
                            ->required(),
                        Forms\Components\TextInput::make('new_initial_hours')
                            ->label(__('fleet.replace_hourmeter_new_hours'))
                            ->numeric()->minValue(0)->suffix('h')
                            ->default(0)
                            ->required(),
                        Forms\Components\Textarea::make('note')
                            ->label(__('fleet.replace_hourmeter_note'))->rows(2),
                    ])
                    ->requiresConfirmation()
                    ->modalDescription(__('fleet.replace_hourmeter_confirm'))
                    ->action(function (Machine $record, array $data) {
                        app(HourmeterReplacementService::class)->replace(
                            $record,
                            (int) $data['old_final_hours'],
                            (int) $data['new_initial_hours'],
                            $data['note'] !== '' ? $data['note'] : null,
                            Auth::user(),
                        );

                        Notification::make()->success()->title(__('fleet.replace_hourmeter_success'))->send();
                    }),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make(),
                    static::papeleraRestoreBulkAction(),
                    static::papeleraForceDeleteBulkAction(),
                    Tables\Actions\BulkAction::make('approveBulk')
                        ->label(__('fleet.approve_bulk'))
                        ->icon('heroicon-o-check-badge')
                        ->color('success')
                        ->visible(fn () => Auth::user()?->can('verify_data') ?? false)
                        // ->visible() controla el render; ->authorize() vuelve a
                        // exigir el permiso en el servidor al ejecutar la acción.
                        ->authorize(fn () => Auth::user()?->can('verify_data') ?? false)
                        ->requiresConfirmation()
                        ->modalDescription(__('fleet.approve_confirm'))
                        ->deselectRecordsAfterCompletion()
                        ->action(function (Collection $records) {
                            $user = Auth::user();
                            $approved = 0;

                            $records->each(function (Machine $machine) use ($user, &$approved) {
                                if (! $machine->needs_review) {
                                    return;
                                }

                                $machine->update(['needs_review' => false]);

                                activity()
                                    ->performedOn($machine)
                                    ->causedBy($user)
                                    ->event('approved')
                                    ->log(LocalizedText::of('mgmt.machine_approved_log')->encode());

                                $approved++;
                            });

                            Notification::make()->success()
                                ->title(__('fleet.approved_bulk_ok', ['count' => $approved]))
                                ->send();
                        }),
                    Tables\Actions\BulkAction::make('move')
                        ->label(__('mgmt.move_machines'))
                        ->icon('heroicon-o-arrows-right-left')
                        ->color('info')
                        ->visible(fn () => Auth::user()?->can('move_fleet') ?? false)
                        // ->visible() controla el render; ->authorize() vuelve a
                        // exigir el permiso en el servidor al ejecutar la acción.
                        ->authorize(fn () => Auth::user()?->can('move_fleet') ?? false)
                        ->form([
                            Forms\Components\Select::make('current_location_id')
                                ->label(__('mgmt.move_to'))
                                ->options(fn () => Location::locationSelectOptions(activeOnly: true))
                                ->searchable()
                                ->required(),
                        ])
                        ->requiresConfirmation()
                        ->modalDescription(__('mgmt.move_confirm'))
                        ->deselectRecordsAfterCompletion()
                        ->action(function (Collection $records, array $data) {
                            $records->each(fn (Machine $machine) => $machine->update([
                                'current_location_id' => $data['current_location_id'],
                            ]));

                            Notification::make()->success()->title(__('mgmt.move_success_bulk'))->send();
                        }),
                ]),
            ]);
    }

    public static function getRelations(): array
    {
        return [
            RelationManagers\PartsRelationManager::class,
            RelationManagers\ReadingsRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListMachines::route('/'),
            'create' => Pages\CreateMachine::route('/create'),
            'view' => Pages\ViewMachine::route('/{record}'),
            'edit' => Pages\EditMachine::route('/{record}/edit'),
        ];
    }
}

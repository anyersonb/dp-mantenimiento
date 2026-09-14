<?php

namespace App\Filament\Resources;

use App\Filament\Concerns\HasPapeleraActions;
use App\Filament\Resources\FleetAttachmentResource\Pages;
use App\Models\FleetAttachment;
use App\Models\Location;
use App\Rules\RejectsDangerousUploadExtensions;
use App\Support\AccessControl;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\HtmlString;

/**
 * Módulo de Complementos (Attachments) — ver spec-complementos-dp.md.
 *
 * Hermano de MachineResource en forma (mismas secciones de formulario, misma
 * tabla con filtros, misma papelera), pero SIN vínculo con Machine: es un
 * registro autónomo, sin `machine_id`, sin historial de montaje, sin OT,
 * sin servicios preventivos ni horómetro.
 */
class FleetAttachmentResource extends Resource
{
    use HasPapeleraActions;

    protected static ?string $model = FleetAttachment::class;

    // Distinto del heroicon-o-truck de Máquinas: sugiere acople/herramienta.
    protected static ?string $navigationIcon = 'heroicon-o-wrench-screwdriver';

    protected static ?int $navigationSort = 2;

    public static function getNavigationLabel(): string
    {
        return __('fleet.attachments');
    }

    public static function getModelLabel(): string
    {
        return __('fleet.attachment');
    }

    public static function getPluralModelLabel(): string
    {
        return __('fleet.attachments');
    }

    public static function getNavigationGroup(): ?string
    {
        return __('fleet.group_fleet');
    }

    /*
     * Permisos: mismo reparto que Máquinas (ver la migración dedicada
     * 2026_09_14_090100_add_fleet_attachment_permissions para el detalle).
     * Todos los roles del panel ven los complementos (view_attachments), solo
     * administrador y responsable_mantenimiento gestionan (manage_attachments),
     * y solo administrador borra (delete_attachments, vía AccessControl::allows()
     * igual que delete_machines en MachineResource).
     */
    public static function canViewAny(): bool
    {
        return Auth::user()?->can('view_attachments') ?? false;
    }

    public static function canView(Model $record): bool
    {
        return Auth::user()?->can('view_attachments') ?? false;
    }

    public static function canCreate(): bool
    {
        return Auth::user()?->can('manage_attachments') ?? false;
    }

    public static function canEdit(Model $record): bool
    {
        return Auth::user()?->can('manage_attachments') ?? false;
    }

    public static function canDelete(Model $record): bool
    {
        return AccessControl::allows(Auth::user(), 'delete_attachments');
    }

    public static function canDeleteAny(): bool
    {
        return AccessControl::allows(Auth::user(), 'delete_attachments');
    }

    /* --------------------- Papelera (Lote A) --------------------- */

    protected static function papeleraResourceKey(): string
    {
        return 'attachments';
    }

    protected static function papeleraRecordLabel(Model $record): string
    {
        /** @var FleetAttachment $record */
        return (string) $record->id_code;
    }

    // Sin sobreescribir papeleraDestructionSummary(): un complemento es
    // independiente (sin OT, sin lecturas, sin partes), así que un borrado
    // definitivo no se lleva ningún hijo por delante — el default (null) de
    // HasPapeleraActions es correcto acá, a diferencia de MachineResource.

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make(__('fleet.identification'))
                ->columns(3)
                ->schema([
                    Forms\Components\TextInput::make('id_code')
                        ->label(__('fleet.attachment_id_code'))->required()->maxLength(50)
                        ->validationAttribute(__('fleet.attachment_id_code'))
                        ->unique(ignoreRecord: true),
                    Forms\Components\TextInput::make('name')->label(__('fleet.attachment_name')),
                    Forms\Components\Select::make('type')
                        ->label(__('fleet.attachment_type'))
                        ->options(collect(FleetAttachment::TYPES)
                            ->mapWithKeys(fn (string $t) => [$t => __('fleet.attachment_type_'.$t)])
                            ->all()),
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
                        ->options(fn () => Location::locationSelectOptions())
                        ->getSearchResultsUsing(fn (string $search) => Location::locationSelectOptions($search))
                        ->getOptionLabelUsing(fn ($value) => Location::find($value)?->display_name)
                        ->searchable()->preload(),
                    Forms\Components\Textarea::make('description')
                        ->label(__('fleet.description'))->columnSpanFull()->rows(2),
                ]),

            Forms\Components\Section::make(__('fleet.attachment_status_section'))
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
                    Forms\Components\DatePicker::make('acquisition_date')
                        ->label(__('fleet.attachment_acquisition_date')),
                    Forms\Components\TextInput::make('condition_note')
                        ->label(__('fleet.attachment_condition_note'))->columnSpan(2),
                ]),

            Forms\Components\Section::make(__('fleet.attachment_technical'))
                ->columns(3)->collapsed()
                ->schema([
                    Forms\Components\TextInput::make('weight')->label(__('fleet.attachment_weight')),
                    Forms\Components\TextInput::make('dimensions')->label(__('fleet.attachment_dimensions')),
                    Forms\Components\TextInput::make('compatibility')->label(__('fleet.attachment_compatibility')),
                    Forms\Components\Textarea::make('spec_sheet')
                        ->label(__('fleet.spec_sheet'))->columnSpanFull()->rows(12),
                ]),

            /*
             * SIN ->collapsed() a propósito (hallazgo de usabilidad,
             * auditoría de seguridad post 01e6a24e): Filament v3 no
             * auto-expande una Section colapsada cuando un componente
             * adentro falla la validación (verificado en
             * vendor/filament/forms/src/Components/Section.php y su blade
             * view — no hay ningún hook de error ahí). Con la sección
             * cerrada, un archivo rechazado deja su mensaje de error
             * invisible hasta que el usuario la abre a mano -- la misma
             * trampa que "no puedo eliminarlo y no sé por qué" que motivó
             * esta ronda de auditoría, ahora en el módulo que se pidió
             * justamente para cargar fotos y documentos (el camino
             * principal, no un caso raro).
             */
            Forms\Components\Section::make(__('fleet.images'))
                ->columns(2)
                ->schema([
                    Forms\Components\FileUpload::make('image')
                        ->label(__('fleet.image'))
                        ->image()
                        ->imageEditor()
                        ->disk('public')
                        ->directory('fleet-attachments/images')
                        ->maxSize(5120)
                        ->rule(new RejectsDangerousUploadExtensions(['png', 'jpg', 'jpeg', 'webp'])),
                    Forms\Components\FileUpload::make('gallery')
                        ->label(__('fleet.gallery'))
                        ->image()
                        ->multiple()
                        ->reorderable()
                        ->disk('public')
                        ->directory('fleet-attachments/gallery')
                        ->maxSize(5120)
                        ->rule(new RejectsDangerousUploadExtensions(['png', 'jpg', 'jpeg', 'webp'])),
                ]),

            /*
             * Documentos: NO lo tiene Máquinas, es propio de este módulo.
             * `->acceptedFileTypes()` solo mira el mimetype DECLARADO por el
             * cliente (Etapa 05, hallazgo A5) — por ahí pasan SVG/.pht/.html
             * disfrazados. `RejectsDangerousUploadExtensions` es la barrera
             * real: valida la extensión final del nombre de archivo contra
             * una whitelist explícita, independiente del mimetype.
             *
             * Hallazgo Alto (auditoría de seguridad post 01e6a24e): estos
             * archivos vivían en disk('public') sin ninguna capa de
             * autorización — se servían con un 200 sin sesión, y la URL
             * sobrevivía a que revocaran el permiso o a la papelera. Mismo
             * patrón ya usado en AttachmentsRelationManager (OT) y en
             * QuoteResource: disk('local') (privado) + sin
             * ->downloadable()/->openable() (esas dos dependen de
             * Storage::url(), que no existe para un disco privado) +
             * ->storeFileNamesIn() para conservar el nombre ORIGINAL del
             * archivo en `document_names` (el nombre que queda en disco es
             * un ULID generado por Filament). El enlace real para abrir un
             * documento ya guardado sale del Placeholder de abajo, que
             * apunta a la ruta autenticada fleet-attachments.documents.download.
             *
             * SIN ->collapsed(): mismo motivo que la sección "Imágenes" de
             * arriba -- un documento rechazado por
             * RejectsDangerousUploadExtensions/acceptedFileTypes no puede
             * dejar su error escondido dentro de una sección cerrada.
             */
            Forms\Components\Section::make(__('fleet.attachment_documents'))
                ->schema([
                    Forms\Components\FileUpload::make('documents')
                        ->label(__('fleet.attachment_documents'))
                        ->multiple()
                        ->reorderable()
                        ->disk('local')
                        ->directory('fleet-attachments/documents')
                        ->storeFileNamesIn('document_names')
                        ->maxSize(10240)
                        ->acceptedFileTypes([
                            'application/pdf',
                            'application/msword',
                            'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
                            'application/vnd.ms-excel',
                            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                            'image/png',
                            'image/jpeg',
                        ])
                        ->rule(new RejectsDangerousUploadExtensions([
                            'pdf', 'doc', 'docx', 'xls', 'xlsx', 'png', 'jpg', 'jpeg',
                        ])),
                    Forms\Components\Hidden::make('document_names'),
                    Forms\Components\Placeholder::make('documents_links')
                        ->label(__('fleet.attachment_documents_uploaded'))
                        ->visible(fn (?FleetAttachment $record) => $record && filled($record->documents))
                        ->content(fn (?FleetAttachment $record) => $record
                            ? new HtmlString(collect((array) $record->documents)
                                ->values()
                                ->map(function (string $path, int $index) use ($record) {
                                    // Acceso directo, no data_get(): el path
                                    // trae puntos (la extensión del archivo)
                                    // y data_get() los interpretaría como
                                    // separador de nivel ("dot notation"),
                                    // fallando siempre para esta clave.
                                    $name = ((array) $record->document_names)[$path] ?? basename($path);

                                    return '<a href="'.e(route('fleet-attachments.documents.download', [$record, $index])).'" target="_blank" class="text-primary-600 underline">'.e($name).'</a>';
                                })
                                ->implode('<br>'))
                            : ''),
                ]),

            Forms\Components\Section::make(__('fleet.data_control'))
                ->columns(3)
                ->schema([
                    Forms\Components\Toggle::make('needs_review')
                        ->label(__('fleet.needs_review'))
                        ->disabled(fn () => ! (Auth::user()?->can('verify_data') ?? false))
                        ->dehydrated(fn () => Auth::user()?->can('verify_data') ?? false),
                    Forms\Components\TextInput::make('review_note')->label(__('fleet.review_note'))->columnSpan(2),
                    Forms\Components\Textarea::make('notes')->label(__('fleet.notes'))->columnSpanFull(),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('id_code')
                    ->label(__('fleet.attachment_id_code'))->searchable()->sortable()->weight('bold'),
                Tables\Columns\TextColumn::make('name')->label(__('fleet.attachment_name'))->searchable(),
                Tables\Columns\TextColumn::make('type')
                    ->label(__('fleet.attachment_type'))->badge()
                    ->formatStateUsing(fn ($state) => $state ? __('fleet.attachment_type_'.$state) : '—'),
                Tables\Columns\TextColumn::make('make.name')
                    ->label(__('fleet.make'))->sortable()->toggleable(),
                Tables\Columns\TextColumn::make('model')->label(__('fleet.model'))->searchable()->toggleable(),
                Tables\Columns\TextColumn::make('serial')->label(__('fleet.serial'))->searchable()
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('location.name')
                    ->label(__('fleet.location'))->badge()->color('gray')->sortable()->toggleable()
                    ->formatStateUsing(fn ($state, FleetAttachment $record) => $record->location?->display_name ?? $state),
                Tables\Columns\TextColumn::make('status')
                    ->label(__('fleet.status'))->badge()
                    ->formatStateUsing(fn ($state) => __('fleet.status_'.$state))
                    ->color(fn ($state) => match ($state) {
                        'active' => 'success',
                        'down' => 'danger',
                        'not_in_service', 'inactive' => 'gray',
                        default => 'warning',
                    }),
                Tables\Columns\IconColumn::make('needs_review')
                    ->label(__('fleet.review'))->boolean()
                    ->trueIcon('heroicon-o-flag')->falseIcon('')->trueColor('warning')->toggleable(),
            ])
            ->defaultSort('id_code')
            ->groups([
                Tables\Grouping\Group::make('type')->label(__('fleet.attachment_type'))
                    ->getTitleFromRecordUsing(fn (FleetAttachment $record) => $record->type ? __('fleet.attachment_type_'.$record->type) : '—'),
                Tables\Grouping\Group::make('location.name')->label(__('fleet.location'))
                    ->getTitleFromRecordUsing(fn (FleetAttachment $record) => $record->location?->display_name ?? '—'),
            ])
            ->filters([
                Tables\Filters\Filter::make('id_code')
                    ->form([
                        Forms\Components\TextInput::make('id_code')
                            ->label(__('fleet.attachment_number'))
                            ->placeholder(__('fleet.attachment_number_placeholder')),
                    ])
                    ->query(function (Builder $query, array $data): Builder {
                        return $query->when(
                            filled($data['id_code'] ?? null),
                            fn (Builder $query) => $query->where('id_code', 'like', '%'.trim($data['id_code']).'%'),
                        );
                    })
                    ->indicateUsing(fn (array $data) => filled($data['id_code'] ?? null)
                        ? __('fleet.attachment_number').': '.trim($data['id_code'])
                        : null),
                Tables\Filters\SelectFilter::make('type')
                    ->label(__('fleet.attachment_type'))
                    ->multiple()
                    ->options(collect(FleetAttachment::TYPES)
                        ->mapWithKeys(fn (string $t) => [$t => __('fleet.attachment_type_'.$t)])
                        ->all()),
                Tables\Filters\SelectFilter::make('make_id')
                    ->label(__('fleet.make'))->relationship('make', 'name')->multiple()->preload(),
                Tables\Filters\SelectFilter::make('current_location_id')
                    ->label(__('fleet.location'))
                    ->options(fn () => Location::locationSelectOptions())
                    ->multiple()->preload(),
                Tables\Filters\SelectFilter::make('status')
                    ->label(__('fleet.status'))
                    ->multiple()
                    ->options(collect(FleetAttachment::STATUSES)
                        ->mapWithKeys(fn (string $s) => [$s => __('fleet.status_'.$s)])
                        ->all()),
                Tables\Filters\TernaryFilter::make('needs_review')->label(__('fleet.needs_review')),
                static::papeleraTrashedFilter(),
            ])
            ->actions([
                Tables\Actions\ViewAction::make(),
                Tables\Actions\EditAction::make(),
                static::papeleraRestoreAction(),
                static::papeleraForceDeleteAction(),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make(),
                    static::papeleraRestoreBulkAction(),
                    static::papeleraForceDeleteBulkAction(),
                ]),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListFleetAttachments::route('/'),
            'create' => Pages\CreateFleetAttachment::route('/create'),
            'view' => Pages\ViewFleetAttachment::route('/{record}'),
            'edit' => Pages\EditFleetAttachment::route('/{record}/edit'),
        ];
    }
}

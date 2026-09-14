<?php

namespace App\Filament\Resources\WorkOrderResource\RelationManagers;

use App\Filament\Concerns\DeletesOnlyWhileWorkOrderIsOpen;
use App\Rules\RejectsDangerousUploadExtensions;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;

class AttachmentsRelationManager extends RelationManager
{
    use DeletesOnlyWhileWorkOrderIsOpen;

    protected static string $relationship = 'attachments';

    protected static ?string $recordTitleAttribute = 'original_name';

    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return __('wo.attachments');
    }

    public function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Select::make('type')
                ->label(__('wo.attachment_type'))
                ->options([
                    'photo' => __('wo.photo'),
                    'invoice' => __('wo.invoice'),
                ])
                ->default('photo')
                ->required(),
            Forms\Components\FileUpload::make('path')
                ->label(__('wo.file'))
                // Hallazgo A5: fotos y, sobre todo, FACTURAS (evidencia de
                // costos) vivían en el disco publico sin ninguna capa de
                // autorizacion. Pasan al disco privado ("local", root
                // storage/app/private) y se sirven por la ruta autorizada
                // attachments.download (ver routes/web.php), que valida
                // view_fleet siempre y view_costs para type=invoice.
                ->disk('local')
                /*
                 * Hallazgo E6-17 — dos adjuntos con el mismo nombre se pisaban.
                 *
                 * Antes: `directory('work-order-attachments')` + preserveFilenames,
                 * o sea TODAS las OT compartiendo un único directorio con el
                 * nombre que trae el archivo. Comprobado sobre el disco: subir
                 * dos veces `factura.pdf` dejaba UN solo archivo, con el
                 * contenido del segundo, y las dos filas de
                 * `work_order_attachments` apuntando ahí — la primera OT
                 * mostraba la factura de la otra. Con facturas eso es pérdida
                 * silenciosa de evidencia de costo, y el nombre repetido es lo
                 * más probable del mundo (dos talleres subiendo "factura.pdf",
                 * o el mismo proveedor con su plantilla).
                 *
                 * Ahora cada archivo cae en su propio subdirectorio, dentro del
                 * de su OT: work-order-attachments/{ot}/{ulid}/factura.pdf.
                 * Se sigue conservando el nombre original en el disco (y en
                 * `original_name`, que es el que se ve en la tabla y el que se
                 * usa para descargar): la unicidad la da la carpeta, no un
                 * nombre mutilado. El ULID además ordena por tiempo de subida.
                 *
                 * Las filas viejas no se tocan: el enlace de descarga lee
                 * `path` de la base, así que los archivos ya guardados en el
                 * directorio plano se siguen sirviendo igual. No hace falta
                 * migración.
                 */
                ->directory(fn (): string => 'work-order-attachments/'
                    .$this->getOwnerRecord()->getKey()
                    .'/'.strtolower((string) Str::ulid()))
                ->preserveFilenames()
                ->acceptedFileTypes(['image/png', 'image/jpeg', 'image/webp', 'application/pdf'])
                ->maxSize(10240)
                ->rule(new RejectsDangerousUploadExtensions(['png', 'jpg', 'jpeg', 'webp', 'pdf']))
                ->required()
                ->columnSpanFull(),
        ]);
    }

    protected static function withOriginalName(array $data): array
    {
        if (! empty($data['path']) && empty($data['original_name'])) {
            $data['original_name'] = basename($data['path']);
        }

        return $data;
    }

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('type')
                    ->label(__('wo.attachment_type'))
                    ->badge()
                    ->formatStateUsing(fn ($state) => __('wo.'.$state))
                    ->color(fn ($state) => $state === 'invoice' ? 'warning' : 'info'),
                Tables\Columns\TextColumn::make('original_name')
                    ->label(__('wo.file'))
                    ->searchable()
                    ->placeholder('—'),
                Tables\Columns\TextColumn::make('created_at')
                    ->label(__('wo.opened_at'))
                    ->dateTime()
                    ->sortable(),
            ])
            ->headerActions([
                Tables\Actions\CreateAction::make()
                    ->mutateFormDataUsing(fn (array $data) => static::withOriginalName($data)),
            ])
            ->actions([
                Tables\Actions\Action::make('download')
                    ->label(__('wo.download'))
                    ->icon('heroicon-o-arrow-down-tray')
                    // Ya no es una URL de storage adivinable: pasa por la
                    // ruta autorizada que valida view_fleet/view_costs
                    // segun el tipo (fix A5).
                    ->url(fn ($record) => route('attachments.download', $record))
                    ->openUrlInNewTab(),
                Tables\Actions\EditAction::make()
                    ->mutateFormDataUsing(fn (array $data) => static::withOriginalName($data)),
                // ->authorize(fn () => true): no se oculta el botón (defecto
                // reportado por el cliente en "parts used", mismo trait); el
                // gate real sigue siendo canDelete(), movido a ->disabled(),
                // con el motivo en ->tooltip().
                Tables\Actions\DeleteAction::make()
                    ->authorize(fn (): bool => true)
                    ->disabled(fn (Model $record): bool => $this->isReadOnly() || ! $this->canDelete($record))
                    ->tooltip(fn (Model $record): ?string => $this->deletionBlockedReason($record)),
            ])
            ->emptyStateHeading(__('wo.attachments_empty_heading'))
            ->emptyStateDescription(__('wo.attachments_empty_desc'));
    }

    /* ----------------------------------------------------------------- *
     * Autorización propia (hallazgo A8). Ver la nota extendida en
     * MachineResource\ReadingsRelationManager: sin Policy, la autorización
     * heredada de un relation manager es `Response::allow()`.
     *
     * Este es el más delicado de los tres de OT: acá viven las facturas.
     * ----------------------------------------------------------------- */

    protected function canCreate(): bool
    {
        return Auth::user()?->can('execute_work_order') ?? false;
    }

    protected function canEdit(Model $record): bool
    {
        return Auth::user()?->can('execute_work_order') ?? false;
    }

    // canDelete()/canDeleteAny() los aporta el trait
    // DeletesOnlyWhileWorkOrderIsOpen: el borrado se gobierna por ESTADO de la
    // OT, no por rol. Con la OT cerrada no borra nadie.
}

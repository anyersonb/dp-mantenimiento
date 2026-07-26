<?php

namespace App\Filament\Resources\WorkOrderResource\RelationManagers;

use App\Rules\RejectsDangerousUploadExtensions;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;

class AttachmentsRelationManager extends RelationManager
{
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
                ->directory('work-order-attachments')
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
                Tables\Actions\DeleteAction::make(),
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

    protected function canDelete(Model $record): bool
    {
        return Auth::user()?->can('execute_work_order') ?? false;
    }

    protected function canDeleteAny(): bool
    {
        return Auth::user()?->can('execute_work_order') ?? false;
    }
}

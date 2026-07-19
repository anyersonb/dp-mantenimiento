<?php

namespace App\Filament\Resources\WorkOrderResource\RelationManagers;

use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;

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
                ->disk('public')
                ->directory('work-order-attachments')
                ->preserveFilenames()
                ->acceptedFileTypes(['image/*', 'application/pdf'])
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
                    ->url(fn ($record) => Storage::disk('public')->url($record->path))
                    ->openUrlInNewTab(),
                Tables\Actions\EditAction::make()
                    ->mutateFormDataUsing(fn (array $data) => static::withOriginalName($data)),
                Tables\Actions\DeleteAction::make(),
            ])
            ->emptyStateHeading(__('wo.attachments_empty_heading'))
            ->emptyStateDescription(__('wo.attachments_empty_desc'));
    }
}

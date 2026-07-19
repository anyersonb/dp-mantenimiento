<?php

namespace App\Filament\Resources;

use App\Filament\Resources\QuoteResource\Pages;
use App\Models\Quote;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\HtmlString;

/**
 * Cotizaciones adjuntadas por el administrador y compartidas por link
 * público (Quote::share_url), sin necesidad de cuenta en el sistema.
 */
class QuoteResource extends Resource
{
    protected static ?string $model = Quote::class;

    protected static ?string $navigationIcon = 'heroicon-o-document-currency-dollar';

    protected static ?int $navigationSort = 2;

    public static function getNavigationLabel(): string
    {
        return __('mgmt.quotes');
    }

    public static function getModelLabel(): string
    {
        return __('mgmt.quote');
    }

    public static function getPluralModelLabel(): string
    {
        return __('mgmt.quotes');
    }

    public static function getNavigationGroup(): ?string
    {
        return __('fleet.group_management');
    }

    public static function canViewAny(): bool
    {
        return Auth::user()?->can('manage_quotes') ?? false;
    }

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make()
                ->columns(2)
                ->schema([
                    Forms\Components\TextInput::make('title')
                        ->label(__('mgmt.title'))->required()->maxLength(255)->columnSpanFull(),
                    Forms\Components\Select::make('machine_id')
                        ->label(__('mgmt.machine'))
                        ->relationship('machine', 'id_code')->searchable()->preload(),
                    Forms\Components\Select::make('work_order_id')
                        ->label(__('mgmt.work_order'))
                        ->relationship('workOrder', 'code')->searchable()->preload(),
                    Forms\Components\TextInput::make('vendor')
                        ->label(__('mgmt.vendor'))->maxLength(255),
                    Forms\Components\TextInput::make('amount')
                        ->label(__('mgmt.amount'))->numeric()->prefix('$')
                        ->visible(fn () => Auth::user()?->can('view_costs') ?? false),
                    Forms\Components\DateTimePicker::make('expires_at')
                        ->label(__('mgmt.expires_at')),
                    Forms\Components\FileUpload::make('file_path')
                        ->label(__('mgmt.file'))
                        ->disk('public')
                        ->directory('quotes')
                        ->acceptedFileTypes(['application/pdf', 'image/png', 'image/jpeg'])
                        ->columnSpanFull(),
                    Forms\Components\Placeholder::make('share_url')
                        ->label(__('mgmt.share_link'))
                        ->columnSpanFull()
                        ->content(fn (?Quote $record) => $record
                            ? new HtmlString('<a href="'.$record->share_url.'" target="_blank" class="text-primary-600 underline">'.$record->share_url.'</a>')
                            : '—')
                        ->visible(fn (?Quote $record) => $record !== null),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('title')
                    ->label(__('mgmt.title'))->searchable()->wrap()->weight('bold'),
                Tables\Columns\TextColumn::make('machine.id_code')
                    ->label(__('mgmt.machine'))->badge()->placeholder('—'),
                Tables\Columns\TextColumn::make('vendor')
                    ->label(__('mgmt.vendor'))->placeholder('—'),
                Tables\Columns\TextColumn::make('amount')
                    ->label(__('mgmt.amount'))
                    ->formatStateUsing(fn ($state) => $state !== null ? '$'.number_format((float) $state, 2) : '—')
                    ->visible(fn () => Auth::user()?->can('view_costs') ?? false)
                    ->sortable(),
                Tables\Columns\TextColumn::make('expires_at')
                    ->label(__('mgmt.expires_at'))->dateTime()->placeholder('—')->sortable()
                    ->color(fn (Quote $record) => $record->expires_at?->isPast() ? 'danger' : null),
                Tables\Columns\TextColumn::make('share_url')
                    ->label(__('mgmt.share_link'))
                    ->limit(35)
                    ->copyable()
                    ->copyMessage(__('mgmt.link_copied'))
                    ->color('gray'),
                Tables\Columns\TextColumn::make('uploader.name')
                    ->label(__('mgmt.causer'))->placeholder('—')->toggleable(),
                Tables\Columns\TextColumn::make('created_at')
                    ->label(__('mgmt.date'))->dateTime()->sortable()->toggleable(isToggledHiddenByDefault: true),
            ])
            ->defaultSort('created_at', 'desc')
            ->actions([
                Tables\Actions\Action::make('open_link')
                    ->label(__('mgmt.public_view_file'))
                    ->icon('heroicon-o-arrow-top-right-on-square')
                    ->url(fn (Quote $record) => $record->share_url)
                    ->openUrlInNewTab(),
                Tables\Actions\EditAction::make(),
                Tables\Actions\DeleteAction::make(),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make(),
                ]),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListQuotes::route('/'),
            'create' => Pages\CreateQuote::route('/create'),
            'edit' => Pages\EditQuote::route('/{record}/edit'),
        ];
    }
}

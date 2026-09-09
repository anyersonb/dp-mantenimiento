<?php

namespace App\Filament\Resources;

use App\Filament\Concerns\HasPapeleraActions;
use App\Filament\Resources\QuoteResource\Pages;
use App\Models\Quote;
use App\Rules\RejectsDangerousUploadExtensions;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\HtmlString;

/**
 * Cotizaciones adjuntadas por el administrador y compartidas por link
 * público (Quote::share_url), sin necesidad de cuenta en el sistema.
 *
 * MÓDULO APAGADO (2026-08-06, pedido del cliente: "ya no es necesario el
 * módulo de cotizaciones"). El interruptor es `config('features.quotes')`,
 * false por defecto — ver config/features.php para el motivo y cómo
 * reactivarlo. Nada se borró: modelo, tabla `quotes` y archivos quedan.
 *
 * Con el flag apagado este Resource no aparece en el menú y sus tres páginas
 * responden 403. El gate va DENTRO de los can*() (y no solo en
 * shouldRegisterNavigation) porque sacar algo del menú no es una barrera:
 * es exactamente el hueco del hallazgo C1 — la URL directa seguía abierta.
 */
class QuoteResource extends Resource
{
    use HasPapeleraActions;

    protected static ?string $model = Quote::class;

    protected static ?string $navigationIcon = 'heroicon-o-document-currency-dollar';

    protected static ?int $navigationSort = 2;

    /**
     * ¿Está encendido el módulo? Única fuente para el menú y para los can*().
     */
    public static function moduleEnabled(): bool
    {
        return (bool) config('features.quotes');
    }

    public static function shouldRegisterNavigation(): bool
    {
        return static::moduleEnabled() && parent::shouldRegisterNavigation();
    }

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
        return static::moduleEnabled() && (Auth::user()?->can('manage_quotes') ?? false);
    }

    /*
     * Inventario Etapa 05 (Bloque 2): este Resource solo tenía canViewAny.
     * Al no declarar canCreate/canEdit/canDelete/canDeleteAny, Filament cae al
     * default de Resource (permitir), exactamente el mismo patrón de C1 —
     * cualquiera con sesión en el panel podía llegar a /admin/quotes/create o
     * /{id}/edit aunque no viera el recurso en el menú. Se cierra con el mismo
     * permiso que ya gobierna canViewAny.
     */
    public static function canCreate(): bool
    {
        return static::moduleEnabled() && (Auth::user()?->can('manage_quotes') ?? false);
    }

    public static function canView(Model $record): bool
    {
        return static::moduleEnabled() && (Auth::user()?->can('manage_quotes') ?? false);
    }

    public static function canEdit(Model $record): bool
    {
        return static::moduleEnabled() && (Auth::user()?->can('manage_quotes') ?? false);
    }

    public static function canDelete(Model $record): bool
    {
        return static::moduleEnabled() && (Auth::user()?->can('manage_quotes') ?? false);
    }

    public static function canDeleteAny(): bool
    {
        return static::moduleEnabled() && (Auth::user()?->can('manage_quotes') ?? false);
    }

    /* --------------------- Papelera (Lote A) --------------------- */

    protected static function papeleraResourceKey(): string
    {
        return 'quotes';
    }

    protected static function papeleraRecordLabel(Model $record): string
    {
        /** @var Quote $record */
        return (string) $record->title;
    }

    /**
     * Con el módulo apagado (`config('features.quotes')`), NINGUNA pantalla de
     * cotizaciones queda accesible — ni la papelera. Se redeclaran acá (pisan
     * a las del trait: PHP prioriza el método de la clase sobre el del trait)
     * para sumar esa condición sin duplicar el resto de HasPapeleraActions.
     */
    public static function canRestore(Model $record): bool
    {
        return static::moduleEnabled() && (Auth::user()?->can(static::papeleraRestorePermission()) ?? false);
    }

    public static function canRestoreAny(): bool
    {
        return static::moduleEnabled() && (Auth::user()?->can(static::papeleraRestorePermission()) ?? false);
    }

    public static function canForceDelete(Model $record): bool
    {
        return static::moduleEnabled() && (Auth::user()?->can(static::papeleraForceDeletePermission()) ?? false);
    }

    public static function canForceDeleteAny(): bool
    {
        return static::moduleEnabled() && (Auth::user()?->can(static::papeleraForceDeletePermission()) ?? false);
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
                        // Hallazgo A5: el archivo de cotizacion vivia en el
                        // disco publico. Pasa al disco privado ("local") y
                        // se sirve solo por el link publico con
                        // share_token (ruta quotes.public.file), que
                        // ademas respeta el vencimiento (expires_at).
                        ->disk('local')
                        ->directory('quotes')
                        ->acceptedFileTypes(['application/pdf', 'image/png', 'image/jpeg'])
                        ->maxSize(10240)
                        ->rule(new RejectsDangerousUploadExtensions(['pdf', 'png', 'jpg', 'jpeg']))
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
            ->filters([
                static::papeleraTrashedFilter(),
            ])
            ->actions([
                Tables\Actions\Action::make('open_link')
                    ->label(__('mgmt.public_view_file'))
                    ->icon('heroicon-o-arrow-top-right-on-square')
                    ->url(fn (Quote $record) => $record->share_url)
                    ->openUrlInNewTab(),
                Tables\Actions\EditAction::make(),
                Tables\Actions\DeleteAction::make(),
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
            'index' => Pages\ListQuotes::route('/'),
            'create' => Pages\CreateQuote::route('/create'),
            'edit' => Pages\EditQuote::route('/{record}/edit'),
        ];
    }
}

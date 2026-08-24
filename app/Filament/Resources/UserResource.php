<?php

namespace App\Filament\Resources;

use App\Filament\Resources\UserResource\Pages;
use App\Models\Location;
use App\Models\User;
use App\Support\RoleCatalog;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Spatie\Permission\Models\Role;

/**
 * Gestión de usuarios y asignación de roles (Spatie).
 * Recurso restringido exclusivamente a usuarios con el permiso `manage_users`
 * (rol administrador): no aparece en el menú ni es accesible por URL directa
 * para el resto de roles.
 */
class UserResource extends Resource
{
    protected static ?string $model = User::class;

    protected static ?string $navigationIcon = 'heroicon-o-users';

    protected static ?int $navigationSort = 90;

    public static function getNavigationLabel(): string
    {
        return __('users.nav');
    }

    public static function getModelLabel(): string
    {
        return __('users.model_user');
    }

    public static function getPluralModelLabel(): string
    {
        return __('users.model_users');
    }

    public static function getNavigationGroup(): ?string
    {
        // Reutiliza el grupo "Administración" ya existente (fleet.group_admin),
        // usado por LocationResource, MakeResource y MachineCategoryResource.
        return __('fleet.group_admin');
    }

    public static function shouldRegisterNavigation(): bool
    {
        return Auth::user()?->can('manage_users') ?? false;
    }

    public static function canViewAny(): bool
    {
        return Auth::user()?->can('manage_users') ?? false;
    }

    public static function canCreate(): bool
    {
        return Auth::user()?->can('manage_users') ?? false;
    }

    public static function canView(Model $record): bool
    {
        return Auth::user()?->can('manage_users') ?? false;
    }

    public static function canEdit(Model $record): bool
    {
        return Auth::user()?->can('manage_users') ?? false;
    }

    public static function canDelete(Model $record): bool
    {
        return Auth::user()?->can('manage_users') ?? false;
    }

    public static function canDeleteAny(): bool
    {
        return Auth::user()?->can('manage_users') ?? false;
    }

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make()
                ->columns(2)
                ->schema([
                    Forms\Components\TextInput::make('name')
                        ->label(__('users.field_name'))
                        ->required()
                        ->maxLength(255),
                    Forms\Components\TextInput::make('email')
                        ->label(__('users.field_email'))
                        ->email()
                        ->required()
                        ->maxLength(255)
                        ->unique(ignoreRecord: true),
                    Forms\Components\TextInput::make('password')
                        ->label(__('users.field_password'))
                        ->password()
                        ->revealable()
                        ->required(fn (string $operation): bool => $operation === 'create')
                        ->dehydrated(fn ($state) => filled($state))
                        ->confirmed()
                        ->maxLength(255)
                        ->helperText(fn (string $operation) => $operation === 'edit' ? __('users.field_password_hint') : null),
                    Forms\Components\TextInput::make('password_confirmation')
                        ->label(__('users.field_password_confirmation'))
                        ->password()
                        ->revealable()
                        ->required(fn (string $operation): bool => $operation === 'create')
                        ->dehydrated(false),
                    Forms\Components\TextInput::make('phone')
                        ->label(__('users.field_phone'))
                        ->tel()
                        ->nullable()
                        ->maxLength(30),
                    Forms\Components\Select::make('locale')
                        ->label(__('users.field_locale'))
                        ->options([
                            'es' => __('users.locale_es'),
                            'en' => __('users.locale_en'),
                        ])
                        ->required()
                        ->default('es'),
                    Forms\Components\Select::make('location_id')
                        ->label(__('users.field_location'))
                        // Sin ->relationship(): busca en SQL contra `display_name`,
                        // que no existe como columna. Ver Location::locationSelectOptions().
                        ->options(fn () => Location::locationSelectOptions())
                        ->getSearchResultsUsing(fn (string $search) => Location::locationSelectOptions($search))
                        ->getOptionLabelUsing(fn ($value) => Location::find($value)?->display_name)
                        ->searchable()
                        ->preload()
                        ->nullable(),
                    Forms\Components\Toggle::make('active')
                        ->label(__('users.field_active'))
                        ->default(true),
                    /*
                     * Roles del usuario. Dos pedidos de la clienta (2026-08-24)
                     * caen justo acá, y por eso esto dejó de ser un Select:
                     *
                     *  - "el rol al crear el usuario sale como id y no como
                     *    nombre": el Select múltiple resuelve las etiquetas en
                     *    el NAVEGADOR (Choices.js las busca por valor dentro de
                     *    una lista JSON), así que cuando esa búsqueda no casa
                     *    queda a la vista el valor crudo, que es el id. Un
                     *    CheckboxList arma cada etiqueta EN EL SERVIDOR: no hay
                     *    búsqueda que pueda fallar y el id no se muestra nunca.
                     *  - "en roles describir qué hace cada uno a la hora de
                     *    seleccionar cada uno": ->descriptions() cuelga el texto
                     *    debajo de cada opción. Un Select no tiene dónde ponerlo.
                     *
                     * El estado sigue siendo el mismo (arreglo de ids sobre la
                     * relación `roles`), así que lo que ya guardaba, guarda.
                     */
                    Forms\Components\CheckboxList::make('roles')
                        ->label(__('users.field_roles'))
                        ->helperText(__('users.field_roles_help'))
                        ->relationship('roles', 'name')
                        ->getOptionLabelFromRecordUsing(fn (Role $record) => RoleCatalog::label($record->name))
                        ->descriptions(fn () => RoleCatalog::roleDescriptionsById())
                        ->bulkToggleable()
                        ->columns(2)
                        ->required()
                        ->columnSpanFull(),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('name')
                    ->label(__('users.field_name'))
                    ->searchable()
                    ->sortable()
                    ->weight('bold'),
                Tables\Columns\TextColumn::make('email')
                    ->label(__('users.field_email'))
                    ->searchable()
                    ->copyable(),
                Tables\Columns\TextColumn::make('roles.name')
                    ->label(__('users.field_roles'))
                    ->badge()
                    // RoleCatalog y no __('users.role_'.$state) a secas: los
                    // roles que la clienta cree desde el panel no tienen
                    // traducción, y __() de una clave inexistente devuelve la
                    // clave —"users.role_mecanico" en pantalla—. El catálogo cae
                    // al nombre del rol.
                    ->formatStateUsing(fn ($state) => $state ? RoleCatalog::label((string) $state) : $state),
                Tables\Columns\TextColumn::make('location.name')
                    ->label(__('users.field_location'))
                    ->badge()
                    ->color('gray')
                    ->placeholder('—')
                    ->formatStateUsing(fn ($state, User $record) => $record->location?->display_name ?? $state),
                Tables\Columns\TextColumn::make('locale')
                    ->label(__('users.field_locale'))
                    ->badge()
                    ->formatStateUsing(fn ($state) => strtoupper((string) $state)),
                Tables\Columns\IconColumn::make('active')
                    ->label(__('users.field_active'))
                    ->boolean(),
                Tables\Columns\TextColumn::make('created_at')
                    ->label(__('users.field_created_at'))
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->defaultSort('name')
            ->filters([
                Tables\Filters\SelectFilter::make('roles')
                    ->label(__('users.filter_role'))
                    ->relationship('roles', 'name')
                    ->getOptionLabelFromRecordUsing(fn (Role $record) => RoleCatalog::label($record->name))
                    ->preload(),
                Tables\Filters\TernaryFilter::make('active')
                    ->label(__('users.filter_active')),
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
                Tables\Actions\DeleteAction::make()
                    ->hidden(fn (User $record) => $record->id === Auth::id()),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make()
                        ->action(function (Collection $records) {
                            $currentId = Auth::id();

                            $records
                                ->reject(fn (User $record) => $record->id === $currentId)
                                ->each(fn (User $record) => $record->delete());
                        }),
                ]),
            ]);
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with(['location', 'roles']);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListUsers::route('/'),
            'create' => Pages\CreateUser::route('/create'),
            'edit' => Pages\EditUser::route('/{record}/edit'),
        ];
    }
}

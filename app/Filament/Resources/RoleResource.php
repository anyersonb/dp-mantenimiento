<?php

namespace App\Filament\Resources;

use App\Filament\Resources\RoleResource\Pages;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Lang;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

/**
 * Administración de roles y su matriz de permisos (Spatie).
 * Recurso restringido exclusivamente a usuarios con el permiso `manage_users`
 * (rol administrador), igual que UserResource: no aparece en el menú ni es
 * accesible por URL directa para el resto de roles.
 */
class RoleResource extends Resource
{
    protected static ?string $model = Role::class;

    protected static ?string $navigationIcon = 'heroicon-o-shield-check';

    protected static ?int $navigationSort = 91;

    /**
     * Roles que crean/usan el resto del código por nombre (policies, seeders,
     * User::canAccessPanel(), etc.). No se pueden renombrar ni borrar para no
     * romper esos checks.
     */
    public const SYSTEM_ROLES = [
        'administrador',
        'responsable_mantenimiento',
        'foreman',
        'operador_cisterna',
        'personal_mantenimiento',
        'taller',
        'gerencia',
    ];

    public static function getNavigationLabel(): string
    {
        return __('roles.nav');
    }

    public static function getModelLabel(): string
    {
        return __('roles.model_role');
    }

    public static function getPluralModelLabel(): string
    {
        return __('roles.model_roles');
    }

    public static function getNavigationGroup(): ?string
    {
        // Reutiliza el grupo "Administración" ya existente (fleet.group_admin),
        // usado por UserResource, LocationResource, MakeResource, etc.
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
        if (! (Auth::user()?->can('manage_users') ?? false)) {
            return false;
        }

        /** @var Role $record */
        return ! in_array($record->name, self::SYSTEM_ROLES, true);
    }

    public static function canDeleteAny(): bool
    {
        return Auth::user()?->can('manage_users') ?? false;
    }

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make()
                ->columns(1)
                ->schema([
                    Forms\Components\TextInput::make('name')
                        ->label(__('roles.field_name'))
                        ->required()
                        ->maxLength(255)
                        ->unique(ignoreRecord: true)
                        ->disabled(fn (?Role $record) => $record !== null && in_array($record->name, self::SYSTEM_ROLES, true))
                        ->helperText(fn (?Role $record) => ($record !== null && in_array($record->name, self::SYSTEM_ROLES, true))
                            ? __('roles.name_locked_hint')
                            : null),
                    Forms\Components\CheckboxList::make('permissions')
                        ->label(__('roles.field_permissions'))
                        ->relationship('permissions', 'name')
                        ->searchable()
                        ->bulkToggleable()
                        ->columns(2)
                        ->getOptionLabelFromRecordUsing(fn (Permission $record) => __('roles.perm_'.$record->name))
                        ->required(),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('name')
                    ->label(__('roles.field_name'))
                    ->searchable()
                    ->sortable()
                    ->weight('bold')
                    ->formatStateUsing(fn ($state) => Lang::has('users.role_'.$state) ? __('users.role_'.$state) : $state),
                Tables\Columns\TextColumn::make('permissions_count')
                    ->label(__('roles.permissions_count'))
                    ->counts('permissions')
                    ->badge()
                    ->sortable(),
                Tables\Columns\TextColumn::make('users_count')
                    ->label(__('roles.users_count'))
                    ->counts('users')
                    ->badge()
                    ->color('gray')
                    ->sortable(),
            ])
            ->defaultSort('name')
            ->actions([
                Tables\Actions\EditAction::make(),
                Tables\Actions\DeleteAction::make()
                    ->hidden(fn (Role $record) => in_array($record->name, self::SYSTEM_ROLES, true)),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make()
                        ->action(function ($records) {
                            $records
                                ->reject(fn (Role $record) => in_array($record->name, self::SYSTEM_ROLES, true))
                                ->each(fn (Role $record) => $record->delete());
                        }),
                ]),
            ]);
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->withCount(['permissions', 'users']);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListRoles::route('/'),
            'create' => Pages\CreateRole::route('/create'),
            'edit' => Pages\EditRole::route('/{record}/edit'),
        ];
    }

    /**
     * Salvaguarda anti-bloqueo: el rol 'administrador' siempre debe conservar
     * el permiso manage_users, sin importar qué se haya marcado/desmarcado en
     * el CheckboxList. Sin esto, un admin podría quitarse a sí mismo (o a todo
     * el rol) el acceso a la gestión de usuarios y dejar el sistema sin nadie
     * que pueda revertirlo desde el panel.
     */
    public static function enforceAdminSafeguard(Role $role): void
    {
        if ($role->name !== 'administrador') {
            return;
        }

        // load() (no loadMissing()): la relación pudo quedar cacheada con el
        // estado previo a la sincronización de permisos que Filament acaba
        // de hacer; forzamos una lectura fresca desde la BD.
        $role->load('permissions');

        if (! $role->permissions->contains('name', 'manage_users')) {
            $role->givePermissionTo('manage_users');
        }
    }
}

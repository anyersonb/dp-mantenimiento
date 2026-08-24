<?php

namespace App\Filament\Resources;

use App\Filament\Resources\RoleResource\Pages;
use App\Support\RoleCatalog;
use Filament\Forms;
use Filament\Notifications\Notification;
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

    /**
     * Por qué este rol no se puede borrar, en el idioma del usuario, o null si
     * sí se puede. Una sola función para que el listado, el borrado en lote y
     * el modal de "por qué no" no puedan contestar cosas distintas.
     */
    public static function deletionBlockReason(Role $record): ?string
    {
        if (in_array($record->name, self::SYSTEM_ROLES, true)) {
            return __('roles.delete_reason_system');
        }

        $users = $record->users()->count();

        if ($users > 0) {
            return __('roles.delete_reason_has_users', ['count' => $users]);
        }

        return null;
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

                    /*
                     * "Al crear cada rol debe poder describir para qué es ese
                     * rol" (clienta, 2026-08-24).
                     *
                     * En los siete roles del sistema el campo va deshabilitado
                     * y muestra la descripción TRADUCIDA como texto de ayuda:
                     * esos textos viven en lang/{es,en}/roles.php justamente
                     * para salir en el idioma de quien mira, y dejar que
                     * alguien los pise a mano en un solo idioma sería perder
                     * eso sin ganar nada. Los roles nuevos sí escriben la suya.
                     */
                    Forms\Components\Textarea::make('description')
                        ->label(__('roles.field_description'))
                        ->rows(2)
                        ->maxLength(1000)
                        ->disabled(fn (?Role $record) => $record !== null && Lang::has('roles.role_desc_'.$record->name))
                        ->dehydrated(fn (?Role $record) => ! ($record !== null && Lang::has('roles.role_desc_'.$record->name)))
                        ->helperText(fn (?Role $record) => ($record !== null && Lang::has('roles.role_desc_'.$record->name))
                            ? __('roles.field_description_locked_hint').' — '.RoleCatalog::describe($record)
                            : __('roles.field_description_help')),

                    Forms\Components\CheckboxList::make('permissions')
                        ->label(__('roles.field_permissions'))
                        ->helperText(__('roles.field_permissions_help'))
                        ->relationship('permissions', 'name')
                        ->searchable()
                        ->bulkToggleable()
                        ->columns(2)
                        ->getOptionLabelFromRecordUsing(fn (Permission $record) => __('roles.perm_'.$record->name))
                        // "En cada permiso que describa para qué es cada
                        // permiso" (clienta, 2026-08-24). Las descripciones van
                        // indexadas por id porque ése es el valor que guarda el
                        // CheckboxList; RoleCatalog las arma en el idioma del
                        // usuario y omite las que no tengan texto.
                        ->descriptions(fn () => RoleCatalog::permissionDescriptionsById())
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
                    ->formatStateUsing(fn ($state) => RoleCatalog::label((string) $state)),
                // Para qué es cada rol, a la vista en el listado y no solo al
                // abrirlo (clienta, 2026-08-24).
                Tables\Columns\TextColumn::make('description')
                    ->label(__('roles.description'))
                    ->getStateUsing(fn (Role $record) => RoleCatalog::describe($record))
                    ->placeholder(__('roles.no_description'))
                    ->wrap()
                    ->limit(140)
                    ->color('gray'),
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

                /*
                 * "Validar roles y permisos para que se puedan borrar"
                 * (clienta, 2026-08-24). Dos huecos distintos:
                 *
                 * 1. En los siete roles del sistema el botón simplemente NO
                 *    aparecía. Desde la pantalla eso se lee como que borrar
                 *    roles no existe, no como que ÉSE está protegido. Sigue sin
                 *    poder borrarse —el código los busca por nombre— pero ahora
                 *    hay un botón que explica por qué (acción `locked`, abajo).
                 * 2. En los roles que sí se pueden borrar no había ninguna
                 *    validación: borrar uno con gente asignada dejaba esas
                 *    cuentas sin permisos de un momento a otro y sin aviso.
                 *    Eso lo corta el `before()`.
                 *
                 * El `before()` corre en el servidor, después de que el usuario
                 * confirma el modal: es el único lugar donde el conteo de
                 * usuarios está fresco. La barrera de los roles del sistema
                 * NO se movió acá: sigue en canDelete(), que es lo que
                 * Filament inyecta como ->authorize() en esta acción
                 * (ListRecords::configureDeleteAction), o sea que para un rol
                 * del sistema esta acción ni existe.
                 */
                Tables\Actions\DeleteAction::make()
                    ->before(function (Role $record, Tables\Actions\DeleteAction $action) {
                        $users = $record->users()->count();

                        if ($users > 0) {
                            Notification::make()
                                ->title(__('roles.delete_blocked_users_title'))
                                ->body(__('roles.delete_blocked_users_body', [
                                    'role' => RoleCatalog::label($record->name),
                                    'count' => $users,
                                ]))
                                ->danger()
                                ->persistent()
                                ->send();

                            $action->cancel();
                        }
                    }),

                /*
                 * El "por qué no" de los roles del sistema. Es una acción que
                 * no ejecuta nada: abre un modal con la explicación y un solo
                 * botón de cerrar (`modalSubmitAction(false)`). Existe para que
                 * la fila no se vea igual que una donde el borrado nunca se
                 * implementó.
                 */
                Tables\Actions\Action::make('locked')
                    ->label(__('filament-actions::delete.single.label'))
                    ->icon('heroicon-m-lock-closed')
                    ->color('gray')
                    ->visible(fn (Role $record) => in_array($record->name, self::SYSTEM_ROLES, true))
                    ->modalHeading(__('roles.delete_blocked_system_title'))
                    ->modalDescription(__('roles.delete_blocked_system_body'))
                    ->modalSubmitAction(false)
                    ->modalCancelActionLabel(__('roles.delete_blocked_understood'))
                    ->action(fn () => null),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    // El borrado en lote saltea los que no se pueden borrar en
                    // vez de reventar, y avisa cuántos dejó (antes los saltaba
                    // en silencio y la pantalla parecía haber borrado todo).
                    Tables\Actions\DeleteBulkAction::make()
                        ->action(function ($records) {
                            $borrados = 0;
                            $omitidos = 0;

                            foreach ($records as $record) {
                                /** @var Role $record */
                                if (static::deletionBlockReason($record) !== null) {
                                    $omitidos++;

                                    continue;
                                }

                                $record->delete();
                                $borrados++;
                            }

                            if ($omitidos > 0) {
                                Notification::make()
                                    ->title(__('roles.bulk_skipped_title'))
                                    ->body(__('roles.bulk_skipped_body', [
                                        'deleted' => $borrados,
                                        'skipped' => $omitidos,
                                    ]))
                                    ->warning()
                                    ->persistent()
                                    ->send();
                            }
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

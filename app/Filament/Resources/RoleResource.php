<?php

namespace App\Filament\Resources;

use App\Filament\Resources\RoleResource\Pages;
use App\Models\User;
use App\Support\AccessControl;
use App\Support\LocalizedText;
use App\Support\RoleCatalog;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Lang;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

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
     * Los siete roles que trae el sistema de fabrica.
     *
     * YA NO son indestructibles. Desde que el acceso se decide por permiso y no
     * por nombre (ver App\Support\AccessControl) se pueden borrar como
     * cualquier otro, que es lo que pidio la clienta el 2026-08-24.
     *
     * La constante sigue viva por dos cosas mas chicas, las dos por el mismo
     * motivo: estos nombres SI se siguen referenciando por texto en sitios que
     * no son control de acceso.
     *
     *   - El nombre no se puede editar. `RolePermissionBaselineSeeder` los
     *     busca por nombre para reconverger la matriz de permisos, y
     *     `RoleCatalog` resuelve por nombre su etiqueta y su descripcion
     *     traducidas. Renombrar uno ya no rompe ningun acceso, pero lo deja sin
     *     traduccion y fuera del alcance del seeder de restauracion.
     *   - Su descripcion sale de los archivos de idioma y no de la columna.
     *
     * Que borrarlos si este permitido y renombrarlos no es deliberado: borrar
     * es un acto explicito, confirmado y con una pregunta de por medio;
     * renombrar es un descuido de un solo campo que no avisa de nada.
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

    /**
     * Permisos sin los cuales el sistema se queda sin dueno: hay que poder
     * ENTRAR al panel y ademas poder GESTIONAR usuarios y roles. Con uno solo
     * no alcanza --entrar sin poder tocar roles no arregla nada, y el permiso
     * sin la puerta no se puede ejercer.
     */
    public const REQUIRED_TO_ADMINISTER = ['access_panel', 'manage_users'];

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

    /**
     * Ya NO se bloquea por nombre de rol.
     *
     * Los siete del sistema dejaron de estar clavados en el codigo y por lo
     * tanto dejaron de ser indestructibles: aca lo unico que se mira es si
     * quien esta pidiendo el borrado puede gestionar usuarios.
     *
     * Las dos barreras que quedan viven en la accion de borrado y no aca:
     * ambas dependen del rol DESTINO que se elige en el modal, y en este punto
     * ese dato todavia no existe.
     */
    public static function canDelete(Model $record): bool
    {
        return Auth::user()?->can('manage_users') ?? false;
    }

    public static function canDeleteAny(): bool
    {
        return Auth::user()?->can('manage_users') ?? false;
    }

    /**
     * ¿Existe ya la columna `roles.description` en esta base?
     *
     * Se consulta una vez por request porque `Schema::hasColumn` pega contra el
     * information_schema y el formulario la preguntaría en cada closure.
     *
     * `once()` y no un `static $x` propio: el static se queda pegado para TODO
     * el proceso, y en la suite eso significa que la primera respuesta gobierna
     * los 341 tests siguientes. `once()` lo flushea el propio ciclo de vida del
     * TestCase (InteractsWithTestCaseLifecycle::flushOnce), así que un test que
     * borre la columna ve la realidad y no una respuesta cacheada.
     *
     * Vive contra el esquema y no en un config para que la respuesta sea la
     * REAL: en este hosting los archivos suben por FTP y la migración corre
     * después, a mano, así que hay una ventana en la que el código nuevo
     * convive con el esquema viejo. Una bandera de configuración habría que
     * acordarse de darla vuelta; el esquema no miente.
     */
    public static function descriptionColumnExists(): bool
    {
        return once(fn () => Schema::hasColumn('roles', 'description'));
    }

    /**
     * Por que este rol no se puede borrar, en el idioma del usuario, o null si
     * si se puede. Una sola funcion para que el listado y el borrado en lote no
     * puedan contestar cosas distintas.
     *
     * Ya no devuelve "tiene usuarios asignados": tener gente dejo de ser un
     * impedimento, es justo lo que el modal resuelve preguntando a donde
     * mandarla.
     */
    public static function deletionBlockReason(Role $record, ?Role $target = null): ?string
    {
        if (self::wouldStrandAdministration($record, $target)) {
            return __('roles.delete_reason_last_admin');
        }

        return null;
    }

    /**
     * Cuanta gente tiene HOY este rol.
     *
     * Se lee fresco de la base y no del `users_count` que trae la tabla: entre
     * que la pantalla se pinto y que alguien confirma el modal pueden haber
     * pasado minutos, y este numero decide si se pregunta el destino o no.
     */
    public static function assignedUserCount(Role $record): int
    {
        return $record->users()->count();
    }

    /**
     * Lo mismo para un lote, en una sola consulta.
     *
     * @param  Collection<int, Role>  $records
     */
    public static function assignedUserCountIn(EloquentCollection $records): int
    {
        if ($records->isEmpty()) {
            return 0;
        }

        return User::query()
            ->whereHas('roles', fn (Builder $query) => $query->whereIn('id', $records->modelKeys()))
            ->count();
    }

    /**
     * Roles a los que se puede mandar la gente del rol que se borra. Se
     * excluyen los que van a desaparecer en esta misma operacion: ofrecerlos
     * seria ofrecer un destino que no va a existir cuando termine el lote.
     *
     * @param  array<int, int|string>  $excludedIds
     * @return array<int|string, string>
     */
    public static function reassignmentOptions(array $excludedIds): array
    {
        return Role::query()
            ->whereNotIn('id', $excludedIds)
            ->orderBy('name')
            ->get()
            ->mapWithKeys(fn (Role $role) => [$role->getKey() => RoleCatalog::label($role->name)])
            ->all();
    }

    /**
     * ¿Queda al menos un usuario ACTIVO capaz de administrar el sistema, si se
     * borra $deleted y su gente pasa a $target?
     *
     * Se simula sobre el conjunto de roles de CADA usuario en vez de razonar
     * "es el ultimo rol que tiene el permiso", porque esa forma se equivoca en
     * los dos sentidos: un rol puede tener el permiso y ningun usuario (y
     * entonces borrarlo no le quita el acceso a nadie), y un usuario puede
     * tener dos roles que por separado no alcanzan pero juntos si.
     *
     * Solo cuentan los usuarios activos: una cuenta desactivada no puede
     * entrar, asi que no sirve de red.
     */
    protected static function administrationSurvives(?Role $deleted = null, ?Role $target = null): bool
    {
        $users = User::query()->where('active', true)->with('roles')->get();

        foreach ($users as $user) {
            $roles = $user->roles;

            if ($deleted !== null) {
                $teniaElRol = $roles->contains(fn (Role $role) => $role->getKey() === $deleted->getKey());
                $roles = $roles->reject(fn (Role $role) => $role->getKey() === $deleted->getKey());

                if ($teniaElRol && $target !== null) {
                    $roles = $roles->concat([$target]);
                }
            }

            $puedeAdministrar = true;

            foreach (self::REQUIRED_TO_ADMINISTER as $permission) {
                if (! AccessControl::roleSetGrants($roles, $permission)) {
                    $puedeAdministrar = false;

                    break;
                }
            }

            if ($puedeAdministrar) {
                return true;
            }
        }

        return false;
    }

    /**
     * La red anti-bloqueo: ¿este borrado dejaria al sistema sin NADIE que pueda
     * volver a entrar a arreglarlo?
     *
     * Es el equivalente a que WordPress no te deje quitarte a vos mismo el rol
     * de administrador. Sin esto, ahora que los siete roles del sistema se
     * pueden borrar, un borrado desafortunado deja el panel cerrado para todo
     * el mundo y desde el panel ya no hay vuelta atras: se sale por base de
     * datos.
     */
    public static function wouldStrandAdministration(Role $record, ?Role $target = null): bool
    {
        // Si el sistema YA estaba sin nadie que pueda administrarlo, este
        // borrado no es el culpable. Bloquearlo dejaria a la pantalla
        // negandose a todo sin que arreglarlo sirva de nada.
        if (! self::administrationSurvives()) {
            return false;
        }

        return ! self::administrationSurvives($record, $target);
    }

    /**
     * Mueve la gente al rol destino y borra el rol. Todo o nada. Devuelve
     * cuantos usuarios cambiaron de rol.
     */
    public static function deleteAndReassign(Role $record, ?Role $target): int
    {
        $moved = DB::transaction(function () use ($record, $target) {
            $users = $record->users()->get();

            foreach ($users as $user) {
                // removeRole + assignRole, y NUNCA syncRoles: el formulario de
                // Usuarios asigna con un CheckboxList, o sea que una cuenta
                // puede tener mas de un rol. syncRoles le borraria los otros
                // sin que nadie lo haya pedido.
                $user->removeRole($record);

                if ($target !== null) {
                    $user->assignRole($target);
                }
            }

            activity()
                ->performedOn($record)
                ->causedBy(Auth::user())
                ->event('role_deleted')
                ->withProperties([
                    'role' => $record->name,
                    'reassigned_to' => $target?->name,
                    'users_moved' => $users->count(),
                ])
                ->log(LocalizedText::of('mgmt.role_deleted_log', [
                    'role' => RoleCatalog::label($record->name),
                    'count' => $users->count(),
                ])->encode());

            $record->delete();

            return $users->count();
        });

        // Spatie cachea la matriz entera de roles y permisos. Sin esto, la
        // gente que acaba de moverse seguiria viendo los permisos del rol que
        // ya no existe hasta que la cache expire sola.
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        return $moved;
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
                     *
                     * El chequeo contra el esquema NO es paranoia: este hosting
                     * no tiene SSH ni despliegue atómico, así que los archivos
                     * SIEMPRE llegan antes que la migración. En esa ventana, un
                     * formulario que escribe `description` es un 500 al guardar
                     * ("Unknown column"). Con el chequeo, el campo todavía no
                     * está y aparece solo cuando la columna existe.
                     */
                    Forms\Components\Textarea::make('description')
                        ->label(__('roles.field_description'))
                        ->rows(2)
                        ->maxLength(1000)
                        ->visible(fn () => static::descriptionColumnExists())
                        ->disabled(fn (?Role $record) => $record !== null && Lang::has('roles.role_desc_'.$record->name))
                        ->dehydrated(fn (?Role $record) => static::descriptionColumnExists()
                            && ! ($record !== null && Lang::has('roles.role_desc_'.$record->name)))
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
                 * Borrado con REASIGNACION, al modo WordPress: "que hacer con
                 * el contenido de este usuario? Atribuirlo a: ___".
                 *
                 * Antes, un rol con gente asignada no se borraba y la pantalla
                 * te mandaba a reasignar a mano, usuario por usuario, antes de
                 * volver a intentarlo. Ahora el modal pregunta a donde va esa
                 * gente y la mueve en el mismo acto.
                 *
                 * El destino es OBLIGATORIO y no existe la opcion "sin rol":
                 * dejar cuentas sin ningun rol es exactamente el accidente que
                 * el bloqueo anterior evitaba, y no hay razon para regalarlo
                 * ahora que existe una forma ordenada de hacerlo.
                 *
                 * El calculo va en ->action() y no en ->before() porque recien
                 * ahi se sabe QUE destino eligio la persona, y sin el destino
                 * la pregunta "queda alguien que pueda administrar esto?" no se
                 * puede contestar.
                 */
                Tables\Actions\DeleteAction::make()
                    ->modalHeading(fn (Role $record) => __('roles.delete_heading', [
                        'role' => RoleCatalog::label($record->name),
                    ]))
                    ->modalDescription(fn (Role $record) => static::assignedUserCount($record) === 0
                        ? __('roles.delete_description_empty')
                        : __('roles.delete_description_with_users', [
                            'count' => static::assignedUserCount($record),
                        ]))
                    ->form(fn (Role $record) => static::assignedUserCount($record) === 0 ? [] : [
                        Forms\Components\Select::make('reassign_to')
                            ->label(__('roles.reassign_label'))
                            ->helperText(__('roles.reassign_help'))
                            ->options(fn () => static::reassignmentOptions([$record->getKey()]))
                            ->native(false)
                            ->searchable()
                            ->required(),
                    ])
                    ->action(function (Role $record, array $data, Tables\Actions\DeleteAction $action) {
                        $target = filled($data['reassign_to'] ?? null)
                            ? Role::find($data['reassign_to'])
                            : null;

                        if (static::wouldStrandAdministration($record, $target)) {
                            Notification::make()
                                ->title(__('roles.delete_blocked_last_admin_title'))
                                ->body(__('roles.delete_blocked_last_admin_body'))
                                ->danger()
                                ->persistent()
                                ->send();

                            // halt() y no cancel(): el modal queda abierto para
                            // que puedan elegir otro destino sin rearmar todo.
                            $action->halt();
                        }

                        // La etiqueta se resuelve ANTES de borrar: despues, el
                        // aviso diria a donde se movio la gente nombrando un
                        // rol que ya no esta en la base.
                        $targetLabel = $target !== null ? RoleCatalog::label($target->name) : null;

                        $moved = static::deleteAndReassign($record, $target);

                        Notification::make()
                            ->title(__('roles.delete_done_title'))
                            ->body($moved === 0
                                ? __('roles.delete_done_empty')
                                : __('roles.delete_done_moved', [
                                    'count' => $moved,
                                    'role' => $targetLabel,
                                ]))
                            ->success()
                            ->persistent()
                            ->send();
                    }),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    /*
                     * El lote pregunta UN destino para todos los seleccionados,
                     * una sola vez, y solo si alguno de ellos tiene gente. Los
                     * roles del propio lote no figuran entre los destinos.
                     *
                     * Lo que se saltea ya no son "los del sistema": es
                     * unicamente el borrado que dejaria a nadie administrando.
                     * Y se dice cuantos quedaron, porque antes los saltaba en
                     * silencio y la pantalla parecia haber borrado todo.
                     */
                    Tables\Actions\DeleteBulkAction::make()
                        ->form(fn (EloquentCollection $records) => static::assignedUserCountIn($records) === 0 ? [] : [
                            Forms\Components\Select::make('reassign_to')
                                ->label(__('roles.reassign_label'))
                                ->helperText(__('roles.bulk_reassign_help', [
                                    'count' => static::assignedUserCountIn($records),
                                ]))
                                ->options(fn () => static::reassignmentOptions($records->modelKeys()))
                                ->native(false)
                                ->searchable()
                                ->required(),
                        ])
                        ->action(function (EloquentCollection $records, array $data) {
                            $target = filled($data['reassign_to'] ?? null)
                                ? Role::find($data['reassign_to'])
                                : null;

                            $borrados = 0;
                            $movidos = 0;
                            $omitidos = 0;

                            foreach ($records as $record) {
                                /** @var Role $record */
                                if (static::wouldStrandAdministration($record, $target)) {
                                    $omitidos++;

                                    continue;
                                }

                                $movidos += static::deleteAndReassign($record, $target);
                                $borrados++;
                            }

                            Notification::make()
                                ->title(__('roles.bulk_done_title'))
                                ->body(__('roles.bulk_done_body', [
                                    'deleted' => $borrados,
                                    'moved' => $movidos,
                                ]))
                                ->success()
                                ->persistent()
                                ->send();

                            if ($omitidos > 0) {
                                Notification::make()
                                    ->title(__('roles.bulk_skipped_title'))
                                    ->body(__('roles.bulk_skipped_body', [
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

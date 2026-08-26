<?php

namespace App\Support;

use App\Models\User;
use Illuminate\Contracts\Auth\Access\Authorizable;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Autorización por PERMISO de las cinco cosas que hasta ahora se decidían por
 * NOMBRE de rol.
 *
 * Por qué existe: la clienta pidió poder borrar los roles que no usa (2026-08-24)
 * y no se podía, porque siete roles estaban clavados en el código por su nombre
 * —`hasRole('administrador')` y compañía—. Mientras el acceso dependa del
 * nombre, borrar o clonar un rol rompe pantallas, así que el candado no era
 * caprichoso: era la consecuencia. Se quita moviendo esos cinco chequeos a
 * permisos, que es lo que la propia pantalla de Roles ya sabe editar.
 *
 * Los cinco:
 *
 *   - `access_panel`        quién entra al panel de escritorio (User::canAccessPanel)
 *   - `view_alerts`         quién ve la pantalla de Alertas y recibe sus avisos
 *   - `delete_machines`     quién borra una máquina (se lleva su historial)
 *   - `delete_work_orders`  quién borra una orden de trabajo
 *   - `receive_alerts_digest` a quien le llega por correo el resumen diario de alertas
 *
 * ---
 *
 * LA RED (LEGACY_ROLE_FALLBACK) NO ES DECORATIVA.
 *
 * En este hosting no hay SSH ni despliegue atómico: los archivos suben por FTP
 * y la migración corre después, a mano. En esa ventana el código nuevo convive
 * con el esquema viejo, y ahí `access_panel` TODAVÍA NO EXISTE como permiso.
 *
 * Spatie no revienta con un permiso inexistente —`checkPermissionTo()` atrapa
 * `PermissionDoesNotExist` y devuelve false— y eso es justamente el peligro:
 * sin red, `canAccessPanel()` respondería false para TODO EL MUNDO y el panel
 * quedaría cerrado para los siete usuarios hasta que alguien corra la
 * migración. Un candado accidental, en producción, sin nadie adentro para
 * abrirlo.
 *
 * Por eso: mientras el permiso no exista en la base, se resuelve por la lista
 * de nombres de siempre. En cuanto la migración corre, manda el permiso y esta
 * lista no se vuelve a mirar. Se deja en su sitio como red permanente, no como
 * andamio a limpiar: vuelve a servir sola si alguien restaura un respaldo
 * viejo del esquema.
 */
class AccessControl
{
    /**
     * Qué roles concedían cada permiso ANTES de que el permiso existiera.
     * Es el estado exacto del código al 2026-08-25, para que la ventana de
     * despliegue se comporte igual que la versión anterior.
     *
     * @var array<string, array<int, string>>
     */
    public const LEGACY_ROLE_FALLBACK = [
        'access_panel' => ['administrador', 'responsable_mantenimiento', 'taller', 'gerencia'],
        'view_alerts' => ['administrador', 'responsable_mantenimiento'],
        'delete_machines' => ['administrador'],
        'delete_work_orders' => ['administrador'],
        'receive_alerts_digest' => ['administrador'],
    ];

    /**
     * ¿Ya existe este permiso en la base?
     *
     * Se pregunta al registrar de Spatie y no con un `Permission::where()`
     * porque el registrar responde desde SU PROPIA caché —la misma que ya
     * cargó para resolver cualquier `can()` de este request—, así que la
     * respuesta no cuesta una query extra.
     *
     * A propósito NO se memoiza en un static ni con `once()`: un static se
     * queda pegado para todo el proceso y en la suite la primera respuesta
     * gobernaría los tests siguientes, y `once()` no distingue por argumento
     * (hashea archivo+línea, no los parámetros), así que memoizaría la
     * respuesta de `access_panel` y se la devolvería también a `view_alerts`.
     */
    public static function permissionExists(string $permission): bool
    {
        return app(PermissionRegistrar::class)
            ->getPermissions(['name' => $permission])
            ->isNotEmpty();
    }

    /**
     * ¿Este usuario puede hacer esto?
     *
     * Es el único punto por el que deberían pasar los cinco permisos de
     * arriba: concentra la red de la ventana de despliegue en un solo lugar en
     * vez de repetir el `if` en cada Resource.
     */
    public static function allows(?Authorizable $user, string $permission): bool
    {
        if ($user === null) {
            return false;
        }

        if (self::permissionExists($permission)) {
            return $user->can($permission);
        }

        /** @var User $user */
        return $user->hasAnyRole(self::LEGACY_ROLE_FALLBACK[$permission] ?? []);
    }

    /**
     * ¿Este CONJUNTO de roles concede el permiso?
     *
     * Separado de allows() porque la salvaguarda anti-bloqueo de RoleResource
     * necesita preguntar por un conjunto HIPOTÉTICO —"si borro este rol y
     * muevo a su gente a aquel otro, ¿queda alguien que pueda entrar?"—, y esa
     * pregunta no se le puede hacer a un usuario que todavía tiene sus roles
     * actuales.
     *
     * @param  Collection<int, Role>  $roles
     */
    public static function roleSetGrants(Collection $roles, string $permission): bool
    {
        if (self::permissionExists($permission)) {
            return $roles->contains(fn (Role $role) => $role->checkPermissionTo($permission));
        }

        return $roles->contains(
            fn (Role $role) => in_array($role->name, self::LEGACY_ROLE_FALLBACK[$permission] ?? [], true)
        );
    }

    /**
     * Usuarios ACTIVOS que tienen este permiso.
     *
     * Existe por el digest de alertas, que sale de un comando de consola: ahi
     * no hay un usuario autenticado a quien preguntarle `can()`, hay que ir a
     * buscar a los destinatarios.
     *
     * El `if` no es adorno. El scope `User::permission()` de Spatie NO perdona
     * un permiso inexistente --lanza `PermissionDoesNotExist`, a diferencia de
     * `can()`, que lo atrapa-- asi que durante la ventana de despliegue esto
     * reventaria el cron diario en vez de degradarse. Con la red, en esa
     * ventana busca por el rol de siempre.
     *
     * @return EloquentCollection<int, User>
     */
    public static function activeUsersWith(string $permission): EloquentCollection
    {
        if (self::permissionExists($permission)) {
            return User::permission($permission)->where('active', true)->get();
        }

        $legacyRoles = self::LEGACY_ROLE_FALLBACK[$permission] ?? [];

        // Sin red que aplicar no se devuelve "todos": se devuelve nadie. Un
        // digest que no sale se nota y se arregla; uno que sale a la lista
        // equivocada ya salio.
        if ($legacyRoles === []) {
            return User::query()->whereRaw('1 = 0')->get();
        }

        return User::role($legacyRoles)->where('active', true)->get();
    }
}

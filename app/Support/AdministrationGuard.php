<?php

namespace App\Support;

use App\Models\User;
use Illuminate\Support\Collection;
use Spatie\Permission\Models\Role;

/**
 * La unica pregunta que decide si el sistema se puede quedar sin duenio:
 * "¿queda al menos un usuario ACTIVO que pueda entrar al panel Y gestionar
 * usuarios y roles?"
 *
 * Existe como clase aparte, y no como un metodo mas de RoleResource, por lo
 * que encontro la auditoria de seguridad del 2026-08-26: la red estaba en UNA
 * puerta y habia CUATRO que llevan al mismo desenlace.
 *
 *   1. borrar un rol (estaba cubierta),
 *   2. desmarcarle permisos a un rol desde el formulario,
 *   3. desactivar al ultimo usuario que administra,
 *   4. quitarle el rol a esa cuenta desde Usuarios.
 *
 * Todas terminan igual: el panel cerrado para todo el mundo, en una instancia
 * unica, sin staging, y sin forma de volver desde el panel — se sale por base
 * de datos. Por eso la pregunta se hace en un solo lugar y las cuatro puertas
 * la consultan.
 *
 * ---
 *
 * POR QUE FALLA CERRADO Y NO ABIERTO.
 *
 * La primera version traia un escape: "si el sistema YA estaba sin nadie que
 * pueda administrarlo, este borrado no es el culpable, dejalo pasar". Sonaba
 * razonable y era un agujero: bastaba desactivar a los administradores para
 * apagar la red entera y despues borrar cualquier cosa.
 *
 * El escape ademas no compraba nada. Si de verdad no queda nadie que pueda
 * administrar, tampoco hay nadie adentro del panel para ejercer ese permiso:
 * la unica forma de estar mirando esta pantalla con la red apagada es haber
 * llegado por la secuencia del ataque. Falla cerrado.
 */
class AdministrationGuard
{
    /**
     * Los dos permisos que hacen falta JUNTOS para poder rescatar el sistema.
     * Con uno solo no alcanza: entrar sin poder tocar roles no arregla nada, y
     * el permiso sin la puerta no se puede ejercer.
     */
    public const REQUIRED = ['access_panel', 'manage_users'];

    /**
     * ¿Sobrevive la administracion en el estado HIPOTETICO que describen los
     * parametros?
     *
     * Se simula sobre el conjunto de roles de CADA usuario en vez de razonar
     * "es el ultimo rol que tiene el permiso", porque esa forma se equivoca en
     * los dos sentidos: un rol puede tener el permiso y ningun usuario (y
     * entonces borrarlo no le quita el acceso a nadie), y un usuario puede
     * tener dos roles que por separado no alcanzan pero juntos si.
     *
     * @param  Role|null  $deletedRole  rol que se va a borrar
     * @param  Role|null  $target  rol al que pasa su gente
     * @param  int|null  $deactivatedUserId  cuenta que se va a desactivar
     * @param  bool  $lock  bloquear las filas (para usar DENTRO de la transaccion)
     */
    public static function survives(
        ?Role $deletedRole = null,
        ?Role $target = null,
        ?int $deactivatedUserId = null,
        bool $lock = false,
    ): bool {
        $query = User::query()->where('active', true)->with('roles');

        // lockForUpdate solo cuando se llama dentro de la transaccion del
        // borrado: sin el, entre que se responde esta pregunta y se ejecuta la
        // mutacion hay una ventana en la que otro administrador puede cambiar
        // el estado sobre el que se contesto.
        if ($lock) {
            $query->lockForUpdate();
        }

        foreach ($query->get() as $user) {
            if ($deactivatedUserId !== null && $user->getKey() === $deactivatedUserId) {
                continue;
            }

            if (self::canAdminister(self::rolesAfter($user->roles, $deletedRole, $target))) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  Collection<int, Role>  $roles
     * @return Collection<int, Role>
     */
    private static function rolesAfter(Collection $roles, ?Role $deletedRole, ?Role $target): Collection
    {
        if ($deletedRole === null) {
            return $roles;
        }

        $loTenia = $roles->contains(fn (Role $role) => $role->getKey() === $deletedRole->getKey());
        $restantes = $roles->reject(fn (Role $role) => $role->getKey() === $deletedRole->getKey());

        return ($loTenia && $target !== null) ? $restantes->concat([$target]) : $restantes;
    }

    /**
     * @param  Collection<int, Role>  $roles
     */
    private static function canAdminister(Collection $roles): bool
    {
        foreach (self::REQUIRED as $permission) {
            if (! AccessControl::roleSetGrants($roles, $permission)) {
                return false;
            }
        }

        return true;
    }

    /** Puerta 1: borrar un rol. */
    public static function deletingRoleWouldStrand(Role $role, ?Role $target = null, bool $lock = false): bool
    {
        return ! self::survives($role, $target, null, $lock);
    }

    /** Puerta 3: desactivar una cuenta. */
    public static function deactivatingWouldStrand(User $user): bool
    {
        return ! self::survives(null, null, $user->getKey());
    }

    /**
     * Puerta 4: cambiarle los roles a una cuenta desde Usuarios.
     *
     * Se simula ANTES de guardar, con el conjunto de roles que viene del
     * formulario, porque aca revertir despues seria mas confuso que impedir
     * el guardado y decir por que.
     *
     * @param  array<int, int|string>  $newRoleIds
     */
    public static function changingRolesWouldStrand(User $user, array $newRoleIds): bool
    {
        $nuevos = Role::whereIn('id', $newRoleIds)->get();

        foreach (User::query()->where('active', true)->with('roles')->get() as $otro) {
            $roles = $otro->getKey() === $user->getKey() ? $nuevos : $otro->roles;

            if (self::canAdminister($roles)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Puertas 2 y 4: cualquier cosa que ya se guardo. Se pregunta DESPUES del
     * guardado, cuando el estado real ya esta en la base, y quien llama decide
     * si repara o revierte.
     */
    public static function isReachable(): bool
    {
        return self::survives();
    }
}

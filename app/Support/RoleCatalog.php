<?php

namespace App\Support;

use Illuminate\Support\Facades\Lang;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

/**
 * Nombres y descripciones legibles de roles y permisos (pedido de la clienta
 * 2026-08-24: describir para que sirve cada rol y cada permiso en el momento
 * de elegirlo, en los dos idiomas).
 *
 * Por que existe esta clase y no dos closures sueltas en cada Resource: el
 * mismo texto se muestra en TRES pantallas distintas --el alta de usuario, el
 * alta de rol y el listado de roles-- y ya paso una vez en este proyecto que
 * una etiqueta se arreglara en una sola de ellas.
 *
 * Regla de resolucion, en este orden:
 *
 *   1. La traduccion de `lang/{es,en}/roles.php` si existe. Es la fuente de los
 *      siete roles del sistema y de los quince permisos: viven en el codigo, no
 *      los edita nadie desde el panel, y asi salen en el idioma del usuario.
 *   2. La columna `roles.description`, para los roles que la clienta crea desde
 *      el panel. No hay traduccion posible de un texto que escribio ella, asi
 *      que se muestra tal cual en los dos idiomas.
 *   3. null. La pantalla decide si muestra un guion o no muestra nada; esta
 *      clase no inventa una descripcion de relleno.
 */
class RoleCatalog
{
    /**
     * Nombre legible del rol. Los del sistema tienen traduccion; los creados
     * desde el panel se muestran con el nombre que les puso quien los creo.
     */
    public static function label(string $name): string
    {
        return Lang::has('users.role_'.$name) ? __('users.role_'.$name) : $name;
    }

    /**
     * Para que sirve este rol. Ver el orden de resolucion en el docblock.
     */
    public static function describe(Role $role): ?string
    {
        if (Lang::has('roles.role_desc_'.$role->name)) {
            return __('roles.role_desc_'.$role->name);
        }

        return filled($role->description) ? $role->description : null;
    }

    /**
     * Etiqueta corta del permiso ("Registrar hour meter").
     */
    public static function permissionLabel(string $name): string
    {
        return Lang::has('roles.perm_'.$name) ? __('roles.perm_'.$name) : $name;
    }

    /**
     * Que habilita exactamente este permiso. Sin traduccion no hay descripcion:
     * un permiso nuevo lo agrega un seeder del codigo, y si alguien lo suma sin
     * escribir su descripcion, la pantalla lo muestra sin texto en vez de
     * mostrar la clave cruda `roles.perm_desc_lo_que_sea`.
     */
    public static function describePermission(string $name): ?string
    {
        return Lang::has('roles.perm_desc_'.$name) ? __('roles.perm_desc_'.$name) : null;
    }

    /**
     * Descripciones listas para ->descriptions() de un CheckboxList de roles,
     * indexadas por id (que es el valor que guarda el campo).
     *
     * @return array<int, string>
     */
    public static function roleDescriptionsById(): array
    {
        return Role::query()->orderBy('name')->get()
            ->mapWithKeys(fn (Role $role) => [$role->id => self::describe($role) ?? ''])
            ->filter(fn (string $text) => $text !== '')
            ->all();
    }

    /**
     * Idem para el CheckboxList de permisos del alta/edicion de rol.
     *
     * @return array<int, string>
     */
    public static function permissionDescriptionsById(): array
    {
        return Permission::query()->orderBy('name')->get()
            ->mapWithKeys(fn (Permission $permission) => [
                $permission->id => self::describePermission($permission->name) ?? '',
            ])
            ->filter(fn (string $text) => $text !== '')
            ->all();
    }
}

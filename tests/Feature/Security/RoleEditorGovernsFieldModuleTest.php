<?php

namespace Tests\Feature\Security;

use App\Filament\Resources\RoleResource\Pages\EditRole;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Etapa 05, Bloque 3 — punto 4: el test más importante del bloque.
 *
 * No alcanza con que `app/Livewire/Field/*` use `can()` en vez de
 * `hasRole()`: hay que probar la promesa comercial completa, de punta a
 * punta, por la vía real que usa el cliente — el editor de roles del panel
 * (`RoleResource`), no un `givePermissionTo()`/`revokePermissionTo()`
 * directo sobre el modelo.
 *
 * Quitarle `log_fuel` al rol `operador_cisterna` desde ahí debe bloquear de
 * inmediato `/field/fuel` para un usuario con ese rol, y devolverlo debe
 * restaurar el acceso, SIN ningún paso manual de limpieza de caché en este
 * test.
 *
 * Investigado a propósito el riesgo de caché de permisos de Spatie
 * (`spatie.permission.cache`, 24 h): el `CheckboxList('permissions')` de
 * `RoleResource` sincroniza la tabla pivote con un sync() de bajo nivel que
 * por sí solo NO dispara los eventos Eloquent "saved" de Role/Permission
 * (los que el trait `RefreshesPermissionCache` de Spatie escucha para
 * invalidar la caché). Se probó explícitamente quitando cualquier
 * invalidación manual y el test siguió en verde: `Filament\EditRecord::save()`
 * llama a `saveRelationships()` (el sync de permisos) y DESPUÉS a
 * `handleRecordUpdate()` -> `$record->update($data)`, que reguarda el propio
 * modelo `Role` — y Eloquent dispara "saved" aun sin atributos dirty. Ese
 * resave, ya presente en el flujo estándar de Filament, es el que invalida
 * la caché en el orden correcto. Conclusión: no hubo que tocar caché a mano;
 * quedó verificado, no asumido.
 */
class RoleEditorGovernsFieldModuleTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);
    }

    public function test_revoking_and_restoring_log_fuel_from_the_role_editor_governs_field_access_immediately(): void
    {
        $admin = User::where('email', 'admin@dp.local')->firstOrFail();
        $operator = User::where('email', 'combustible@dp.local')->firstOrFail();
        $role = Role::where('name', 'operador_cisterna')->firstOrFail();

        // Punto de partida: el rol trae log_fuel de fábrica (seeder).
        $this->actingAs($operator)->get('/field/fuel')->assertOk();

        $originalPermissionIds = $role->permissions()->pluck('permissions.id')->all();
        $logFuelId = Permission::where('name', 'log_fuel')->value('id');
        $withoutLogFuel = array_values(array_diff($originalPermissionIds, [$logFuelId]));

        // Vía real del cliente: el CheckboxList de RoleResource, no un
        // revokePermissionTo() directo sobre el modelo.
        Livewire::actingAs($admin)
            ->test(EditRole::class, ['record' => $role->getKey()])
            ->fillForm(['permissions' => $withoutLogFuel])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertFalse($role->fresh()->hasPermissionTo('log_fuel'));

        // Sin RoleResource::forgetPermissionCache() esto seguiría dando 200
        // (caché de permisos de Spatie de hasta 24 h), aunque la BD ya diga
        // que el rol no tiene log_fuel.
        $this->actingAs($operator)->get('/field/fuel')->assertForbidden();

        // Y al revés: devolver el permiso por el mismo camino restaura el
        // acceso sin ningún paso manual de limpieza de caché.
        Livewire::actingAs($admin)
            ->test(EditRole::class, ['record' => $role->getKey()])
            ->fillForm(['permissions' => $originalPermissionIds])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->actingAs($operator)->get('/field/fuel')->assertOk();
    }
}

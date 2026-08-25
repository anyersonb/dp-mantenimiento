<?php

namespace Tests\Feature\Management;

use App\Filament\Resources\RoleResource;
use App\Filament\Resources\RoleResource\Pages\ListRoles;
use App\Filament\Resources\UserResource\Pages\CreateUser;
use App\Models\User;
use App\Support\RoleCatalog;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Pantallas de Usuarios y de Roles y permisos, después de los comentarios de la
 * clienta del 2026-08-24. Cuatro pedidos distintos y un test por cada uno:
 *
 *   1. "El rol al crear el usuario sale como id y no como nombre."
 *   2. "En roles describir qué hace cada uno a la hora de seleccionar cada uno"
 *      y "en cada permiso que describa para qué es cada permiso".
 *   3. "Al crear cada rol debe poder describir para qué es ese rol."
 *   4. "Validar roles y permisos para que se puedan borrar."
 *
 * El cuarto es el que más cuidado necesita: es fácil escribir un test de
 * borrado que pasaría igual si el borrado estuviera roto del todo (nada se
 * borra => nada falla). Por eso va en dos mitades, la que bloquea y la que
 * borra de verdad, y las dos se leen contra la base.
 */
class RolesAndPermissionsScreenTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);
    }

    private function admin(): User
    {
        return User::where('email', 'admin@dp.local')->firstOrFail();
    }

    public function test_the_user_form_shows_role_names_and_never_their_ids(): void
    {
        $foreman = Role::where('name', 'foreman')->firstOrFail();

        $componente = Livewire::actingAs($this->admin())->test(CreateUser::class);

        // El nombre legible del rol está en pantalla...
        $componente->assertSee(RoleCatalog::label('foreman'));

        // ...y el campo es un CheckboxList, que arma las etiquetas en el
        // servidor. Ésa es la garantía de fondo: con el Select anterior la
        // etiqueta la resolvía el navegador y por eso podía quedar a la vista
        // el valor crudo (el id).
        $campo = $componente->instance()->form->getFlatFields(withHidden: true)['roles'];

        $this->assertInstanceOf(\Filament\Forms\Components\CheckboxList::class, $campo);
        $this->assertSame(
            RoleCatalog::label('foreman'),
            $campo->getOptions()[$foreman->id] ?? null,
        );

        // Y sigue guardando lo mismo que antes: un arreglo de ids sobre la
        // relación. Si esto se rompiera, el usuario se crearía sin rol.
        Livewire::actingAs($this->admin())
            ->test(CreateUser::class)
            ->fillForm([
                'name' => 'Capataz Nuevo',
                'email' => 'capataz.nuevo@dp.local',
                'password' => 'password123',
                'password_confirmation' => 'password123',
                'locale' => 'es',
                'active' => true,
                'roles' => [$foreman->id],
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertTrue(
            User::where('email', 'capataz.nuevo@dp.local')->firstOrFail()->hasRole('foreman')
        );
    }

    public function test_every_role_and_every_permission_explains_what_it_is_for_when_you_pick_it(): void
    {
        // Roles: la descripción viaja junto a cada opción del alta de usuario.
        $descripcionesDeRol = RoleCatalog::roleDescriptionsById();

        foreach (Role::whereIn('name', RoleResource::SYSTEM_ROLES)->get() as $rol) {
            $this->assertArrayHasKey(
                $rol->id,
                $descripcionesDeRol,
                "El rol {$rol->name} no tiene descripción y se ofrecería sin explicar qué hace.",
            );
        }

        Livewire::actingAs($this->admin())
            ->test(CreateUser::class)
            ->assertSee(__('roles.role_desc_taller'));

        // Permisos: idem en el alta/edición de rol.
        $administrador = Role::where('name', 'administrador')->firstOrFail();

        Livewire::actingAs($this->admin())
            ->test(RoleResource\Pages\EditRole::class, ['record' => $administrador->id])
            ->assertSee(__('roles.perm_desc_view_costs'))
            ->assertSee(__('roles.perm_desc_view_audit_log'))
            // Y la etiqueta con la grafía que pidió la clienta: "hour meter",
            // separado. En español la etiqueta es "Registrar horómetro".
            ->assertSee(__('roles.perm_log_horometer'));

        // Ningún permiso queda sin describir: si el seeder agrega uno nuevo y
        // nadie le escribe la descripción, este test lo canta por nombre.
        $sinDescripcion = \Spatie\Permission\Models\Permission::all()
            ->filter(fn ($permiso) => RoleCatalog::describePermission($permiso->name) === null)
            ->pluck('name')
            ->all();

        $this->assertSame([], $sinDescripcion, 'Permisos sin descripción: '.implode(', ', $sinDescripcion));
    }

    public function test_a_role_created_from_the_panel_carries_the_description_that_was_written_for_it(): void
    {
        Livewire::actingAs($this->admin())
            ->test(RoleResource\Pages\CreateRole::class)
            ->fillForm([
                'name' => 'mecanico_externo',
                'description' => 'Taller tercerizado: solo carga repuestos y cierra la orden.',
                'permissions' => [
                    \Spatie\Permission\Models\Permission::where('name', 'view_fleet')->firstOrFail()->id,
                ],
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $rol = Role::where('name', 'mecanico_externo')->firstOrFail();

        $this->assertSame(
            'Taller tercerizado: solo carga repuestos y cierra la orden.',
            RoleCatalog::describe($rol),
        );

        // Y se ve en el listado, no solo guardada.
        Livewire::actingAs($this->admin())
            ->test(ListRoles::class)
            ->assertCanSeeTableRecords([$rol])
            ->assertSee('Taller tercerizado');
    }

    /**
     * El formulario no escribe `roles.description` si la columna todavía no
     * existe.
     *
     * No es un caso teórico: este hosting no tiene despliegue atómico, los
     * archivos suben por FTP y la migración corre después a mano, así que
     * SIEMPRE hay una ventana con el código nuevo y el esquema viejo. Sin el
     * chequeo, en esa ventana crear un rol es un 500 ("Unknown column").
     *
     * El test tira la columna de verdad y crea un rol por el formulario.
     */
    public function test_the_role_form_survives_the_window_where_the_migration_has_not_run_yet(): void
    {
        \Illuminate\Support\Facades\Schema::table('roles', function ($tabla) {
            $tabla->dropColumn('description');
        });

        // Antes de nada: que el propio chequeo vea la realidad. Si esto diera
        // true, el resto del test pasaría por el camino equivocado y no
        // probaría nada.
        $this->assertFalse(RoleResource::descriptionColumnExists());

        Livewire::actingAs($this->admin())
            ->test(RoleResource\Pages\CreateRole::class)
            ->assertFormFieldIsHidden('description')
            ->fillForm([
                'name' => 'rol_sin_columna',
                'permissions' => [
                    \Spatie\Permission\Models\Permission::where('name', 'view_fleet')->firstOrFail()->id,
                ],
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertDatabaseHas('roles', ['name' => 'rol_sin_columna']);
    }

    public function test_a_role_with_users_assigned_cannot_be_deleted_and_one_without_users_can(): void
    {
        $permisoBase = \Spatie\Permission\Models\Permission::where('name', 'view_fleet')->firstOrFail();

        $conGente = Role::create(['name' => 'rol_con_gente', 'guard_name' => 'web']);
        $conGente->givePermissionTo($permisoBase);

        $vacio = Role::create(['name' => 'rol_vacio', 'guard_name' => 'web']);
        $vacio->givePermissionTo($permisoBase);

        $usuario = User::factory()->create(['email' => 'ocupa.rol@dp.local']);
        $usuario->assignRole($conGente);

        // Mitad 1: con usuarios asignados, no se borra.
        Livewire::actingAs($this->admin())
            ->test(ListRoles::class)
            ->callTableAction('delete', $conGente);

        $this->assertDatabaseHas('roles', ['id' => $conGente->id]);
        $this->assertNotNull(RoleResource::deletionBlockReason($conGente->fresh()));

        // Mitad 2: el mismo botón, sobre un rol sin usuarios, SÍ borra. Sin
        // esta mitad, un borrado roto del todo pasaría la mitad de arriba.
        Livewire::actingAs($this->admin())
            ->test(ListRoles::class)
            ->callTableAction('delete', $vacio);

        $this->assertDatabaseMissing('roles', ['id' => $vacio->id]);
    }

    public function test_a_system_role_offers_no_delete_but_says_why(): void
    {
        $taller = Role::where('name', 'taller')->firstOrFail();

        Livewire::actingAs($this->admin())
            ->test(ListRoles::class)
            // La barrera real: canDelete() es false, y Filament la inyecta como
            // ->authorize() de esta acción, así que el borrado no existe.
            ->assertTableActionHidden('delete', $taller)
            // Y en su lugar hay algo que explica el porqué, en vez de una fila
            // que se ve igual que una donde el borrado nunca se implementó.
            ->assertTableActionVisible('locked', $taller);

        $this->assertFalse(RoleResource::canDelete($taller));
        $this->assertSame(__('roles.delete_reason_system'), RoleResource::deletionBlockReason($taller));

        // Sigue en la base después de intentarlo por la pantalla.
        Livewire::actingAs($this->admin())
            ->test(ListRoles::class)
            ->callTableAction('locked', $taller);

        $this->assertDatabaseHas('roles', ['id' => $taller->id]);
    }
}

<?php

namespace Tests\Feature\Management;

use App\Filament\Resources\RoleResource;
use App\Filament\Resources\RoleResource\Pages\EditRole;
use App\Filament\Resources\RoleResource\Pages\ListRoles;
use App\Filament\Resources\UserResource\Pages\CreateUser;
use App\Models\User;
use App\Support\AccessControl;
use App\Support\RoleCatalog;
use Database\Seeders\RolesAndPermissionsSeeder;
use Filament\Facades\Filament;
use Filament\Forms\Components\CheckboxList;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
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

        $this->assertInstanceOf(CheckboxList::class, $campo);
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
            ->test(EditRole::class, ['record' => $administrador->id])
            ->assertSee(__('roles.perm_desc_view_costs'))
            ->assertSee(__('roles.perm_desc_view_audit_log'))
            // Y la etiqueta con la grafía que pidió la clienta: "hour meter",
            // separado. En español la etiqueta es "Registrar horómetro".
            ->assertSee(__('roles.perm_log_horometer'));

        // Ningún permiso queda sin describir: si el seeder agrega uno nuevo y
        // nadie le escribe la descripción, este test lo canta por nombre.
        $sinDescripcion = Permission::all()
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
                    Permission::where('name', 'view_fleet')->firstOrFail()->id,
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
        Schema::table('roles', function ($tabla) {
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
                    Permission::where('name', 'view_fleet')->firstOrFail()->id,
                ],
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertDatabaseHas('roles', ['name' => 'rol_sin_columna']);
    }

    public function test_a_role_with_users_is_deleted_by_moving_its_people_where_you_say(): void
    {
        $permisoBase = Permission::where('name', 'view_fleet')->firstOrFail();

        $conGente = Role::create(['name' => 'rol_con_gente', 'guard_name' => 'web']);
        $conGente->givePermissionTo($permisoBase);

        $destino = Role::create(['name' => 'rol_destino', 'guard_name' => 'web']);
        $destino->givePermissionTo($permisoBase);

        $usuario = User::factory()->create(['email' => 'ocupa.rol@dp.local']);
        $usuario->assignRole($conGente);

        Livewire::actingAs($this->admin())
            ->test(ListRoles::class)
            ->callTableAction('delete', $conGente, ['reassign_to' => $destino->id]);

        // El rol se fue Y la persona aterrizo donde se dijo. Las dos mitades
        // importan: si solo se mirara el borrado, dejar la cuenta sin ningun
        // rol pasaria el test igual.
        $this->assertDatabaseMissing('roles', ['id' => $conGente->id]);
        $this->assertTrue($usuario->fresh()->hasRole('rol_destino'));
    }

    public function test_deleting_a_role_with_users_demands_saying_where_they_go(): void
    {
        $conGente = Role::create(['name' => 'rol_con_gente', 'guard_name' => 'web']);
        Role::create(['name' => 'rol_destino', 'guard_name' => 'web']);

        $usuario = User::factory()->create(['email' => 'ocupa.rol@dp.local']);
        $usuario->assignRole($conGente);

        Livewire::actingAs($this->admin())
            ->test(ListRoles::class)
            ->callTableAction('delete', $conGente)
            ->assertHasTableActionErrors(['reassign_to']);

        // Ni se borro el rol ni la cuenta quedo sin permisos, que es el
        // accidente que el destino obligatorio existe para evitar.
        $this->assertDatabaseHas('roles', ['id' => $conGente->id]);
        $this->assertTrue($usuario->fresh()->hasRole('rol_con_gente'));
    }

    /**
     * El pedido literal de la clienta. Antes esta prueba era imposible: los
     * siete roles del sistema no ofrecian siquiera el boton.
     */
    public function test_a_system_role_can_now_be_deleted(): void
    {
        $taller = Role::where('name', 'taller')->firstOrFail();
        $gerencia = Role::where('name', 'gerencia')->firstOrFail();
        $tecnico = User::where('email', 'taller@dp.local')->firstOrFail();

        // El actingAs no es decorativo: canDelete() pregunta por el usuario
        // autenticado, y sin nadie logueado esta afirmacion se contestaria
        // sola con false. (El test anterior afirmaba justamente false, y
        // pasaba por este motivo y no por el candado que creia estar probando.)
        $this->actingAs($this->admin());
        $this->assertTrue(RoleResource::canDelete($taller));

        Livewire::actingAs($this->admin())
            ->test(ListRoles::class)
            ->callTableAction('delete', $taller, ['reassign_to' => $gerencia->id]);

        $this->assertDatabaseMissing('roles', ['id' => $taller->id]);
        $this->assertTrue($tecnico->fresh()->hasRole('gerencia'));
    }

    /**
     * La red anti-bloqueo. Sin esto, ahora que los siete se pueden borrar, un
     * borrado desafortunado cierra el panel para todo el mundo y desde el
     * panel ya no hay como volver.
     */
    public function test_the_last_way_into_the_system_cannot_be_deleted(): void
    {
        $administrador = Role::where('name', 'administrador')->firstOrFail();
        $foreman = Role::where('name', 'foreman')->firstOrFail();

        $this->assertSame(
            __('roles.delete_reason_last_admin'),
            RoleResource::deletionBlockReason($administrador, $foreman)
        );

        Livewire::actingAs($this->admin())
            ->test(ListRoles::class)
            ->callTableAction('delete', $administrador, ['reassign_to' => $foreman->id]);

        $this->assertDatabaseHas('roles', ['id' => $administrador->id]);
        $this->assertTrue($this->admin()->fresh()->hasRole('administrador'));
    }

    /**
     * La pregunta que abrio todo esto: si clono un rol con las mismas
     * caracteristicas, ¿sirve igual y puedo borrar el original?
     *
     * Con el acceso decidido por nombre la respuesta era no: el clon no entraba
     * al panel por mas permisos que tuviera. Por permiso, si.
     */
    public function test_a_clone_with_the_same_permissions_can_replace_the_original(): void
    {
        $administrador = Role::where('name', 'administrador')->firstOrFail();

        $clon = Role::create(['name' => 'administrador_2', 'guard_name' => 'web']);
        $clon->syncPermissions($administrador->permissions);

        $suplente = User::factory()->create(['email' => 'suplente@dp.local', 'active' => true]);
        $suplente->assignRole($clon);

        // 1. El clon abre el panel igual que el original.
        $this->assertTrue($suplente->fresh()->canAccessPanel(Filament::getDefaultPanel()));

        // 2. Y con el clon en pie, el original ya se puede borrar.
        Livewire::actingAs($this->admin())
            ->test(ListRoles::class)
            ->callTableAction('delete', $administrador, ['reassign_to' => $clon->id]);

        $this->assertDatabaseMissing('roles', ['id' => $administrador->id]);
        $this->assertTrue($this->admin()->fresh()->hasRole('administrador_2'));
    }

    public function test_moving_someone_does_not_strip_their_other_roles(): void
    {
        $aBorrar = Role::create(['name' => 'rol_a_borrar', 'guard_name' => 'web']);
        $otro = Role::create(['name' => 'rol_que_conserva', 'guard_name' => 'web']);
        $destino = Role::create(['name' => 'rol_destino', 'guard_name' => 'web']);

        $usuario = User::factory()->create(['email' => 'dos.roles@dp.local']);
        $usuario->assignRole($aBorrar);
        $usuario->assignRole($otro);

        Livewire::actingAs($this->admin())
            ->test(ListRoles::class)
            ->callTableAction('delete', $aBorrar, ['reassign_to' => $destino->id]);

        $usuario = $usuario->fresh();

        // syncRoles() habria pasado el resto de este test y borrado en silencio
        // el segundo rol. Por eso se afirma lo que CONSERVA, no solo lo nuevo.
        $this->assertTrue($usuario->hasRole('rol_destino'));
        $this->assertTrue($usuario->hasRole('rol_que_conserva'));
        $this->assertFalse($usuario->hasRole('rol_a_borrar'));
    }

    /**
     * La puerta del panel es un PERMISO, no una lista de cuatro nombres. Es lo
     * que hace que clonar un rol sirva de algo.
     */
    public function test_the_panel_door_is_a_permission_that_a_brand_new_role_can_hold(): void
    {
        $conPuerta = Role::create(['name' => 'supervisor_obra', 'guard_name' => 'web']);
        $conPuerta->givePermissionTo('access_panel');

        $sinPuerta = Role::create(['name' => 'ayudante_obra', 'guard_name' => 'web']);
        $sinPuerta->givePermissionTo('view_fleet');

        $entra = User::factory()->create(['email' => 'entra@dp.local', 'active' => true]);
        $entra->assignRole($conPuerta);

        $noEntra = User::factory()->create(['email' => 'no.entra@dp.local', 'active' => true]);
        $noEntra->assignRole($sinPuerta);

        $panel = Filament::getDefaultPanel();

        $this->assertTrue($entra->fresh()->canAccessPanel($panel));
        // La otra mitad: sin el permiso no entra. Sin esto, un canAccessPanel()
        // que devolviera true siempre pasaria la primera mitad.
        $this->assertFalse($noEntra->fresh()->canAccessPanel($panel));
    }

    /**
     * La ventana de despliegue, que en este hosting es real: los archivos
     * suben por FTP y la migracion corre despues, a mano.
     *
     * Si en ese hueco `canAccessPanel()` se apoyara solo en el permiso, el
     * panel quedaria cerrado para TODOS hasta que alguien corriera la
     * migracion. Se simula borrando el permiso.
     */
    public function test_the_panel_still_opens_while_the_migration_has_not_run_yet(): void
    {
        Permission::where('name', 'access_panel')->delete();
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $this->assertFalse(AccessControl::permissionExists('access_panel'));

        $tecnico = User::where('email', 'taller@dp.local')->firstOrFail();
        $capataz = User::where('email', 'foreman@dp.local')->firstOrFail();

        // Cae a la lista de nombres de siempre: taller entra, foreman no.
        $panel = Filament::getDefaultPanel();

        $this->assertTrue($tecnico->fresh()->canAccessPanel($panel));
        $this->assertFalse($capataz->fresh()->canAccessPanel($panel));
    }

    public function test_bulk_delete_moves_everyone_and_skips_the_last_way_in(): void
    {
        $administrador = Role::where('name', 'administrador')->firstOrFail();
        $gerencia = Role::where('name', 'gerencia')->firstOrFail();

        $prescindible = Role::create(['name' => 'rol_prescindible', 'guard_name' => 'web']);
        $usuario = User::factory()->create(['email' => 'en.lote@dp.local']);
        $usuario->assignRole($prescindible);

        Livewire::actingAs($this->admin())
            ->test(ListRoles::class)
            ->callTableBulkAction('delete', [$prescindible, $administrador], [
                'reassign_to' => $gerencia->id,
            ]);

        // El prescindible se fue y su gente aterrizo en gerencia...
        $this->assertDatabaseMissing('roles', ['id' => $prescindible->id]);
        $this->assertTrue($usuario->fresh()->hasRole('gerencia'));

        // ...pero administrador quedo en pie: gerencia no puede gestionar
        // usuarios, asi que ese borrado habria dejado el sistema sin dueno.
        $this->assertDatabaseHas('roles', ['id' => $administrador->id]);
        $this->assertTrue($this->admin()->fresh()->hasRole('administrador'));
    }

    /**
     * La pantalla de edicion monta SU PROPIA instancia del boton de borrado.
     * Es la leccion A8 del proyecto: una regla que solo se arregla en el
     * listado deja la otra pantalla con el comportamiento viejo, y nadie se
     * entera hasta que alguien borra desde ahi.
     */
    public function test_the_edit_screen_deletes_with_the_same_rules_as_the_list(): void
    {
        $conGente = Role::create(['name' => 'rol_en_edicion', 'guard_name' => 'web']);
        $destino = Role::create(['name' => 'rol_destino', 'guard_name' => 'web']);

        $usuario = User::factory()->create(['email' => 'edita@dp.local']);
        $usuario->assignRole($conGente);

        // Sin destino no borra, igual que en el listado.
        Livewire::actingAs($this->admin())
            ->test(EditRole::class, ['record' => $conGente->getKey()])
            ->callAction('delete')
            ->assertHasActionErrors(['reassign_to']);

        $this->assertDatabaseHas('roles', ['id' => $conGente->id]);

        // Con destino, borra y mueve.
        Livewire::actingAs($this->admin())
            ->test(EditRole::class, ['record' => $conGente->getKey()])
            ->callAction('delete', ['reassign_to' => $destino->id]);

        $this->assertDatabaseMissing('roles', ['id' => $conGente->id]);
        $this->assertTrue($usuario->fresh()->hasRole('rol_destino'));
    }
}

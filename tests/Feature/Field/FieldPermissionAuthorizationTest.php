<?php

namespace Tests\Feature\Field;

use App\Livewire\Field\ForemanBoard;
use App\Models\Location;
use App\Models\Machine;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Etapa 05, Bloque 3 — hallazgos C2 y A1.
 *
 * Antes del fix, `app/Livewire/Field/*` autorizaba por NOMBRE DE ROL
 * (hasRole) y no tenía ni un solo `can()`. Consecuencia comercial: el
 * `RoleResource` que le entregamos al cliente para editar permisos no
 * gobernaba absolutamente nada del módulo de campo. Este archivo prueba en
 * particular que "confirmar ubicación" (confirm_location, foreman) y "mover
 * flota" (move_fleet, gerencia) son facultades distintas, no una sola
 * disfrazada de la otra (C2), más un control directo de A1 sobre log_fuel.
 * La cobertura de C3 (field_report inalcanzable para foreman) vive en
 * ForemanFieldReportAccessTest.
 */
class FieldPermissionAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);
    }

    protected function machine(?Location $location = null): Machine
    {
        $location ??= Location::create(['name' => 'Origin Yard', 'slug' => 'origin-yard-'.uniqid()]);

        return Machine::create([
            'id_code' => 'PRM-'.random_int(1000, 9999),
            'status' => 'active',
            'current_location_id' => $location->id,
            'current_hours' => 100,
        ]);
    }

    /**
     * C2: foreman solo tiene confirm_location. Elegir una obra distinta de
     * la actual por el <select> del formulario debe bloquearse server-side.
     */
    public function test_foreman_cannot_move_a_machine_to_a_different_location_via_the_form(): void
    {
        $foreman = User::where('email', 'foreman@dp.local')->firstOrFail();
        $machine = $this->machine();
        $otherLocation = Location::create(['name' => 'Other Jobsite', 'slug' => 'other-jobsite-'.uniqid()]);

        // abort_unless(403) dentro de save() es manejado por el kernel de
        // pruebas (HttpException queda excluida de withoutExceptionHandling
        // en el testing de Livewire) y llega acá como respuesta 403, no como
        // excepción no atrapada.
        Livewire::actingAs($foreman)
            ->test(ForemanBoard::class)
            ->call('selectMachine', $machine->id)
            ->set('locationId', $otherLocation->id)
            ->call('save')
            ->assertForbidden();

        $this->assertSame($machine->current_location_id, $machine->refresh()->current_location_id);
        $this->assertDatabaseMissing('activity_log', [
            'subject_type' => Machine::class,
            'subject_id' => $machine->id,
            'event' => 'location_moved',
        ]);
    }

    /**
     * C2: mismo bloqueo, simulando un payload Livewire manipulado — el
     * <select> jamás ofrecería esta obra (getAllowedLocationsProperty la
     * excluye), pero el servidor debe rechazarlo igual si alguien lo manda
     * directo por el wire:model.
     */
    public function test_foreman_cannot_move_a_machine_via_a_manipulated_livewire_payload(): void
    {
        $foreman = User::where('email', 'foreman@dp.local')->firstOrFail();
        $machine = $this->machine();
        $otherLocation = Location::create(['name' => 'Manipulated Target', 'slug' => 'manipulated-target-'.uniqid()]);

        // El <select> real jamás listaría $otherLocation para este usuario.
        $allowedIds = Livewire::actingAs($foreman)
            ->test(ForemanBoard::class)
            ->call('selectMachine', $machine->id)
            ->viewData('locations')
            ->pluck('id')
            ->all();

        $this->assertNotContains($otherLocation->id, $allowedIds);

        Livewire::actingAs($foreman)
            ->test(ForemanBoard::class)
            ->call('selectMachine', $machine->id)
            ->set('locationId', $otherLocation->id)
            ->call('save')
            ->assertForbidden();

        $this->assertSame($machine->current_location_id, $machine->refresh()->current_location_id);
    }

    /**
     * C2, control positivo: foreman SÍ puede confirmar la obra que la
     * máquina ya tiene asignada (confirm_location alcanza para eso).
     */
    public function test_foreman_can_confirm_the_machine_current_location(): void
    {
        $foreman = User::where('email', 'foreman@dp.local')->firstOrFail();
        $machine = $this->machine();

        Livewire::actingAs($foreman)
            ->test(ForemanBoard::class)
            ->call('selectMachine', $machine->id)
            ->call('save')
            ->assertSet('submitted', true);

        $this->assertDatabaseHas('activity_log', [
            'subject_type' => Machine::class,
            'subject_id' => $machine->id,
            'event' => 'location_confirmed',
        ]);
    }

    /**
     * C2, control positivo del otro lado: quien tiene move_fleet sí puede
     * reasignar la máquina a otra obra desde este mismo componente — la
     * facultad correcta gobierna, no el nombre del rol.
     */
    public function test_a_user_with_move_fleet_permission_can_relocate_the_machine(): void
    {
        $gerencia = User::where('email', 'gerencia@dp.local')->firstOrFail();
        $machine = $this->machine();
        $destination = Location::create(['name' => 'Destination Jobsite', 'slug' => 'destination-jobsite-'.uniqid()]);

        Livewire::actingAs($gerencia)
            ->test(ForemanBoard::class)
            ->call('selectMachine', $machine->id)
            ->set('locationId', $destination->id)
            ->call('save')
            ->assertSet('submitted', true);

        $this->assertSame($destination->id, $machine->refresh()->current_location_id);

        $this->assertDatabaseHas('activity_log', [
            'subject_type' => Machine::class,
            'subject_id' => $machine->id,
            'event' => 'location_moved',
        ]);
    }

    /**
     * A1: operador_cisterna sin log_fuel no puede registrar combustible; con
     * el permiso devuelto, sí puede. Prueba directa sobre el permiso (no el
     * rol), independiente del flujo de RoleResource que cubre el punto 4.
     */
    public function test_operador_cisterna_without_log_fuel_permission_cannot_log_fuel_but_can_with_it(): void
    {
        $operator = User::where('email', 'combustible@dp.local')->firstOrFail();
        $role = Role::where('name', 'operador_cisterna')->firstOrFail();

        $role->revokePermissionTo('log_fuel');
        $operator->unsetRelation('roles');

        $this->actingAs($operator)->get('/field/fuel')->assertForbidden();

        $role->givePermissionTo('log_fuel');
        $operator->unsetRelation('roles');

        $this->actingAs($operator)->get('/field/fuel')->assertOk();
    }
}

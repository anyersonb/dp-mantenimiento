<?php

namespace Tests\Feature\Management;

use App\Filament\Resources\MachineResource\Pages\ListMachines;
use App\Models\Location;
use App\Models\Machine;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Movimiento de flota entre obras: solo gerencia/administrador (permiso
 * "move_fleet") pueden reubicar máquinas, y el cambio queda en la bitácora
 * (activity_log) porque Machine::getActivitylogOptions() registra
 * current_location_id.
 */
class FleetMovementTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);
    }

    protected function machine(Location $location): Machine
    {
        return Machine::create([
            'id_code' => 'MOV-'.random_int(1000, 9999),
            'status' => 'active',
            'current_location_id' => $location->id,
        ]);
    }

    public function test_gerencia_can_move_a_machine_to_another_location_and_it_is_logged(): void
    {
        $gerencia = User::where('email', 'gerencia@dp.local')->firstOrFail();
        $origin = Location::create(['name' => 'Origin Yard', 'slug' => 'origin-yard-'.uniqid()]);
        $destination = Location::create(['name' => 'Destination Jobsite', 'slug' => 'destination-'.uniqid()]);
        $machine = $this->machine($origin);

        Livewire::actingAs($gerencia)
            ->test(ListMachines::class)
            ->callTableAction('move', $machine, data: [
                'current_location_id' => $destination->id,
            ])
            ->assertHasNoTableActionErrors();

        $this->assertSame($destination->id, $machine->refresh()->current_location_id);

        $this->assertDatabaseHas('activity_log', [
            'subject_type' => Machine::class,
            'subject_id' => $machine->id,
        ]);
    }

    public function test_administrator_can_bulk_move_machines(): void
    {
        $admin = User::where('email', 'admin@dp.local')->firstOrFail();
        $origin = Location::create(['name' => 'Origin Yard 2', 'slug' => 'origin-yard-2-'.uniqid()]);
        $destination = Location::create(['name' => 'Destination 2', 'slug' => 'destination-2-'.uniqid()]);
        $machineA = $this->machine($origin);
        $machineB = $this->machine($origin);

        Livewire::actingAs($admin)
            ->test(ListMachines::class)
            ->callTableBulkAction('move', [$machineA, $machineB], data: [
                'current_location_id' => $destination->id,
            ]);

        $this->assertSame($destination->id, $machineA->refresh()->current_location_id);
        $this->assertSame($destination->id, $machineB->refresh()->current_location_id);
    }

    public function test_a_user_without_move_fleet_permission_does_not_see_the_move_action(): void
    {
        $taller = User::where('email', 'taller@dp.local')->firstOrFail();
        $location = Location::create(['name' => 'Some Yard', 'slug' => 'some-yard-'.uniqid()]);
        $machine = $this->machine($location);

        Livewire::actingAs($taller)
            ->test(ListMachines::class)
            ->assertTableActionHidden('move', $machine);
    }
}

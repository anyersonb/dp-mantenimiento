<?php

namespace Tests\Feature\Security;

use App\Filament\Resources\MachineResource\Pages\CreateMachine;
use App\Filament\Resources\MachineResource\Pages\EditMachine;
use App\Models\Location;
use App\Models\Machine;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Hallazgo A7 (Etapa 05, nunca entró en un bloque de fix — reconciliado en
 * Etapa 06). `machines.current_hours`, `service_interval_hours` y
 * `last_service_hours` son `int unsigned` en base: un valor negativo no daba
 * error de validación, reventaba contra MySQL con `SQLSTATE 22003` (HTTP 500).
 *
 * Este test falla SIN el fix (`->minValue(0)` en MachineResource): sin él,
 * `create`/`save` deja pasar el -5 hasta el INSERT/UPDATE y Livewire no
 * reporta error de formulario (la excepción de base ni siquiera llega a
 * convertirse en un ValidationException limpio en este flujo).
 */
class MachineNumericFloorTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);
    }

    public function test_negative_current_hours_is_rejected_by_form_validation_on_create(): void
    {
        $admin = User::where('email', 'admin@dp.local')->firstOrFail();

        Livewire::actingAs($admin)
            ->test(CreateMachine::class)
            ->fillForm([
                'id_code' => 'QA-FLOOR-'.random_int(1000, 9999),
                'status' => 'active',
                'hourmeter_status' => 'ok',
                'current_hours' => -5,
            ])
            ->call('create')
            ->assertHasFormErrors(['current_hours']);

        $this->assertDatabaseCount('machines', 0);
    }

    public function test_negative_service_interval_hours_is_rejected_by_form_validation_on_create(): void
    {
        $admin = User::where('email', 'admin@dp.local')->firstOrFail();

        Livewire::actingAs($admin)
            ->test(CreateMachine::class)
            ->fillForm([
                'id_code' => 'QA-FLOOR-'.random_int(1000, 9999),
                'status' => 'active',
                'hourmeter_status' => 'ok',
                'service_interval_hours' => -100,
            ])
            ->call('create')
            ->assertHasFormErrors(['service_interval_hours']);

        $this->assertDatabaseCount('machines', 0);
    }

    public function test_negative_last_service_hours_is_rejected_by_form_validation_on_edit(): void
    {
        $admin = User::where('email', 'admin@dp.local')->firstOrFail();
        $location = Location::create(['name' => 'Test Yard', 'slug' => 'test-yard-'.uniqid()]);
        $machine = Machine::create([
            'id_code' => 'QA-FLOOR-EDIT-'.random_int(1000, 9999),
            'status' => 'active',
            'current_location_id' => $location->id,
        ]);

        Livewire::actingAs($admin)
            ->test(EditMachine::class, ['record' => $machine->getKey()])
            ->fillForm(['last_service_hours' => -1])
            ->call('save')
            ->assertHasFormErrors(['last_service_hours']);
    }

    /**
     * Caso legítimo que NO debe romperse: `remaining_hours` es `int` firmado
     * a propósito (una máquina vencida tiene horas restantes negativas) y
     * NO lleva `minValue(0)`. Si algún fix futuro le agrega un piso por
     * error, este test lo detecta.
     */
    public function test_negative_remaining_hours_is_still_accepted(): void
    {
        $admin = User::where('email', 'admin@dp.local')->firstOrFail();

        Livewire::actingAs($admin)
            ->test(CreateMachine::class)
            ->fillForm([
                'id_code' => 'QA-FLOOR-'.random_int(1000, 9999),
                'status' => 'active',
                'hourmeter_status' => 'ok',
                'remaining_hours' => -250,
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertDatabaseHas('machines', ['remaining_hours' => -250]);
    }

    /**
     * Caso legítimo que NO debe romperse: `hours_adjustment` es `int`
     * firmado a propósito y puede ser negativo.
     */
    public function test_negative_hours_adjustment_is_still_accepted(): void
    {
        $admin = User::where('email', 'admin@dp.local')->firstOrFail();

        Livewire::actingAs($admin)
            ->test(CreateMachine::class)
            ->fillForm([
                'id_code' => 'QA-FLOOR-'.random_int(1000, 9999),
                'status' => 'active',
                'hourmeter_status' => 'ok',
                'hours_adjustment' => -10,
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertDatabaseHas('machines', ['hours_adjustment' => -10]);
    }
}

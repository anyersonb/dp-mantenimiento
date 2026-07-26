<?php

namespace Tests\Feature\Field;

use App\Livewire\Field\ForemanBoard;
use App\Livewire\Field\FuelLog;
use App\Livewire\Field\ReportForm;
use App\Models\Location;
use App\Models\Machine;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Hallazgo M4: hasta antes de este fix, una lectura de horómetro menor a la
 * actual quedaba guardada en el historial sin ningún efecto y sin avisar a
 * quien la cargó (el observer hacía un `return` silencioso). Ahora se
 * rechaza explícitamente en el camino de campo (estos 3 componentes
 * Livewire); el importador del PM Service Report sigue tolerando filas
 * desordenadas del Excel porque no pasa por esta validación (crea el
 * HorometerReading directamente, ver PmReportRegressiveToleranceTest).
 */
class RegressiveReadingRejectionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);
    }

    protected function machine(): Machine
    {
        $location = Location::create(['name' => 'Test Yard', 'slug' => 'test-yard-'.uniqid()]);

        return Machine::create([
            'id_code' => 'TST-'.random_int(1000, 9999),
            'status' => 'active',
            'current_location_id' => $location->id,
            'current_hours' => 500,
        ]);
    }

    public function test_report_form_rejects_a_regressive_reading_with_a_message_and_saves_nothing(): void
    {
        $worker = User::where('email', 'campo@dp.local')->firstOrFail();
        $machine = $this->machine();

        Livewire::actingAs($worker)
            ->test(ReportForm::class)
            ->call('selectMachine', $machine->id)
            ->set('condition', 'ok')
            ->set('hours', '480')
            ->call('save')
            ->assertHasErrors(['hours'])
            ->assertSet('submitted', false);

        $this->assertSame(500, $machine->refresh()->current_hours);
        $this->assertDatabaseMissing('field_reports', ['machine_id' => $machine->id]);
        $this->assertDatabaseMissing('horometer_readings', ['machine_id' => $machine->id]);
    }

    public function test_fuel_log_rejects_a_regressive_reading_with_a_message(): void
    {
        $operator = User::where('email', 'combustible@dp.local')->firstOrFail();
        $machine = $this->machine();

        Livewire::actingAs($operator)
            ->test(FuelLog::class)
            ->call('selectMachine', $machine->id)
            ->set('gallons', '10')
            ->set('hours', '480')
            ->call('save')
            ->assertHasErrors(['hours'])
            ->assertSet('submitted', false);

        $this->assertDatabaseMissing('horometer_readings', ['machine_id' => $machine->id]);
    }

    public function test_foreman_board_rejects_a_regressive_reading_and_does_not_move_the_machine_either(): void
    {
        $foreman = User::where('email', 'foreman@dp.local')->firstOrFail();
        $machine = $this->machine();
        $newLocation = Location::create(['name' => 'New Job Site', 'slug' => 'new-job-site-'.uniqid()]);

        Livewire::actingAs($foreman)
            ->test(ForemanBoard::class)
            ->call('selectMachine', $machine->id)
            ->set('locationId', $newLocation->id)
            ->set('hours', '480')
            ->call('save')
            ->assertHasErrors(['hours'])
            ->assertSet('submitted', false);

        $machine->refresh();
        $this->assertNotSame($newLocation->id, $machine->current_location_id);
        $this->assertDatabaseMissing('horometer_readings', ['machine_id' => $machine->id]);
    }

    public function test_a_normal_higher_reading_is_still_accepted_in_the_field(): void
    {
        $worker = User::where('email', 'campo@dp.local')->firstOrFail();
        $machine = $this->machine();

        Livewire::actingAs($worker)
            ->test(ReportForm::class)
            ->call('selectMachine', $machine->id)
            ->set('condition', 'ok')
            ->set('hours', '520')
            ->call('save')
            ->assertHasNoErrors(['hours'])
            ->assertSet('submitted', true);

        $this->assertSame(520, $machine->refresh()->current_hours);
    }
}

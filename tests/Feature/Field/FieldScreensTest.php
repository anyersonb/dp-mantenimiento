<?php

namespace Tests\Feature\Field;

use App\Livewire\Field\ForemanBoard;
use App\Livewire\Field\FuelLog;
use App\Livewire\Field\ReportForm;
use App\Models\HorometerReading;
use App\Models\Location;
use App\Models\Machine;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class FieldScreensTest extends TestCase
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
            'current_hours' => 100,
        ]);
    }

    public function test_guests_are_redirected_to_login_instead_of_erroring(): void
    {
        // Regresión: el middleware "auth" genérico necesita una ruta nombrada "login".
        $this->get('/field')->assertRedirect(route('login'));
        $this->get('/field/fuel')->assertRedirect(route('login'));
        $this->get('/field/report')->assertRedirect(route('login'));
        $this->get('/field/foreman')->assertRedirect(route('login'));
    }

    public function test_login_page_redirects_a_panel_user_to_the_admin_panel(): void
    {
        $admin = User::where('email', 'admin@dp.local')->firstOrFail();

        $response = $this->actingAs($admin)->get('/field/login');

        $response->assertRedirect('/admin');
    }

    public function test_login_page_redirects_a_field_user_to_field_home(): void
    {
        $foreman = User::where('email', 'foreman@dp.local')->firstOrFail();

        $response = $this->actingAs($foreman)->get('/field/login');

        $response->assertRedirect(route('field.home'));
    }

    public function test_fuel_log_is_denied_for_a_role_other_than_operador_cisterna(): void
    {
        $foreman = User::where('email', 'foreman@dp.local')->firstOrFail();

        $this->actingAs($foreman)->get('/field/fuel')->assertForbidden();
    }

    public function test_fuel_log_creates_a_horometer_reading_with_gallons_and_geolocation(): void
    {
        $operator = User::where('email', 'combustible@dp.local')->firstOrFail();
        $machine = $this->machine();

        Livewire::actingAs($operator)
            ->test(FuelLog::class)
            ->call('selectMachine', $machine->id)
            ->set('gallons', '35.5')
            ->set('hours', '150')
            ->call('setLocation', 26.123456, -80.123456)
            ->call('save')
            ->assertSet('submitted', true);

        $this->assertDatabaseHas('horometer_readings', [
            'machine_id' => $machine->id,
            'source' => 'fuel',
            'hours' => 150,
            'recorded_by' => $operator->id,
        ]);

        $reading = HorometerReading::where('machine_id', $machine->id)->firstOrFail();
        $this->assertEqualsWithDelta(35.5, (float) $reading->gallons, 0.01);
        $this->assertNotNull($reading->latitude);
    }

    public function test_fuel_log_requires_machine_gallons_and_hours(): void
    {
        $operator = User::where('email', 'combustible@dp.local')->firstOrFail();

        Livewire::actingAs($operator)
            ->test(FuelLog::class)
            ->call('save')
            ->assertHasErrors(['machineId', 'gallons', 'hours']);
    }

    public function test_report_form_creates_a_field_report_and_optional_reading(): void
    {
        $worker = User::where('email', 'campo@dp.local')->firstOrFail();
        $machine = $this->machine();

        Livewire::actingAs($worker)
            ->test(ReportForm::class)
            ->call('selectMachine', $machine->id)
            ->set('condition', 'critical')
            ->set('hours', '160')
            ->set('notes', 'Leaking hydraulic hose')
            ->call('save')
            ->assertSet('submitted', true);

        $this->assertDatabaseHas('field_reports', [
            'machine_id' => $machine->id,
            'reported_by' => $worker->id,
            'condition' => 'critical',
        ]);

        $this->assertDatabaseHas('horometer_readings', [
            'machine_id' => $machine->id,
            'source' => 'maintenance',
            'hours' => 160,
        ]);
    }

    public function test_foreman_board_updates_the_machine_location(): void
    {
        $foreman = User::where('email', 'foreman@dp.local')->firstOrFail();
        $machine = $this->machine();
        $newLocation = Location::create(['name' => 'New Job Site', 'slug' => 'new-job-site-'.uniqid()]);

        Livewire::actingAs($foreman)
            ->test(ForemanBoard::class)
            ->call('selectMachine', $machine->id)
            ->set('locationId', $newLocation->id)
            ->call('save')
            ->assertSet('submitted', true);

        $machine->refresh();
        $this->assertSame($newLocation->id, $machine->current_location_id);
    }
}

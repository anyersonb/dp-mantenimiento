<?php

namespace Tests\Feature\Field;

use App\Livewire\Field\ForemanBoard;
use App\Livewire\Field\FuelLog;
use App\Livewire\Field\ReportForm;
use App\Models\FieldReport;
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

    /**
     * Mismo tratamiento que ReportForm (cabo suelto del lote anterior): antes
     * de enviar sin ubicación el operario tiene que verlo escrito, no
     * adivinarlo. El aviso desaparece apenas la ubicación queda capturada.
     */
    public function test_fuel_log_warns_before_sending_without_a_captured_location(): void
    {
        $operator = User::where('email', 'combustible@dp.local')->firstOrFail();
        $machine = $this->machine();

        Livewire::actingAs($operator)
            ->test(FuelLog::class)
            ->call('selectMachine', $machine->id)
            ->assertSee(__('field.fuel_will_submit_without_location'))
            ->call('setLocation', 26.1, -80.1)
            ->assertDontSee(__('field.fuel_will_submit_without_location'));
    }

    /**
     * La ubicación NO es obligatoria (obra sin señal), pero el aviso de éxito
     * tiene que distinguir un registro completo de uno guardado sin ella, en
     * vez de mostrar el mismo "✅ Registrado ✓" para los dos casos.
     */
    public function test_fuel_log_shows_a_clean_success_notice_when_location_was_captured(): void
    {
        $operator = User::where('email', 'combustible@dp.local')->firstOrFail();
        $machine = $this->machine();

        Livewire::actingAs($operator)
            ->test(FuelLog::class)
            ->call('selectMachine', $machine->id)
            ->set('gallons', '20')
            ->set('hours', '120')
            ->call('setLocation', 26.123456, -80.123456)
            ->call('save')
            ->assertSet('submitted', true)
            ->assertSet('submittedWithoutLocation', false)
            ->assertSee(__('field.fuel_success'))
            ->assertDontSee(__('field.fuel_success_no_location'));

        $reading = HorometerReading::where('machine_id', $machine->id)->firstOrFail();
        $this->assertNotNull($reading->latitude);
    }

    public function test_fuel_log_warns_instead_of_celebrating_when_no_location_was_captured(): void
    {
        $operator = User::where('email', 'combustible@dp.local')->firstOrFail();
        $machine = $this->machine();

        Livewire::actingAs($operator)
            ->test(FuelLog::class)
            ->call('selectMachine', $machine->id)
            ->set('gallons', '20')
            ->set('hours', '120')
            // Nunca llega a setLocation(): sin señal, permiso denegado, o el
            // operario envía antes de que la geolocalización responda.
            ->call('save')
            ->assertSet('submitted', true)
            ->assertSet('submittedWithoutLocation', true)
            ->assertSee(__('field.fuel_success_no_location'))
            ->assertDontSee(__('field.fuel_success'));

        $reading = HorometerReading::where('machine_id', $machine->id)->firstOrFail();
        $this->assertNull($reading->latitude);
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

    /**
     * Antes de enviar sin ubicación el operario tiene que verlo escrito, no
     * adivinarlo. El aviso desaparece apenas la ubicación queda capturada.
     */
    public function test_report_form_warns_before_sending_without_a_captured_location(): void
    {
        $worker = User::where('email', 'campo@dp.local')->firstOrFail();
        $machine = $this->machine();

        Livewire::actingAs($worker)
            ->test(ReportForm::class)
            ->call('selectMachine', $machine->id)
            ->assertSee(__('field.report_will_submit_without_location'))
            ->call('setLocation', 26.1, -80.1)
            ->assertDontSee(__('field.report_will_submit_without_location'));
    }

    /**
     * DEFECTO 3: un reporte guardado sin coordenadas mostraba el mismo
     * "✅ Reporte enviado ✓" que uno completo. La decisión del negocio es que
     * la ubicación NO es obligatoria (un operario sin señal debe poder
     * reportar igual), pero el aviso de éxito tiene que distinguir los dos
     * casos en vez de fingir que salió todo perfecto.
     */
    public function test_report_form_shows_a_clean_success_notice_when_location_was_captured(): void
    {
        $worker = User::where('email', 'campo@dp.local')->firstOrFail();
        $machine = $this->machine();

        Livewire::actingAs($worker)
            ->test(ReportForm::class)
            ->call('selectMachine', $machine->id)
            ->set('condition', 'ok')
            ->call('setLocation', 26.123456, -80.123456)
            ->call('save')
            ->assertSet('submitted', true)
            ->assertSet('submittedWithoutLocation', false)
            ->assertSee(__('field.report_success'))
            ->assertDontSee(__('field.report_success_no_location'));

        $report = FieldReport::where('machine_id', $machine->id)->firstOrFail();
        $this->assertNotNull($report->latitude);
        $this->assertNotNull($report->longitude);
    }

    public function test_report_form_warns_instead_of_celebrating_when_no_location_was_captured(): void
    {
        $worker = User::where('email', 'campo@dp.local')->firstOrFail();
        $machine = $this->machine();

        Livewire::actingAs($worker)
            ->test(ReportForm::class)
            ->call('selectMachine', $machine->id)
            ->set('condition', 'attention')
            // Nunca llega a setLocation(): navegador sin GPS, permiso denegado,
            // o el operario envía antes de que la geolocalización responda.
            ->call('save')
            ->assertSet('submitted', true)
            ->assertSet('submittedWithoutLocation', true)
            ->assertSee(__('field.report_success_no_location'))
            ->assertDontSee(__('field.report_success'));

        $report = FieldReport::where('machine_id', $machine->id)->firstOrFail();
        $this->assertNull($report->latitude);
        $this->assertNull($report->longitude);
    }

    /**
     * Hallazgo C2 (Etapa 05): este test antes usaba una obra NUEVA y
     * afirmaba que foreman podía reasignar la máquina — eso era exactamente
     * el bug (foreman ejerciendo move_fleet sin tenerlo). Corregido: foreman
     * solo tiene confirm_location, así que aquí ratifica la obra que la
     * máquina ya tiene. La cobertura del bug (foreman no puede moverla, y
     * quien tiene move_fleet sí) vive en FieldPermissionAuthorizationTest.
     */
    public function test_foreman_board_confirms_the_current_location_without_moving_it(): void
    {
        $foreman = User::where('email', 'foreman@dp.local')->firstOrFail();
        $machine = $this->machine();
        $originalLocationId = $machine->current_location_id;

        Livewire::actingAs($foreman)
            ->test(ForemanBoard::class)
            ->call('selectMachine', $machine->id)
            ->call('save')
            ->assertSet('submitted', true);

        $machine->refresh();
        $this->assertSame($originalLocationId, $machine->current_location_id);

        $this->assertDatabaseHas('activity_log', [
            'subject_type' => Machine::class,
            'subject_id' => $machine->id,
            'event' => 'location_confirmed',
        ]);
    }
}

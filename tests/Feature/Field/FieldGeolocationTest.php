<?php

namespace Tests\Feature\Field;

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
 * DEFECTO 2: el callback de error de getCurrentPosition() era `() => {}`.
 * PERMISSION_DENIED, POSITION_UNAVAILABLE y TIMEOUT se descartaban en
 * silencio y "Obteniendo tu ubicación…" se quedaba puesto para siempre
 * (reproducido en escritorio con permiso CONCEDIDO: el timeout corto
 * expiraba sin avisar nada). El fix vive en el componente Livewire
 * (setLocationError/retryLocation), compartido por report-form y fuel-log
 * a través del mismo parcial JS — por eso este test cubre ambos.
 */
class FieldGeolocationTest extends TestCase
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

    public function test_report_form_shows_the_reason_in_plain_language_when_permission_is_denied(): void
    {
        $worker = User::where('email', 'campo@dp.local')->firstOrFail();

        Livewire::actingAs($worker)
            ->test(ReportForm::class)
            ->call('setLocationError', 'denied')
            ->assertSet('locationError', 'denied')
            ->assertSet('locationCaptured', false)
            ->assertSee(__('field.geolocation_error_denied'))
            // Nada de jerga técnica en pantalla.
            ->assertDontSee('POSITION_UNAVAILABLE')
            ->assertDontSee('PERMISSION_DENIED')
            ->assertSee(__('field.geolocation_retry'));
    }

    public function test_report_form_distinguishes_unavailable_from_denied(): void
    {
        $worker = User::where('email', 'campo@dp.local')->firstOrFail();

        Livewire::actingAs($worker)
            ->test(ReportForm::class)
            ->call('setLocationError', 'unavailable')
            ->assertSee(__('field.geolocation_error_unavailable'))
            ->assertDontSee(__('field.geolocation_error_denied'));
    }

    public function test_report_form_reports_the_unsupported_browser_case_instead_of_staying_silent(): void
    {
        $worker = User::where('email', 'campo@dp.local')->firstOrFail();

        Livewire::actingAs($worker)
            ->test(ReportForm::class)
            ->call('setLocationError', 'unsupported')
            ->assertSee(__('field.geolocation_error_unsupported'));
    }

    /**
     * El botón Reintentar tiene que devolver la pantalla a "obteniendo" y
     * pedirle al JS que llame getCurrentPosition() otra vez — y volver a
     * funcionar una segunda vez, no solo la primera.
     */
    public function test_report_form_retry_resets_the_error_and_can_be_used_more_than_once(): void
    {
        $worker = User::where('email', 'campo@dp.local')->firstOrFail();

        $component = Livewire::actingAs($worker)
            ->test(ReportForm::class)
            ->call('setLocationError', 'unavailable')
            ->assertSet('locationError', 'unavailable')
            ->call('retryLocation')
            ->assertSet('locationError', null)
            ->assertSet('locationCaptured', false)
            ->assertSee(__('field.geolocation_capturing'))
            ->assertDispatched('geolocation-retry');

        // Falla otra vez y se reintenta otra vez: no es un botón de un solo uso.
        $component->call('setLocationError', 'denied')
            ->assertSet('locationError', 'denied')
            ->call('retryLocation')
            ->assertSet('locationError', null)
            ->assertDispatched('geolocation-retry');
    }

    public function test_fuel_log_shows_the_reason_in_plain_language_when_permission_is_denied(): void
    {
        $operator = User::where('email', 'combustible@dp.local')->firstOrFail();

        Livewire::actingAs($operator)
            ->test(FuelLog::class)
            ->call('setLocationError', 'denied')
            ->assertSet('locationError', 'denied')
            ->assertSee(__('field.geolocation_error_denied'))
            ->assertDontSee('POSITION_UNAVAILABLE');
    }

    public function test_fuel_log_retry_resets_the_error_and_can_be_used_more_than_once(): void
    {
        $operator = User::where('email', 'combustible@dp.local')->firstOrFail();

        Livewire::actingAs($operator)
            ->test(FuelLog::class)
            ->call('setLocationError', 'unsupported')
            ->assertSet('locationError', 'unsupported')
            ->call('retryLocation')
            ->assertSet('locationError', null)
            ->assertSet('locationCaptured', false)
            ->assertDispatched('geolocation-retry')
            ->call('setLocationError', 'unavailable')
            ->assertSet('locationError', 'unavailable')
            ->call('retryLocation')
            ->assertSet('locationError', null)
            ->assertDispatched('geolocation-retry');
    }

    public function test_a_successful_capture_clears_a_previous_error(): void
    {
        $worker = User::where('email', 'campo@dp.local')->firstOrFail();
        $machine = $this->machine();

        Livewire::actingAs($worker)
            ->test(ReportForm::class)
            ->call('selectMachine', $machine->id)
            ->call('setLocationError', 'unavailable')
            ->assertSet('locationError', 'unavailable')
            ->call('setLocation', 26.1, -80.1)
            ->assertSet('locationError', null)
            ->assertSet('locationCaptured', true)
            ->assertSee(__('field.geolocation_ok'));
    }
}

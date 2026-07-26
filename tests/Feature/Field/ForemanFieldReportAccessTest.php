<?php

namespace Tests\Feature\Field;

use App\Livewire\Field\ReportForm;
use App\Models\Location;
use App\Models\Machine;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Etapa 05, Bloque 3 — hallazgo C3.
 *
 * `ReportForm::mount()` exigía `hasRole('personal_mantenimiento')`, así que
 * foreman —con `field_report` asignado en la matriz de permisos— recibía un
 * 403 en `/field/report`: un permiso otorgado pero inalcanzable. El fix
 * (parte del hallazgo A1, ver `fix(A1)`) cambió el gate a
 * `can('field_report')`. Este test prueba la promesa completa: no solo que
 * no haya 403, sino que foreman de verdad pueda guardar un reporte de campo.
 */
class ForemanFieldReportAccessTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);
    }

    public function test_foreman_can_access_and_submit_a_field_report(): void
    {
        $foreman = User::where('email', 'foreman@dp.local')->firstOrFail();
        $location = Location::create(['name' => 'Origin Yard', 'slug' => 'origin-yard-'.uniqid()]);
        $machine = Machine::create([
            'id_code' => 'FR-'.random_int(1000, 9999),
            'status' => 'active',
            'current_location_id' => $location->id,
            'current_hours' => 100,
        ]);

        $this->actingAs($foreman)->get('/field/report')->assertOk();

        Livewire::actingAs($foreman)
            ->test(ReportForm::class)
            ->call('selectMachine', $machine->id)
            ->set('condition', 'attention')
            ->set('notes', 'Ruido en el motor')
            ->call('save')
            ->assertSet('submitted', true);

        $this->assertDatabaseHas('field_reports', [
            'machine_id' => $machine->id,
            'reported_by' => $foreman->id,
            'condition' => 'attention',
        ]);
    }
}

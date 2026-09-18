<?php

namespace Tests\Feature\Field;

use App\Livewire\Field\ReportForm;
use App\Models\FieldReport;
use App\Models\Location;
use App\Models\Machine;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Hallazgo 6 (auditoría 2026-09-18, BAJO): `machineLocationId` era una
 * propiedad pública de Livewire, ausente de `rules()`, que se guardaba tal
 * cual como `location_id`. Un operario con la consola del navegador podía
 * `$wire.set('machineLocationId', <otro id>)` y registrar que la máquina
 * estaba en una obra distinta de la real — falseo de un dato de
 * trazabilidad. El fix ignora lo que manda el cliente: `save()` deriva
 * `location_id` en el servidor desde `Machine::find($machineId)
 * ->current_location_id`.
 */
class ReportFormLocationIntegrityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);
    }

    private function machineAt(string $locationName): Machine
    {
        $location = Location::create(['name' => $locationName, 'slug' => Str::slug($locationName).'-'.uniqid()]);

        return Machine::create([
            'id_code' => 'LOC-'.random_int(1000, 9999),
            'status' => 'active',
            'current_location_id' => $location->id,
        ]);
    }

    public function test_the_saved_location_id_always_matches_the_machines_real_location(): void
    {
        $worker = User::where('email', 'campo@dp.local')->firstOrFail();
        $realMachine = $this->machineAt('Real Yard');
        $otherMachine = $this->machineAt('Other Yard');

        $component = Livewire::actingAs($worker)
            ->test(ReportForm::class)
            ->call('selectMachine', $realMachine->id);

        // Tras seleccionar la máquina real, se pisa la propiedad pública tal
        // como podría hacerlo la consola del navegador: apunta a la obra de
        // OTRA máquina.
        $component->set('machineLocationId', $otherMachine->current_location_id)
            ->set('condition', 'ok')
            ->call('save')
            ->assertSet('submitted', true);

        $report = FieldReport::where('machine_id', $realMachine->id)->firstOrFail();

        $this->assertSame(
            $realMachine->current_location_id,
            $report->location_id,
            'location_id debe salir de la máquina real, no de lo que mandó el cliente.'
        );
        $this->assertNotSame($otherMachine->current_location_id, $report->location_id);
    }

    public function test_the_saved_location_id_matches_without_any_client_tampering(): void
    {
        $worker = User::where('email', 'campo@dp.local')->firstOrFail();
        $machine = $this->machineAt('Normal Yard');

        Livewire::actingAs($worker)
            ->test(ReportForm::class)
            ->call('selectMachine', $machine->id)
            ->set('condition', 'attention')
            ->call('save')
            ->assertSet('submitted', true);

        $report = FieldReport::where('machine_id', $machine->id)->firstOrFail();

        $this->assertSame($machine->current_location_id, $report->location_id);
    }
}

<?php

namespace Tests\Feature\Management;

use App\Models\HorometerReading;
use App\Models\Location;
use App\Models\Machine;
use App\Services\HourmeterReplacementService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Hallazgo E6-13, séptimo camino: el reemplazo de horómetro no fijaba la
 * frontera de escala.
 *
 * Lo encontró el `HorometerWritePathSentinelTest`, y era un hueco propio: la
 * columna `hours_scale_since` se agregó con E6-01..04 exactamente para esto y
 * ningún escritor la llenaba. Sin ella, las lecturas de la escala vieja siguen
 * en el historial sin marca, y la primera lectura de la escala nueva dispara el
 * recálculo completo, que se queda con la lectura más alta —la vieja— y devuelve
 * la máquina a la escala anterior.
 *
 * No es hipotético: MS-TEMP-01 está en `replaced` en la base real, y RL016
 * aparece en el reporte del cliente con 26 h y 3564 h, que es el mismo caso.
 */
class ReplacedHourmeterKeepsItsScaleTest extends TestCase
{
    use RefreshDatabase;

    private function machine(): Machine
    {
        $location = Location::create(['name' => 'Yard', 'slug' => 'yard-'.uniqid()]);

        return Machine::create([
            'id_code' => 'SCALE-'.random_int(100, 999),
            'status' => 'active',
            'hourmeter_status' => 'ok',
            'current_location_id' => $location->id,
            'current_hours' => 180,
            'current_hours_date' => '2026-06-01',
            'last_service_hours' => 0,
            'service_interval_hours' => 500,
            'hours_adjustment' => 0,
        ]);
    }

    public function test_the_replacement_writes_the_scale_boundary(): void
    {
        $machine = $this->machine();

        app(HourmeterReplacementService::class)->replace($machine, 180, 20, null, null);

        $machine->refresh();

        $this->assertNotNull($machine->hours_scale_since, 'el reemplazo tiene que dejar la frontera de escala');
        $this->assertSame(now()->toDateString(), $machine->hours_scale_since->toDateString());
        $this->assertSame(20, $machine->current_hours);
    }

    public function test_a_new_reading_does_not_drag_the_machine_back_to_the_old_scale(): void
    {
        $machine = $this->machine();

        // Lectura de la escala VIEJA, que queda en el historial.
        HorometerReading::create([
            'machine_id' => $machine->id,
            'hours' => 180,
            'read_at' => '2026-06-01',
            'source' => 'import',
        ]);

        app(HourmeterReplacementService::class)->replace($machine, 180, 20, null, null);

        // Primera lectura de la escala nueva: dispara el recálculo completo.
        HorometerReading::create([
            'machine_id' => $machine->id,
            'hours' => 25,
            'read_at' => now()->toDateString(),
            'source' => 'manual',
        ]);

        $machine->refresh();

        $this->assertSame(25, $machine->current_hours, 'la lectura de 180 h es de otra escala y no puede ganar');
        $this->assertNotSame(180, $machine->current_hours);

        // Y el ciclo de servicio sigue contado en la escala nueva.
        $this->assertSame(495, $machine->remaining_hours, '500 - (25 - 20)');
    }

    public function test_the_repair_command_respects_the_boundary_too(): void
    {
        $machine = $this->machine();

        HorometerReading::create([
            'machine_id' => $machine->id, 'hours' => 180, 'read_at' => '2026-06-01', 'source' => 'import',
        ]);

        app(HourmeterReplacementService::class)->replace($machine, 180, 20, null, null);

        HorometerReading::create([
            'machine_id' => $machine->id, 'hours' => 25, 'read_at' => now()->toDateString(), 'source' => 'manual',
        ]);

        $this->artisan('machines:recalculate-hours --apply')->assertExitCode(0);

        $this->assertSame(25, $machine->refresh()->current_hours);
    }
}

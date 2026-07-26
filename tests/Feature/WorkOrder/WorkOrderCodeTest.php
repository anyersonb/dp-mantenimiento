<?php

namespace Tests\Feature\WorkOrder;

use App\Filament\Resources\AlertResource\Pages\ListAlerts;
use App\Models\Alert;
use App\Models\Location;
use App\Models\Machine;
use App\Models\User;
use App\Models\WorkOrder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Hallazgo E6-09.
 *
 * El código de OT salía de `'WO-'.str_pad(max('id') + 1, 4, '0')`. Medido en la
 * Sesión 1: con `max(id)=14` la propuesta fue `WO-0015` y la fila quedó con
 * **id 16**. Dos defectos en una línea:
 *
 *   - el número **no identifica** la fila (no es su id);
 *   - **no está garantizado libre**: alcanza un código tecleado a mano con el
 *     mismo patrón —el patrón del cliente— para que choque, y al chocar caía en
 *     E6-07: un 500 sin ningún mensaje.
 */
class WorkOrderCodeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);
    }

    private function machine(array $overrides = []): Machine
    {
        $location = Location::create(['name' => 'Patio', 'slug' => 'patio-'.uniqid()]);

        return Machine::create(array_merge([
            'id_code' => 'COD-'.random_int(1000, 9999),
            'status' => 'active',
            'hourmeter_status' => 'ok',
            'current_location_id' => $location->id,
            'current_hours' => 1000,
            'last_service_hours' => 900,
            'service_interval_hours' => 500,
        ], $overrides));
    }

    private function workOrder(Machine $machine, string $code): WorkOrder
    {
        return WorkOrder::create([
            'code' => $code,
            'machine_id' => $machine->id,
            'type' => 'preventive',
            'status' => 'open',
            'priority' => 'normal',
            'opened_at' => now()->toDateString(),
        ]);
    }

    public function test_the_next_code_follows_the_codes_and_not_the_ids(): void
    {
        $machine = $this->machine();

        // Códigos altos con ids bajos: es la situación real de la base, donde
        // los ids saltaron por filas de prueba borradas.
        $this->workOrder($machine, 'WO-0020');
        $this->workOrder($machine, 'WO-0021');

        $this->assertSame('WO-0022', WorkOrder::nextCode());
    }

    public function test_hand_typed_codes_outside_the_pattern_do_not_move_the_numbering(): void
    {
        $machine = $this->machine();

        $this->workOrder($machine, 'WO-0007');
        $this->workOrder($machine, 'QA-OT-01');
        $this->workOrder($machine, 'OT-DEL-CLIENTE');

        $this->assertSame('WO-0008', WorkOrder::nextCode());
    }

    /**
     * El corazón del hallazgo: el código propuesto tiene que estar libre aunque
     * alguien ya haya usado a mano el que "tocaba".
     */
    public function test_the_proposed_code_is_always_free(): void
    {
        $machine = $this->machine();

        $this->workOrder($machine, 'WO-0001');
        // Alguien tecleó a mano el siguiente de la serie.
        $this->workOrder($machine, 'WO-0002');
        // Y también el siguiente.
        $this->workOrder($machine, 'WO-0003');

        $propuesto = WorkOrder::nextCode();

        $this->assertSame('WO-0004', $propuesto);
        $this->assertDatabaseMissing('work_orders', ['code' => $propuesto]);
    }

    public function test_the_first_code_of_an_empty_system(): void
    {
        $this->assertSame('WO-0001', WorkOrder::nextCode());
    }

    /**
     * El camino sin formulario: la acción "Crear OT" de una alerta no pasa por
     * ninguna validación, así que su código tiene que salir libre igual.
     */
    public function test_the_alert_action_creates_the_work_order_with_a_free_code(): void
    {
        $machine = $this->machine(['current_hours' => 1450]);
        $this->workOrder($machine, 'WO-0001');

        $alerta = Alert::create([
            'machine_id' => $machine->id,
            'type' => 'service',
            'title' => 'QA',
            'message' => 'QA',
            'remaining_hours' => 50,
            'status' => 'open',
        ]);

        Livewire::actingAs(User::where('email', 'admin@dp.local')->firstOrFail())
            ->test(ListAlerts::class)
            ->callTableAction('create_work_order', $alerta);

        $nueva = WorkOrder::where('machine_id', $machine->id)->where('code', '!=', 'WO-0001')->firstOrFail();

        $this->assertSame('WO-0002', $nueva->code);
        $this->assertSame('open', $nueva->status);
        // Y sigue sellando lo que ya sellaba antes (E6-08).
        $this->assertSame(1450, (int) $nueva->hours_at_open);
        $this->assertNotNull($nueva->opened_by);
    }
}

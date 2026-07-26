<?php

namespace Tests\Feature\Security;

use App\Filament\Resources\MachineResource;
use App\Filament\Resources\MachineResource\Pages\EditMachine;
use App\Filament\Resources\MachineResource\Pages\ListMachines;
use App\Models\Alert;
use App\Models\HorometerReading;
use App\Models\Location;
use App\Models\Machine;
use App\Models\User;
use App\Models\WorkOrder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;

/**
 * Hallazgo E6-05 (crítico).
 *
 * El responsable podía borrar una máquina desde el panel y el borrado se
 * llevaba en cascada sus órdenes de trabajo, lecturas, alertas, partes y —con
 * las OT— los costos históricos. Todas las FK que apuntan a `machines` son
 * ON DELETE CASCADE. El diálogo preguntaba únicamente "Are you sure you would
 * like to do this?".
 *
 * Corrige además un reporte anterior equivocado: se había informado que las
 * máquinas NO se podían borrar desde el panel, y que eso bloqueaba la tarea
 * pendiente del cliente de descartar las máquinas del Info Book. Era falso, y
 * se comprobó ejecutándolo.
 */
class MachineDeletionIsGovernedTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);
    }

    private function machineWithHistory(array $overrides = []): Machine
    {
        $location = Location::create(['name' => 'Del Yard', 'slug' => 'del-yard-'.uniqid()]);

        $machine = Machine::create(array_merge([
            'id_code' => 'DEL-'.random_int(1000, 9999),
            'status' => 'active',
            'hourmeter_status' => 'ok',
            'current_location_id' => $location->id,
            'current_hours' => 500,
            'last_service_hours' => 100,
            'service_interval_hours' => 500,
            'hours_adjustment' => 0,
            'remaining_hours' => 100,
        ], $overrides));

        WorkOrder::create([
            'code' => 'WO-'.random_int(1000, 9999),
            'machine_id' => $machine->id,
            'type' => 'preventive',
            'status' => 'completed',
            'priority' => 'normal',
            'hours_at_open' => 500,
            'opened_at' => now()->toDateString(),
            'parts_cost' => 1234.56,
        ]);

        HorometerReading::create([
            'machine_id' => $machine->id,
            'hours' => 500,
            'read_at' => now()->toDateString(),
            'source' => 'manual',
        ]);

        Alert::create([
            'machine_id' => $machine->id,
            'type' => 'service',
            'title' => 'QA',
            'message' => 'QA',
            'remaining_hours' => 100,
            'status' => 'open',
        ]);

        return $machine->refresh();
    }

    private function user(string $email): User
    {
        return User::where('email', $email)->firstOrFail();
    }

    public function test_only_the_administrator_passes_the_delete_gate(): void
    {
        $machine = $this->machineWithHistory();

        $this->actingAs($this->user('responsable@dp.local'));
        $this->assertFalse(MachineResource::canDelete($machine), 'El responsable no debe poder borrar máquinas.');
        $this->assertFalse(MachineResource::canDeleteAny());

        $this->actingAs($this->user('taller@dp.local'));
        $this->assertFalse(MachineResource::canDelete($machine));

        $this->actingAs($this->user('admin@dp.local'));
        $this->assertTrue(MachineResource::canDelete($machine), 'El administrador sí debe poder.');
        $this->assertTrue(MachineResource::canDeleteAny());
    }

    public function test_the_delete_action_is_hidden_for_the_responsible_on_the_edit_page(): void
    {
        $machine = $this->machineWithHistory();

        Livewire::actingAs($this->user('responsable@dp.local'))
            ->test(EditMachine::class, ['record' => $machine->getKey()])
            ->assertActionHidden('delete');
    }

    /**
     * El corazón del hallazgo: aunque el administrador borre, el historial
     * sobrevive, porque el borrado es suave y la cascada de la base no se
     * dispara sin un DELETE.
     */
    public function test_deleting_a_machine_no_longer_destroys_its_history(): void
    {
        $machine = $this->machineWithHistory();

        Livewire::actingAs($this->user('admin@dp.local'))
            ->test(EditMachine::class, ['record' => $machine->getKey()])
            ->callAction('delete');

        $this->assertSoftDeleted('machines', ['id' => $machine->id]);

        $this->assertDatabaseHas('work_orders', ['machine_id' => $machine->id, 'parts_cost' => 1234.56]);
        $this->assertDatabaseHas('horometer_readings', ['machine_id' => $machine->id, 'hours' => 500]);
        $this->assertDatabaseHas('alerts', ['machine_id' => $machine->id]);
    }

    public function test_the_delete_dialog_enumerates_what_would_be_destroyed_with_real_counts(): void
    {
        $machine = $this->machineWithHistory();

        $aviso = MachineResource::deletionWarning($machine);

        $this->assertStringContainsString($machine->id_code, $aviso);
        $this->assertStringContainsString('1', $aviso, 'Tiene que traer los conteos reales, no un texto genérico.');

        $resumen = $machine->destructionSummary();
        $this->assertSame(1, $resumen['work_orders']);
        $this->assertSame(1, $resumen['readings']);
        // >= 1 y no == 1: la lectura de 500 h deja remaining_hours en el umbral
        // de 100, así que el motor de alertas levanta una automática además de
        // la que crea el montaje. Es comportamiento correcto del sistema.
        $this->assertGreaterThanOrEqual(1, $resumen['alerts']);
    }

    /* ------------------------------------------------------------------ *
     * Descartar: el camino real de baja para las máquinas en revisión.
     * ------------------------------------------------------------------ */

    public function test_discarding_a_machine_under_review_keeps_its_history_and_leaves_a_trace(): void
    {
        $machine = $this->machineWithHistory(['needs_review' => true]);
        $admin = $this->user('admin@dp.local');

        Livewire::actingAs($admin)
            ->test(ListMachines::class)
            ->callTableAction('discard', $machine, data: ['reason' => 'No existe en la flota real (Info Book).']);

        $machine->refresh();

        $this->assertSame('inactive', $machine->status);
        $this->assertFalse((bool) $machine->needs_review);
        $this->assertNull($machine->deleted_at, 'Descartar NO borra.');
        $this->assertDatabaseHas('work_orders', ['machine_id' => $machine->id]);

        $asiento = Activity::where('subject_type', Machine::class)
            ->where('subject_id', $machine->id)
            ->where('event', 'discarded')
            ->first();

        $this->assertNotNull($asiento, 'Descartar tiene que quedar en la bitácora.');
        $this->assertSame($admin->id, $asiento->causer_id);
        $this->assertStringContainsString('Info Book', $asiento->properties['reason']);
    }

    public function test_a_role_without_verify_data_cannot_discard(): void
    {
        $machine = $this->machineWithHistory(['needs_review' => true]);

        Livewire::actingAs($this->user('responsable@dp.local'))
            ->test(ListMachines::class)
            ->assertTableActionHidden('discard', $machine);
    }

    public function test_the_discard_action_does_not_appear_on_a_machine_that_is_not_under_review(): void
    {
        $machine = $this->machineWithHistory(['needs_review' => false]);

        Livewire::actingAs($this->user('admin@dp.local'))
            ->test(ListMachines::class)
            ->assertTableActionHidden('discard', $machine);
    }
}

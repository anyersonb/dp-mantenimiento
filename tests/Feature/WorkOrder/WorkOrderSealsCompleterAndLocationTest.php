<?php

namespace Tests\Feature\WorkOrder;

use App\Models\Location;
use App\Models\Machine;
use App\Models\User;
use App\Models\WorkOrder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Las dos columnas que el reporte de costos necesitaba y la OT no guardaba
 * (2026-08-05): quién cerró el trabajo y en qué obra se hizo.
 *
 * Sigue la lección de E6-08/A8/C3: el sello vive en el observer, así que se
 * prueba por **cada camino** que completa una OT, no solo por el de la
 * pantalla. Van cinco hallazgos en este proyecto con la misma forma —regla
 * puesta en un camino, otros caminos sin ella— y esa es la razón de que este
 * archivo pruebe lo mismo tres veces.
 */
class WorkOrderSealsCompleterAndLocationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);
    }

    private function location(string $name): Location
    {
        return Location::create(['name' => $name, 'slug' => str($name)->slug().'-'.uniqid()]);
    }

    private function machine(?Location $location = null): Machine
    {
        return Machine::create([
            'id_code' => 'SEAL2-'.random_int(1000, 9999),
            'status' => 'active',
            'hourmeter_status' => 'ok',
            'current_location_id' => $location?->id,
            'current_hours' => 900,
            'last_service_hours' => 500,
            'service_interval_hours' => 500,
            'hours_adjustment' => 0,
        ]);
    }

    private function openWorkOrder(Machine $machine, array $overrides = []): WorkOrder
    {
        return WorkOrder::create(array_merge([
            'code' => 'WO-S'.random_int(10000, 99999),
            'machine_id' => $machine->id,
            'type' => 'corrective',
            'status' => 'open',
            'opened_at' => '2026-08-05',
        ], $overrides));
    }

    /* ------------------------------------------------------------------ *
     * La obra, sellada al abrir.
     * ------------------------------------------------------------------ */

    public function test_the_job_site_is_stamped_from_the_machine_when_the_work_order_is_created(): void
    {
        $site = $this->location('Blount Rd');
        $machine = $this->machine($site);

        $wo = $this->openWorkOrder($machine);

        $this->assertSame($site->id, $wo->location_id);
    }

    public function test_moving_the_machine_afterwards_does_not_rewrite_the_history(): void
    {
        $original = $this->location('Broadview yd');
        $other = $this->location('WPB Yd');
        $machine = $this->machine($original);

        $wo = $this->openWorkOrder($machine);

        // Mover la flota entre obras es una función del sistema (move_fleet). El
        // servicio se hizo donde se hizo.
        $machine->update(['current_location_id' => $other->id]);

        $this->assertSame($original->id, $wo->fresh()->location_id);
        $this->assertSame('Broadview yd', $wo->fresh()->location->name);
    }

    public function test_an_explicit_job_site_wins_over_the_machine_one(): void
    {
        $machineSite = $this->location('Broadview yd');
        $realSite = $this->location('Blount Rd');
        $machine = $this->machine($machineSite);

        $wo = $this->openWorkOrder($machine, ['location_id' => $realSite->id]);

        $this->assertSame($realSite->id, $wo->location_id);
    }

    public function test_a_machine_without_a_job_site_leaves_the_column_empty_instead_of_guessing(): void
    {
        $wo = $this->openWorkOrder($this->machine(null));

        $this->assertNull($wo->location_id);
    }

    /* ------------------------------------------------------------------ *
     * Quién cerró, sellado por los tres caminos.
     * ------------------------------------------------------------------ */

    public function test_closing_by_update_stamps_the_authenticated_user(): void
    {
        $taller = User::where('email', 'taller@dp.local')->firstOrFail();
        $wo = $this->openWorkOrder($this->machine($this->location('Yard')));

        $this->actingAs($taller);
        $wo->update(['status' => 'completed']);

        $this->assertSame($taller->id, $wo->fresh()->completed_by);
    }

    public function test_a_work_order_born_completed_is_stamped_too(): void
    {
        // Camino que `updating` se perdería: la OT que nace cerrada (import,
        // seeder, carga histórica).
        $admin = User::where('email', 'admin@dp.local')->firstOrFail();
        $machine = $this->machine($this->location('Yard'));

        $this->actingAs($admin);
        $wo = $this->openWorkOrder($machine, ['status' => 'completed']);

        $this->assertSame($admin->id, $wo->completed_by);
    }

    public function test_the_stamp_is_not_the_assignee(): void
    {
        // El corazón del asunto: `assigned_to` es una intención, `completed_by`
        // es lo que pasó. Un reporte que confunda las dos atribuye el trabajo a
        // la persona equivocada.
        $taller = User::where('email', 'taller@dp.local')->firstOrFail();
        $admin = User::where('email', 'admin@dp.local')->firstOrFail();

        $wo = $this->openWorkOrder($this->machine($this->location('Yard')), [
            'assigned_to' => $taller->id,
        ]);

        // La cierra el administrador, no el asignado.
        $this->actingAs($admin);
        $wo->update(['status' => 'completed']);

        $fresh = $wo->fresh();
        $this->assertSame($taller->id, $fresh->assigned_to);
        $this->assertSame($admin->id, $fresh->completed_by);
        $this->assertNotSame($fresh->assigned_to, $fresh->completed_by);
    }

    public function test_reopening_and_closing_again_keeps_the_first_executor(): void
    {
        $taller = User::where('email', 'taller@dp.local')->firstOrFail();
        $admin = User::where('email', 'admin@dp.local')->firstOrFail();
        $wo = $this->openWorkOrder($this->machine($this->location('Yard')));

        $this->actingAs($taller);
        $wo->update(['status' => 'completed']);

        // Reabrir y volver a cerrar no debe borrar a quien hizo el trabajo: el
        // sello solo rellena lo que está vacío.
        $wo->update(['status' => 'in_progress']);
        $this->actingAs($admin);
        $wo->update(['status' => 'completed']);

        $this->assertSame($taller->id, $wo->fresh()->completed_by);
    }

    public function test_without_a_session_the_stamp_stays_empty_instead_of_falling_back_to_the_assignee(): void
    {
        $taller = User::where('email', 'taller@dp.local')->firstOrFail();
        $wo = $this->openWorkOrder($this->machine($this->location('Yard')), [
            'assigned_to' => $taller->id,
        ]);

        // Consola / cola / importador: nadie autenticado.
        $wo->update(['status' => 'completed']);

        $this->assertNull($wo->fresh()->completed_by);
    }

    /* ------------------------------------------------------------------ *
     * La fecha de cierre, que es el eje del periodo del reporte.
     * ------------------------------------------------------------------ */

    public function test_completing_without_a_date_stamps_today_so_the_work_order_lands_in_a_period(): void
    {
        $wo = $this->openWorkOrder($this->machine($this->location('Yard')));

        $wo->update(['status' => 'completed']);

        // Sin esto la OT quedaría completada con `completed_at` NULL y no
        // aparecería en el reporte de NINGÚN mes: trabajo hecho, costo cargado y
        // fuera de todo reporte.
        $this->assertSame(now()->toDateString(), $wo->fresh()->completed_at->toDateString());
    }

    public function test_an_explicit_completion_date_is_respected(): void
    {
        $wo = $this->openWorkOrder($this->machine($this->location('Yard')));

        $wo->update(['status' => 'completed', 'completed_at' => '2026-07-20']);

        $this->assertSame('2026-07-20', $wo->fresh()->completed_at->toDateString());
    }
}

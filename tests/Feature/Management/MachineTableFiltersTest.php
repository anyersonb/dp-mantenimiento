<?php

namespace Tests\Feature\Management;

use App\Filament\Resources\MachineResource\Pages\ListMachines;
use App\Models\Machine;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Filtro por número de máquina en el listado de flota (pedido del cliente
 * 2026-08-05).
 *
 * Ya existía la caja de búsqueda de la tabla, que encuentra por `id_code`. Lo que
 * no existía era un **filtro**, y la diferencia importa: la búsqueda no se
 * combina con los demás filtros ni queda registrada como filtro aplicado, y los
 * reportes se arman a partir de los filtros.
 */
class MachineTableFiltersTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);
    }

    private function machine(string $idCode, string $status = 'active'): Machine
    {
        return Machine::create([
            'id_code' => $idCode,
            'status' => $status,
            'hourmeter_status' => 'ok',
            'current_hours' => 100,
            'last_service_hours' => 0,
            'service_interval_hours' => 500,
            'hours_adjustment' => 0,
        ]);
    }

    private function list(): \Livewire\Features\SupportTesting\Testable
    {
        $admin = User::where('email', 'admin@dp.local')->firstOrFail();

        return Livewire::actingAs($admin)->test(ListMachines::class);
    }

    public function test_the_machine_number_filter_accepts_a_prefix(): void
    {
        $this->machine('EX010');
        $this->machine('EX011');
        $this->machine('LD022');

        $this->list()
            ->filterTable('id_code', ['id_code' => 'EX'])
            ->assertCanSeeTableRecords(Machine::whereIn('id_code', ['EX010', 'EX011'])->get())
            ->assertCanNotSeeTableRecords(Machine::where('id_code', 'LD022')->get());
    }

    public function test_the_machine_number_filter_accepts_an_exact_code(): void
    {
        $this->machine('EX010');
        $this->machine('EX011');

        $this->list()
            ->filterTable('id_code', ['id_code' => 'EX010'])
            ->assertCanSeeTableRecords(Machine::where('id_code', 'EX010')->get())
            ->assertCanNotSeeTableRecords(Machine::where('id_code', 'EX011')->get());
    }

    public function test_surrounding_spaces_do_not_break_the_filter(): void
    {
        // Pegar un código desde el Excel del cliente arrastra espacios.
        $this->machine('EX010');
        $this->machine('LD022');

        $this->list()
            ->filterTable('id_code', ['id_code' => '  EX010  '])
            ->assertCanSeeTableRecords(Machine::where('id_code', 'EX010')->get())
            ->assertCanNotSeeTableRecords(Machine::where('id_code', 'LD022')->get());
    }

    public function test_an_empty_filter_does_not_hide_anything(): void
    {
        $this->machine('EX010');
        $this->machine('LD022');

        $this->list()
            ->filterTable('id_code', ['id_code' => ''])
            ->assertCanSeeTableRecords(Machine::all());
    }

    public function test_the_status_filter_can_find_the_unknown_machines(): void
    {
        // El caso que estaba roto: `unknown` no era una opción del filtro, y en la
        // flota real ahí viven 29 de las 99 máquinas.
        $this->machine('EX010', 'active');
        $this->machine('MS003', 'unknown');

        $this->list()
            ->filterTable('status', ['unknown'])
            ->assertCanSeeTableRecords(Machine::where('status', 'unknown')->get())
            ->assertCanNotSeeTableRecords(Machine::where('status', 'active')->get());
    }
}

<?php

namespace Tests\Feature\WorkOrder;

use App\Filament\Resources\WorkOrderResource\Pages\CreateWorkOrder;
use App\Models\Location;
use App\Models\Machine;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Hallazgo E6-12 (subido a Medio).
 *
 * El desplegable "Asignada a" ofrecía los 7 usuarios del sistema, incluidos
 * gerencia y el operador de cisterna, que **no tienen `execute_work_order`** y
 * por lo tanto no pueden ni abrir la OT que se les asigna. Una OT asignada a
 * quien no puede ejecutarla se ve trabajada y no lo está.
 */
class AssigneeIsLimitedToExecutorsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);
    }

    private function opcionesDeAsignado(): array
    {
        $location = Location::create(['name' => 'Patio', 'slug' => 'patio-'.uniqid()]);
        Machine::create([
            'id_code' => 'ASG-01', 'status' => 'active', 'hourmeter_status' => 'ok',
            'current_location_id' => $location->id, 'current_hours' => 100,
        ]);

        $componente = Livewire::actingAs(User::where('email', 'admin@dp.local')->firstOrFail())
            ->test(CreateWorkOrder::class)
            ->instance();

        return $componente->form->getFlatFields()['assigned_to']->getOptions();
    }

    public function test_only_users_who_can_execute_work_orders_are_offered(): void
    {
        $opciones = $this->opcionesDeAsignado();
        $nombres = array_values($opciones);

        // Con la matriz vigente, execute_work_order lo tienen administrador y taller.
        $this->assertContains('Administrador DP', $nombres);
        $this->assertContains('Taller / Técnico', $nombres);

        foreach (['Gerencia', 'Operador Cisterna', 'Foreman', 'Personal Mtto', 'Responsable Mtto'] as $fuera) {
            $this->assertNotContains(
                $fuera,
                $nombres,
                "{$fuera} no puede ejecutar OT y no debe poder recibir una asignada."
            );
        }
    }

    public function test_the_list_follows_the_permission_and_not_a_hardcoded_role(): void
    {
        // Se le da el permiso directo (no por rol) a gerencia: el desplegable
        // tiene que seguir al permiso, que es lo que gobierna de verdad.
        $gerencia = User::where('email', 'gerencia@dp.local')->firstOrFail();
        $gerencia->givePermissionTo('execute_work_order');

        $this->assertContains('Gerencia', array_values($this->opcionesDeAsignado()));
    }
}

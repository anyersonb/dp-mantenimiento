<?php

namespace Tests\Feature\Security;

use App\Filament\Resources\MachineResource\Pages\EditMachine;
use App\Filament\Resources\MachineResource\Pages\ListMachines;
use App\Models\Location;
use App\Models\Machine;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Hallazgo A3 (QA Etapa 05): responsable_mantenimiento (tiene manage_machines
 * pero no verify_data) apagaba needs_review desde el form normal de edición
 * de la máquina. La acción "Aprobar datos" sí validaba el permiso; el campo
 * suelto, no. Machine usa $guarded = [], así que la única barrera real es
 * MachineObserver::saving() (fix), no lo que se oculte en el form.
 */
class MachineDataIntegrityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);
    }

    protected function machineNeedingReview(): Machine
    {
        $location = Location::create(['name' => 'Test Yard', 'slug' => 'test-yard-'.uniqid()]);

        return Machine::create([
            'id_code' => 'QA-'.random_int(1000, 9999),
            'status' => 'active',
            'current_location_id' => $location->id,
            'needs_review' => true,
            'review_note' => 'QA - pendiente de aprobación',
        ]);
    }

    public function test_responsable_cannot_turn_off_needs_review_from_the_edit_form(): void
    {
        $responsable = User::where('email', 'responsable@dp.local')->firstOrFail();
        $machine = $this->machineNeedingReview();

        Livewire::actingAs($responsable)
            ->test(EditMachine::class, ['record' => $machine->getKey()])
            ->fillForm(['needs_review' => false])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertTrue($machine->refresh()->needs_review);
    }

    public function test_responsable_cannot_turn_off_needs_review_via_a_direct_model_update(): void
    {
        // Payload manipulado: sin pasar por el Toggle disabled/dehydrated del
        // form, directo a Eloquent::update() como lo haría un request armado
        // a mano contra el mismo controlador/página.
        $responsable = User::where('email', 'responsable@dp.local')->firstOrFail();
        $machine = $this->machineNeedingReview();

        $this->actingAs($responsable);
        $machine->update(['needs_review' => false]);

        $this->assertTrue($machine->refresh()->needs_review);
    }

    public function test_responsable_does_not_see_the_approve_action(): void
    {
        $responsable = User::where('email', 'responsable@dp.local')->firstOrFail();
        $machine = $this->machineNeedingReview();

        Livewire::actingAs($responsable)
            ->test(ListMachines::class)
            ->assertTableActionHidden('approve', $machine);
    }

    public function test_administrator_can_turn_off_needs_review_via_the_approve_action(): void
    {
        $admin = User::where('email', 'admin@dp.local')->firstOrFail();
        $machine = $this->machineNeedingReview();

        Livewire::actingAs($admin)
            ->test(ListMachines::class)
            ->callTableAction('approve', $machine)
            ->assertHasNoTableActionErrors();

        $this->assertFalse($machine->refresh()->needs_review);
    }

    public function test_administrator_can_turn_off_needs_review_from_the_edit_form(): void
    {
        $admin = User::where('email', 'admin@dp.local')->firstOrFail();
        $machine = $this->machineNeedingReview();

        Livewire::actingAs($admin)
            ->test(EditMachine::class, ['record' => $machine->getKey()])
            ->fillForm(['needs_review' => false])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertFalse($machine->refresh()->needs_review);
    }

    public function test_a_trusted_process_without_an_authenticated_user_can_still_set_needs_review(): void
    {
        // El importador del PM Service Report y los seeders corren sin sesión
        // web: no son el camino que reporta A3 y no deben quedar bloqueados.
        $machine = $this->machineNeedingReview();

        $machine->needs_review = false;
        $machine->save();

        $this->assertFalse($machine->refresh()->needs_review);
    }
}

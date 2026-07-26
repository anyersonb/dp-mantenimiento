<?php

namespace Tests\Feature\Security;

use App\Filament\Resources\MachineResource\Pages\EditMachine;
use App\Filament\Resources\MachineResource\RelationManagers\PartsRelationManager as MachinePartsRelationManager;
use App\Filament\Resources\MachineResource\RelationManagers\ReadingsRelationManager;
use App\Filament\Resources\WorkOrderResource\Pages\EditWorkOrder;
use App\Filament\Resources\WorkOrderResource\RelationManagers\AttachmentsRelationManager;
use App\Filament\Resources\WorkOrderResource\RelationManagers\ChecklistResultsRelationManager;
use App\Filament\Resources\WorkOrderResource\RelationManagers\PartsRelationManager as WorkOrderPartsRelationManager;
use App\Models\HorometerReading;
use App\Models\Location;
use App\Models\Machine;
use App\Models\User;
use App\Models\WorkOrder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Hallazgo A8 — Etapa 06.
 *
 * Los relation managers declaraban Create/Edit/Delete sin ningún control y
 * dependían de que el `canEdit()` del Resource dueño impidiera llegar a la
 * página. Eso es acoplamiento indirecto, no autorización: sin Policy,
 * `Filament\authorize()` devuelve `allow()`, y este proyecto no tiene
 * `app/Policies`.
 *
 * Este test prueba el relation manager DIRECTAMENTE, sin pasar por la página,
 * que es justo lo que el `canEdit()` del Resource no puede defender. Falla
 * sin los overrides de can*() y pasa con ellos.
 */
class RelationManagerWritePermissionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);
    }

    private function machine(): Machine
    {
        $location = Location::create(['name' => 'RM Yard', 'slug' => 'rm-yard-'.uniqid()]);

        return Machine::create([
            'id_code' => 'RM-'.random_int(1000, 9999),
            'status' => 'active',
            'hourmeter_status' => 'ok',
            'current_location_id' => $location->id,
            'current_hours' => 500,
            'last_service_hours' => 100,
            'service_interval_hours' => 500,
            'hours_adjustment' => 0,
            'remaining_hours' => 100,
        ]);
    }

    private function workOrder(Machine $machine, string $status = 'open'): WorkOrder
    {
        return WorkOrder::create([
            'code' => 'WO-'.random_int(1000, 9999),
            'machine_id' => $machine->id,
            'type' => 'corrective',
            'status' => $status,
            'priority' => 'normal',
            'hours_at_open' => $machine->current_hours,
            'opened_at' => now()->toDateString(),
        ]);
    }

    private function user(string $email): User
    {
        return User::where('email', $email)->firstOrFail();
    }

    /* ------------------------------------------------------------------ *
     * Relation managers de MÁQUINA -> manage_machines
     * ------------------------------------------------------------------ */

    public function test_a_role_without_manage_machines_cannot_write_horometer_readings(): void
    {
        // taller tiene view_fleet y log_horometer, pero NO manage_machines.
        $machine = $this->machine();
        $reading = HorometerReading::create([
            'machine_id' => $machine->id,
            'hours' => 500,
            'read_at' => now()->toDateString(),
            'source' => 'manual',
            'verified' => true,
        ]);

        Livewire::actingAs($this->user('taller@dp.local'))
            ->test(ReadingsRelationManager::class, [
                'ownerRecord' => $machine,
                'pageClass' => EditMachine::class,
            ])
            ->assertTableActionHidden('create')
            ->assertTableActionHidden('edit', $reading)
            ->assertTableActionHidden('delete', $reading);
    }

    public function test_the_responsible_with_manage_machines_can_write_horometer_readings(): void
    {
        $machine = $this->machine();
        $reading = HorometerReading::create([
            'machine_id' => $machine->id,
            'hours' => 500,
            'read_at' => now()->toDateString(),
            'source' => 'manual',
            'verified' => true,
        ]);

        Livewire::actingAs($this->user('responsable@dp.local'))
            ->test(ReadingsRelationManager::class, [
                'ownerRecord' => $machine,
                'pageClass' => EditMachine::class,
            ])
            ->assertTableActionVisible('create')
            ->assertTableActionVisible('edit', $reading)
            ->assertTableActionVisible('delete', $reading);
    }

    public function test_a_role_without_manage_machines_cannot_write_the_machine_parts_catalog(): void
    {
        Livewire::actingAs($this->user('taller@dp.local'))
            ->test(MachinePartsRelationManager::class, [
                'ownerRecord' => $this->machine(),
                'pageClass' => EditMachine::class,
            ])
            ->assertTableActionHidden('create');
    }

    /* ------------------------------------------------------------------ *
     * Relation managers de OT -> execute_work_order
     * ------------------------------------------------------------------ */

    /**
     * @return array<string, array{0: class-string}>
     */
    public static function workOrderRelationManagers(): array
    {
        return [
            'adjuntos' => [AttachmentsRelationManager::class],
            'checklist' => [ChecklistResultsRelationManager::class],
            'repuestos' => [WorkOrderPartsRelationManager::class],
        ];
    }

    /**
     * @param  class-string  $relationManager
     */
    #[DataProvider('workOrderRelationManagers')]
    public function test_a_role_without_execute_work_order_cannot_write_on_a_work_order(string $relationManager): void
    {
        // gerencia tiene view_fleet, view_costs, move_fleet y view_reports,
        // pero NO execute_work_order. Verificado contra role_has_permissions.
        Livewire::actingAs($this->user('gerencia@dp.local'))
            ->test($relationManager, [
                'ownerRecord' => $this->workOrder($this->machine()),
                'pageClass' => EditWorkOrder::class,
            ])
            ->assertTableActionHidden('create');
    }

    /**
     * @param  class-string  $relationManager
     */
    #[DataProvider('workOrderRelationManagers')]
    public function test_the_workshop_with_execute_work_order_can_write_on_an_open_work_order(string $relationManager): void
    {
        Livewire::actingAs($this->user('taller@dp.local'))
            ->test($relationManager, [
                'ownerRecord' => $this->workOrder($this->machine()),
                'pageClass' => EditWorkOrder::class,
            ])
            ->assertTableActionVisible('create');
    }
}

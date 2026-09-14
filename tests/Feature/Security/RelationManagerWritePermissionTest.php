<?php

namespace Tests\Feature\Security;

use App\Filament\Resources\MachineResource\Pages\EditMachine;
use App\Filament\Resources\MachineResource\RelationManagers\PartsRelationManager as MachinePartsRelationManager;
use App\Filament\Resources\MachineResource\RelationManagers\ReadingsRelationManager;
use App\Filament\Resources\WorkOrderResource\Pages\EditWorkOrder;
use App\Filament\Resources\WorkOrderResource\RelationManagers\AttachmentsRelationManager;
use App\Filament\Resources\WorkOrderResource\RelationManagers\ChecklistResultsRelationManager;
use App\Filament\Resources\WorkOrderResource\RelationManagers\PartsRelationManager as WorkOrderPartsRelationManager;
use App\Models\ChecklistResult;
use App\Models\HorometerReading;
use App\Models\Location;
use App\Models\Machine;
use App\Models\User;
use App\Models\WorkOrder;
use App\Models\WorkOrderAttachment;
use App\Models\WorkOrderPart;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Database\Eloquent\Model;
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

    /* ------------------------------------------------------------------ *
     * Borrado gobernado por ESTADO de la OT, no por rol.
     *
     *   OT abierta -> borra quien tiene execute_work_order.
     *   OT cerrada -> nadie borra, tampoco el administrador.
     *
     * Regla única en App\Filament\Concerns\DeletesOnlyWhileWorkOrderIsOpen.
     * ------------------------------------------------------------------ */

    /**
     * Cada caso: [relation manager, estado de la OT, correo del usuario, se espera poder borrar].
     *
     * @return array<string, array{0: class-string, 1: string, 2: string, 3: bool}>
     */
    public static function deletionCases(): array
    {
        $casos = [];

        foreach (self::workOrderRelationManagers() as $nombre => [$clase]) {
            // Estados abiertos: los tres, para que agregar uno nuevo al enum
            // sin decidir de qué lado cae se note acá.
            foreach (['open', 'assigned', 'in_progress'] as $abierto) {
                $casos["$nombre · OT $abierto · taller SÍ borra"] = [$clase, $abierto, 'taller@dp.local', true];
            }

            $casos["$nombre · OT abierta · gerencia NO borra"] = [$clase, 'open', 'gerencia@dp.local', false];

            foreach (['completed', 'cancelled'] as $cerrado) {
                $casos["$nombre · OT $cerrado · taller NO borra"] = [$clase, $cerrado, 'taller@dp.local', false];
                $casos["$nombre · OT $cerrado · administrador NO borra"] = [$clase, $cerrado, 'admin@dp.local', false];
            }
        }

        return $casos;
    }

    /**
     * @param  class-string  $relationManager
     */
    #[DataProvider('deletionCases')]
    public function test_deletion_of_work_order_related_records_follows_the_work_order_status(
        string $relationManager,
        string $status,
        string $email,
        bool $shouldBeAbleToDelete,
    ): void {
        $workOrder = $this->workOrder($this->machine(), $status);
        $record = $this->relatedRecord($relationManager, $workOrder);

        $component = Livewire::actingAs($this->user($email))
            ->test($relationManager, [
                'ownerRecord' => $workOrder,
                'pageClass' => EditWorkOrder::class,
            ]);

        // El botón NUNCA se oculta (hallazgo "parts used", 2026-09-14): un
        // botón que desaparece sin explicación se vive como "está roto". Lo
        // que cambia es si queda habilitado o deshabilitado con un motivo
        // legible — ver DeletesOnlyWhileWorkOrderIsOpen::deletionBlockedReason().
        $component->assertTableActionVisible('delete', $record);

        $shouldBeAbleToDelete
            ? $component->assertTableActionEnabled('delete', $record)
            : $component->assertTableActionDisabled('delete', $record);
    }

    /**
     * El administrador tiene los 15 permisos, así que este caso es el que
     * demuestra que la regla es de ESTADO y no de rol: con la OT cerrada
     * tampoco borra, y aun así sigue pudiendo entrar y ver.
     */
    public function test_the_administrator_cannot_delete_an_invoice_from_a_closed_work_order(): void
    {
        $workOrder = $this->workOrder($this->machine(), 'completed');
        $attachment = $this->relatedRecord(AttachmentsRelationManager::class, $workOrder);

        Livewire::actingAs($this->user('admin@dp.local'))
            ->test(AttachmentsRelationManager::class, [
                'ownerRecord' => $workOrder,
                'pageClass' => EditWorkOrder::class,
            ])
            ->assertTableActionVisible('delete', $attachment)
            ->assertTableActionDisabled('delete', $attachment)
            ->assertSuccessful();

        $this->assertDatabaseHas('work_order_attachments', ['id' => $attachment->getKey()]);
    }

    /**
     * @param  class-string  $relationManager
     */
    private function relatedRecord(string $relationManager, WorkOrder $workOrder): Model
    {
        return match ($relationManager) {
            AttachmentsRelationManager::class => WorkOrderAttachment::create([
                'work_order_id' => $workOrder->id,
                'type' => 'invoice',
                'path' => 'work-orders/qa-factura.pdf',
                'original_name' => 'qa-factura.pdf',
            ]),
            // Ojo con los nombres de columna: checklist_results usa `label` y
            // work_order_parts usa `description` + `part_number`. Ver las notas
            // de esquema del CLAUDE.md.
            ChecklistResultsRelationManager::class => ChecklistResult::create([
                'work_order_id' => $workOrder->id,
                'label' => 'Nivel de aceite',
                'result' => 'ok',
            ]),
            WorkOrderPartsRelationManager::class => WorkOrderPart::create([
                'work_order_id' => $workOrder->id,
                'part_number' => 'QA-FLT-01',
                'description' => 'Filtro de aceite',
                'quantity' => 1,
                'unit_cost' => 25,
            ]),
        };
    }
}

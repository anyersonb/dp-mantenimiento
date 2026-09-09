<?php

namespace Tests\Feature\Trash;

use App\Filament\Resources\WorkOrderResource;
use App\Models\ChecklistResult;
use App\Models\Location;
use App\Models\Machine;
use App\Models\User;
use App\Models\WorkOrder;
use App\Models\WorkOrderAttachment;
use App\Models\WorkOrderPart;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Papelera (Lote A) — el caso más fácil de hacer mal: qué hijos revuelven al
 * restaurar una orden de trabajo.
 *
 * `work_order_parts`, `work_order_attachments` y `checklist_results` tienen
 * `cascadeOnDelete()` hacia `work_orders` a nivel de FK, pero eso SOLO se
 * dispara con un DELETE real — un soft delete nunca llega a ejecutar uno, así
 * que sin la cascada lógica de `App\Models\WorkOrder::booted()` esos hijos
 * quedarían visibles y huérfanos con su OT dueña ya en la papelera.
 *
 * El marcador que distingue "se fue CON esta baja" de "ya estaba borrado de
 * antes" es el propio `deleted_at` de la OT, copiado sin pasar por el cast
 * datetime (ver el comentario en el modelo). Estos tests prueban exactamente
 * esa distinción, que es la parte que más fácil se hace mal.
 */
class WorkOrderTrashCascadeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);
        Storage::fake('local');
    }

    private function admin(): User
    {
        return User::where('email', 'admin@dp.local')->firstOrFail();
    }

    private function workOrderWithChildren(): WorkOrder
    {
        $location = Location::create(['name' => 'Trash Yard', 'slug' => 'trash-yard-'.uniqid()]);

        $machine = Machine::create([
            'id_code' => 'TR-'.random_int(1000, 9999),
            'status' => 'active',
            'hourmeter_status' => 'ok',
            'current_location_id' => $location->id,
        ]);

        $workOrder = WorkOrder::create([
            'code' => 'WO-TR-'.random_int(1000, 9999),
            'machine_id' => $machine->id,
            'type' => 'corrective',
            'status' => 'open',
            'priority' => 'normal',
            'opened_at' => now()->toDateString(),
        ]);

        WorkOrderPart::create([
            'work_order_id' => $workOrder->id,
            'part_number' => 'P-001',
            'description' => 'Filtro de aceite',
            'quantity' => 1,
            'unit_cost' => 25,
        ]);

        $path = 'work-order-attachments/'.$workOrder->id.'/test/factura.pdf';
        Storage::disk('local')->put($path, "%PDF-1.4\nfactura de prueba\n");

        WorkOrderAttachment::create([
            'work_order_id' => $workOrder->id,
            'type' => 'invoice',
            'path' => $path,
            'original_name' => 'factura.pdf',
        ]);

        ChecklistResult::create([
            'work_order_id' => $workOrder->id,
            'label' => 'Frenos',
            'result' => 'ok',
        ]);

        return $workOrder->fresh();
    }

    /* ------------------------------------------------------------------ *
     * 1. Borrar una OT la manda a la papelera y NO la elimina de la BD.
     * ------------------------------------------------------------------ */

    public function test_deleting_a_work_order_sends_it_to_trash_without_removing_the_row(): void
    {
        $workOrder = $this->workOrderWithChildren();

        $workOrder->delete();

        $this->assertSoftDeleted('work_orders', ['id' => $workOrder->id]);
        $this->assertDatabaseHas('work_orders', ['id' => $workOrder->id, 'code' => $workOrder->code]);
    }

    /* ------------------------------------------------------------------ *
     * 2. Los hijos desaparecen al borrar y vuelven al restaurar.
     * ------------------------------------------------------------------ */

    public function test_children_are_trashed_with_the_work_order_and_hidden_from_normal_queries(): void
    {
        $workOrder = $this->workOrderWithChildren();

        $partId = $workOrder->parts()->sole()->id;
        $attachmentId = $workOrder->attachments()->sole()->id;
        $checklistId = $workOrder->checklistResults()->sole()->id;

        $workOrder->delete();

        // Las consultas normales (sin ->withTrashed()) ya no los ven.
        $this->assertNull(WorkOrderPart::find($partId));
        $this->assertNull(WorkOrderAttachment::find($attachmentId));
        $this->assertNull(ChecklistResult::find($checklistId));

        // Pero las filas siguen ahí, soft-deleted, no destruidas.
        $this->assertSoftDeleted('work_order_parts', ['id' => $partId]);
        $this->assertSoftDeleted('work_order_attachments', ['id' => $attachmentId]);
        $this->assertSoftDeleted('checklist_results', ['id' => $checklistId]);
    }

    public function test_children_return_when_the_work_order_is_restored(): void
    {
        $workOrder = $this->workOrderWithChildren();

        $partId = $workOrder->parts()->sole()->id;
        $attachmentId = $workOrder->attachments()->sole()->id;
        $checklistId = $workOrder->checklistResults()->sole()->id;

        $workOrder->delete();
        $workOrder->restore();

        $this->assertNotNull(WorkOrderPart::find($partId), 'El repuesto no volvió al restaurar la OT.');
        $this->assertNotNull(WorkOrderAttachment::find($attachmentId), 'El adjunto no volvió al restaurar la OT.');
        $this->assertNotNull(ChecklistResult::find($checklistId), 'El resultado de checklist no volvió al restaurar la OT.');

        $this->assertNull(WorkOrderPart::find($partId)->deleted_at);
        $this->assertNull(WorkOrderAttachment::find($attachmentId)->deleted_at);
        $this->assertNull(ChecklistResult::find($checklistId)->deleted_at);
    }

    /**
     * El punto más fácil de hacer mal del lote: un hijo que YA estaba en la
     * papelera antes de que su OT se borrara no puede reaparecer solo porque
     * la OT se restauró.
     */
    public function test_a_child_already_trashed_before_the_parent_does_not_revive_on_restore(): void
    {
        $workOrder = $this->workOrderWithChildren();

        $part = $workOrder->parts()->sole();
        $part->delete(); // se borra SOLO, antes que la OT

        $this->assertSoftDeleted('work_order_parts', ['id' => $part->id]);

        $workOrder->delete();   // ahora se borra la OT completa
        $workOrder->restore();  // y se restaura

        // El repuesto que ya estaba en la papelera ANTES sigue en la papelera.
        $this->assertSoftDeleted('work_order_parts', ['id' => $part->id]);
        $this->assertNull(WorkOrderPart::find($part->id));

        // El resto de los hijos (que sí se fueron CON la OT) sí volvieron.
        $this->assertNotNull(WorkOrderAttachment::find($workOrder->attachments()->withTrashed()->sole()->id));
    }

    /* ------------------------------------------------------------------ *
     * 3. El archivo físico del adjunto sigue existiendo tras el soft delete.
     * ------------------------------------------------------------------ */

    public function test_the_attachment_file_survives_a_soft_delete(): void
    {
        $workOrder = $this->workOrderWithChildren();
        $path = $workOrder->attachments()->sole()->path;

        $this->assertTrue(Storage::disk('local')->exists($path));

        $workOrder->delete();

        $this->assertTrue(
            Storage::disk('local')->exists($path),
            'El soft delete no debe tocar el archivo físico del adjunto.'
        );
    }

    /* ------------------------------------------------------------------ *
     * 5. Eliminar definitivamente sí borra el registro y su archivo.
     * ------------------------------------------------------------------ */

    public function test_force_deleting_a_work_order_removes_the_row_and_the_attachment_file(): void
    {
        $workOrder = $this->workOrderWithChildren();
        $path = $workOrder->attachments()->sole()->path;
        $partId = $workOrder->parts()->sole()->id;
        $checklistId = $workOrder->checklistResults()->sole()->id;

        $workOrder->delete();
        $workOrder->forceDelete();

        $this->assertDatabaseMissing('work_orders', ['id' => $workOrder->id]);
        $this->assertDatabaseMissing('work_order_parts', ['id' => $partId]);
        $this->assertDatabaseMissing('checklist_results', ['id' => $checklistId]);
        $this->assertDatabaseMissing('work_order_attachments', ['path' => $path]);

        $this->assertFalse(
            Storage::disk('local')->exists($path),
            'La eliminación definitiva sí tiene que borrar el archivo físico del adjunto.'
        );
    }

    public function test_force_delete_action_is_wired_in_the_resource_and_gated_by_permission(): void
    {
        $workOrder = $this->workOrderWithChildren();
        $workOrder->delete();

        $this->actingAs($this->admin());

        $this->assertTrue(WorkOrderResource::canForceDelete($workOrder));
        $this->assertTrue(WorkOrderResource::canRestore($workOrder));
    }
}

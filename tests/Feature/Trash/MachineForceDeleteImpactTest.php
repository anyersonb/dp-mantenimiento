<?php

namespace Tests\Feature\Trash;

use App\Filament\Resources\MachineResource;
use App\Filament\Resources\MachineResource\Pages\ListMachines;
use App\Models\Alert;
use App\Models\FieldReport;
use App\Models\HorometerReading;
use App\Models\Location;
use App\Models\Machine;
use App\Models\MachinePart;
use App\Models\User;
use App\Models\WorkOrder;
use App\Models\WorkOrderAttachment;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use ReflectionMethod;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;

/**
 * Papelera reabre el hallazgo E6-05: `papeleraForceDeleteAction()` llamaba
 * `forceDelete()` pelado. Todas las FK que apuntan a `machines`
 * (work_orders, horometer_readings, machine_parts, alerts, field_reports)
 * son `cascadeOnDelete()`, y QA lo reprodujo con clic real en la base local
 * (máquina 131: 1 OT + 1 lectura + 17 partes, las tres tablas quedaron en 0
 * tras un solo "Force delete", sin ningún aviso previo).
 *
 * Este archivo prueba el arreglo:
 *   1. El resumen de destrucción trae las CINCO tablas (antes faltaba
 *      field_reports).
 *   2. El modal enumera los conteos reales, no un texto genérico.
 *   3. Un registro SIN historial no exige nada extra (no se regresiona el
 *      flujo simple).
 *   4. El borrado se BLOQUEA si no se re-tecleó el id_code exacto.
 *   5. Con el id_code exacto, sí borra todo, y deja un asiento en la
 *      bitácora con los conteos reales ANTES de que las filas desaparezcan
 *      (si el código contara DESPUÉS de forceDelete(), los conteos
 *      guardados serían 0 — la aserción de valores exactos es la prueba de
 *      que el conteo se hizo ANTES).
 *   6. La variante MASIVA recibe el mismo trato: bloquea sin la cantidad
 *      exacta, y con ella borra y registra el impacto de cada máquina.
 *
 * Nota de arnés: para invocar `forceDelete`/`restore` sobre un registro que
 * YA está en la papelera hace falta el filtro "trashed" ACTIVO en la misma
 * sesión de Livewire — `getTableRecord()`/`getSelectedTableRecords()`
 * resuelven contra la consulta FILTRADA de la tabla, y sin `withTrashed()`
 * ese registro no existe para esa consulta (el soft delete lo saca del
 * scope por defecto). Sin el filtro activo, `callTableAction()` no revienta
 * ni avisa nada: simplemente no monta la acción y el test "pasa" sin haber
 * probado nada (el defecto exacto que exige verificar el check al revés).
 */
class MachineForceDeleteImpactTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);
    }

    private function admin(): User
    {
        return User::where('email', 'admin@dp.local')->firstOrFail();
    }

    private function machineWithHistory(): Machine
    {
        $location = Location::create(['name' => 'Impact Yard', 'slug' => 'impact-yard-'.uniqid()]);

        $machine = Machine::create([
            'id_code' => 'FD-'.random_int(100000, 999999),
            'status' => 'active',
            'hourmeter_status' => 'ok',
            'current_location_id' => $location->id,
        ]);

        WorkOrder::create([
            'code' => 'WO-FD-'.random_int(100000, 999999),
            'machine_id' => $machine->id,
            'type' => 'corrective',
            'status' => 'open',
            'priority' => 'normal',
            'opened_at' => now()->toDateString(),
        ]);

        HorometerReading::create([
            'machine_id' => $machine->id,
            'hours' => 500,
            'read_at' => now()->toDateString(),
            'source' => 'manual',
        ]);

        MachinePart::create(['machine_id' => $machine->id, 'label' => 'Filtro de aceite']);
        MachinePart::create(['machine_id' => $machine->id, 'label' => 'Filtro de aire']);

        Alert::create([
            'machine_id' => $machine->id,
            'type' => 'service',
            'title' => 'QA',
            'message' => 'QA',
            'remaining_hours' => 50,
            'status' => 'open',
        ]);

        FieldReport::create([
            'machine_id' => $machine->id,
            'condition' => 'ok',
        ]);

        return $machine->refresh();
    }

    private function emptyMachine(): Machine
    {
        $location = Location::create(['name' => 'Empty Yard', 'slug' => 'empty-yard-'.uniqid()]);

        return Machine::create([
            'id_code' => 'EMPTY-'.random_int(100000, 999999),
            'status' => 'active',
            'hourmeter_status' => 'ok',
            'current_location_id' => $location->id,
        ])->refresh();
    }

    private function trash(Machine $machine): Machine
    {
        $machine->delete();

        return $machine->fresh();
    }

    /* ------------------------------------------------------------------ *
     * 1. El resumen que alimenta el modal trae las cinco tablas reales.
     * ------------------------------------------------------------------ */
    public function test_the_destruction_summary_includes_all_five_cascaded_tables(): void
    {
        $machine = $this->machineWithHistory();

        $resumen = $machine->destructionSummary();

        $this->assertSame(1, $resumen['work_orders']);
        $this->assertSame(1, $resumen['readings']);
        $this->assertSame(2, $resumen['parts']);
        $this->assertGreaterThanOrEqual(1, $resumen['alerts']);
        $this->assertSame(1, $resumen['field_reports']);
    }

    /* ------------------------------------------------------------------ *
     * 2. El modal de borrado definitivo enumera los conteos reales.
     *    (locale de la suite: en/en, ver .env APP_LOCALE — el texto sale en
     *    inglés; lang/es/fleet.php trae el equivalente en español).
     * ------------------------------------------------------------------ */
    public function test_the_force_delete_modal_enumerates_real_counts_and_says_it_cannot_be_undone(): void
    {
        $machine = $this->trash($this->machineWithHistory());

        $method = new ReflectionMethod(MachineResource::class, 'papeleraForceDeleteModalDescription');
        $method->setAccessible(true);
        $aviso = $method->invoke(null, $machine);

        $this->assertNotNull($aviso, 'Con historial, el modal tiene que traer un texto propio, no el genérico de Filament.');
        $this->assertStringContainsString($machine->id_code, $aviso);
        $this->assertStringContainsString('cannot be undone', $aviso);
        $this->assertStringContainsString('1 work order', $aviso);
        $this->assertStringContainsString('2 part', $aviso);
        $this->assertStringContainsString('1 field report', $aviso);
    }

    /**
     * Un registro SIN historial no debe pedir ninguna confirmación extra: el
     * flujo simple de siempre (requiresConfirmation() de Filament) sigue
     * intacto.
     */
    public function test_a_machine_without_history_does_not_require_extra_confirmation(): void
    {
        $machine = $this->trash($this->emptyMachine());

        $descMethod = new ReflectionMethod(MachineResource::class, 'papeleraForceDeleteModalDescription');
        $descMethod->setAccessible(true);
        $this->assertNull($descMethod->invoke(null, $machine));

        $formMethod = new ReflectionMethod(MachineResource::class, 'papeleraForceDeleteForm');
        $formMethod->setAccessible(true);
        $this->assertSame([], $formMethod->invoke(null, $machine));
    }

    /* ------------------------------------------------------------------ *
     * 3. Sin el código exacto, no se borra nada (ni la máquina ni sus hijos).
     * ------------------------------------------------------------------ */
    public function test_force_delete_is_blocked_without_the_exact_id_code(): void
    {
        $machine = $this->trash($this->machineWithHistory());

        Livewire::actingAs($this->admin())
            ->test(ListMachines::class)
            ->filterTable('trashed', true)
            ->callTableAction('forceDelete', $machine, data: ['confirm_value' => 'codigo-incorrecto']);

        $this->assertDatabaseHas('machines', ['id' => $machine->id]);
        $this->assertDatabaseHas('work_orders', ['machine_id' => $machine->id]);
        $this->assertDatabaseHas('horometer_readings', ['machine_id' => $machine->id]);
        $this->assertDatabaseHas('machine_parts', ['machine_id' => $machine->id]);
        $this->assertDatabaseHas('field_reports', ['machine_id' => $machine->id]);

        $this->assertDatabaseMissing('activity_log', [
            'subject_type' => Machine::class,
            'subject_id' => $machine->id,
            'event' => 'force_delete_impact',
        ]);
    }

    /**
     * Prueba de control del test anterior: con el arnés mal preparado (sin
     * `filterTable('trashed', true)`), `callTableAction()` NO monta la
     * acción y el test de arriba "pasaría" sin haber ejecutado nada —
     * exactamente el defecto de instrumentación que hay que descartar antes
     * de creer un bloqueo real. Esto confirma que el escenario de arriba SÍ
     * corre la acción de verdad.
     */
    public function test_control_without_the_trashed_filter_the_action_never_mounts(): void
    {
        $machine = $this->trash($this->machineWithHistory());

        Livewire::actingAs($this->admin())
            ->test(ListMachines::class)
            ->callTableAction('forceDelete', $machine, data: ['confirm_value' => $machine->id_code]);

        // Ni siquiera con el código CORRECTO borra nada: sin el filtro
        // "trashed" activo, el registro no existe en la consulta filtrada
        // por defecto (sin withTrashed()), así que la acción no llega a
        // montarse y esto NO borra — a diferencia del test de arriba, que
        // con el MISMO código correcto y el filtro activo sí borra todo.
        // Esa diferencia es la prueba de que el bloqueo real se ejerce en
        // los tests con `filterTable('trashed', true)`, no en un arnés que
        // nunca llegó a ejecutar nada.
        $this->assertDatabaseHas('machines', ['id' => $machine->id]);
    }

    /* ------------------------------------------------------------------ *
     * 4. Con el código exacto, borra TODO y deja el asiento de impacto con
     *    los conteos reales, calculados ANTES del borrado.
     * ------------------------------------------------------------------ */
    public function test_force_delete_with_the_exact_id_code_deletes_everything_and_logs_the_impact_first(): void
    {
        $machine = $this->trash($this->machineWithHistory());

        Livewire::actingAs($this->admin())
            ->test(ListMachines::class)
            ->filterTable('trashed', true)
            ->callTableAction('forceDelete', $machine, data: ['confirm_value' => $machine->id_code]);

        $this->assertDatabaseMissing('machines', ['id' => $machine->id]);
        $this->assertDatabaseMissing('work_orders', ['machine_id' => $machine->id]);
        $this->assertDatabaseMissing('horometer_readings', ['machine_id' => $machine->id]);
        $this->assertDatabaseMissing('machine_parts', ['machine_id' => $machine->id]);
        $this->assertDatabaseMissing('alerts', ['machine_id' => $machine->id]);
        $this->assertDatabaseMissing('field_reports', ['machine_id' => $machine->id]);

        $asiento = Activity::where('subject_type', Machine::class)
            ->where('subject_id', $machine->id)
            ->where('event', 'force_delete_impact')
            ->first();

        $this->assertNotNull($asiento, 'Tiene que quedar un asiento de impacto en la bitácora.');
        $this->assertSame($this->admin()->id, $asiento->causer_id);

        // Si estos conteos se hubieran calculado DESPUÉS de forceDelete(),
        // acá serían 0 (los hijos ya no existen). Que sean los reales
        // demuestra que el conteo se hizo ANTES de borrar.
        $this->assertSame(1, $asiento->properties['work_orders'] ?? null);
        $this->assertSame(1, $asiento->properties['readings'] ?? null);
        $this->assertSame(2, $asiento->properties['parts'] ?? null);
        $this->assertSame(1, $asiento->properties['field_reports'] ?? null);
    }

    /* ------------------------------------------------------------------ *
     * 5. Masivo: mismo trato, mismo arnés (filtro "trashed" activo para que
     *    la selección resuelva contra las filas soft-deleted).
     * ------------------------------------------------------------------ */
    public function test_bulk_force_delete_is_blocked_without_the_exact_count(): void
    {
        $a = $this->trash($this->machineWithHistory());
        $b = $this->trash($this->machineWithHistory());

        Livewire::actingAs($this->admin())
            ->test(ListMachines::class)
            ->filterTable('trashed', true)
            ->callTableBulkAction('forceDelete', [$a, $b], data: ['confirm_count' => '99']);

        $this->assertDatabaseHas('machines', ['id' => $a->id]);
        $this->assertDatabaseHas('machines', ['id' => $b->id]);
        $this->assertDatabaseHas('work_orders', ['machine_id' => $a->id]);
        $this->assertDatabaseHas('work_orders', ['machine_id' => $b->id]);
    }

    public function test_bulk_force_delete_with_the_exact_count_deletes_all_selected_and_logs_each_impact(): void
    {
        $a = $this->trash($this->machineWithHistory());
        $b = $this->trash($this->machineWithHistory());

        Livewire::actingAs($this->admin())
            ->test(ListMachines::class)
            ->filterTable('trashed', true)
            ->callTableBulkAction('forceDelete', [$a, $b], data: ['confirm_count' => '2']);

        $this->assertDatabaseMissing('machines', ['id' => $a->id]);
        $this->assertDatabaseMissing('machines', ['id' => $b->id]);
        $this->assertDatabaseMissing('work_orders', ['machine_id' => $a->id]);
        $this->assertDatabaseMissing('work_orders', ['machine_id' => $b->id]);

        $entradas = Activity::where('subject_type', Machine::class)
            ->whereIn('subject_id', [$a->id, $b->id])
            ->where('event', 'force_delete_impact')
            ->count();

        $this->assertSame(2, $entradas, 'Cada máquina del lote tiene que dejar su propio asiento de impacto.');
    }

    /* ------------------------------------------------------------------ *
     * 7. Regresión del hallazgo Alto (seguridad, 2026-09-09): una máquina
     *    cuyas OT YA están en la papelera —el flujo normal antes de dar de
     *    baja el activo— y sin ningún otro hijo vivo tenía `summary =
     *    [0,0,0,0,0]` porque `workOrders()->count()` no traía `withTrashed()`.
     *    Eso apagaba, en cascada: `papeleraHasDestructiveImpact()` (false),
     *    el pedido de re-teclear el id_code (vacío), el texto del modal
     *    (el genérico de Filament) y el asiento de impacto (cortaba por
     *    `array_sum($summary) === 0`). Un clic se llevaba la OT archivada.
     * ------------------------------------------------------------------ */
    public function test_a_machine_with_only_an_already_trashed_work_order_still_has_destructive_impact(): void
    {
        $location = Location::create(['name' => 'Sentinel Yard', 'slug' => 'sentinel-yard-'.uniqid()]);

        $machine = Machine::create([
            'id_code' => 'SENT-'.random_int(100000, 999999),
            'status' => 'active',
            'hourmeter_status' => 'ok',
            'current_location_id' => $location->id,
        ]);

        $workOrder = WorkOrder::create([
            'code' => 'WO-SENT-'.random_int(100000, 999999),
            'machine_id' => $machine->id,
            'type' => 'corrective',
            'status' => 'open',
            'priority' => 'normal',
            'opened_at' => now()->toDateString(),
        ]);

        // La OT ya está en la papelera ANTES de tocar la máquina: es el
        // flujo normal (se limpian las OT antes de dar de baja el activo).
        $workOrder->delete();

        $resumen = $machine->refresh()->destructionSummary();
        $this->assertSame(1, $resumen['work_orders'], 'El resumen tiene que contar la OT ya archivada.');

        $machine = $this->trash($machine);

        $impactMethod = new ReflectionMethod(MachineResource::class, 'papeleraHasDestructiveImpact');
        $impactMethod->setAccessible(true);
        $this->assertTrue(
            $impactMethod->invoke(null, $machine),
            'Con una OT archivada de por medio, la máquina SÍ tiene algo que perder.'
        );

        // Sin el código exacto, no borra nada.
        Livewire::actingAs($this->admin())
            ->test(ListMachines::class)
            ->filterTable('trashed', true)
            ->callTableAction('forceDelete', $machine, data: ['confirm_value' => 'codigo-incorrecto']);

        $this->assertDatabaseHas('machines', ['id' => $machine->id]);
        $this->assertDatabaseHas('work_orders', ['id' => $workOrder->id]);

        // Con el código exacto, sí borra todo y deja el asiento con el
        // conteo real (1), no con el [0,0,0,0,0] del defecto original.
        Livewire::actingAs($this->admin())
            ->test(ListMachines::class)
            ->filterTable('trashed', true)
            ->callTableAction('forceDelete', $machine, data: ['confirm_value' => $machine->id_code]);

        $this->assertDatabaseMissing('machines', ['id' => $machine->id]);
        $this->assertDatabaseMissing('work_orders', ['id' => $workOrder->id]);

        $asiento = Activity::where('subject_type', Machine::class)
            ->where('subject_id', $machine->id)
            ->where('event', 'force_delete_impact')
            ->first();

        $this->assertNotNull($asiento, 'Tiene que quedar un asiento de impacto en la bitácora.');
        $this->assertSame(1, $asiento->properties['work_orders'] ?? null);
    }

    /* ------------------------------------------------------------------ *
     * 8. Hallazgo seguridad Medio: el forceDelete de la MÁQUINA (no el de
     *    la OT directamente) tiene que seguir borrando el archivo físico
     *    del adjunto. Antes de este fix, `Machine::forceDelete()` se llevaba
     *    `work_orders`/`work_order_attachments` por la cascada REAL de la
     *    base (`cascadeOnDelete()`), que no dispara `forceDeleting()` — el
     *    archivo quedaba huérfano en `storage/app`, sin ninguna fila que lo
     *    referencie.
     * ------------------------------------------------------------------ */
    public function test_force_deleting_the_machine_also_removes_the_attachment_file_of_its_work_orders(): void
    {
        Storage::fake('local');

        $machine = $this->emptyMachine();

        $workOrder = WorkOrder::create([
            'code' => 'WO-FD-'.random_int(100000, 999999),
            'machine_id' => $machine->id,
            'type' => 'corrective',
            'status' => 'open',
            'priority' => 'normal',
            'opened_at' => now()->toDateString(),
        ]);

        $path = 'work-order-attachments/'.$workOrder->id.'/factura.pdf';
        Storage::disk('local')->put($path, "%PDF-1.4\nfactura de prueba\n");

        WorkOrderAttachment::create([
            'work_order_id' => $workOrder->id,
            'type' => 'invoice',
            'path' => $path,
            'original_name' => 'factura.pdf',
        ]);

        $this->assertTrue(Storage::disk('local')->exists($path));

        $machine = $this->trash($machine);

        Livewire::actingAs($this->admin())
            ->test(ListMachines::class)
            ->filterTable('trashed', true)
            ->callTableAction('forceDelete', $machine, data: ['confirm_value' => $machine->id_code]);

        $this->assertDatabaseMissing('machines', ['id' => $machine->id]);
        $this->assertDatabaseMissing('work_orders', ['id' => $workOrder->id]);
        $this->assertDatabaseMissing('work_order_attachments', ['work_order_id' => $workOrder->id]);

        $this->assertFalse(
            Storage::disk('local')->exists($path),
            'El borrado definitivo de la máquina tiene que arrastrar el archivo físico del adjunto de su OT.'
        );
    }

    /* ------------------------------------------------------------------ *
     * 9. Hallazgo seguridad Medio: las fotos PROPIAS de la máquina (`image`
     *    y `gallery`, FileUpload sobre disk('public')) no tenían ningún
     *    borrado —ni observer ni cascada de base, porque no son una fila con
     *    FK— y quedaban descargables para siempre tras un borrado
     *    definitivo. Se escribe contenido real en el disco falso (no
     *    UploadedFile::fake()->create(), que reporta KB pero escribe 0
     *    bytes) para que `Storage::exists()` verifique un archivo de verdad,
     *    no una entrada vacía.
     * ------------------------------------------------------------------ */
    public function test_force_deleting_the_machine_also_removes_its_own_image_and_gallery_files(): void
    {
        Storage::fake('public');

        $imagePath = 'machines/images/foto-principal.jpg';
        $galleryPathA = 'machines/gallery/foto-a.jpg';
        $galleryPathB = 'machines/gallery/foto-b.jpg';

        Storage::disk('public')->put($imagePath, 'contenido real de la foto principal');
        Storage::disk('public')->put($galleryPathA, 'contenido real de la foto A');
        Storage::disk('public')->put($galleryPathB, 'contenido real de la foto B');

        $machine = $this->emptyMachine();
        $machine->forceFill([
            'image' => $imagePath,
            'gallery' => [$galleryPathA, $galleryPathB],
        ])->save();

        $this->assertTrue(Storage::disk('public')->exists($imagePath));
        $this->assertTrue(Storage::disk('public')->exists($galleryPathA));
        $this->assertTrue(Storage::disk('public')->exists($galleryPathB));

        $machine = $this->trash($machine);

        Livewire::actingAs($this->admin())
            ->test(ListMachines::class)
            ->filterTable('trashed', true)
            ->callTableAction('forceDelete', $machine, data: ['confirm_value' => $machine->id_code]);

        $this->assertDatabaseMissing('machines', ['id' => $machine->id]);

        $this->assertFalse(
            Storage::disk('public')->exists($imagePath),
            'El borrado definitivo de la máquina tiene que borrar su propia foto principal del disco público.'
        );
        $this->assertFalse(
            Storage::disk('public')->exists($galleryPathA),
            'El borrado definitivo de la máquina tiene que borrar sus propias fotos de galería del disco público.'
        );
        $this->assertFalse(Storage::disk('public')->exists($galleryPathB));
    }

    /**
     * Prueba de control del test anterior: sin la máquina en la papelera, el
     * "Force delete" nunca llega a montarse (mismo motivo que
     * `test_control_without_the_trashed_filter_the_action_never_mounts`), así
     * que las fotos deben seguir en el disco. Si este test fallara (archivos
     * borrados igual), significaría que algo más —no la acción de borrado
     * definitivo— los está tocando.
     */
    public function test_control_the_files_survive_a_soft_delete_of_the_machine(): void
    {
        Storage::fake('public');

        $imagePath = 'machines/images/foto-control.jpg';
        Storage::disk('public')->put($imagePath, 'contenido real de control');

        $machine = $this->emptyMachine();
        $machine->forceFill(['image' => $imagePath])->save();

        $machine->delete();

        $this->assertTrue(
            Storage::disk('public')->exists($imagePath),
            'Un borrado SUAVE (papelera) no tiene que tocar el archivo físico.'
        );
    }
}

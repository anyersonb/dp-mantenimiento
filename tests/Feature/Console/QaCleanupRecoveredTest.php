<?php

namespace Tests\Feature\Console;

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
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * RECREADO el 2026-09-09 durante el lote de Papelera (Lote A).
 *
 * El archivo original, `tests/Feature/Console/QaCleanupTest.php`, desapareció
 * del árbol de trabajo por su cuenta (sin `rm`, sin `git`) y quedó en un
 * estado "delete pending" de NTFS: `git status` lo marca `D`, Windows
 * confirma que no existe, pero recrearlo con ESE mismo nombre falla con
 * "Permission denied" tanto desde `git checkout` como desde `touch`/
 * `New-Item` directo. El contenido es el original (recuperado de
 * `git show HEAD:...`), sin cambios: sigue siendo válido porque
 * `QaCleanup::purgeAttachments()`/`purgeUsers()` ahora usan `forceDelete()`
 * (Papelera, Lote A le agregó SoftDeletes a `WorkOrderAttachment` y `User`;
 * sin ese cambio este comando dejaría datos de PRUEBA vivos en la papelera en
 * vez de purgarlos de verdad) y las aserciones de este test siguen siendo
 * `assertDatabaseMissing`, que un `forceDelete()` sigue cumpliendo igual.
 *
 * Etapa 06 — comando qa:cleanup. Cubre el requisito no negociable del brief:
 * "jamás debe poder borrar algo que no tenga el marcador QA-". El test crea,
 * de cada tipo de dato involucrado, un registro CON marcador y uno SIN
 * marcador, corre el comando, y verifica que solo el marcado desaparece.
 */
class QaCleanupRecoveredTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);
        Storage::fake('local');
    }

    private function makeLocation(): Location
    {
        return Location::create(['name' => 'Test Yard', 'slug' => 'test-yard-'.uniqid()]);
    }

    public function test_dry_run_reports_without_deleting_anything(): void
    {
        $location = $this->makeLocation();
        $qaMachine = Machine::create([
            'id_code' => 'QA-'.random_int(1000, 9999),
            'status' => 'active',
            'current_location_id' => $location->id,
        ]);
        $qaReading = HorometerReading::create(['machine_id' => $qaMachine->id, 'hours' => 5, 'read_at' => now()]);

        $countBefore = Machine::count();

        Artisan::call('qa:cleanup', ['--dry-run' => true]);

        $this->assertDatabaseHas('machines', ['id' => $qaMachine->id]);
        $this->assertDatabaseHas('horometer_readings', ['id' => $qaReading->id]);
        $this->assertSame($countBefore, Machine::count());
    }

    public function test_cleanup_removes_only_records_marked_qa_and_leaves_the_rest_untouched(): void
    {
        $location = $this->makeLocation();

        // --- Máquinas: una QA-, una real ---
        $qaMachine = Machine::create([
            'id_code' => 'QA-'.random_int(1000, 9999),
            'status' => 'active',
            'current_location_id' => $location->id,
        ]);
        $realMachine = Machine::create([
            'id_code' => 'EX-REAL-'.random_int(1000, 9999),
            'status' => 'active',
            'current_location_id' => $location->id,
        ]);

        // --- field_reports colgando de cada una ---
        $qaFieldReport = FieldReport::create(['machine_id' => $qaMachine->id, 'hours' => 10]);
        $realFieldReport = FieldReport::create(['machine_id' => $realMachine->id, 'hours' => 20]);

        // --- horometer_readings colgando de cada una ---
        $qaReading = HorometerReading::create(['machine_id' => $qaMachine->id, 'hours' => 5, 'read_at' => now()]);
        $realReading = HorometerReading::create(['machine_id' => $realMachine->id, 'hours' => 15, 'read_at' => now()]);

        // --- alerts y machine_parts colgando de cada una ---
        $qaAlert = Alert::create(['machine_id' => $qaMachine->id, 'type' => 'service', 'title' => 'QA alert', 'status' => 'open']);
        $realAlert = Alert::create(['machine_id' => $realMachine->id, 'type' => 'service', 'title' => 'Real alert', 'status' => 'open']);
        $qaPart = MachinePart::create(['machine_id' => $qaMachine->id, 'label' => 'QA part']);
        $realPart = MachinePart::create(['machine_id' => $realMachine->id, 'label' => 'Real part']);

        // --- Work orders: una por marcador propio, una por colgar de la máquina QA-, una real ---
        $qaWorkOrderByCode = WorkOrder::create(['code' => 'QA-WO-'.random_int(1000, 9999), 'machine_id' => $realMachine->id]);
        $qaWorkOrderByMachine = WorkOrder::create(['code' => 'WO-'.random_int(1000, 9999), 'machine_id' => $qaMachine->id]);
        $realWorkOrder = WorkOrder::create(['code' => 'WO-REAL-'.random_int(1000, 9999), 'machine_id' => $realMachine->id]);

        // --- Adjuntos: uno por cada OT de prueba y uno para la real, con archivo real en disco fake ---
        Storage::disk('local')->put('work-order-attachments/qa-1.jpg', 'qa-content');
        Storage::disk('local')->put('work-order-attachments/qa-2.jpg', 'qa-content-2');
        Storage::disk('local')->put('work-order-attachments/real.jpg', 'real-content');

        $qaAttachment1 = WorkOrderAttachment::create([
            'work_order_id' => $qaWorkOrderByCode->id,
            'type' => 'photo',
            'path' => 'work-order-attachments/qa-1.jpg',
        ]);
        $qaAttachment2 = WorkOrderAttachment::create([
            'work_order_id' => $qaWorkOrderByMachine->id,
            'type' => 'photo',
            'path' => 'work-order-attachments/qa-2.jpg',
        ]);
        $realAttachment = WorkOrderAttachment::create([
            'work_order_id' => $realWorkOrder->id,
            'type' => 'photo',
            'path' => 'work-order-attachments/real.jpg',
        ]);

        // --- Usuarios: uno con marcador en el email, uno real ---
        $qaUser = User::create([
            'name' => 'Tester',
            'email' => 'qa-tester+QA-1234@dp.local',
            'password' => bcrypt('secret'),
        ]);
        $qaUser->assignRole('taller');
        $realUser = User::where('email', 'admin@dp.local')->firstOrFail();

        Artisan::call('qa:cleanup');

        // --- Lo QA- desaparece ---
        $this->assertDatabaseMissing('machines', ['id' => $qaMachine->id]);
        $this->assertDatabaseMissing('field_reports', ['id' => $qaFieldReport->id]);
        $this->assertDatabaseMissing('horometer_readings', ['id' => $qaReading->id]);
        $this->assertDatabaseMissing('alerts', ['id' => $qaAlert->id]);
        $this->assertDatabaseMissing('machine_parts', ['id' => $qaPart->id]);
        $this->assertDatabaseMissing('work_orders', ['id' => $qaWorkOrderByCode->id]);
        $this->assertDatabaseMissing('work_orders', ['id' => $qaWorkOrderByMachine->id]);
        $this->assertDatabaseMissing('work_order_attachments', ['id' => $qaAttachment1->id]);
        $this->assertDatabaseMissing('work_order_attachments', ['id' => $qaAttachment2->id]);
        Storage::disk('local')->assertMissing('work-order-attachments/qa-1.jpg');
        Storage::disk('local')->assertMissing('work-order-attachments/qa-2.jpg');
        $this->assertDatabaseMissing('users', ['id' => $qaUser->id]);
        $this->assertDatabaseMissing('model_has_roles', ['model_id' => $qaUser->id, 'model_type' => User::class]);

        // --- Lo real sigue intacto ---
        $this->assertDatabaseHas('machines', ['id' => $realMachine->id]);
        $this->assertDatabaseHas('field_reports', ['id' => $realFieldReport->id]);
        $this->assertDatabaseHas('horometer_readings', ['id' => $realReading->id]);
        $this->assertDatabaseHas('alerts', ['id' => $realAlert->id]);
        $this->assertDatabaseHas('machine_parts', ['id' => $realPart->id]);
        $this->assertDatabaseHas('work_orders', ['id' => $realWorkOrder->id]);
        $this->assertDatabaseHas('work_order_attachments', ['id' => $realAttachment->id]);
        Storage::disk('local')->assertExists('work-order-attachments/real.jpg');
        $this->assertTrue($realUser->exists());
        $this->assertDatabaseHas('model_has_roles', ['model_id' => $realUser->id, 'model_type' => User::class]);
    }

    public function test_a_record_without_the_qa_marker_is_never_deleted(): void
    {
        $location = $this->makeLocation();
        $untouchable = Machine::create([
            'id_code' => 'NOQA-'.random_int(1000, 9999),
            'status' => 'active',
            'current_location_id' => $location->id,
        ]);

        Artisan::call('qa:cleanup');

        $this->assertDatabaseHas('machines', ['id' => $untouchable->id]);
    }

    public function test_it_reports_orphaned_activity_log_entries_without_deleting_them(): void
    {
        $activityLogTable = config('activitylog.table_name');

        DB::table($activityLogTable)->insert([
            'log_name' => 'default',
            'description' => 'updated',
            'subject_type' => Machine::class,
            'subject_id' => 999999,
            'properties' => '{}',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        Artisan::call('qa:cleanup');

        $this->assertDatabaseHas($activityLogTable, ['subject_type' => Machine::class, 'subject_id' => 999999]);
    }
}

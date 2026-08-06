<?php

namespace Tests\Feature\Security;

use App\Filament\Resources\WorkOrderResource\Pages\EditWorkOrder;
use App\Filament\Resources\WorkOrderResource\RelationManagers\AttachmentsRelationManager;
use App\Models\Location;
use App\Models\Machine;
use App\Models\User;
use App\Models\WorkOrder;
use App\Models\WorkOrderAttachment;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Hallazgo E6-17 — dos adjuntos con el mismo nombre de archivo se pisaban.
 *
 * `FileUpload` guardaba con `preserveFilenames()` en un único directorio
 * compartido por TODAS las OT. Comprobado en su momento sobre el disco: subir
 * dos veces `factura.pdf` dejaba un solo archivo, con el contenido del
 * segundo, y las dos filas de `work_order_attachments` apuntando ahí — la
 * primera OT mostraba la factura de la otra.
 *
 * No es un detalle cosmético: con facturas es pérdida silenciosa de evidencia
 * de costo (el mismo criterio de A5, "el archivo es la evidencia"), y el
 * nombre repetido es lo más probable del mundo: dos talleres subiendo
 * "factura.pdf", o el mismo proveedor con su plantilla.
 *
 * Estos tests miden el DISCO y la BASE, nunca el mensaje de pantalla: lo que
 * el defecto rompía era el archivo, y la pantalla decía "Creado" igual.
 */
class AttachmentsDoNotOverwriteEachOtherTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Contenidos distintos a propósito: es la única forma de detectar el pisado.
     *
     * OJO con `UploadedFile::fake()->create($nombre, $kb)`: ese helper **reporta**
     * los kilobytes pero escribe el archivo VACÍO, así que medir el tamaño del
     * archivo guardado da 0 en los dos casos y la aserción "los contenidos son
     * distintos" pasaría sin probar nada. Hay que subir contenido de verdad
     * (`createWithContent`) y comparar lo que quedó en el disco.
     *
     * El `%PDF-1.4` del principio no es decorativo: la validación de tipo mira
     * el mime que se adivina del contenido, y sin esa firma el PDF se rechaza.
     */
    private const PDF_PRIMERA = "%PDF-1.4\nfactura de la primera OT\n";

    private const PDF_SEGUNDA = "%PDF-1.4\nfactura de la segunda OT, con otro contenido\n";

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);
        Storage::fake('local');
    }

    protected function admin(): User
    {
        return User::where('email', 'admin@dp.local')->firstOrFail();
    }

    protected function workOrder(string $code): WorkOrder
    {
        $location = Location::create(['name' => 'Test Yard', 'slug' => 'test-yard-'.uniqid()]);

        $machine = Machine::create([
            'id_code' => 'QA-'.random_int(1000, 9999),
            'status' => 'active',
            'current_location_id' => $location->id,
        ]);

        return WorkOrder::create([
            'code' => $code,
            'machine_id' => $machine->id,
            'type' => 'corrective',
            'status' => 'open',
            'priority' => 'normal',
            'opened_at' => now()->toDateString(),
        ]);
    }

    /**
     * Sube un adjunto por el mismo camino que usa una persona: la acción
     * "crear" del relation manager, montado sobre su OT dueña.
     */
    protected function subirFactura(WorkOrder $workOrder, string $nombre, string $contenido): void
    {
        Livewire::actingAs($this->admin())
            ->test(AttachmentsRelationManager::class, [
                'ownerRecord' => $workOrder,
                'pageClass' => EditWorkOrder::class,
            ])
            ->callTableAction('create', data: [
                'type' => 'invoice',
                'path' => [UploadedFile::fake()->createWithContent($nombre, $contenido)],
            ])
            ->assertHasNoTableActionErrors();
    }

    public function test_two_invoices_with_the_same_name_on_different_work_orders_both_survive(): void
    {
        $primera = $this->workOrder('WO-E617-A');
        $segunda = $this->workOrder('WO-E617-B');

        $this->subirFactura($primera, 'factura.pdf', self::PDF_PRIMERA);
        $this->subirFactura($segunda, 'factura.pdf', self::PDF_SEGUNDA);

        $rutaA = WorkOrderAttachment::where('work_order_id', $primera->id)->sole()->path;
        $rutaB = WorkOrderAttachment::where('work_order_id', $segunda->id)->sole()->path;

        $this->assertNotSame($rutaA, $rutaB, 'Las dos filas apuntan al MISMO archivo: una factura pisó a la otra.');

        $this->assertTrue(Storage::disk('local')->exists($rutaA));
        $this->assertTrue(Storage::disk('local')->exists($rutaB));

        // Cada fila conserva SU contenido y no el del último que subió.
        $this->assertSame(self::PDF_PRIMERA, Storage::disk('local')->get($rutaA));
        $this->assertSame(self::PDF_SEGUNDA, Storage::disk('local')->get($rutaB));
    }

    public function test_two_invoices_with_the_same_name_on_the_same_work_order_both_survive(): void
    {
        $workOrder = $this->workOrder('WO-E617-C');

        $this->subirFactura($workOrder, 'factura.pdf', self::PDF_PRIMERA);
        $this->subirFactura($workOrder, 'factura.pdf', self::PDF_SEGUNDA);

        $rutas = WorkOrderAttachment::where('work_order_id', $workOrder->id)->pluck('path');

        $this->assertCount(2, $rutas);
        $this->assertCount(2, $rutas->unique(), 'Dentro de una misma OT, el segundo archivo pisó al primero.');

        foreach ($rutas as $ruta) {
            $this->assertTrue(Storage::disk('local')->exists($ruta));
        }

        $this->assertEqualsCanonicalizing(
            [self::PDF_PRIMERA, self::PDF_SEGUNDA],
            $rutas->map(fn (string $ruta) => Storage::disk('local')->get($ruta))->all()
        );
    }

    /**
     * La unicidad la da la CARPETA, no un nombre mutilado: el nombre que ve la
     * administradora en la tabla —y el que recibe al descargar— sigue siendo el
     * que traía el archivo.
     */
    public function test_the_human_readable_name_is_preserved(): void
    {
        $workOrder = $this->workOrder('WO-E617-D');

        $this->subirFactura($workOrder, 'factura-proveedor.pdf', self::PDF_PRIMERA);

        $adjunto = WorkOrderAttachment::where('work_order_id', $workOrder->id)->sole();

        $this->assertSame('factura-proveedor.pdf', $adjunto->original_name);
        $this->assertSame('factura-proveedor.pdf', basename($adjunto->path));
    }

    /**
     * Centinela del layout: el archivo tiene que quedar bajo el directorio de
     * SU orden de trabajo. Si alguien vuelve al directorio plano, esto falla.
     */
    public function test_the_stored_path_is_scoped_to_its_work_order(): void
    {
        $workOrder = $this->workOrder('WO-E617-E');

        $this->subirFactura($workOrder, 'factura.pdf', self::PDF_PRIMERA);

        $ruta = WorkOrderAttachment::where('work_order_id', $workOrder->id)->sole()->path;

        $this->assertStringStartsWith('work-order-attachments/'.$workOrder->id.'/', $ruta);

        // Y no directamente dentro de esa carpeta: cada archivo lleva la suya,
        // que es lo que garantiza que dos nombres iguales no choquen.
        $this->assertSame(
            4,
            count(explode('/', $ruta)),
            'Se esperaba work-order-attachments/{ot}/{unico}/{archivo}, llegó: '.$ruta
        );
    }
}

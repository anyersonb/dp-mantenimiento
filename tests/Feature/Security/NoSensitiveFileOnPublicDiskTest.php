<?php

namespace Tests\Feature\Security;

use App\Models\Location;
use App\Models\Machine;
use App\Models\Quote;
use App\Models\WorkOrder;
use App\Models\WorkOrderAttachment;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Etapa 05 (hallazgo A5, cierre): la migracion 090000 copio los archivos a
 * disk('local') pero dejaba el original en disk('public') -- por eso
 * GET /storage/quotes/demo-quote.pdf seguia respondiendo 200 sin sesion
 * pese a que la app ya servia todo por la ruta autorizada. La migracion
 * 100000 borra ese original una vez verificado el hash en tiempo de
 * ejecucion.
 *
 * Este test fija el invariante hacia adelante: recorre los `path` que
 * referencia la BD (quotes.file_path, work_order_attachments.path) y falla
 * si cualquiera de ellos es alcanzable en disk('public'). Es el que evita
 * que una proxima subida, un `preserveFilenames()` mal puesto, o una
 * migracion futura, vuelva a dejar la puerta vieja abierta sin que nada lo
 * note.
 */
class NoSensitiveFileOnPublicDiskTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');
        Storage::fake('local');
    }

    public function test_no_quote_file_referenced_in_the_database_exists_on_the_public_disk(): void
    {
        // Escenario correcto: el archivo esta migrado, solo vive en disk('local').
        Storage::disk('local')->put('quotes/migrated-quote.pdf', 'contenido privado de la cotizacion');

        Quote::create([
            'title' => 'Migrated quote',
            'file_path' => 'quotes/migrated-quote.pdf',
        ]);

        $this->assertNoLeakedPaths(
            Quote::query()->whereNotNull('file_path')->pluck('file_path'),
            'quotes.file_path'
        );
    }

    public function test_no_work_order_attachment_referenced_in_the_database_exists_on_the_public_disk(): void
    {
        $location = Location::create(['name' => 'Test Yard', 'slug' => 'test-yard-'.uniqid()]);
        $machine = Machine::create(['id_code' => 'QA-'.random_int(1000, 9999), 'status' => 'active', 'current_location_id' => $location->id]);
        $workOrder = WorkOrder::create([
            'code' => 'WO-'.random_int(1000, 9999),
            'machine_id' => $machine->id,
            'type' => 'corrective',
            'status' => 'open',
            'priority' => 'normal',
            'opened_at' => now()->toDateString(),
        ]);

        Storage::disk('local')->put('work-order-attachments/migrated-invoice.pdf', 'contenido privado de la factura');

        WorkOrderAttachment::create([
            'work_order_id' => $workOrder->id,
            'type' => 'invoice',
            'path' => 'work-order-attachments/migrated-invoice.pdf',
            'original_name' => 'migrated-invoice.pdf',
        ]);

        $this->assertNoLeakedPaths(
            WorkOrderAttachment::query()->whereNotNull('path')->pluck('path'),
            'work_order_attachments.path'
        );
    }

    /**
     * Prueba negativa: si un path referenciado en BD SI existe en
     * disk('public') (el escenario exacto del hallazgo A5 reabierto), el
     * detector debe encontrarlo. Sin esta prueba, las dos de arriba serian
     * tautologicas -- pasarian aunque el filtro estuviera roto y nunca
     * detectara nada.
     */
    public function test_the_detector_actually_flags_a_file_that_leaks_on_the_public_disk(): void
    {
        Storage::disk('local')->put('quotes/leaked.pdf', 'contenido');
        Storage::disk('public')->put('quotes/leaked.pdf', 'contenido'); // simula la puerta vieja abierta

        Quote::create([
            'title' => 'Leaked quote',
            'file_path' => 'quotes/leaked.pdf',
        ]);

        $leaked = $this->leakedPaths(
            Quote::query()->whereNotNull('file_path')->pluck('file_path')
        );

        $this->assertTrue($leaked->contains('quotes/leaked.pdf'));
    }

    /**
     * @param  Collection<int, string>|EloquentCollection<int, string>  $paths
     */
    private function leakedPaths($paths): Collection
    {
        return collect($paths)->filter(fn (string $path) => Storage::disk('public')->exists($path))->values();
    }

    /**
     * @param  Collection<int, string>|EloquentCollection<int, string>  $paths
     */
    private function assertNoLeakedPaths($paths, string $column): void
    {
        $leaked = $this->leakedPaths($paths);

        $this->assertTrue(
            $leaked->isEmpty(),
            "Hallazgo A5 reabierto: estos {$column} son alcanzables sin autenticacion por la URL publica de storage: ".$leaked->implode(', ')
        );
    }
}

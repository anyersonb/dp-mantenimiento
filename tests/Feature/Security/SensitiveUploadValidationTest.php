<?php

namespace Tests\Feature\Security;

use App\Filament\Resources\QuoteResource\Pages\CreateQuote;
use App\Filament\Resources\WorkOrderResource\Pages\EditWorkOrder;
use App\Filament\Resources\WorkOrderResource\RelationManagers\AttachmentsRelationManager;
use App\Models\Location;
use App\Models\Machine;
use App\Models\User;
use App\Models\WorkOrder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Etapa 05, Bloque 4 -- auditoria de la ruta de SUBIDA (nunca antes
 * verificada por QA). Cubre lo que pide el hallazgo A5 sobre la subida en
 * si misma: tipos permitidos, tamano maximo, archivo corrupto, nombres con
 * acentos/enie, y el caso puntual de doble extension ".pdf.php".
 */
class SensitiveUploadValidationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Cotizaciones apagado por defecto desde el 2026-08-06
        // (config/features.php). Sin esto, CreateQuote::class aborta 403 al
        // montar y los dos tests de abajo dejarian de auditar la subida.
        config(['features.quotes' => true]);

        $this->seed(RolesAndPermissionsSeeder::class);
        Storage::fake('local');
    }

    protected function admin(): User
    {
        return User::where('email', 'admin@dp.local')->firstOrFail();
    }

    protected function workOrder(): WorkOrder
    {
        $location = Location::create(['name' => 'Test Yard', 'slug' => 'test-yard-'.uniqid()]);

        $machine = Machine::create([
            'id_code' => 'QA-'.random_int(1000, 9999),
            'status' => 'active',
            'current_location_id' => $location->id,
        ]);

        return WorkOrder::create([
            'code' => 'WO-'.random_int(1000, 9999),
            'machine_id' => $machine->id,
            'type' => 'corrective',
            'status' => 'open',
            'priority' => 'normal',
            'opened_at' => now()->toDateString(),
        ]);
    }

    /* ------------------------------------------------------------------ *
     * Adjuntos de OT
     * ------------------------------------------------------------------ */

    public function test_a_legitimate_pdf_invoice_is_accepted(): void
    {
        $workOrder = $this->workOrder();

        Livewire::actingAs($this->admin())
            ->test(AttachmentsRelationManager::class, [
                'ownerRecord' => $workOrder,
                'pageClass' => EditWorkOrder::class,
            ])
            ->callTableAction('create', data: [
                'type' => 'invoice',
                'path' => [UploadedFile::fake()->create('vendor-invoice.pdf', 200, 'application/pdf')],
            ])
            ->assertHasNoTableActionErrors();

        $this->assertDatabaseHas('work_order_attachments', [
            'work_order_id' => $workOrder->id,
            'type' => 'invoice',
        ]);
    }

    public function test_a_double_extension_pdf_php_upload_is_rejected(): void
    {
        $workOrder = $this->workOrder();

        Livewire::actingAs($this->admin())
            ->test(AttachmentsRelationManager::class, [
                'ownerRecord' => $workOrder,
                'pageClass' => EditWorkOrder::class,
            ])
            ->callTableAction('create', data: [
                'type' => 'invoice',
                // Bytes/mime declarados como PDF valido -- el ataque no es
                // el contenido, es el nombre: si Filament preserva o
                // deriva la extension del nombre original, esto podria
                // terminar guardado como ".php".
                'path' => [UploadedFile::fake()->create('vendor-invoice.pdf.php', 200, 'application/pdf')],
            ])
            ->assertHasTableActionErrors(['path']);

        $this->assertDatabaseMissing('work_order_attachments', ['work_order_id' => $workOrder->id]);
    }

    public function test_a_corrupt_file_declaring_the_wrong_content_type_is_rejected(): void
    {
        $workOrder = $this->workOrder();

        Livewire::actingAs($this->admin())
            ->test(AttachmentsRelationManager::class, [
                'ownerRecord' => $workOrder,
                'pageClass' => EditWorkOrder::class,
            ])
            ->callTableAction('create', data: [
                'type' => 'photo',
                // Extension .pdf pero contenido/mime que no es realmente un
                // PDF ni una imagen -- simula un archivo corrupto o
                // disfrazado; no basta con confiar en el nombre.
                'path' => [UploadedFile::fake()->create('corrupt.pdf', 50, 'text/plain')],
            ])
            ->assertHasTableActionErrors(['path']);

        $this->assertDatabaseMissing('work_order_attachments', ['work_order_id' => $workOrder->id]);
    }

    public function test_a_file_over_the_size_limit_is_rejected(): void
    {
        $workOrder = $this->workOrder();

        Livewire::actingAs($this->admin())
            ->test(AttachmentsRelationManager::class, [
                'ownerRecord' => $workOrder,
                'pageClass' => EditWorkOrder::class,
            ])
            ->callTableAction('create', data: [
                'type' => 'photo',
                'path' => [UploadedFile::fake()->create('huge.pdf', 11000, 'application/pdf')], // > 10240 KB
            ])
            ->assertHasTableActionErrors(['path']);
    }

    public function test_a_filename_with_accents_and_enie_is_accepted(): void
    {
        $workOrder = $this->workOrder();

        Livewire::actingAs($this->admin())
            ->test(AttachmentsRelationManager::class, [
                'ownerRecord' => $workOrder,
                'pageClass' => EditWorkOrder::class,
            ])
            ->callTableAction('create', data: [
                'type' => 'photo',
                'path' => [UploadedFile::fake()->create('Reparación_Peña_ñoño.pdf', 100, 'application/pdf')],
            ])
            ->assertHasNoTableActionErrors();

        $this->assertDatabaseHas('work_order_attachments', [
            'work_order_id' => $workOrder->id,
            'type' => 'photo',
        ]);
    }

    /* ------------------------------------------------------------------ *
     * Cotizaciones (mismo componente FileUpload, mismo riesgo)
     * ------------------------------------------------------------------ */

    public function test_quote_upload_also_rejects_the_double_extension(): void
    {
        Livewire::actingAs($this->admin())
            ->test(CreateQuote::class)
            ->fillForm([
                'title' => 'Quote with a malicious file name',
                'file_path' => [UploadedFile::fake()->create('quote.pdf.php', 100, 'application/pdf')],
            ])
            ->call('create')
            ->assertHasFormErrors(['file_path']);

        $this->assertDatabaseMissing('quotes', ['title' => 'Quote with a malicious file name']);
    }

    public function test_quote_upload_accepts_a_legitimate_pdf(): void
    {
        Livewire::actingAs($this->admin())
            ->test(CreateQuote::class)
            ->fillForm([
                'title' => 'Legitimate hydraulic pump quote',
                'file_path' => [UploadedFile::fake()->create('quote.pdf', 100, 'application/pdf')],
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertDatabaseHas('quotes', ['title' => 'Legitimate hydraulic pump quote']);
    }
}

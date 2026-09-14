<?php

namespace Tests\Feature\Security;

use App\Filament\Resources\FleetAttachmentResource\Pages\CreateFleetAttachment;
use App\Models\FleetAttachment;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Subida de `documents` en el módulo de Complementos.
 *
 * `->acceptedFileTypes()` de Filament solo mira el MIME que el cliente
 * DECLARA (Etapa 05, hallazgo A5); no es la barrera real contra un archivo
 * con extensión peligrosa que declara un mimetype permitido. Cada test de
 * rechazo de abajo declara un mimetype de la whitelist (pdf/png) a propósito,
 * para probar que lo que realmente bloquea es `RejectsDangerousUploadExtensions`
 * mirando la extensión REAL del nombre, no el mimetype.
 *
 * `UploadedFile::fake()->create($nombre, $kb)` reporta kilobytes pero escribe
 * el archivo VACÍO — no sirve para probar contenido. El test de aceptación
 * usa `createWithContent()` y compara el contenido real guardado en disco.
 */
class FleetAttachmentUploadValidationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);
        Storage::fake('public');
    }

    private function admin(): User
    {
        return User::where('email', 'admin@dp.local')->firstOrFail();
    }

    public function test_a_legitimate_pdf_document_is_accepted_and_its_real_content_is_stored(): void
    {
        $contenido = "%PDF-1.4\nmanual del complemento, contenido real\n";

        Livewire::actingAs($this->admin())
            ->test(CreateFleetAttachment::class)
            ->fillForm([
                'id_code' => 'DOC-OK',
                'status' => 'active',
                'documents' => [UploadedFile::fake()->createWithContent('manual.pdf', $contenido)],
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $attachment = FleetAttachment::where('id_code', 'DOC-OK')->firstOrFail();
        $paths = (array) $attachment->documents;

        $this->assertNotEmpty($paths, 'El documento subido debe quedar guardado en la columna documents.');
        $this->assertTrue(Storage::disk('public')->exists($paths[0]));

        // Comparación de CONTENIDO real, no solo de existencia: si el arnés
        // estuviera escribiendo el archivo vacío (UploadedFile::fake()->create()
        // sin contenido), esta aserción fallaría y delataría el falso positivo.
        $this->assertSame(
            $contenido,
            Storage::disk('public')->get($paths[0]),
            'El archivo guardado en disco debe tener el mismo contenido que se subió.'
        );
    }

    public function test_a_double_extension_pdf_php_document_is_rejected(): void
    {
        Livewire::actingAs($this->admin())
            ->test(CreateFleetAttachment::class)
            ->fillForm([
                'id_code' => 'DOC-PHP',
                'status' => 'active',
                // Mimetype declarado como PDF válido (pasaría acceptedFileTypes);
                // el ataque está en el nombre, no en el contenido.
                'documents' => [UploadedFile::fake()->create('manual.pdf.php', 100, 'application/pdf')],
            ])
            ->call('create')
            ->assertHasFormErrors(['documents']);

        $this->assertDatabaseMissing('fleet_attachments', ['id_code' => 'DOC-PHP']);
    }

    public function test_an_svg_disguised_with_an_allowed_mimetype_is_rejected(): void
    {
        Livewire::actingAs($this->admin())
            ->test(CreateFleetAttachment::class)
            ->fillForm([
                'id_code' => 'DOC-SVG',
                'status' => 'active',
                // Mime declarado 'image/png' (está en la whitelist de
                // acceptedFileTypes): si esa fuera la única barrera, esto
                // pasaría. La extensión real .svg es la que lo frena.
                'documents' => [UploadedFile::fake()->create('logo.svg', 50, 'image/png')],
            ])
            ->call('create')
            ->assertHasFormErrors(['documents']);

        $this->assertDatabaseMissing('fleet_attachments', ['id_code' => 'DOC-SVG']);
    }

    public function test_an_html_file_disguised_as_pdf_is_rejected(): void
    {
        Livewire::actingAs($this->admin())
            ->test(CreateFleetAttachment::class)
            ->fillForm([
                'id_code' => 'DOC-HTML',
                'status' => 'active',
                'documents' => [UploadedFile::fake()->create('reporte.html', 50, 'application/pdf')],
            ])
            ->call('create')
            ->assertHasFormErrors(['documents']);

        $this->assertDatabaseMissing('fleet_attachments', ['id_code' => 'DOC-HTML']);
    }

    public function test_a_pht_file_disguised_as_pdf_is_rejected(): void
    {
        Livewire::actingAs($this->admin())
            ->test(CreateFleetAttachment::class)
            ->fillForm([
                'id_code' => 'DOC-PHT',
                'status' => 'active',
                'documents' => [UploadedFile::fake()->create('shell.pht', 50, 'application/pdf')],
            ])
            ->call('create')
            ->assertHasFormErrors(['documents']);

        $this->assertDatabaseMissing('fleet_attachments', ['id_code' => 'DOC-PHT']);
    }
}

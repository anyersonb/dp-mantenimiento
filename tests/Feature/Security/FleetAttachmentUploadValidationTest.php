<?php

namespace Tests\Feature\Security;

use App\Filament\Resources\FleetAttachmentResource\Pages\CreateFleetAttachment;
use App\Models\FleetAttachment;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Filament\Forms\Components\Section;
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
 *
 * `documents` vive en disk('local') desde el fix del hallazgo Alto (auditoría
 * de seguridad post 01e6a24e) -- antes vivía en 'public' sin ninguna capa de
 * autorización.
 */
class FleetAttachmentUploadValidationTest extends TestCase
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
        $this->assertTrue(Storage::disk('local')->exists($paths[0]));

        // Comparación de CONTENIDO real, no solo de existencia: si el arnés
        // estuviera escribiendo el archivo vacío (UploadedFile::fake()->create()
        // sin contenido), esta aserción fallaría y delataría el falso positivo.
        $this->assertSame(
            $contenido,
            Storage::disk('local')->get($paths[0]),
            'El archivo guardado en disco debe tener el mismo contenido que se subió.'
        );

        // `document_names` (hallazgo Alto): guarda el nombre ORIGINAL del
        // archivo, porque en disco queda con un nombre generado (ULID). Sin
        // esto, la ruta de descarga no podría devolver "manual.pdf" al
        // usuario.
        $this->assertSame(
            'manual.pdf',
            $attachment->document_names[$paths[0]] ?? null,
            'document_names debe guardar el nombre original del archivo subido.'
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

    /**
     * Hallazgo de usabilidad (mismo lote de auditoría, post 01e6a24e):
     * Filament v3 no auto-expande una `Section` colapsada cuando un campo
     * de adentro falla la validación (verificado por código, sin ningún
     * hook de error en Section.php ni en su blade view). Con "Imágenes" y
     * "Documentos" colapsadas de entrada, un archivo rechazado dejaba su
     * mensaje de error invisible hasta que el usuario abriera la sección a
     * mano -- la misma trampa por la que el cliente se quejó en repuestos
     * ("no puedo eliminarlo y no sé por qué"), ahora en el módulo pensado
     * justamente para cargar fotos y documentos (el camino principal, no
     * un caso raro). "Técnico" sí puede seguir colapsada: no tiene ninguna
     * validación que pueda fallar en silencio ahí.
     */
    public function test_the_images_and_documents_sections_do_not_start_collapsed(): void
    {
        $form = Livewire::actingAs($this->admin())
            ->test(CreateFleetAttachment::class)
            ->instance()
            ->getForm('form');

        $sections = collect($form->getComponents())
            ->filter(fn ($component) => $component instanceof Section)
            ->keyBy(fn (Section $section) => $section->getHeading());

        $this->assertFalse(
            $sections[__('fleet.images')]->isCollapsed(),
            'La sección "Imágenes" no debe nacer colapsada.'
        );
        $this->assertFalse(
            $sections[__('fleet.attachment_documents')]->isCollapsed(),
            'La sección "Documentos" no debe nacer colapsada: un archivo rechazado dejaría su error invisible.'
        );
        $this->assertTrue(
            $sections[__('fleet.attachment_technical')]->isCollapsed(),
            'La sección "Técnico" sí puede seguir colapsada: ahí no hay validación que pueda fallar.'
        );
    }

    /**
     * Complemento del test de arriba: no basta con que la sección no nazca
     * colapsada -- el mensaje de error real tiene que llegar al HTML
     * renderizado. Antes del fix, este mismo escenario (documento
     * rechazado) ya hacía fallar la validación en el servidor
     * (`assertHasFormErrors`), pero el texto quedaba dentro de una sección
     * cerrada, invisible para el usuario sin un clic extra.
     *
     * `shell.php.pdf` (no `manual.pdf.php`, como en el test de rechazo de
     * arriba) a propósito: bajo `runningUnitTests()` el MIME que valida
     * `->acceptedFileTypes()` sale del NOMBRE del archivo, y con extensión
     * FINAL ".pdf" ese chequeo pasa igual que uno legítimo -- así el único
     * error que puede aparecer en el HTML es el de
     * `RejectsDangerousUploadExtensions` (detecta "php" como segmento
     * intermedio peligroso), que es el que este test necesita ver
     * literalmente en el render, no cualquier error del campo.
     */
    public function test_a_rejected_document_shows_its_error_message_in_the_rendered_form(): void
    {
        Livewire::actingAs($this->admin())
            ->test(CreateFleetAttachment::class)
            ->fillForm([
                'id_code' => 'DOC-VISIBLE-ERROR',
                'status' => 'active',
                'documents' => [UploadedFile::fake()->create('shell.php.pdf', 100, 'application/pdf')],
            ])
            ->call('create')
            ->assertHasFormErrors(['documents'])
            ->assertSee(__('wo.invalid_upload_extension'));

        $this->assertDatabaseMissing('fleet_attachments', ['id_code' => 'DOC-VISIBLE-ERROR']);
    }
}

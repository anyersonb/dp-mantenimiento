<?php

namespace Tests\Feature\Security;

use App\Models\FleetAttachment;
use App\Models\Location;
use App\Models\Machine;
use App\Models\Quote;
use App\Models\User;
use App\Models\WorkOrder;
use App\Models\WorkOrderAttachment;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Hallazgo A5 (QA Etapa 05, Bloque 4): "GET /storage/quotes/demo-quote.pdf
 * responde 200 sin sesion" -- todo lo subido vivia en disk('public'),
 * servido por el servidor web sin ninguna capa de autorizacion. Fotos y
 * FACTURAS de OT y archivos de cotizacion pasan a disk('local') (privado)
 * y se sirven solo por rutas autorizadas:
 * - attachments.download: exige "view_fleet" siempre, y ademas
 *   "view_costs" cuando el adjunto es type=invoice (evidencia de costos).
 * - quotes.public.file: publica a proposito (link compartible por
 *   share_token, sin cuenta), pero respeta el vencimiento (expires_at).
 *
 * Hallazgo Alto (auditoría de seguridad, módulo Complementos, post
 * 01e6a24e): los `documents` de FleetAttachment vivían en disk('public') sin
 * ninguna capa de autorización -- mismo patrón que A5, cubierto acá abajo.
 */
class SensitiveUploadsAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // El módulo de cotizaciones está apagado por defecto desde el
        // 2026-08-06 (config/features.php). Se enciende acá para no perder la
        // cobertura del hallazgo A5 (disco privado + vencimiento del link) si
        // alguna vez se reactiva. Los adjuntos de OT no dependen del flag.
        config(['features.quotes' => true]);

        $this->seed(RolesAndPermissionsSeeder::class);
        Storage::fake('local');
    }

    protected function workOrderWithAttachment(string $type): WorkOrderAttachment
    {
        $location = Location::create(['name' => 'Test Yard', 'slug' => 'test-yard-'.uniqid()]);

        $machine = Machine::create([
            'id_code' => 'QA-'.random_int(1000, 9999),
            'status' => 'active',
            'current_location_id' => $location->id,
        ]);

        $workOrder = WorkOrder::create([
            'code' => 'WO-'.random_int(1000, 9999),
            'machine_id' => $machine->id,
            'type' => 'corrective',
            'status' => 'open',
            'priority' => 'normal',
            'opened_at' => now()->toDateString(),
        ]);

        Storage::disk('local')->put('work-order-attachments/secret-file.pdf', 'contenido privado del archivo');

        return WorkOrderAttachment::create([
            'work_order_id' => $workOrder->id,
            'type' => $type,
            'path' => 'work-order-attachments/secret-file.pdf',
            'original_name' => 'secret-file.pdf',
        ]);
    }

    /* ------------------------------------------------------------------ *
     * Adjuntos de OT
     * ------------------------------------------------------------------ */

    public function test_an_anonymous_visitor_cannot_download_a_work_order_attachment(): void
    {
        $attachment = $this->workOrderWithAttachment('invoice');

        $response = $this->get(route('attachments.download', $attachment));

        $response->assertStatus(302); // redirigido al login por el middleware "auth"
        $this->assertNotEquals(200, $response->getStatusCode());
    }

    public function test_a_role_without_view_costs_gets_403_on_an_invoice_attachment(): void
    {
        $attachment = $this->workOrderWithAttachment('invoice');
        $foreman = User::where('email', 'foreman@dp.local')->firstOrFail(); // view_fleet, sin view_costs

        $response = $this->actingAs($foreman)->get(route('attachments.download', $attachment));

        $response->assertForbidden();
    }

    public function test_a_role_with_view_costs_can_download_an_invoice_attachment(): void
    {
        $attachment = $this->workOrderWithAttachment('invoice');
        $taller = User::where('email', 'taller@dp.local')->firstOrFail(); // view_fleet + view_costs

        $response = $this->actingAs($taller)->get(route('attachments.download', $attachment));

        $response->assertOk();
    }

    public function test_a_role_without_view_costs_can_still_download_a_photo_attachment(): void
    {
        $attachment = $this->workOrderWithAttachment('photo');
        $foreman = User::where('email', 'foreman@dp.local')->firstOrFail();

        $response = $this->actingAs($foreman)->get(route('attachments.download', $attachment));

        $response->assertOk();
    }

    public function test_a_user_without_view_fleet_gets_403_even_on_a_photo(): void
    {
        $attachment = $this->workOrderWithAttachment('photo');
        $userWithoutPermissions = User::factory()->create(['active' => true]);

        $response = $this->actingAs($userWithoutPermissions)->get(route('attachments.download', $attachment));

        $response->assertForbidden();
    }

    /* ------------------------------------------------------------------ *
     * Cotizaciones (link publico por share_token)
     * ------------------------------------------------------------------ */

    protected function admin(): User
    {
        return User::where('email', 'admin@dp.local')->firstOrFail();
    }

    public function test_a_valid_share_token_downloads_the_quote_file(): void
    {
        Storage::disk('local')->put('quotes/valid-quote.pdf', 'contenido de la cotizacion');

        $quote = Quote::create([
            'title' => 'Engine overhaul quote',
            'uploaded_by' => $this->admin()->id,
            'file_path' => 'quotes/valid-quote.pdf',
        ]);

        $response = $this->get(route('quotes.public.file', $quote->share_token));

        $response->assertOk();
    }

    public function test_an_unknown_share_token_returns_404_for_the_file_route(): void
    {
        $this->get('/quotes/does-not-exist-token/archivo')->assertNotFound();
    }

    public function test_an_expired_quote_does_not_deliver_the_file_even_with_the_right_token(): void
    {
        Storage::disk('local')->put('quotes/expired-quote.pdf', 'contenido vencido');

        $quote = Quote::create([
            'title' => 'Old quote',
            'uploaded_by' => $this->admin()->id,
            'file_path' => 'quotes/expired-quote.pdf',
            'expires_at' => now()->subDay(),
        ]);

        $response = $this->get(route('quotes.public.file', $quote->share_token));

        $response->assertNotFound();
    }

    /* ------------------------------------------------------------------ *
     * Documentos de FleetAttachment (módulo Complementos, hallazgo Alto)
     * ------------------------------------------------------------------ */

    protected function attachmentWithDocument(): FleetAttachment
    {
        Storage::disk('local')->put('fleet-attachments/documents/01SECPROBE.pdf', 'contenido privado del manual');

        return FleetAttachment::create([
            'id_code' => 'DOC-SEC-'.random_int(100000, 999999),
            'status' => 'active',
            'documents' => ['fleet-attachments/documents/01SECPROBE.pdf'],
            'document_names' => ['fleet-attachments/documents/01SECPROBE.pdf' => 'manual-original.pdf'],
        ]);
    }

    public function test_an_anonymous_visitor_cannot_download_a_fleet_attachment_document(): void
    {
        $attachment = $this->attachmentWithDocument();

        $response = $this->get(route('fleet-attachments.documents.download', [$attachment, 0]));

        $response->assertStatus(302); // redirigido al login por el middleware "auth"
        $this->assertNotEquals(200, $response->getStatusCode());
    }

    public function test_a_role_without_view_attachments_gets_403_on_a_document(): void
    {
        $attachment = $this->attachmentWithDocument();
        $userWithoutPermissions = User::factory()->create(['active' => true]);

        $response = $this->actingAs($userWithoutPermissions)
            ->get(route('fleet-attachments.documents.download', [$attachment, 0]));

        $response->assertForbidden();
    }

    public function test_a_role_with_view_attachments_downloads_the_real_document_with_its_original_name(): void
    {
        $attachment = $this->attachmentWithDocument();
        // foreman: view_attachments, sin manage_attachments ni access_panel --
        // sirve para probar que view_attachments basta, sin depender de un
        // permiso de gestión ni de acceso al panel de escritorio.
        $foreman = User::where('email', 'foreman@dp.local')->firstOrFail();

        $response = $this->actingAs($foreman)
            ->get(route('fleet-attachments.documents.download', [$attachment, 0]));

        // Storage::disk('local')->response() devuelve un StreamedResponse:
        // $response->getContent() no sirve (Symfony la produce por
        // callback, no la deja en un buffer), pero
        // TestResponse::streamedContent() SÍ ejecuta ese callback y captura
        // lo que de verdad se mandó por HTTP -- a diferencia de comparar
        // contra Storage::disk('local')->get(...), que sería el disco
        // contra sí mismo (no puede fallar y no prueba nada del cuerpo de
        // la respuesta).
        $response->assertOk();
        $this->assertStringContainsString('manual-original.pdf', $response->headers->get('content-disposition'));
        $this->assertSame('contenido privado del manual', $response->streamedContent());
    }

    /**
     * `documents` es UN campo JSON con varios archivos, no una fila por
     * archivo: la "propiedad" del archivo la da su POSICIÓN en el array de
     * ESE registro, no un id propio. Pedir un índice fuera de rango es el
     * equivalente a pedir "el archivo ajeno" -- tiene que dar 403, nunca
     * resolver por accidente el documento de otro registro ni tirar un
     * error de servidor.
     */
    public function test_an_out_of_range_document_index_returns_403(): void
    {
        $attachment = $this->attachmentWithDocument();
        $admin = User::where('email', 'admin@dp.local')->firstOrFail();

        $response = $this->actingAs($admin)
            ->get(route('fleet-attachments.documents.download', [$attachment, 99]));

        $response->assertForbidden();
    }

    /**
     * El parámetro {index} está forzado a numérico (whereNumber) -- un
     * intento de path traversal ni siquiera matchea la ruta, así que Laravel
     * resuelve 404 en el router antes de tocar el modelo o el disco.
     */
    public function test_a_non_numeric_index_does_not_match_the_route_at_all(): void
    {
        $attachment = $this->attachmentWithDocument();
        $admin = User::where('email', 'admin@dp.local')->firstOrFail();

        $response = $this->actingAs($admin)
            ->get('/fleet-attachments/'.$attachment->id.'/documents/..%2F..%2F.env');

        $response->assertNotFound();
    }
}

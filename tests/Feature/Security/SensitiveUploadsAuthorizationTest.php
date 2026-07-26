<?php

namespace Tests\Feature\Security;

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
 */
class SensitiveUploadsAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

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
}

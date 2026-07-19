<?php

namespace Tests\Feature\Management;

use App\Models\Quote;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * El link público de una cotización (/quotes/{share_token}) no requiere
 * cuenta en el sistema: cualquier persona con el link puede ver/descargar
 * el archivo, salvo que haya vencido (expires_at en el pasado).
 */
class QuotePublicLinkTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);
        Storage::fake('public');
    }

    protected function admin(): User
    {
        return User::where('email', 'admin@dp.local')->firstOrFail();
    }

    public function test_a_quote_gets_a_share_token_automatically_on_creation(): void
    {
        $quote = Quote::create([
            'title' => 'Engine overhaul quote',
            'uploaded_by' => $this->admin()->id,
            'vendor' => 'Acme Diesel',
            'amount' => 500,
        ]);

        $this->assertNotEmpty($quote->share_token);
        $this->assertStringContainsString($quote->share_token, $quote->share_url);
    }

    public function test_the_public_route_shows_the_quote_and_a_working_file_link_without_authentication(): void
    {
        $file = UploadedFile::fake()->create('quote.pdf', 100, 'application/pdf');
        $path = $file->store('quotes', 'public');

        $quote = Quote::create([
            'title' => 'Hydraulic pump quote',
            'uploaded_by' => $this->admin()->id,
            'vendor' => 'South Florida Hydraulics',
            'amount' => 1875.50,
            'file_path' => $path,
        ]);

        $response = $this->get('/quotes/'.$quote->share_token);

        $response->assertOk();
        $response->assertSee('Hydraulic pump quote');
        $response->assertSee('South Florida Hydraulics');

        Storage::disk('public')->assertExists($path);
    }

    public function test_an_expired_quote_link_shows_the_expired_message_instead_of_the_file(): void
    {
        $quote = Quote::create([
            'title' => 'Old quote',
            'uploaded_by' => $this->admin()->id,
            'vendor' => 'Vendor Should Not Show',
            'expires_at' => now()->subDay(),
        ]);

        $response = $this->get('/quotes/'.$quote->share_token);

        $response->assertOk();
        $response->assertSee(__('mgmt.public_expired'));
        // The expired notice replaces the details/download section entirely.
        $response->assertDontSee('Vendor Should Not Show');
        $response->assertDontSee(__('mgmt.public_view_file'));
    }

    public function test_an_unknown_token_returns_a_404(): void
    {
        $this->get('/quotes/does-not-exist-token')->assertNotFound();
    }
}

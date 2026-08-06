<?php

namespace Tests\Feature\Management;

use App\Filament\Resources\QuoteResource;
use App\Models\Quote;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * El cliente dio de baja el módulo de cotizaciones (2026-08-06: "ya no es
 * necesario el módulo de cotizaciones"). Se apagó con `features.quotes`
 * (config/features.php) en vez de borrarse, para no perder la tabla `quotes`
 * ni los archivos ya subidos.
 *
 * Este test fija el estado APAGADO. Es lo contrario de los otros tests de
 * cotizaciones (QuotePublicLinkTest, SensitiveUploadValidationTest,
 * SensitiveUploadsAuthorizationTest), que encienden el flag a mano para seguir
 * cubriendo el módulo por si se reactiva.
 *
 * Por qué no alcanza con mirar el menú: sacar un Resource de la navegación no
 * cierra nada — es literalmente el hallazgo C1 de este proyecto, donde la URL
 * directa seguía abierta. Por eso acá se piden las tres páginas del Resource a
 * mano, con el rol que SÍ tiene `manage_quotes`.
 */
class QuotesModuleIsOffTest extends TestCase
{
    use RefreshDatabase;

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

    protected function quote(): Quote
    {
        return Quote::create([
            'title' => 'Cotización histórica',
            'uploaded_by' => $this->admin()->id,
            'vendor' => 'Acme Diesel',
            'amount' => 1200,
        ]);
    }

    public function test_the_quotes_module_ships_disabled_by_default(): void
    {
        $this->assertFalse(
            (bool) config('features.quotes'),
            'features.quotes tiene que venir apagado: el cliente dio de baja el módulo. '
            .'Si esto falla es porque alguien cambió el default en config/features.php '
            .'o dejó FEATURE_QUOTES=true en el entorno.'
        );

        $this->assertFalse(QuoteResource::moduleEnabled());
        $this->assertFalse(QuoteResource::shouldRegisterNavigation());
    }

    public function test_the_admin_does_not_see_quotes_in_the_panel_navigation(): void
    {
        $this->actingAs($this->admin())
            ->get('/admin')
            ->assertOk()
            ->assertDontSee('/admin/quotes', false);
    }

    public function test_the_three_resource_pages_are_forbidden_even_for_a_user_with_manage_quotes(): void
    {
        $admin = $this->admin();

        // Precondición: si el permiso no existiera, este test pasaría por el
        // motivo equivocado y no probaría nada del apagado.
        $this->assertTrue($admin->can('manage_quotes'));

        $quote = $this->quote();

        $this->actingAs($admin)->get('/admin/quotes')->assertForbidden();
        $this->actingAs($admin)->get('/admin/quotes/create')->assertForbidden();
        $this->actingAs($admin)->get('/admin/quotes/'.$quote->id.'/edit')->assertForbidden();
    }

    public function test_a_public_share_link_that_was_already_handed_out_now_returns_404(): void
    {
        Storage::disk('local')->put('quotes/historica.pdf', 'contenido');

        $quote = $this->quote();
        $quote->update(['file_path' => 'quotes/historica.pdf']);

        // Token VÁLIDO y archivo presente: el 404 viene del módulo apagado, no
        // de un token inexistente ni de un archivo faltante.
        $this->assertNotEmpty($quote->share_token);

        $this->get('/quotes/'.$quote->share_token)->assertNotFound();
        $this->get('/quotes/'.$quote->share_token.'/archivo')->assertNotFound();
    }

    /**
     * El apagado es de superficie, no de datos: la fila y el archivo siguen
     * ahí. Si algún día se decide borrar de verdad, este test es el que hay
     * que cambiar (y ahí sí hace falta migración).
     */
    public function test_the_stored_quotes_are_not_touched(): void
    {
        $quote = $this->quote();

        $this->assertDatabaseHas('quotes', ['id' => $quote->id, 'title' => 'Cotización histórica']);
    }
}

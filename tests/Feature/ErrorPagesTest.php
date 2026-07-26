<?php

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Session\TokenMismatchException;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * Etapa 05, Bloque 5 — hallazgos M6 y M7.
 *
 * M6: resources/views/errors/ no existía, así que un 403/404/419/500 caía en
 * las páginas por defecto de Laravel: en inglés, sin el logo del cliente y
 * sin ningún enlace de salida. En este sistema el 403 es la respuesta de
 * diseño (no un caso raro) cada vez que un rol toca algo que no le
 * corresponde, así que estas páginas necesitan texto en el idioma correcto y
 * un botón que funcione según quién esté mirando la pantalla.
 *
 * Las rutas ad hoc (`/__test/...`) se registran dentro de cada test para
 * aislar la prueba del enrutamiento real de la app (que puede cambiar) y
 * probar puntualmente la vista de error + el destino del botón de salida.
 */
class ErrorPagesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);
    }

    public static function localeProvider(): array
    {
        return [
            'español' => ['es'],
            'inglés' => ['en'],
        ];
    }

    /**
     * @dataProvider localeProvider
     */
    public function test_a_403_renders_the_branded_page_translated(string $locale): void
    {
        app()->setLocale($locale);
        Route::get('/__test/403', fn () => abort(403))->middleware('web');

        $response = $this->get('/__test/403');

        $response->assertStatus(403);
        $response->assertSee('DP Fleet Maintenance');
        $response->assertSee(__('errors.403_heading', [], $locale));
        $response->assertSee(__('errors.403_message', [], $locale));
        $response->assertSee('dp-logo.jpg', false);
    }

    /**
     * @dataProvider localeProvider
     */
    public function test_a_404_renders_the_branded_page_translated(string $locale): void
    {
        app()->setLocale($locale);

        $response = $this->get('/__test/does-not-exist-'.uniqid());

        $response->assertStatus(404);
        $response->assertSee('DP Fleet Maintenance');
        $response->assertSee(__('errors.404_heading', [], $locale));
        $response->assertSee(__('errors.404_message', [], $locale));
    }

    /**
     * @dataProvider localeProvider
     */
    public function test_a_419_renders_the_branded_page_translated(string $locale): void
    {
        app()->setLocale($locale);
        Route::get('/__test/419', function () {
            throw new TokenMismatchException('token mismatch');
        })->middleware('web');

        $response = $this->get('/__test/419');

        $response->assertStatus(419);
        $response->assertSee('DP Fleet Maintenance');
        $response->assertSee(__('errors.419_heading', [], $locale));
        $response->assertSee(__('errors.419_message', [], $locale));
    }

    public function test_the_exit_link_goes_to_the_admin_panel_for_a_desktop_role(): void
    {
        Route::get('/__test/403-exit', fn () => abort(403))->middleware('web');

        $admin = User::where('email', 'admin@dp.local')->firstOrFail();

        $response = $this->actingAs($admin)->get('/__test/403-exit');

        $response->assertStatus(403);
        $response->assertSee(__('errors.exit_panel'));
        $response->assertSee('href="'.url('/admin').'"', false);
    }

    public function test_the_exit_link_goes_to_field_home_for_a_field_role_not_to_admin(): void
    {
        Route::get('/__test/403-exit-field', fn () => abort(403))->middleware('web');

        $foreman = User::where('email', 'foreman@dp.local')->firstOrFail();

        $response = $this->actingAs($foreman)->get('/__test/403-exit-field');

        $response->assertStatus(403);
        $response->assertSee(__('errors.exit_field'));
        $response->assertSee('href="'.route('field.home').'"', false);
        $response->assertDontSee('href="'.url('/admin').'"', false);
    }

    public function test_the_exit_link_goes_to_login_for_a_guest(): void
    {
        Route::get('/__test/403-exit-guest', fn () => abort(403))->middleware('web');

        $response = $this->get('/__test/403-exit-guest');

        $response->assertStatus(403);
        $response->assertSee(__('errors.exit_login'));
        $response->assertSee('href="'.route('login').'"', false);
    }

    /**
     * M7: con APP_DEBUG=false, un error no controlado debe mostrar la página
     * de error con marca y NO filtrar stack trace, nombres de clase, ni rutas
     * del servidor (que es lo que hace Ignition con APP_DEBUG=true).
     */
    public function test_a_500_with_debug_disabled_renders_the_branded_page_without_leaking_internals(): void
    {
        config(['app.debug' => false]);

        Route::get('/__test/boom', function () {
            throw new \RuntimeException('internal detail that must never reach the browser: '.base_path('.env'));
        })->middleware('web');

        $response = $this->get('/__test/boom');

        $response->assertStatus(500);
        $response->assertSee('DP Fleet Maintenance');
        $response->assertSee(__('errors.500_heading'));
        $response->assertDontSee('RuntimeException', false);
        $response->assertDontSee(base_path('.env'), false);
        $response->assertDontSee('Stack trace', false);
        $response->assertDontSee('ignition', false);
        $response->assertDontSee('Whoops', false);
    }
}

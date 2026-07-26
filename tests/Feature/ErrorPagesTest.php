<?php

namespace Tests\Feature;

use App\Http\Middleware\SetLocale;
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

    /**
     * Regresión reportada por QA (Etapa 05, Bloque 5, corrección sobre M6):
     * el 403 más frecuente del sistema — un rol de campo (canAccessPanel()
     * false) entrando a /admin — se renderizaba SIEMPRE en el locale por
     * defecto de la app, ignorando users.locale. Verificado en vivo con
     * foreman@dp.local (locale=es): <html lang="en">, "Access not allowed".
     *
     * Causa: Filament\Http\Middleware\Authenticate hace abort_if(403) DENTRO
     * de su propio handle(), y Laravel ordena el pipeline de middleware por
     * PRIORIDAD (Illuminate\Foundation\Http\Kernel::$middlewarePriority), no
     * por el orden declarado en AdminPanelProvider::middleware(). SetLocale
     * no estaba en esa lista, así que Laravel lo relegaba siempre al final
     * del pipeline real — después del 403, no antes.
     *
     * Estos tests NO usan una ruta ad hoc con abort(403) manual (por eso el
     * test original no detectó el bug): pasan por el 403 REAL de
     * canAccessPanel() en GET /admin, con un usuario de rol de campo
     * autenticado de verdad, para probar el pipeline de middleware tal cual
     * corre en producción.
     */
    public function test_the_real_panel_403_from_can_access_panel_respects_the_field_users_locale_es(): void
    {
        $foreman = User::where('email', 'foreman@dp.local')->firstOrFail();
        $foreman->forceFill(['locale' => 'es'])->save();

        $response = $this->actingAs($foreman)->get('/admin');

        $response->assertStatus(403);
        $response->assertSee('lang="es"', false);
        $response->assertSee(__('errors.403_heading', [], 'es'));
        $response->assertSee(__('errors.403_message', [], 'es'));
        $response->assertDontSee(__('errors.403_heading', [], 'en'));
    }

    public function test_the_real_panel_403_from_can_access_panel_respects_the_field_users_locale_en(): void
    {
        $foreman = User::where('email', 'foreman@dp.local')->firstOrFail();
        $foreman->forceFill(['locale' => 'en'])->save();

        $response = $this->actingAs($foreman)->get('/admin');

        $response->assertStatus(403);
        $response->assertSee('lang="en"', false);
        $response->assertSee(__('errors.403_heading', [], 'en'));
        $response->assertSee(__('errors.403_message', [], 'en'));
        $response->assertDontSee(__('errors.403_heading', [], 'es'));
    }

    /**
     * Punto 4 del pedido de QA: revisar si 404 y 419 arrastran el mismo
     * problema por otra vía. 419 no lo tenía (la ruta que lo dispara siempre
     * matchea, así que SetLocale ya corría dentro de ese grupo). El 404
     * genuino (URL que no matchea NINGUNA ruta) sí lo tenía, por una causa
     * distinta a la del 403: Laravel resuelve ese 404 directo en el router,
     * sin pasar por el middleware de ningún grupo (ni "web" ni el del panel)
     * — se agregó Route::fallback(...)->middleware(['web', SetLocale::class])
     * al final de routes/web.php para que ese caso también pase por
     * SetLocale. Este test golpea una URL real, no una ruta ad hoc.
     */
    public function test_a_genuinely_unmatched_url_404_respects_the_authenticated_users_locale(): void
    {
        $admin = User::where('email', 'admin@dp.local')->firstOrFail();
        $admin->forceFill(['locale' => 'es'])->save();

        $response = $this->actingAs($admin)->get('/esto-no-existe-'.uniqid());

        $response->assertStatus(404);
        $response->assertSee('lang="es"', false);
        $response->assertSee(__('errors.404_heading', [], 'es'));
    }

    /**
     * Control: un 404 de ruta que SÍ matchea (registro inexistente, ej. el
     * edit de un Machine con un id que no existe) ya pasaba por el pipeline
     * completo del panel antes de este fix — se deja como regresión.
     */
    public function test_a_404_for_a_matched_route_with_a_missing_record_respects_the_users_locale(): void
    {
        $admin = User::where('email', 'admin@dp.local')->firstOrFail();
        $admin->forceFill(['locale' => 'es'])->save();

        $response = $this->actingAs($admin)->get('/admin/machines/999999999/edit');

        $response->assertStatus(404);
        $response->assertSee('lang="es"', false);
        $response->assertSee(__('errors.404_heading', [], 'es'));
    }

    /**
     * 419 a través de la MISMA combinación real de middleware que usan las
     * rutas de campo (['auth', SetLocale::class], ver routes/web.php) — no
     * el genérico "web" del test sintético de más arriba.
     */
    public function test_a_419_through_the_real_auth_and_setlocale_middleware_combo_respects_the_users_locale(): void
    {
        Route::post('/__test/419-field', function () {
            throw new TokenMismatchException('token mismatch');
        })->middleware(['auth', SetLocale::class]);

        $foreman = User::where('email', 'foreman@dp.local')->firstOrFail();
        $foreman->forceFill(['locale' => 'es'])->save();

        $response = $this->actingAs($foreman)->post('/__test/419-field');

        $response->assertStatus(419);
        $response->assertSee('lang="es"', false);
        $response->assertSee(__('errors.419_heading', [], 'es'));
    }
}

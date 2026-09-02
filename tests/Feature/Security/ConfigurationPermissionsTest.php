<?php

namespace Tests\Feature\Security;

use App\Filament\Pages\Configuration;
use App\Models\Setting;
use App\Models\User;
use App\Services\TaxCalculator;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

/**
 * Hallazgo de QA (Etapa 2026-09-01): App\Filament\Pages\Configuration no
 * tenía ninguna regresión automatizada de `canAccess()`/`mount()`/`save()`.
 * El gate (`manage_settings`, solo `administrador`) ya estaba en el código;
 * lo que faltaba era la prueba de que sigue ahí. Mismo patrón que
 * WorkOrderPermissionsTest: un método por rol/acción, un solo `actingAs`
 * por test — encadenar varios `actingAs` en el mismo test dio, en otro
 * contexto de este mismo lote, un 500 que resultó ser artefacto del
 * encadenado y no un defecto real.
 */
class ConfigurationPermissionsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);
    }

    private function user(string $email): User
    {
        return User::where('email', $email)->firstOrFail();
    }

    /* ---------------------------------------------------------- *
     * canAccess()
     * ---------------------------------------------------------- */

    public function test_gerencia_cannot_access_configuration(): void
    {
        $this->actingAs($this->user('gerencia@dp.local'));

        $this->assertFalse(Configuration::canAccess());
    }

    public function test_taller_cannot_access_configuration(): void
    {
        $this->actingAs($this->user('taller@dp.local'));

        $this->assertFalse(Configuration::canAccess());
    }

    public function test_administrador_can_access_configuration(): void
    {
        $this->actingAs($this->user('admin@dp.local'));

        $this->assertTrue(Configuration::canAccess());
    }

    /* ---------------------------------------------------------- *
     * mount() — la ruta HTTP de la página
     * ---------------------------------------------------------- */

    public function test_gerencia_gets_403_opening_the_configuration_page(): void
    {
        $this->actingAs($this->user('gerencia@dp.local'));

        $this->get(Configuration::getUrl())->assertForbidden();
    }

    public function test_taller_gets_403_opening_the_configuration_page(): void
    {
        $this->actingAs($this->user('taller@dp.local'));

        $this->get(Configuration::getUrl())->assertForbidden();
    }

    public function test_administrador_can_open_the_configuration_page(): void
    {
        $this->actingAs($this->user('admin@dp.local'));

        $this->get(Configuration::getUrl())->assertOk();
    }

    /* ---------------------------------------------------------- *
     * save() — defensa en profundidad: el método de Livewire es
     * alcanzable por su nombre aunque mount() ya haya bloqueado la
     * página, si alguien arma la petición a mano.
     * ---------------------------------------------------------- */

    /**
     * `Livewire::test(Configuration::class)` monta la página primero (y ya
     * aborta 403 en mount() para gerencia), así que no sirve para probar la
     * defensa en profundidad de `save()` — el abort de mount() rompe el
     * snapshot y el error que ve el test es de Livewire, no el 403 real
     * (así se comprobó armando el test: `InvalidArgumentException: Invalid
     * Livewire snapshot structure`, no la excepción de autorización).
     * Instanciar la página directa y llamar `save()` sin pasar por `mount()`
     * es lo que reproduce el escenario real del docblock: alguien arma la
     * petición a mano y llega al método sin haber montado la página.
     */
    public function test_gerencia_cannot_call_save_directly_even_bypassing_mount(): void
    {
        Setting::set(TaxCalculator::SETTING_KEY, 7.0, TaxCalculator::SETTING_TYPE);

        $this->actingAs($this->user('gerencia@dp.local'));

        $page = new Configuration;

        try {
            $page->save();
            $this->fail('save() no lanzó ninguna excepción de autorización para gerencia');
        } catch (HttpException $e) {
            $this->assertSame(403, $e->getStatusCode());
        }

        $this->assertSame(7.0, TaxCalculator::rate(), 'la tasa cambió pese al gate de manage_settings');
    }

    public function test_administrador_can_save_the_tax_rate(): void
    {
        $this->actingAs($this->user('admin@dp.local'));

        Livewire::test(Configuration::class)
            ->set('data.tax_rate', 9.5)
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame(9.5, TaxCalculator::rate());
    }

    /**
     * Hallazgo menor de seguridad, 2026-09-01: un valor con más de 2
     * decimales se aceptaba y se guardaba con la precisión completa del
     * float de PHP (7.123456789012345678 → "7.1234567890123" en la base),
     * ensuciando el rótulo del reporte sin ganar nada.
     */
    public function test_saving_a_rate_with_many_decimals_is_rounded_to_two(): void
    {
        $this->actingAs($this->user('admin@dp.local'));

        Livewire::test(Configuration::class)
            ->set('data.tax_rate', '7.123456789012345678')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame(7.12, TaxCalculator::rate());
        $this->assertSame('7.12', DB::table('settings')->where('key', TaxCalculator::SETTING_KEY)->value('value'));
    }
}

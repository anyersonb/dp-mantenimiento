<?php

namespace Tests\Feature\Localization;

use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\App;
use Tests\TestCase;

/**
 * Defecto reportado por el cliente: con el panel en inglés, la tarjeta de
 * bienvenida (AccountWidget de Filament, que imprime getFilamentName()) seguía
 * diciendo "Administrador DP" porque `name` es un dato sembrado en español.
 *
 * La cuenta de admin@dp.local es genérica del sistema (no una persona), así
 * que SOLO ella se traduce con el idioma activo. Cualquier otro usuario
 * (persona real) tiene que seguir mostrando su `name` tal cual está en la BD,
 * en cualquier idioma.
 */
class SystemAccountNameIsTranslatedTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);
    }

    public function test_the_generic_admin_account_name_follows_the_active_locale(): void
    {
        $admin = User::where('email', 'admin@dp.local')->firstOrFail();

        App::setLocale('es');
        $this->assertSame('Administrador DP', $admin->getFilamentName());

        App::setLocale('en');
        $this->assertSame('Administrator DP', $admin->getFilamentName());
    }

    public function test_a_real_person_keeps_their_stored_name_in_any_locale(): void
    {
        $persona = User::create([
            'name' => 'Darwin Herrera',
            'email' => 'darwin.herrera@dp.local',
            'password' => bcrypt('password'),
            'locale' => 'es',
            'active' => true,
        ]);

        App::setLocale('es');
        $this->assertSame('Darwin Herrera', $persona->getFilamentName());

        // El caso que el fix no puede romper: una persona real que además
        // trabaje con el panel en inglés no puede convertirse en "Administrator DP"
        // ni en ninguna otra traducción — no es la cuenta genérica.
        App::setLocale('en');
        $this->assertSame('Darwin Herrera', $persona->getFilamentName());
    }

    public function test_the_raw_name_column_is_never_rewritten_in_the_database(): void
    {
        $admin = User::where('email', 'admin@dp.local')->firstOrFail();

        App::setLocale('en');
        $admin->getFilamentName();

        // La traducción vive solo en lo que se muestra: el dato guardado no
        // se toca, porque otros lugares (reportes, exports) lo leen crudo a
        // propósito y deben seguir viéndolo igual.
        $this->assertSame('Administrador DP', $admin->fresh()->name);
    }

    public function test_a_different_account_with_the_same_stored_name_is_not_translated(): void
    {
        // Ancla por email, no por el string "Administrador DP": una persona
        // real que se llamara igual no debe verse afectada por la traducción.
        $tocaya = User::create([
            'name' => 'Administrador DP',
            'email' => 'otra.persona@dp.local',
            'password' => bcrypt('password'),
            'locale' => 'es',
            'active' => true,
        ]);

        App::setLocale('en');
        $this->assertSame('Administrador DP', $tocaya->getFilamentName());
    }
}

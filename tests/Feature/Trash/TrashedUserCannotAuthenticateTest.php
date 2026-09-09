<?php

namespace Tests\Feature\Trash;

use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Tests\TestCase;

/**
 * Papelera (Lote A) — caso delicado: un usuario en papelera NO puede
 * autenticarse. No se asume por el scope de SoftDeletes: se comprueba con
 * los tres caminos reales por los que alguien entra o se mantiene adentro.
 */
class TrashedUserCannotAuthenticateTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);
    }

    private function taller(): User
    {
        return User::where('email', 'taller@dp.local')->firstOrFail();
    }

    /**
     * Camino 1: intentar loguearse DESPUÉS de que la cuenta ya está en la
     * papelera, con la contraseña correcta.
     */
    public function test_a_trashed_user_cannot_authenticate_with_valid_credentials(): void
    {
        $user = $this->taller();
        $user->delete();

        $this->assertSoftDeleted('users', ['id' => $user->id]);

        $attempt = Auth::attempt(['email' => 'taller@dp.local', 'password' => 'password']);

        $this->assertFalse($attempt, 'Un usuario en la papelera no debe poder autenticarse con credenciales válidas.');
        $this->assertNull(Auth::user());
    }

    /**
     * Camino 2: la cuenta YA tenía sesión iniciada y un administrador la manda
     * a la papelera mientras esa sesión sigue viva. El guard de sesión resuelve
     * `retrieveById()` en cada request — ese camino respeta el scope global de
     * SoftDeletes sin que nadie lo tenga que codificar a mano.
     */
    public function test_the_session_provider_can_no_longer_resolve_a_trashed_user(): void
    {
        $user = $this->taller();
        $this->actingAs($user);
        $this->assertAuthenticatedAs($user);

        $user->delete();

        $resuelto = Auth::createUserProvider('users')->retrieveById($user->getKey());

        $this->assertNull(
            $resuelto,
            'El proveedor de autenticación (session guard) no debe poder recuperar un usuario en papelera.'
        );
    }

    /**
     * Camino 3: segunda capa explícita en `User::canAccessPanel()` — no
     * depende únicamente del scope; el chequeo está en el propio método.
     */
    public function test_can_access_panel_returns_false_for_a_trashed_user(): void
    {
        $user = $this->taller();
        $user->delete();

        $panel = Filament::getPanel('admin');

        $this->assertFalse($user->canAccessPanel($panel));
    }

    /**
     * Control: un usuario ACTIVO y no borrado sigue entrando normalmente
     * (para que el test anterior no esté verde por casualidad).
     */
    public function test_a_non_trashed_active_user_can_still_access_the_panel(): void
    {
        $user = $this->taller();
        $panel = Filament::getPanel('admin');

        $this->assertTrue($user->canAccessPanel($panel));
    }
}

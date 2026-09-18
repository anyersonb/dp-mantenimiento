<?php

namespace Tests\Feature\Field;

use App\Livewire\Field\Login;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Rate limiting de `/field/login` (App\Livewire\Field\Login).
 *
 * Hallazgo 3 (auditoria 2026-09-18): el limitador por email+IP (5 intentos,
 * `Login::MAX_ATTEMPTS`/`throttleKey()`). Hallazgo ALTO de la re-auditoria del
 * mismo dia: ESE contador, por si solo, no frena credential stuffing --
 * `email+IP` significa 5 intentos POR CADA correo que se pruebe, asi que una
 * sola IP tenia 5 x (cantidad de correos) sin ningun tope agregado y sin
 * dejar rastro en el log. El fix agrega un SEGUNDO contador, solo por IP
 * (`MAX_ATTEMPTS_PER_IP`/`ipThrottleKey()`), independiente del primero y que
 * NO se limpia con un login exitoso.
 *
 * Livewire::test()->call() despacha internamente un POST real (RequestBroker
 * usa MakesHttpRequests), asi que la IP que ve Login::login() es la que el
 * cliente de test HTTP de Laravel usa por defecto (Symfony sin REMOTE_ADDR
 * declarado -> 127.0.0.1), la MISMA en todas las llamadas de un mismo
 * metodo de test. Cada test se lee esa IP con currentIp() en vez de asumir
 * el literal, para no acoplarse a un detalle interno de Symfony/Livewire que
 * podria cambiar.
 */
class LoginRateLimitTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);
    }

    private function demoPassword(): string
    {
        return config('accounts.demo_seed_password');
    }

    /**
     * La IP que Login::login() vio en la ultima llamada de Livewire::test()
     * de este test -- request()->ip() resuelve del binding 'request' que
     * queda en el contenedor tras el ultimo POST interno de Livewire.
     */
    private function currentIp(): string
    {
        return (string) request()->ip();
    }

    /** Misma formula que Login::throttleKey() (email+IP). */
    private function emailIpKey(string $email, string $ip): string
    {
        return Str::transliterate(Str::lower($email)).'|'.$ip;
    }

    /** Misma formula que Login::ipThrottleKey() (solo IP). */
    private function ipKey(string $ip): string
    {
        return 'field-login-ip|'.$ip;
    }

    /**
     * Control positivo: sin fallos previos, un login normal funciona y manda
     * a cada rol a su destino (con access_panel -> /admin, sin el -> /field).
     */
    public function test_a_normal_login_with_no_prior_failures_works(): void
    {
        $worker = User::where('email', 'taller@dp.local')->firstOrFail();

        Livewire::test(Login::class)
            ->set('email', $worker->email)
            ->set('password', $this->demoPassword())
            ->call('login')
            ->assertRedirect(url('/admin'));

        $this->assertAuthenticatedAs($worker);
    }

    /**
     * El 6to intento fallido consecutivo (clave email+IP) se rechaza AUNQUE
     * la clave sea la correcta: el contador ya se agoto antes de que
     * Auth::attempt se llegue a evaluar.
     */
    public function test_the_sixth_consecutive_failed_attempt_is_rejected_even_with_the_correct_password(): void
    {
        $worker = User::where('email', 'gerencia@dp.local')->firstOrFail();

        for ($i = 0; $i < 5; $i++) {
            Livewire::test(Login::class)
                ->set('email', $worker->email)
                ->set('password', 'wrong-password')
                ->call('login')
                ->assertHasErrors('email');
        }

        Livewire::test(Login::class)
            ->set('email', $worker->email)
            ->set('password', $this->demoPassword())
            ->call('login')
            ->assertHasErrors('email');

        $this->assertGuest();
    }

    /**
     * Un login exitoso limpia el contador email+IP (comportamiento
     * preexistente, no tocado por el fix): tras acertar, la cuenta vuelve a
     * tener su cupo completo de 5 fallos antes de bloquearse de nuevo.
     */
    public function test_a_successful_login_clears_the_email_plus_ip_counter(): void
    {
        $worker = User::where('email', 'admin@dp.local')->firstOrFail();

        Livewire::test(Login::class)->set('email', $worker->email)->set('password', 'wrong-1')->call('login');
        Livewire::test(Login::class)->set('email', $worker->email)->set('password', 'wrong-2')->call('login');

        $ip = $this->currentIp();

        Livewire::test(Login::class)
            ->set('email', $worker->email)
            ->set('password', $this->demoPassword())
            ->call('login')
            ->assertRedirect(url('/admin'));

        $this->assertSame(0, RateLimiter::attempts($this->emailIpKey($worker->email, $ip)));
    }

    /**
     * Un usuario legitimo que se equivoca dos veces y acierta al tercer
     * intento NO queda bloqueado (2 fallos < 5, el cupo email+IP nunca se
     * agota).
     */
    public function test_two_mistakes_then_the_correct_password_on_the_third_try_does_not_lock_the_user_out(): void
    {
        $worker = User::where('email', 'campo@dp.local')->firstOrFail();

        Livewire::test(Login::class)
            ->set('email', $worker->email)->set('password', 'wrong-1')->call('login')
            ->assertHasErrors('email');

        Livewire::test(Login::class)
            ->set('email', $worker->email)->set('password', 'wrong-2')->call('login')
            ->assertHasErrors('email');

        Livewire::test(Login::class)
            ->set('email', $worker->email)
            ->set('password', $this->demoPassword())
            ->call('login')
            ->assertRedirect(route('field.home'));

        $this->assertAuthenticatedAs($worker);
    }

    /**
     * El hueco real del hallazgo ALTO: un atacante que prueba 30 correos
     * DISTINTOS desde la MISMA IP (un intento cada uno, nunca agotando el
     * cupo email+IP de ninguna cuenta individual) si agota el cupo agregado
     * de la IP, y el intento 31 -con credenciales CORRECTAS- queda bloqueado
     * igual. Antes del fix esto no tenia tope ni dejaba rastro.
     */
    public function test_the_ip_wide_counter_blocks_credential_stuffing_across_many_different_emails(): void
    {
        for ($i = 0; $i < 30; $i++) {
            Livewire::test(Login::class)
                ->set('email', "attacker{$i}@dp.local")
                ->set('password', 'wrong-password')
                ->call('login');
        }

        $ip = $this->currentIp();

        $this->assertSame(30, RateLimiter::attempts($this->ipKey($ip)));

        Log::spy();

        $worker = User::where('email', 'admin@dp.local')->firstOrFail();

        Livewire::test(Login::class)
            ->set('email', $worker->email)
            ->set('password', $this->demoPassword())
            ->call('login')
            ->assertHasErrors('email');

        $this->assertGuest();

        Log::shouldHaveReceived('warning')
            ->withArgs(fn (string $message): bool => $message === 'field_login.ip_throttled')
            ->once();
    }

    /**
     * El contador por IP NO se limpia con un login exitoso: si se limpiara,
     * un atacante podria alternar una cuenta propia valida para resetear el
     * cupo de la IP y seguir probando correos ajenos sin limite real.
     */
    public function test_a_successful_login_does_not_reset_the_ip_wide_counter(): void
    {
        for ($i = 0; $i < 5; $i++) {
            Livewire::test(Login::class)
                ->set('email', "other{$i}@dp.local")
                ->set('password', 'wrong-password')
                ->call('login');
        }

        $ip = $this->currentIp();

        $this->assertSame(5, RateLimiter::attempts($this->ipKey($ip)));

        $worker = User::where('email', 'admin@dp.local')->firstOrFail();

        Livewire::test(Login::class)
            ->set('email', $worker->email)
            ->set('password', $this->demoPassword())
            ->call('login')
            ->assertRedirect(url('/admin'));

        // Si el login exitoso hubiese limpiado el contador de IP, esto seria 0.
        $this->assertSame(5, RateLimiter::attempts($this->ipKey($ip)));
    }
}

<?php

namespace App\Livewire\Field;

use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Livewire\Component;

class Login extends Component
{
    public string $email = '';

    public string $password = '';

    /**
     * Hallazgo 3 (auditoría 2026-09-18): `/field/login` autentica a CUALQUIER
     * usuario del sistema —incluido administrador— y decide a dónde mandarlo
     * DESPUÉS de autenticar, así que sin límite era un oráculo de credenciales
     * sin fondo para todo el padrón, aunque el panel de Filament sí estuviera
     * protegido.
     *
     * CORRECCIÓN (hallazgo ALTO, re-auditoría 2026-09-18): el docblock
     * original de esta constante decía "mismo tope que el login de Filament".
     * Es falso: el limitador de Filament
     * (vendor/danharrin/livewire-rate-limiting/src/WithRateLimiting.php:27)
     * usa clave `sha1(componente|método|IP)` —SOLO IP, sin mirar el correo—,
     * mientras que este contador usa clave email+IP. Con clave email+IP, 5
     * intentos son 5 POR CADA correo que se pruebe: desde una sola IP, con los
     * 7 correos demo que estuvieron en un repositorio público, eso son
     * ~2 100 intentos/hora sin un solo tope agregado y sin dejar rastro en el
     * log. Ver MAX_ATTEMPTS_PER_IP más abajo: ese es el que de verdad cierra
     * ese hueco.
     */
    private const MAX_ATTEMPTS = 5;

    private const DECAY_SECONDS = 60;

    /**
     * Segundo contador, SOLO por IP —independiente del anterior—, para el
     * hueco que el de email+IP no cubre: agota el cupo de una cuenta a la vez,
     * pero no el de la IP completa probando cuentas distintas.
     *
     * 30 intentos / 300s (5 minutos): en una obra donde varios operarios
     * comparten la misma conexión, el peor caso realista es de ~6 personas
     * entrando a la vez en el cambio de turno con algún error de tipeo cada
     * una — eso cabe holgado dentro del cupo sin bloquear a nadie legítimo. Un
     * script de credential stuffing en cambio queda limitado a ~360
     * intentos/hora en esa IP (30 cada 5 min), muy lejos de los ~2 100/hora de
     * hoy, y CADA vez que se agota el cupo queda un Log::warning (hoy no
     * queda ningún rastro).
     *
     * A propósito NO se limpia en el login exitoso (a diferencia del de
     * email+IP, más abajo): si se limpiara, un atacante podría alternar un
     * login válido propio para resetear el cupo de la IP y seguir probando
     * correos ajenos sin límite real.
     */
    private const MAX_ATTEMPTS_PER_IP = 30;

    private const DECAY_SECONDS_PER_IP = 300;

    public function mount()
    {
        if (Auth::check()) {
            return redirect()->to($this->targetUrl(Auth::user()));
        }
    }

    /**
     * Clave por email + IP (no solo IP): así un atacante no puede bloquear a
     * un usuario legítimo agotando el cupo desde otra IP, y probar contra
     * miles de emails desde una sola IP tampoco comparte cupo entre ellos.
     * `Str::lower` + `transliterate` para que mayúsculas/acentos no abran una
     * clave distinta para la misma cuenta (mismo criterio que el throttle de
     * login clásico de Laravel).
     */
    protected function throttleKey(): string
    {
        return Str::transliterate(Str::lower($this->email)).'|'.request()->ip();
    }

    protected function tooManyLoginAttempts(): bool
    {
        return RateLimiter::tooManyAttempts($this->throttleKey(), self::MAX_ATTEMPTS);
    }

    /**
     * Clave SOLO por IP (a diferencia de throttleKey(), que mezcla email+IP).
     * Ver el docblock de MAX_ATTEMPTS_PER_IP.
     */
    protected function ipThrottleKey(): string
    {
        return 'field-login-ip|'.request()->ip();
    }

    protected function tooManyIpAttempts(): bool
    {
        return RateLimiter::tooManyAttempts($this->ipThrottleKey(), self::MAX_ATTEMPTS_PER_IP);
    }

    public function login()
    {
        $credentials = $this->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);

        if ($this->tooManyIpAttempts()) {
            $seconds = RateLimiter::availableIn($this->ipThrottleKey());

            // Hallazgo ALTO (re-auditoría 2026-09-18): antes una ráfaga de
            // miles de intentos desde una IP, variando el correo, no dejaba
            // NINGÚN rastro. Esta es la única señal que queda.
            Log::warning('field_login.ip_throttled', [
                'ip' => request()->ip(),
                'attempts' => RateLimiter::attempts($this->ipThrottleKey()),
            ]);

            $this->addError('email', __('field.login_throttled', ['seconds' => $seconds]));

            return null;
        }

        if ($this->tooManyLoginAttempts()) {
            $seconds = RateLimiter::availableIn($this->throttleKey());

            $this->addError('email', __('field.login_throttled', ['seconds' => $seconds]));

            return null;
        }

        if (! Auth::attempt($credentials)) {
            RateLimiter::hit($this->throttleKey(), self::DECAY_SECONDS);
            RateLimiter::hit($this->ipThrottleKey(), self::DECAY_SECONDS_PER_IP);
            $this->addError('email', __('field.login_error'));

            return null;
        }

        /** @var User $user */
        $user = Auth::user();

        if (! $user->active) {
            Auth::logout();
            RateLimiter::hit($this->throttleKey(), self::DECAY_SECONDS);
            RateLimiter::hit($this->ipThrottleKey(), self::DECAY_SECONDS_PER_IP);
            $this->addError('email', __('field.login_error'));

            return null;
        }

        // Solo se limpia el contador email+IP. El contador por IP NO se
        // limpia acá a propósito — ver su docblock más arriba.
        RateLimiter::clear($this->throttleKey());

        // `session()` (el manager del contenedor) y no `request()->session()`:
        // dentro de la acción de un componente Livewire, el objeto Request no
        // siempre tiene la sesión adjunta de la misma forma que en un
        // controlador HTTP normal, y `request()->session()` puede lanzar
        // "Session store not set on request" (verificado con test — ver
        // LoginThrottleTest). `session()->regenerate()` resuelve el mismo
        // store activo sin depender de esa atadura.
        session()->regenerate();

        return redirect()->to($this->targetUrl($user));
    }

    protected function targetUrl(User $user): string
    {
        return $user->canAccessPanel(Filament::getDefaultPanel())
            ? url('/admin')
            : route('field.home');
    }

    public function render()
    {
        return view('livewire.field.login')
            ->layout('components.layouts.field', ['title' => __('field.login_title')]);
    }
}

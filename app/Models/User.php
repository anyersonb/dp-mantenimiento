<?php

namespace App\Models;

use App\Models\Concerns\LogsPapeleraActivity;
use App\Support\AccessControl;
use Database\Factories\UserFactory;
use Filament\Models\Contracts\FilamentUser;
use Filament\Models\Contracts\HasName;
use Filament\Panel;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Spatie\Permission\Traits\HasRoles;

class User extends Authenticatable implements FilamentUser, HasName
{
    /** @use HasFactory<UserFactory> */

    /**
     * SoftDeletes (Papelera, Lote A). Un usuario en papelera no puede
     * autenticarse: el guard de sesión resuelve `retrieveById`/
     * `retrieveByCredentials` contra `newModelQuery()`, que respeta el scope
     * global de SoftDeletes y por lo tanto YA excluye los usuarios borrados
     * sin tocar nada más (verificado con test, no asumido — ver
     * tests/Feature/Security/TrashedUserCannotAuthenticateTest.php). El
     * chequeo explícito en `canAccessPanel()` de más abajo es una segunda
     * capa, no la única.
     */
    use HasFactory, HasRoles, LogsPapeleraActivity, Notifiable, SoftDeletes;

    public function papeleraLabel(): string
    {
        return (string) $this->name;
    }

    protected $fillable = [
        'name',
        'email',
        'password',
        'locale',
        'phone',
        'active',
        'location_id',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'active' => 'boolean',
        ];
    }

    /**
     * Acceso al panel de escritorio de Filament.
     * Se decide por el permiso `access_panel` y ya no por una lista de cuatro
     * nombres de rol. Ese cambio es el que permite borrar y clonar roles sin
     * romper el acceso: un rol nuevo con `access_panel` entra al panel igual
     * que `administrador`, cosa que con la lista de nombres era imposible por
     * definicion. Ver App\Support\AccessControl (incluida la red para la
     * ventana en la que el archivo ya subio y la migracion todavia no corrio).
     *
     * Los roles de escritorio/taller entran al panel; los de campo usan la PWA móvil.
     */
    public function canAccessPanel(Panel $panel): bool
    {
        if ($this->trashed()) {
            return false;
        }

        if (! $this->active) {
            return false;
        }

        return AccessControl::allows($this, 'access_panel');
    }

    /**
     * Defecto reportado por el cliente: en inglés la tarjeta de bienvenida
     * seguía diciendo "Administrador DP" porque `name` es un dato sembrado en
     * español, no algo que Filament traduzca solo. Esa cuenta es genérica del
     * sistema (no una persona), así que acá SÍ se traduce con el idioma
     * activo; cualquier otro usuario (persona real) sigue mostrando su
     * `name` tal cual llegó de la BD.
     */
    public function getFilamentName(): string
    {
        if ($this->isSystemAdminAccount()) {
            return __('users.system_admin_name');
        }

        return $this->name;
    }

    /**
     * Ancla al email (config, no cableado acá) en vez de comparar contra el
     * string "Administrador DP": así no depende de qué texto tenga sembrado
     * el `name` ni se confunde con una persona real que se llamara igual.
     */
    protected function isSystemAdminAccount(): bool
    {
        return $this->email === config('accounts.system_admin_email');
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }
}

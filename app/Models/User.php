<?php

namespace App\Models;

use App\Support\AccessControl;
use Database\Factories\UserFactory;
use Filament\Models\Contracts\FilamentUser;
use Filament\Models\Contracts\HasName;
use Filament\Panel;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Spatie\Permission\Traits\HasRoles;

class User extends Authenticatable implements FilamentUser, HasName
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, HasRoles, Notifiable;

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
        if (! $this->active) {
            return false;
        }

        return AccessControl::allows($this, 'access_panel');
    }

    public function getFilamentName(): string
    {
        return $this->name;
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }
}

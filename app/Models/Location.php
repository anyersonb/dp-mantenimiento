<?php

namespace App\Models;

use App\Models\Concerns\HasManualOrder;
use App\Models\Concerns\LogsPapeleraActivity;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Location extends Model
{
    /** SoftDeletes (Papelera, Lote A). `machines.current_location_id` es `nullOnDelete`, no cascada: un soft delete acá no altera ninguna máquina. */
    use HasManualOrder, LogsPapeleraActivity, SoftDeletes;

    public function papeleraLabel(): string
    {
        return (string) $this->name;
    }

    protected $guarded = [];

    protected $casts = [
        'active' => 'boolean',
        'latitude' => 'float',
        'longitude' => 'float',
    ];

    public function machines(): HasMany
    {
        return $this->hasMany(Machine::class, 'current_location_id');
    }

    /**
     * Pedido del cliente 2026-08-06 ("everything is job numbers"): la obra se
     * identifica primero por su número de trabajo, no solo por el nombre. El
     * número va PRIMERO porque es el identificador que usa el cliente.
     *
     * Las obras cargadas antes de que existiera `job_number` lo tienen NULL
     * (dato faltante real, no inventado — ver la migración), así que caen al
     * nombre solo. Nunca un guion suelto ni "sin asignar" acá: eso se lee como
     * un registro roto dentro de un <select>. Ese aviso vive en la lista de
     * LocationResource (columna `job_number` con placeholder), no en este
     * rótulo que se usa para IDENTIFICAR la obra en selects, tablas y mapas.
     */
    protected function displayName(): Attribute
    {
        return Attribute::get(function (): string {
            $name = (string) $this->name;
            $jobNumber = $this->job_number;

            if ($jobNumber === null || $jobNumber === '') {
                return $name;
            }

            return $jobNumber.' — '.$name;
        });
    }

    /**
     * Opciones de `<select>` rotuladas con `display_name`, para los selects de
     * Filament que necesitan mostrar el número de trabajo pero NO pueden
     * resolver `display_name` con `->relationship()` (esa consulta contra la
     * columna real `name`/`job_number` en SQL, no contra un atributo calculado).
     *
     * Centralizado acá porque el mismo problema se repite en varios recursos
     * (MachineResource, UserResource, Reports) y cada uno reimplementándolo por
     * su cuenta es la forma en que dos de esas copias terminan buscando distinto.
     *
     * @return array<int, string>
     */
    public static function locationSelectOptions(?string $search = null, bool $activeOnly = false): array
    {
        return static::query()
            ->when($activeOnly, fn ($query) => $query->where('active', true))
            ->when(
                filled($search),
                fn ($query) => $query->where(
                    fn ($sub) => $sub->where('name', 'like', "%{$search}%")
                        ->orWhere('job_number', 'like', "%{$search}%")
                )
            )
            ->orderBy('job_number')
            ->orderBy('name')
            ->get()
            ->mapWithKeys(fn (Location $location) => [$location->id => $location->display_name])
            ->all();
    }
}

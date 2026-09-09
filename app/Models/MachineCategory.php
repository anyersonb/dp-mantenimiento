<?php

namespace App\Models;

use App\Models\Concerns\HasManualOrder;
use App\Models\Concerns\LogsPapeleraActivity;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

class MachineCategory extends Model
{
    /** SoftDeletes (Papelera, Lote A). `machines.machine_category_id` es `nullOnDelete`, no cascada. */
    use HasManualOrder, LogsPapeleraActivity, SoftDeletes;

    public function papeleraLabel(): string
    {
        return (string) $this->name;
    }

    protected $guarded = [];

    public function machines(): HasMany
    {
        return $this->hasMany(Machine::class);
    }

    /**
     * Nombre de la categoría en el idioma de quien lee.
     *
     * `name` guarda el valor canónico (en inglés, como salió al parsear el
     * reporte de flota del cliente) y no se toca: es lo que referencian los
     * seeders, el importador y cualquier consulta. La traducción es solo de
     * presentación, con `lang/{es,en}/categories.php`, y **cae al nombre
     * guardado si no hay clave** — así una categoría nueva creada desde el
     * panel se muestra igual sin tener que editar ningún archivo.
     */
    protected function displayName(): Attribute
    {
        return Attribute::get(function (): string {
            $name = (string) $this->name;

            if ($name === '') {
                return '';
            }

            $key = 'categories.'.Str::slug($name);
            $translated = __($key);

            return $translated === $key ? $name : $translated;
        });
    }
}

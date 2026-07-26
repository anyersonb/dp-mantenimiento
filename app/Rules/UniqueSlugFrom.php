<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Hallazgo E6-07, mitad "slug".
 *
 * `locations`, `machine_categories` y `makes` tienen el índice único en `slug`,
 * pero el usuario no escribe el slug: escribe el **nombre**, y el formulario
 * deriva el slug con `Str::slug()`. Consecuencias del diseño anterior:
 *
 *   - un nombre repetido daba un slug repetido y eso era un **500 mudo**;
 *   - poner `->unique()` en el campo `slug`, que es `Hidden`, tampoco servía:
 *     el error se renderiza debajo de un campo invisible, así que el formulario
 *     se negaría a guardar sin decir por qué. Casi tan malo como el 500.
 *
 * Por eso la regla se valida sobre el campo visible —el nombre— pero consulta
 * la columna `slug`, slugificando antes de comparar. Una sola implementación
 * para los tres recursos: es la lección de C3/A4/E6-03 (la misma regla en un
 * solo lugar, no copiada por camino).
 */
class UniqueSlugFrom implements ValidationRule
{
    public function __construct(
        private readonly string $table,
        private readonly ?int $ignoreId = null,
        private readonly string $column = 'slug',
    ) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $slug = Str::slug((string) $value);

        if ($slug === '') {
            return;
        }

        $existe = DB::table($this->table)
            ->where($this->column, $slug)
            ->when($this->ignoreId !== null, fn ($q) => $q->where('id', '!=', $this->ignoreId))
            ->exists();

        if ($existe) {
            $fail(__('errors.name_already_used', ['name' => $value]));
        }
    }
}

<?php

namespace App\Casts;

use App\Support\LocalizedText;
use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;

/**
 * Hallazgo E6-10. Convierte una columna de texto en "texto sin idioma":
 *
 *  - al **leer**, si lo guardado es un sobre de `LocalizedText`, lo renderiza en
 *    el idioma activo. Si es una frase suelta —lo que ya está en la base, o una
 *    nota que escribió una persona— la devuelve tal cual.
 *  - al **escribir**, acepta un `LocalizedText` (guarda el sobre) o un string
 *    (lo guarda literal).
 *
 * El accesor devuelve **string** a propósito: así el resto del sistema y las
 * columnas de Filament siguen funcionando sin cambios, y no hace falta migrar
 * ninguna fila para que lo viejo se siga viendo.
 *
 * Contra: `->searchable()` en SQL busca sobre la columna cruda, o sea el sobre.
 * Donde eso importaba se quitó la búsqueda por el texto y quedó la búsqueda por
 * el código de la máquina, que es por lo que la gente busca de verdad.
 */
class AsLocalizedText implements CastsAttributes
{
    public function get(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        if ($value === null) {
            return null;
        }

        return LocalizedText::fromStored($value)?->render() ?? (string) $value;
    }

    public function set(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        if ($value === null) {
            return null;
        }

        if ($value instanceof LocalizedText) {
            return $value->encode();
        }

        return (string) $value;
    }
}

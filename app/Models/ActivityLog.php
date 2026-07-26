<?php

namespace App\Models;

use App\Casts\AsLocalizedText;
use Spatie\Activitylog\Models\Activity as SpatieActivity;

/**
 * Modelo de bitácora propio, registrado en `config/activitylog.php`.
 *
 * Existe por el hallazgo E6-10: `description` se guardaba ya traducida (y en dos
 * lugares directamente hardcodeada en español), así que un auditor que trabaja
 * en inglés leía la bitácora en español y al revés. Ahora los asientos nuevos
 * guardan clave + parámetros y se renderizan en el idioma del que lee.
 *
 * **Los asientos viejos NO se reescriben.** La bitácora de este proyecto es
 * append-only por decisión declarada (ver CLAUDE.md, "Notas de esquema"): se
 * declara la deriva, no se corrige el pasado. El cast devuelve la frase suelta
 * tal cual, así que los 8 asientos con texto viejo se siguen leyendo igual.
 */
class ActivityLog extends SpatieActivity
{
    public function getCasts(): array
    {
        return array_merge(parent::getCasts(), [
            'description' => AsLocalizedText::class,
        ]);
    }
}

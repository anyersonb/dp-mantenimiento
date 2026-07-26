<?php

namespace App\Rules;

use App\Models\Machine;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Única implementación de la coherencia de una lectura de horómetro.
 *
 * Antes esta regla vivía copiada TRES veces, en
 * `app/Livewire/Field/{ReportForm,FuelLog,ForemanBoard}::isRegressiveReading()`,
 * y su propio docblock declaraba que era "exclusiva del camino de campo". El
 * resultado fue el hallazgo E6-03: por el panel se aceptó sin un solo mensaje
 * una lectura de 10 h fechada después de una de 60 h — un horómetro que baja
 * con el paso del tiempo, que es físicamente imposible.
 *
 * Es la tercera vez en el proyecto que el mismo defecto aparece por tener la
 * regla en un camino y no en todos (C1: flota arreglada y OT afuera; A4: la
 * fórmula de `remaining_hours` duplicada en el lector; y esto). De ahí que la
 * regla viva acá y que los cuatro caminos la consuman.
 *
 * Qué valida, según el contexto:
 *
 *   - **Alta desde campo** (`$readAt` nulo): la lectura no puede ser menor que
 *     la última registrada en la máquina. Conserva el mensaje exacto del fix
 *     M4 (`field.hours_regressive`), que quedó verificado en pantalla con el
 *     rol capataz y está protegido por `RegressiveReadingRejectionTest`.
 *   - **Alta o edición desde el panel** (con fecha): la lectura tiene que
 *     encajar entre sus vecinas en la línea de tiempo — no menor que la
 *     anterior, no mayor que la siguiente.
 *
 * Lo que NO valida: el importador del PM Service Report, que crea los
 * `HorometerReading` directamente sin pasar por validación, porque el Excel del
 * cliente trae filas desordenadas. Eso es deliberado y está documentado en
 * `regla-horometro.md`.
 */
class CoherentHorometerReading implements ValidationRule
{
    public function __construct(
        private ?Machine $machine,
        private ?string $readAt = null,
        private ?int $ignoreReadingId = null,
    ) {}

    /**
     * Devuelve `null` si la lectura es coherente, o `['key' => ..., 'params' => [...]]`
     * con el mensaje que corresponde. Es el único lugar donde se decide.
     *
     * @return array{key: string, params: array<string, int|string>}|null
     */
    public static function problem(
        ?Machine $machine,
        ?int $hours,
        ?string $readAt = null,
        ?int $ignoreReadingId = null,
    ): ?array {
        if ($machine === null || $hours === null) {
            return null;
        }

        // --- Alta desde campo: siempre es la lectura más reciente. ---
        if ($readAt === null) {
            if ($machine->current_hours !== null && $hours < $machine->current_hours) {
                return [
                    'key' => 'field.hours_regressive',
                    'params' => ['hours' => $hours, 'current' => $machine->current_hours],
                ];
            }

            return null;
        }

        // --- Con fecha: tiene que encajar entre sus vecinas. ---
        $vecinas = $machine->readings()
            ->when($ignoreReadingId, fn ($q) => $q->where('id', '!=', $ignoreReadingId))
            ->when(
                $machine->hours_scale_since,
                fn ($q) => $q->where('read_at', '>=', $machine->hours_scale_since)
            );

        $anteriorMasAlta = (clone $vecinas)->where('read_at', '<=', $readAt)->max('hours');

        if ($anteriorMasAlta !== null && $hours < $anteriorMasAlta) {
            return [
                'key' => 'field.hours_regressive',
                'params' => ['hours' => $hours, 'current' => (int) $anteriorMasAlta],
            ];
        }

        $siguienteMasBaja = (clone $vecinas)->where('read_at', '>', $readAt)->min('hours');

        if ($siguienteMasBaja !== null && $hours > $siguienteMasBaja) {
            return [
                'key' => 'field.hours_above_next',
                'params' => ['hours' => $hours, 'next' => (int) $siguienteMasBaja],
            ];
        }

        return null;
    }

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if ($value === null || $value === '') {
            return;
        }

        $problem = self::problem(
            $this->machine,
            (int) round((float) $value),
            $this->readAt,
            $this->ignoreReadingId,
        );

        if ($problem !== null) {
            $fail(__($problem['key'], $problem['params']));
        }
    }
}

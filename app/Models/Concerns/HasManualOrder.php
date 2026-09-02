<?php

namespace App\Models\Concerns;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Asigna `sort_order` automáticamente al crear un registro nuevo (2026-09-01,
 * lote de reordenamiento manual).
 *
 * Sin esto, el orden manual solo existiría para lo que ya tenía el backfill
 * de la migración: cualquier fila creada DESPUÉS del deploy nacería con
 * `sort_order = 0` (el default de la columna), empatada con cualquier otra
 * fila nueva, y el drag-and-drop de Filament no reordena filas que ya
 * arrancan sin un valor propio hasta que alguien las arrastra a mano.
 *
 * Dos modos, ver `manualOrderPrepend()`:
 *
 *   - **Append (default, max + 1):** la fila nueva se coloca al FINAL de la
 *     secuencia ascendente. Es lo que quiere un catálogo simple (obras,
 *     tipos, marcas, líneas de repuestos): lo nuevo aparece al final de la
 *     lista hasta que alguien lo arrastre.
 *   - **Prepend (min - 1, vía "correr" toda la secuencia +1):** la fila nueva
 *     pasa a valer 1 y todo lo demás se corre un puesto. Existe para
 *     `WorkOrder`: Filament siempre pinta el reorderColumn en ASCENDENTE
 *     mientras el modo arrastrar está activo (`CanSortRecords::isTableReordering()`),
 *     así que para que "la más nueva arriba" sobreviva al mismo `defaultSort`
 *     ascendente que usa el modo arrastrar, la más nueva tiene que valer 1,
 *     no el número más alto.
 *
 * El alcance (global vs. por grupo) es el mismo en los dos modos — ver
 * `manualOrderScopeColumn()`.
 */
trait HasManualOrder
{
    protected static function bootHasManualOrder(): void
    {
        static::creating(function ($model): void {
            if (filled($model->sort_order)) {
                return;
            }

            $query = static::query();

            $scopeColumn = $model->manualOrderScopeColumn();

            if ($scopeColumn !== null) {
                $query->where($scopeColumn, $model->{$scopeColumn});
            }

            if ($model->manualOrderPrepend()) {
                // Se corre TODO lo que ya existe en el alcance un puesto hacia
                // abajo antes de asignarle 1 a la fila nueva, para que quede
                // primera bajo el orden ascendente que Filament fuerza
                // mientras el modo arrastrar está activo.
                //
                // `increment()` pisa `updated_at` de TODAS las filas del
                // alcance, y como es una query de builder no dispara eventos
                // — la bitácora no se entera pero el dato sí cambió.
                // `update(['sort_order' => DB::raw(...)])` sobre un
                // Eloquent\Builder tiene EL MISMO problema: Eloquent inyecta
                // `updated_at` solo en el mass-update aunque no se lo pida
                // (comprobado, no es un supuesto). Hace falta bajar a
                // `toBase()` — el query builder de base, sin la capa de
                // Eloquent que agrega esa columna sola. Hallazgo de
                // seguridad, 2026-09-01.
                (clone $query)->toBase()->update(['sort_order' => DB::raw('sort_order + 1')]);
                $model->sort_order = 1;

                return;
            }

            $model->sort_order = ((int) $query->max('sort_order')) + 1;
        });
    }

    /**
     * El incremento del modo prepend y el INSERT de la fila nueva comparten
     * transacción (hallazgo de seguridad, 2026-09-01): antes, el `UPDATE` de
     * "correr" la secuencia corría como una query suelta dentro de
     * `creating`, ANTES del INSERT real de Eloquent. Si el INSERT fallaba
     * después —ej. un código de OT duplicado—, el `UPDATE` ya se había
     * aplicado a la tabla entera y quedaba así: una operación que nunca
     * llegó a existir corrió el orden de todas las demás filas. Envolver
     * `performInsert` (que es quien dispara `creating`, el INSERT y
     * `created`, todo en la misma llamada) en una transacción hace que las
     * dos vivan o mueran juntas.
     */
    protected function performInsert(Builder $query)
    {
        return $this->getConnection()->transaction(fn () => parent::performInsert($query));
    }

    /**
     * Columna que reinicia la numeración por grupo (ej. `work_order_id` para
     * que cada orden de trabajo numere sus propias líneas 1..n). `null` =
     * secuencia global, como en los catálogos simples.
     */
    protected function manualOrderScopeColumn(): ?string
    {
        return null;
    }

    /**
     * `true` = la fila nueva nace en la posición 1 (arriba), corriendo todo lo
     * demás. `false` (default) = nace al final (max + 1).
     */
    protected function manualOrderPrepend(): bool
    {
        return false;
    }
}

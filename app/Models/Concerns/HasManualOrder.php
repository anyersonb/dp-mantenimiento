<?php

namespace App\Models\Concerns;

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
                (clone $query)->increment('sort_order');
                $model->sort_order = 1;

                return;
            }

            $model->sort_order = ((int) $query->max('sort_order')) + 1;
        });
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

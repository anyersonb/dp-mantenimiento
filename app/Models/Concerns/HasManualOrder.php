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
 * Se coloca al final (max + 1) del alcance que corresponda: global para los
 * catálogos, o del "padre" (ej. la orden de trabajo) para las líneas que se
 * reinician por grupo — ver `manualOrderScopeColumn()`.
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
}

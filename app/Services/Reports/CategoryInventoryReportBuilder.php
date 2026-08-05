<?php

namespace App\Services\Reports;

use App\Models\Machine;
use App\Models\MachineCategory;

/**
 * Cuántas máquinas hay de cada tipo (pedido del cliente: "un reporte de cuántos
 * tractores se tiene por categoría").
 *
 * Es el mismo conteo que la rueda del escritorio, pero con dos diferencias
 * deliberadas:
 *
 *   - **Incluye las categorías en cero.** El widget solo muestra las que tienen
 *     máquinas (`machines_count > 0`), y para un inventario eso es un problema:
 *     una categoría vacía es exactamente la información que DP necesitaba
 *     cuando pidió quitar Cold Planer, Sweeper y Tractor "porque no las
 *     tenemos". Un reporte que esconde los ceros no permite verificar eso.
 *   - **Desglosa por estado**, porque "tengo 6 rodillos" y "tengo 6 rodillos, 2
 *     fuera de servicio" son dos respuestas distintas para quien planifica.
 *
 * Las máquinas dadas de baja (soft delete) quedan fuera: el scope por defecto de
 * `Machine` ya las excluye y un inventario debe contar lo que existe.
 */
class CategoryInventoryReportBuilder
{
    /**
     * @return array{rows: array<int, array<string, mixed>>, totals: array<string, int>, uncategorized: int}
     */
    public static function build(): array
    {
        // Los cinco del enum, de `Machine::STATUSES`. Escribir la lista a mano acá
        // fue el defecto: se dejó `unknown` afuera y el desglose sumaba 72 sobre
        // un total de 101 sin decir nada.
        $statuses = Machine::STATUSES;

        $categories = MachineCategory::query()
            ->withCount('machines')
            ->orderBy('name')
            ->get();

        // Un solo agrupado en base para los desgloses, en vez de una consulta por
        // categoría y estado (que serían decenas).
        $byStatus = Machine::query()
            ->selectRaw('machine_category_id, status, COUNT(*) as total')
            ->groupBy('machine_category_id', 'status')
            ->get()
            ->groupBy('machine_category_id');

        $needsReview = Machine::query()
            ->where('needs_review', true)
            ->selectRaw('machine_category_id, COUNT(*) as total')
            ->groupBy('machine_category_id')
            ->pluck('total', 'machine_category_id');

        $rows = $categories->map(function (MachineCategory $category) use ($byStatus, $statuses, $needsReview) {
            $counts = $byStatus->get($category->id, collect())->pluck('total', 'status');

            $row = [
                'category' => $category->display_name,
                'category_raw' => $category->name,
                'total' => (int) $category->machines_count,
                'needs_review' => (int) ($needsReview[$category->id] ?? 0),
            ];

            foreach ($statuses as $status) {
                $row[$status] = (int) ($counts[$status] ?? 0);
            }

            return $row;
        })->all();

        // Máquinas sin tipo asignado. Si existen, el total por categorías no
        // cuadra contra el total de la flota, y eso hay que decirlo en vez de
        // dejar que el lector sume y no le dé.
        $uncategorized = Machine::query()->whereNull('machine_category_id')->count();

        $totals = ['total' => Machine::query()->count(), 'needs_review' => Machine::query()->where('needs_review', true)->count()];

        foreach ($statuses as $status) {
            $totals[$status] = Machine::query()->where('status', $status)->count();
        }

        return [
            'rows' => $rows,
            'totals' => $totals,
            'uncategorized' => $uncategorized,
            'statuses' => $statuses,
        ];
    }
}

<?php

namespace App\Services\Reports;

use App\Models\WorkOrder;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * El reporte de costos de mantenimiento por máquina y periodo.
 *
 * **Una sola implementación, tres consumidores:** la pantalla del panel, el PDF
 * y el Excel. Es la misma razón por la que `WorkOrderCompletionService::serviceHours()`
 * existe: si cada camino armara su propia consulta, un día el PDF y la pantalla
 * mostrarían totales distintos para el mismo mes y nadie sabría cuál creer.
 *
 * **Qué cuenta como gasto (decisión de Anyerson, 2026-08-05): solo repuestos.**
 * El total sale de sumar `cantidad × costo unitario` de cada repuesto cargado en
 * la OT. La mano de obra **no** entra: las horas se registran (`labor_hours`) y
 * se muestran como dato, pero el proyecto no tiene tarifa por hora definida, y
 * valorizarlas con un número inventado convertiría el reporte en ficción.
 *
 * El total se calcula **sumando las líneas de repuestos**, no leyendo
 * `work_orders.parts_cost`. Las dos deberían coincidir (`WorkOrderPartObserver`
 * mantiene la columna), pero un reporte que imprime su propio detalle y un total
 * que no sale de ese detalle es un reporte que puede mentirle al lector sin que
 * el lector pueda notarlo.
 */
class CostReportBuilder
{
    /**
     * @return array{filters: CostReportFilters, machines: array<int, array<string, mixed>>, totals: array<string, mixed>}
     */
    public static function build(CostReportFilters $filters): array
    {
        $workOrders = static::query($filters)->get();

        $machines = $workOrders
            ->groupBy('machine_id')
            ->map(fn (Collection $group) => static::machineBlock($group))
            ->sortBy(fn (array $block) => $block['machine_id_code'])
            ->values()
            ->all();

        return [
            'filters' => $filters,
            'machines' => $machines,
            'totals' => static::totals($machines),
        ];
    }

    /**
     * La consulta, aparte, para que los tests puedan afirmar sobre el filtrado
     * sin tener que armar todo el reporte.
     */
    public static function query(CostReportFilters $filters): Builder
    {
        $from = $filters->from->toDateString();
        $to = $filters->to->toDateString();

        return WorkOrder::query()
            ->with([
                'machine.category',
                'machine.make',
                'location',
                'completer',
                'assignee',
                'parts',
                'checklistResults',
            ])
            ->whereIn('status', $filters->statuses)
            // El periodo se mide por la fecha de cierre, que es cuando el gasto
            // ocurrió. Las OT incluidas que todavía no cerraron no tienen esa
            // fecha, así que para esas se cae a la de apertura — si no, una OT
            // abierta quedaría fuera de todo periodo posible y el filtro de
            // estado "abiertas" no mostraría nada nunca.
            ->where(function (Builder $q) use ($from, $to) {
                $q->whereBetween('completed_at', [$from, $to])
                    ->orWhere(fn (Builder $sub) => $sub
                        ->whereNull('completed_at')
                        ->whereBetween('opened_at', [$from, $to]));
            })
            ->when($filters->types !== [], fn (Builder $q) => $q->whereIn('type', $filters->types))
            ->when($filters->machineIds !== [], fn (Builder $q) => $q->whereIn('machine_id', $filters->machineIds))
            ->when($filters->locationIds !== [], fn (Builder $q) => $q->whereIn('location_id', $filters->locationIds))
            ->when($filters->completedBy !== [], fn (Builder $q) => $q->whereIn('completed_by', $filters->completedBy))
            ->when($filters->idCode !== null, fn (Builder $q) => $q->whereHas(
                'machine',
                fn (Builder $m) => $m->where('id_code', 'like', '%'.$filters->idCode.'%'),
            ))
            ->when($filters->categoryIds !== [], fn (Builder $q) => $q->whereHas(
                'machine',
                fn (Builder $m) => $m->whereIn('machine_category_id', $filters->categoryIds),
            ))
            ->orderBy('completed_at')
            ->orderBy('opened_at')
            ->orderBy('id');
    }

    /**
     * @param  Collection<int, WorkOrder>  $group
     * @return array<string, mixed>
     */
    private static function machineBlock(Collection $group): array
    {
        $machine = $group->first()->machine;

        $rows = $group->map(fn (WorkOrder $wo) => static::workOrderRow($wo))->all();

        return [
            'machine_id' => $machine?->id,
            'machine_id_code' => $machine?->id_code ?? '—',
            'machine_description' => $machine?->description,
            'machine_category' => $machine?->category?->display_name,
            'machine_make' => $machine?->make?->name,
            'machine_model' => $machine?->model,
            'work_orders' => $rows,
            'work_order_count' => count($rows),
            // Los importes y las horas viajan SIEMPRE como float. `array_sum` de
            // un array vacío devuelve int 0, y un total que a veces es entero y a
            // veces decimal termina formateándose distinto según si hubo
            // repuestos o no.
            'parts_total' => (float) array_sum(array_column($rows, 'parts_total')),
            'labor_hours' => (float) array_sum(array_column($rows, 'labor_hours')),
            'parts_without_cost' => (int) array_sum(array_column($rows, 'parts_without_cost')),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function workOrderRow(WorkOrder $wo): array
    {
        $parts = $wo->parts->map(fn ($part) => [
            'part_number' => $part->part_number,
            'description' => $part->description,
            'quantity' => (float) $part->quantity,
            'unit_cost' => $part->unit_cost === null ? null : (float) $part->unit_cost,
            'subtotal' => $part->unit_cost === null ? 0.0 : (float) $part->quantity * (float) $part->unit_cost,
        ])->all();

        $checklist = $wo->checklistResults;

        return [
            'id' => $wo->id,
            'code' => $wo->code,
            'type' => $wo->type,
            'status' => $wo->status,
            'execution_mode' => $wo->execution_mode,
            'service_tier' => $wo->service_tier,
            'opened_at' => $wo->opened_at?->toDateString(),
            'completed_at' => $wo->completed_at?->toDateString(),
            'labor_hours' => (float) ($wo->labor_hours ?? 0),
            'hours_at_open' => $wo->hours_at_open,

            // Quién hizo el mantenimiento. Se muestra `completed_by`, y NO se cae
            // a `assigned_to` cuando falta: son dos cosas distintas y mezclarlas
            // es justo lo que esta columna vino a evitar. El asignado se informa
            // aparte, rotulado como asignado.
            'completed_by' => $wo->completer?->name,
            'assigned_to' => $wo->assignee?->name,

            // Dónde. Congelado al abrir la OT.
            'location' => $wo->location?->name,
            'location_job_number' => $wo->location?->job_number,
            // Rótulo con el número primero ("JOB-100 — Blount Rd"), consistente
            // con Location::displayName() en el resto del panel (pedido del
            // cliente 2026-08-06). 'location' y 'location_job_number' se dejan
            // intactos: el Excel los usa como columnas separadas.
            'location_label' => $wo->location?->display_name,

            'description' => $wo->description,
            'resolution' => $wo->resolution,

            'checklist_total' => $checklist->count(),
            'checklist_ok' => $checklist->where('result', 'ok')->count(),
            'checklist_alert' => $checklist->where('result', 'alert')->count(),
            'checklist_na' => $checklist->where('result', 'na')->count(),
            'checklist_alerts' => $checklist->where('result', 'alert')->map(fn ($item) => [
                'label' => $item->label,
                'detail' => $item->alert_detail,
            ])->values()->all(),

            'parts' => $parts,
            'parts_count' => count($parts),
            'parts_total' => (float) array_sum(array_column($parts, 'subtotal')),
            // Repuestos cargados sin costo unitario. Se cuentan y se informan
            // porque son gasto real que el total NO incluye: sin este aviso, el
            // reporte se lee como completo cuando está corto.
            'parts_without_cost' => count(array_filter($parts, fn (array $p) => $p['unit_cost'] === null)),
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>  $machines
     * @return array<string, mixed>
     */
    private static function totals(array $machines): array
    {
        $rows = [];

        foreach ($machines as $machine) {
            foreach ($machine['work_orders'] as $row) {
                $rows[] = $row;
            }
        }

        return [
            'machine_count' => count($machines),
            'work_order_count' => count($rows),
            'parts_total' => (float) array_sum(array_column($rows, 'parts_total')),
            'labor_hours' => (float) array_sum(array_column($rows, 'labor_hours')),
            'parts_without_cost' => (int) array_sum(array_column($rows, 'parts_without_cost')),
            // Cuánto del reporte no puede responder "quién" y "dónde". Las dos
            // columnas nacieron el 2026-08-05, así que las OT anteriores las
            // tienen vacías y el lector tiene derecho a saber cuántas son.
            'unknown_completer' => count(array_filter($rows, fn (array $r) => $r['completed_by'] === null)),
            'unknown_location' => count(array_filter($rows, fn (array $r) => $r['location'] === null)),
        ];
    }
}

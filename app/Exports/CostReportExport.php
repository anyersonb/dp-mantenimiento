<?php

namespace App\Exports;

use App\Services\Reports\CostReportBuilder;
use App\Services\Reports\CostReportFilters;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithTitle;

/**
 * El reporte de costos en Excel.
 *
 * **Una fila por repuesto**, no una por orden de trabajo. Es a propósito: el
 * Excel se pide para pivotear y para cruzar precios de un mismo repuesto entre
 * proveedores y fechas —que es literalmente para lo que el cliente dijo que
 * registra costos— y eso no se puede hacer si los repuestos vienen resumidos en
 * un total por OT. Cada fila repite la máquina, la OT, el quién, el dónde y el
 * cuándo para que la tabla dinámica pueda agrupar por cualquiera de esos ejes
 * sin tener que rellenar celdas a mano.
 *
 * Las OT sin repuestos aparecen igual, con una fila de repuesto vacía: si se
 * omitieran, el conteo de OT del Excel no coincidiría con el del PDF ni con el
 * de la pantalla, y esa clase de descuadre es la que hace desconfiar de todo el
 * reporte.
 *
 * El PDF y la pantalla salen del mismo `CostReportBuilder`, así que los totales
 * cuadran por construcción.
 */
class CostReportExport implements FromCollection, ShouldAutoSize, WithHeadings, WithTitle
{
    public function __construct(private readonly CostReportFilters $filters) {}

    public function title(): string
    {
        return __('reports.report_costs');
    }

    public function collection(): Collection
    {
        $report = CostReportBuilder::build($this->filters);

        $rows = collect();

        foreach ($report['machines'] as $block) {
            foreach ($block['work_orders'] as $wo) {
                if ($wo['parts'] === []) {
                    $rows->push($this->row($block, $wo, null));

                    continue;
                }

                foreach ($wo['parts'] as $part) {
                    $rows->push($this->row($block, $wo, $part));
                }
            }
        }

        return $rows;
    }

    public function headings(): array
    {
        return [
            __('fleet.machine_number'),
            __('fleet.description'),
            __('fleet.category'),
            __('wo.code'),
            __('wo.type'),
            __('fleet.status'),
            __('wo.opened_at'),
            __('wo.completed_at'),
            __('reports.completed_by'),
            __('wo.assigned_to'),
            __('nav.job_number'),
            __('reports.location'),
            __('wo.labor_hours'),
            __('reports.checklist_total'),
            __('reports.checklist_alerts'),
            __('reports.part_number'),
            __('reports.part_description'),
            __('reports.quantity'),
            __('reports.unit_cost'),
            __('reports.subtotal'),
        ];
    }

    /**
     * @param  array<string, mixed>  $block
     * @param  array<string, mixed>  $wo
     * @param  array<string, mixed>|null  $part
     * @return array<int, mixed>
     */
    private function row(array $block, array $wo, ?array $part): array
    {
        return [
            $block['machine_id_code'],
            $block['machine_description'],
            $block['machine_category'],
            $wo['code'],
            __('wo.'.$wo['type']),
            __('wo.'.$wo['status']),
            $wo['opened_at'],
            $wo['completed_at'],
            // Sin sesión que sellar, la celda queda con el aviso explícito en
            // vez de vacía: una celda vacía en Excel se lee como "no aplica".
            $wo['completed_by'] ?? __('reports.not_recorded'),
            $wo['assigned_to'],
            $wo['location_job_number'],
            $wo['location'] ?? __('reports.not_recorded'),
            $wo['labor_hours'],
            $wo['checklist_total'],
            $wo['checklist_alert'],
            $part['part_number'] ?? null,
            $part['description'] ?? null,
            $part === null ? null : $part['quantity'],
            // Un repuesto sin costo unitario NO va con 0: sería afirmar que fue
            // gratis. Va con el aviso, y el subtotal queda en 0 porque es lo que
            // el total del reporte cuenta.
            $part === null ? null : ($part['unit_cost'] ?? __('reports.no_cost_loaded')),
            $part === null ? null : $part['subtotal'],
        ];
    }
}

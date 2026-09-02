@php
    $filters = $report['filters'];
    $totals = $report['totals'];
@endphp
<!DOCTYPE html>
<html lang="{{ app()->getLocale() }}">
<head>
    <meta charset="utf-8">
    <title>{{ __('reports.report_costs') }}</title>
    <style>
        body { font-family: sans-serif; font-size: 10px; color: #1f2937; }
        .header { border-bottom: 2px solid #f59e0b; padding-bottom: 8px; margin-bottom: 12px; }
        .header img { height: 34px; vertical-align: middle; margin-right: 10px; }
        .header h1 { font-size: 16px; margin: 0; display: inline-block; vertical-align: middle; }
        .header .meta { font-size: 9px; color: #6b7280; margin-top: 4px; }
        .applied { background: #f9fafb; border: 1px solid #e5e7eb; padding: 5px 7px; margin-bottom: 10px; font-size: 9px; }
        .applied strong { color: #374151; }
        .kpis { width: 100%; margin-bottom: 10px; border-collapse: collapse; }
        .kpis td { border: 1px solid #e5e7eb; padding: 6px; text-align: center; width: 25%; }
        .kpis .label { font-size: 8px; color: #6b7280; text-transform: uppercase; }
        .kpis .value { font-size: 14px; font-weight: bold; }
        .warn { background: #fffbeb; border: 1px solid #fcd34d; padding: 5px 7px; margin-bottom: 10px; font-size: 9px; color: #92400e; }
        .warn li { margin: 1px 0; }
        table.grid { width: 100%; border-collapse: collapse; margin-bottom: 4px; }
        table.grid th, table.grid td { border: 1px solid #d1d5db; padding: 3px 5px; text-align: left; font-size: 9px; }
        table.grid th { background-color: #fef3c7; }
        .machine-head { background: #1f2937; color: #fff; padding: 4px 6px; font-size: 11px; font-weight: bold; margin-top: 12px; }
        .machine-head span { font-weight: normal; font-size: 9px; color: #d1d5db; }
        .wo { border: 1px solid #d1d5db; border-top: none; padding: 5px 6px; }
        .wo-title { font-weight: bold; font-size: 10px; margin-bottom: 3px; }
        .wo-meta { font-size: 9px; color: #4b5563; margin-bottom: 4px; }
        .wo-meta b { color: #1f2937; }
        .text-right { text-align: right; }
        .muted { color: #9ca3af; font-style: italic; }
        .subtotal { background: #f3f4f6; font-weight: bold; }
        .grand { background: #1f2937; color: #fff; padding: 6px; font-size: 12px; font-weight: bold; margin-top: 14px; }
        .alerts { background: #fef2f2; border: 1px solid #fecaca; padding: 3px 5px; font-size: 9px; color: #991b1b; margin-top: 3px; }
        footer { margin-top: 14px; font-size: 8px; color: #9ca3af; border-top: 1px solid #e5e7eb; padding-top: 4px; }
        .page-break { page-break-after: always; }
    </style>
</head>
<body>
    <div class="header">
        <img src="{{ $logoPath }}" alt="DP">
        <h1>{{ __('reports.report_costs') }}</h1>
        <div class="meta">
            {{ __('reports.period') }}:
            <strong>{{ $filters->from->format('Y-m-d') }} — {{ $filters->to->format('Y-m-d') }}</strong>
            &nbsp;·&nbsp; {{ __('mgmt.generated_at') }}: {{ $generatedAt }}
            @if($generatedBy) &nbsp;·&nbsp; {{ __('reports.generated_by') }}: {{ $generatedBy }} @endif
        </div>
    </div>

    {{-- Los filtros aplicados se imprimen en el documento. Un PDF de costos que
         circula por correo sin decir qué recorte muestra es un PDF que alguien va
         a leer como "todo el gasto" cuando es el de una obra y un mes. --}}
    <div class="applied">
        <strong>{{ __('reports.applied_filters') }}:</strong>
        {{ __('fleet.status') }}:
        {{ implode(', ', array_map(fn ($s) => __('wo.'.$s), $filters->statuses)) }}
        @if($filters->types !== [])
            &nbsp;|&nbsp; {{ __('wo.type') }}: {{ implode(', ', array_map(fn ($t) => __('wo.'.$t), $filters->types)) }}
        @endif
        @if($filters->idCode)
            &nbsp;|&nbsp; {{ __('fleet.machine_number') }}: {{ $filters->idCode }}
        @endif
        @if($filters->machineIds !== [])
            &nbsp;|&nbsp; {{ __('reports.machines') }}: {{ count($filters->machineIds) }} {{ __('reports.selected') }}
        @endif
        @if($filters->categoryIds !== [])
            &nbsp;|&nbsp; {{ __('fleet.category') }}: {{ count($filters->categoryIds) }} {{ __('reports.selected') }}
        @endif
        @if($filters->locationIds !== [])
            &nbsp;|&nbsp; {{ __('reports.locations') }}: {{ count($filters->locationIds) }} {{ __('reports.selected') }}
        @endif
        @if($filters->completedBy !== [])
            &nbsp;|&nbsp; {{ __('reports.completed_by') }}: {{ count($filters->completedBy) }} {{ __('reports.selected') }}
        @endif
    </div>

    <table class="kpis">
        <tr>
            <td>
                <div class="label">{{ __('reports.total_spent') }}</div>
                <div class="value">${{ number_format($totals['parts_total'], 2) }}</div>
                <div class="label">{{ __('reports.parts_only') }}</div>
            </td>
            <td>
                <div class="label">{{ __('reports.machines_with_spend') }}</div>
                <div class="value">{{ $totals['machine_count'] }}</div>
            </td>
            <td>
                <div class="label">{{ __('reports.work_orders') }}</div>
                <div class="value">{{ $totals['work_order_count'] }}</div>
            </td>
            <td>
                <div class="label">{{ __('reports.labor_hours') }}</div>
                <div class="value">{{ number_format($totals['labor_hours'], 1) }} h</div>
                <div class="label">{{ __('reports.not_priced') }}</div>
            </td>
        </tr>
    </table>

    @if($totals['parts_without_cost'] > 0 || $totals['unknown_completer'] > 0 || $totals['unknown_location'] > 0)
        <div class="warn">
            <strong>{{ __('reports.data_gaps') }}</strong>
            <ul>
                @if($totals['parts_without_cost'] > 0)
                    <li>{{ __('reports.gap_parts_without_cost', ['count' => $totals['parts_without_cost']]) }}</li>
                @endif
                @if($totals['unknown_completer'] > 0)
                    <li>{{ __('reports.gap_unknown_completer', ['count' => $totals['unknown_completer']]) }}</li>
                @endif
                @if($totals['unknown_location'] > 0)
                    <li>{{ __('reports.gap_unknown_location', ['count' => $totals['unknown_location']]) }}</li>
                @endif
            </ul>
        </div>
    @endif

    @if($report['machines'] === [])
        <p class="muted">{{ __('reports.no_results') }}</p>
    @else
        {{-- Resumen por máquina, para leer el total de un tiro antes del detalle. --}}
        <table class="grid">
            <thead>
                <tr>
                    <th>{{ __('fleet.machine_number') }}</th>
                    <th>{{ __('fleet.category') }}</th>
                    <th class="text-right">{{ __('reports.work_orders') }}</th>
                    <th class="text-right">{{ __('reports.labor_hours') }}</th>
                    <th class="text-right">{{ __('reports.parts_spend') }}</th>
                </tr>
            </thead>
            <tbody>
                @foreach($report['machines'] as $block)
                    <tr>
                        <td><strong>{{ $block['machine_id_code'] }}</strong></td>
                        <td>{{ $block['machine_category'] ?? '—' }}</td>
                        <td class="text-right">{{ $block['work_order_count'] }}</td>
                        <td class="text-right">{{ number_format($block['labor_hours'], 1) }}</td>
                        <td class="text-right">${{ number_format($block['parts_total'], 2) }}</td>
                    </tr>
                @endforeach
                <tr class="subtotal">
                    <td colspan="2">{{ __('reports.grand_total') }}</td>
                    <td class="text-right">{{ $totals['work_order_count'] }}</td>
                    <td class="text-right">{{ number_format($totals['labor_hours'], 1) }}</td>
                    <td class="text-right">${{ number_format($totals['parts_total'], 2) }}</td>
                </tr>
            </tbody>
        </table>

        <div class="page-break"></div>

        <h2 style="font-size:13px;">{{ __('reports.detail') }}</h2>

        @foreach($report['machines'] as $block)
            <div class="machine-head">
                {{ $block['machine_id_code'] }}
                <span>
                    {{ $block['machine_description'] }}
                    @if($block['machine_make'] || $block['machine_model'])
                        — {{ $block['machine_make'] }} {{ $block['machine_model'] }}
                    @endif
                    · {{ __('reports.parts_spend') }}: ${{ number_format($block['parts_total'], 2) }}
                </span>
            </div>

            @foreach($block['work_orders'] as $wo)
                <div class="wo">
                    <div class="wo-title">
                        {{ $wo['code'] }} — {{ __('wo.'.$wo['type']) }}
                        @if($wo['service_tier']) ({{ $wo['service_tier'] }} h) @endif
                        · {{ __('wo.'.$wo['status']) }}
                    </div>
                    <div class="wo-meta">
                        <b>{{ __('reports.completed_by') }}:</b>
                        @if($wo['completed_by'])
                            {{ $wo['completed_by'] }}
                        @else
                            <span class="muted">{{ __('reports.not_recorded') }}</span>
                        @endif
                        @if($wo['assigned_to'])
                            &nbsp;(<b>{{ __('wo.assigned_to') }}:</b> {{ $wo['assigned_to'] }})
                        @endif
                        &nbsp;|&nbsp;
                        <b>{{ __('reports.location') }}:</b>
                        @if($wo['location'])
                            {{ $wo['location_label'] }}
                        @else
                            <span class="muted">{{ __('reports.not_recorded') }}</span>
                        @endif
                        &nbsp;|&nbsp;
                        <b>{{ __('wo.opened_at') }}:</b> {{ $wo['opened_at'] ?? '—' }}
                        &nbsp;|&nbsp;
                        <b>{{ __('wo.completed_at') }}:</b> {{ $wo['completed_at'] ?? '—' }}
                        &nbsp;|&nbsp;
                        <b>{{ __('wo.labor_hours') }}:</b> {{ number_format($wo['labor_hours'], 1) }} h
                        @if($wo['execution_mode'])
                            &nbsp;|&nbsp; <b>{{ __('wo.execution_mode') }}:</b> {{ __('wo.'.$wo['execution_mode']) }}
                        @endif
                    </div>

                    @if($wo['description'])
                        <div class="wo-meta"><b>{{ __('fleet.description') }}:</b> {{ $wo['description'] }}</div>
                    @endif

                    {{-- Checklist: el resumen, y el detalle SOLO de los ítems que
                         salieron con alerta. Imprimir los 61 ítems de cada DVIR
                         haría un PDF de cientos de páginas que nadie lee. --}}
                    @if($wo['checklist_total'] > 0)
                        <div class="wo-meta">
                            <b>{{ __('reports.checklist') }}:</b>
                            {{ $wo['checklist_total'] }} {{ __('reports.items') }} —
                            {{ $wo['checklist_ok'] }} {{ __('checklist.result_ok') }},
                            {{ $wo['checklist_alert'] }} {{ __('checklist.result_alert') }},
                            {{ $wo['checklist_na'] }} {{ __('checklist.result_na') }}
                        </div>
                        @if($wo['checklist_alerts'] !== [])
                            <div class="alerts">
                                <b>{{ __('reports.checklist_alerts') }}:</b>
                                <ul>
                                    @foreach($wo['checklist_alerts'] as $alert)
                                        <li>{{ $alert['label'] }}@if($alert['detail']): {{ $alert['detail'] }}@endif</li>
                                    @endforeach
                                </ul>
                            </div>
                        @endif
                    @else
                        <div class="wo-meta muted">{{ __('reports.no_checklist') }}</div>
                    @endif

                    @if($wo['parts'] !== [])
                        <table class="grid">
                            <thead>
                                <tr>
                                    <th>{{ __('reports.part_number') }}</th>
                                    <th>{{ __('reports.part_description') }}</th>
                                    <th class="text-right">{{ __('reports.quantity') }}</th>
                                    <th class="text-right">{{ __('reports.unit_cost') }}</th>
                                    <th class="text-right">{{ __('reports.subtotal') }}</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach($wo['parts'] as $part)
                                    <tr>
                                        <td>{{ $part['part_number'] ?? '—' }}</td>
                                        <td>{{ $part['description'] ?? '—' }}</td>
                                        <td class="text-right">{{ rtrim(rtrim(number_format($part['quantity'], 2), '0'), '.') }}</td>
                                        <td class="text-right">
                                            @if($part['unit_cost'] === null)
                                                <span class="muted">{{ __('reports.no_cost_loaded') }}</span>
                                            @else
                                                ${{ number_format($part['unit_cost'], 2) }}
                                            @endif
                                        </td>
                                        <td class="text-right">${{ number_format($part['subtotal'], 2) }}</td>
                                    </tr>
                                @endforeach
                                <tr class="subtotal">
                                    <td colspan="4">{{ __('reports.wo_total') }}</td>
                                    <td class="text-right">${{ number_format($wo['parts_total'], 2) }}</td>
                                </tr>
                            </tbody>
                        </table>
                    @else
                        <div class="wo-meta muted">{{ __('reports.no_parts') }}</div>
                    @endif
                </div>
            @endforeach
        @endforeach

        @php
            $taxRateLabel = rtrim(rtrim(number_format($totals['tax_rate'], 2), '0'), '.');
        @endphp
        <table class="grid" style="margin-top:14px;">
            <tbody>
                <tr>
                    <td colspan="4">{{ __('reports.subtotal_parts') }}</td>
                    <td class="text-right">${{ number_format($totals['subtotal'], 2) }}</td>
                </tr>
                <tr>
                    <td colspan="4">{{ __('reports.tax_amount_label', ['rate' => $taxRateLabel]) }}</td>
                    <td class="text-right">${{ number_format($totals['tax_amount'], 2) }}</td>
                </tr>
                <tr class="subtotal">
                    <td colspan="4"><strong>{{ __('reports.grand_total_with_tax') }}</strong></td>
                    <td class="text-right"><strong>${{ number_format($totals['total'], 2) }}</strong></td>
                </tr>
            </tbody>
        </table>

        <div class="grand">
            {{ __('reports.grand_total_with_tax') }}: ${{ number_format($totals['total'], 2) }}
            &nbsp;·&nbsp; {{ $totals['work_order_count'] }} {{ __('reports.work_orders') }}
            &nbsp;·&nbsp; {{ $totals['machine_count'] }} {{ __('fleet.machines') }}
        </div>
    @endif

    <footer>
        {{ __('reports.footer_note') }}
    </footer>
</body>
</html>

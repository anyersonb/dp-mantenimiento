{{--
    Reporte en pantalla.

    **El CSS va acá, embebido, y no en clases de Tailwind.** Motivo medido, no
    preferencia: el panel se sirve con el CSS de Filament ya compilado, y ese
    build **no contiene** buena parte de las utilidades que uno da por sentadas.
    Comprobado buscando el selector real en `public/css/filament/filament/app.css`:
    `.grid{` existe pero `.grid-cols-4{` NO, así que un `grid grid-cols-2
    sm:grid-cols-4` deja `display:grid` sin plantilla de columnas y las cuatro
    tarjetas salen apiladas. Lo mismo con `pr-3`, `border-t-2`, `items-baseline`,
    `mb-1` y `space-y-0.5`.

    (Y ojo con cómo se verifica: `grep -c grid-cols-4` sobre un CSS minificado
    devuelve 1 porque el archivo entero es UNA línea y "grid-cols-4" aparece como
    subcadena de otra clase. Hay que buscar el selector `\.grid-cols-4{`.)

    Añadir las clases faltantes exigiría recompilar con Vite y **subir los assets
    aparte** en cada despliegue —están gitignored— para que el reporte no se vea
    roto. Con el CSS acá, el archivo que se sube es el mismo que se ve.
--}}
<x-filament-panels::page>
    <style>
        .dp-rep { font-size: 0.875rem; }
        .dp-rep-head { display: flex; flex-wrap: wrap; align-items: flex-end;
            justify-content: space-between; gap: 0.5rem; margin-bottom: 1rem; }
        .dp-rep-title { font-size: 1rem; font-weight: 600; color: rgb(3 7 18); }
        .dp-rep-sub { font-size: 0.875rem; color: rgb(107 114 128); }

        .dp-kpis { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 0.75rem; margin-bottom: 1rem; }
        @media (min-width: 768px) { .dp-kpis { grid-template-columns: repeat(4, minmax(0, 1fr)); } }
        .dp-kpi { border-radius: 0.5rem; background: rgb(249 250 251); padding: 0.75rem; }
        .dp-kpi-label { font-size: 0.75rem; color: rgb(107 114 128); }
        .dp-kpi-value { font-size: 1.25rem; font-weight: 700; color: rgb(3 7 18); }
        .dp-kpi-foot { font-size: 0.75rem; color: rgb(156 163 175); }

        .dp-warn { border-radius: 0.5rem; background: rgb(255 251 235);
            border: 1px solid rgb(253 230 138); padding: 0.75rem; margin-bottom: 1rem; }
        .dp-warn-title { font-weight: 600; color: rgb(146 64 14); margin-bottom: 0.25rem; }
        .dp-warn ul { list-style: disc inside; color: rgb(180 83 9); margin: 0; padding: 0; }
        .dp-warn li { margin: 0.125rem 0; }

        .dp-scroll { overflow-x: auto; }
        .dp-table { width: 100%; border-collapse: collapse; }
        .dp-table th, .dp-table td { padding: 0.5rem 0.75rem 0.5rem 0; text-align: left; }
        .dp-table thead tr { border-bottom: 1px solid rgb(229 231 235); }
        .dp-table th { font-weight: 600; white-space: nowrap; }
        .dp-table tbody tr { border-bottom: 1px solid rgb(243 244 246); }
        .dp-table tfoot tr { border-top: 2px solid rgb(209 213 219); font-weight: 700; }
        .dp-num { text-align: right; }
        .dp-strong { font-weight: 500; color: rgb(3 7 18); }
        .dp-desc { display: block; font-size: 0.75rem; color: rgb(107 114 128); }
        .dp-zero { color: rgb(156 163 175); }
        .dp-empty { text-align: center; color: rgb(107 114 128); padding: 3rem 0; }
        .dp-note { margin-top: 0.75rem; font-size: 0.75rem; color: rgb(107 114 128); }

        .dark .dp-rep-title, .dark .dp-kpi-value, .dark .dp-strong { color: rgb(255 255 255); }
        .dark .dp-kpi { background: rgba(255, 255, 255, 0.05); }
        .dark .dp-table thead tr { border-bottom-color: rgba(255, 255, 255, 0.1); }
        .dark .dp-table tbody tr { border-bottom-color: rgba(255, 255, 255, 0.05); }
        .dark .dp-table tfoot tr { border-top-color: rgba(255, 255, 255, 0.2); }
        .dark .dp-warn { background: rgba(245, 158, 11, 0.1); border-color: rgba(245, 158, 11, 0.2); }
        .dark .dp-warn-title { color: rgb(252 211 77); }
        .dark .dp-warn ul { color: rgba(253, 230, 138, 0.9); }
    </style>

    {{ $this->form }}

    @php
        $report = $this->selectedReport();
        $data = $this->reportData();
    @endphp

    @if($report === 'costs')
        @php
            $filters = $data['filters'];
            $totals = $data['totals'];
        @endphp

        <div class="fi-section rounded-xl bg-white shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10 p-4 dp-rep">
            <div class="dp-rep-head">
                <h2 class="dp-rep-title">{{ __('reports.report_costs') }}</h2>
                <span class="dp-rep-sub">
                    {{ $filters->from->translatedFormat('d M Y') }} — {{ $filters->to->translatedFormat('d M Y') }}
                </span>
            </div>

            {{-- Totales del periodo. Solo repuestos: la mano de obra se informa en
                 horas y no se valoriza, porque no hay tarifa definida. --}}
            <div class="dp-kpis">
                <div class="dp-kpi">
                    <div class="dp-kpi-label">{{ __('reports.total_spent') }}</div>
                    <div class="dp-kpi-value">${{ number_format($totals['parts_total'], 2) }}</div>
                    <div class="dp-kpi-foot">{{ __('reports.parts_only') }}</div>
                </div>
                <div class="dp-kpi">
                    <div class="dp-kpi-label">{{ __('reports.machines_with_spend') }}</div>
                    <div class="dp-kpi-value">{{ $totals['machine_count'] }}</div>
                </div>
                <div class="dp-kpi">
                    <div class="dp-kpi-label">{{ __('reports.work_orders') }}</div>
                    <div class="dp-kpi-value">{{ $totals['work_order_count'] }}</div>
                </div>
                <div class="dp-kpi">
                    <div class="dp-kpi-label">{{ __('reports.labor_hours') }}</div>
                    <div class="dp-kpi-value">{{ number_format($totals['labor_hours'], 1) }} h</div>
                    <div class="dp-kpi-foot">{{ __('reports.not_priced') }}</div>
                </div>
            </div>

            {{-- Avisos de integridad. Un reporte de costos que no dice qué le falta
                 se lee como completo cuando está corto. --}}
            @if($totals['parts_without_cost'] > 0 || $totals['unknown_completer'] > 0 || $totals['unknown_location'] > 0)
                <div class="dp-warn">
                    <div class="dp-warn-title">{{ __('reports.data_gaps') }}</div>
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

            @if($data['machines'] === [])
                <div class="dp-empty">{{ __('reports.no_results') }}</div>
            @else
                <div class="dp-scroll">
                    <table class="dp-table">
                        <thead>
                            <tr>
                                <th>{{ __('fleet.machine_number') }}</th>
                                <th>{{ __('fleet.category') }}</th>
                                <th class="dp-num">{{ __('reports.work_orders') }}</th>
                                <th class="dp-num">{{ __('reports.labor_hours') }}</th>
                                <th class="dp-num">{{ __('reports.parts_spend') }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($data['machines'] as $block)
                                <tr>
                                    <td class="dp-strong">
                                        {{ $block['machine_id_code'] }}
                                        @if($block['machine_description'])
                                            <span class="dp-desc">{{ $block['machine_description'] }}</span>
                                        @endif
                                    </td>
                                    <td>{{ $block['machine_category'] ?? '—' }}</td>
                                    <td class="dp-num">{{ $block['work_order_count'] }}</td>
                                    <td class="dp-num">{{ number_format($block['labor_hours'], 1) }}</td>
                                    <td class="dp-num"><strong>${{ number_format($block['parts_total'], 2) }}</strong></td>
                                </tr>
                            @endforeach
                        </tbody>
                        <tfoot>
                            <tr>
                                <td colspan="2">{{ __('reports.grand_total') }}</td>
                                <td class="dp-num">{{ $totals['work_order_count'] }}</td>
                                <td class="dp-num">{{ number_format($totals['labor_hours'], 1) }}</td>
                                <td class="dp-num">${{ number_format($totals['parts_total'], 2) }}</td>
                            </tr>
                        </tfoot>
                    </table>
                </div>

                <p class="dp-note">{{ __('reports.detail_in_files') }}</p>
            @endif
        </div>
    @else
        {{-- Inventario por categoría --}}
        <div class="fi-section rounded-xl bg-white shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10 p-4 dp-rep">
            <div class="dp-rep-head">
                <h2 class="dp-rep-title">{{ __('reports.report_category_inventory') }}</h2>
            </div>

            @if($data['uncategorized'] > 0)
                <div class="dp-warn">
                    <div class="dp-warn-title">{{ __('reports.data_gaps') }}</div>
                    <ul>
                        <li>{{ __('reports.uncategorized_notice', ['count' => $data['uncategorized']]) }}</li>
                    </ul>
                </div>
            @endif

            <div class="dp-scroll">
                <table class="dp-table">
                    <thead>
                        <tr>
                            <th>{{ __('fleet.category') }}</th>
                            <th class="dp-num">{{ __('reports.total') }}</th>
                            @foreach($data['statuses'] as $status)
                                <th class="dp-num">{{ __('fleet.status_'.$status) }}</th>
                            @endforeach
                            <th class="dp-num">{{ __('fleet.needs_review') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($data['rows'] as $row)
                            <tr @class(['dp-zero' => $row['total'] === 0])>
                                <td class="dp-strong">{{ $row['category'] }}</td>
                                <td class="dp-num"><strong>{{ $row['total'] }}</strong></td>
                                @foreach($data['statuses'] as $status)
                                    <td class="dp-num">{{ $row[$status] }}</td>
                                @endforeach
                                <td class="dp-num">{{ $row['needs_review'] }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                    <tfoot>
                        <tr>
                            <td>{{ __('reports.grand_total') }}</td>
                            <td class="dp-num">{{ $data['totals']['total'] }}</td>
                            @foreach($data['statuses'] as $status)
                                <td class="dp-num">{{ $data['totals'][$status] }}</td>
                            @endforeach
                            <td class="dp-num">{{ $data['totals']['needs_review'] }}</td>
                        </tr>
                    </tfoot>
                </table>
            </div>
        </div>
    @endif
</x-filament-panels::page>

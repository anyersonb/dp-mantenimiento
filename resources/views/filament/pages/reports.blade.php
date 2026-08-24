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
        /* Los KPIs de costos quedaron en tres al sacarse el conteo de órdenes
           de trabajo (clienta, 2026-08-24). Con la plantilla de cuatro, la
           última columna quedaba vacía y las tarjetas se veían desalineadas
           contra la tabla de abajo. */
        @media (min-width: 768px) { .dp-kpis-3 { grid-template-columns: repeat(3, minmax(0, 1fr)); } }
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
        /* El texto atenuado necesita su propio tono en oscuro. Medido en el
           navegador contra el fondo real del panel (zinc-900, rgb(24 24 27) —
           NO el gray-900 de la paleta): gray-500 da 3.66:1 y gray-600 da
           2.34:1, ambos por debajo del 4.5:1 que pide WCAG AA para texto
           chico. gray-400 es el tono que este mismo archivo ya usa para lo
           atenuado en oscuro (.dp-kpi-foot, .dp-detail-figures) y mide 6.1:1.
           Sin esta regla heredaban el color del modo claro. */
        .dark .dp-rep-sub, .dark .dp-kpi-label, .dark .dp-desc,
        .dark .dp-note, .dark .dp-empty { color: rgb(156 163 175); }
        .dark .dp-kpi { background: rgba(255, 255, 255, 0.05); }
        .dark .dp-table thead tr { border-bottom-color: rgba(255, 255, 255, 0.1); }
        .dark .dp-table tbody tr { border-bottom-color: rgba(255, 255, 255, 0.05); }
        .dark .dp-table tfoot tr { border-top-color: rgba(255, 255, 255, 0.2); }
        .dark .dp-warn { background: rgba(245, 158, 11, 0.1); border-color: rgba(245, 158, 11, 0.2); }
        .dark .dp-warn-title { color: rgb(252 211 77); }
        .dark .dp-warn ul { color: rgba(253, 230, 138, 0.9); }

        /* Botón de impresión: nace del pedido "viewable online without
           exporting" — imprime la misma vista que ya está en pantalla, sin
           bajar el PDF. Estilo a mano porque .dp-* es todo lo que hay. */
        .dp-print-bar { display: flex; justify-content: flex-end; margin-bottom: 0.75rem; }
        .dp-print-btn { display: inline-flex; align-items: center; gap: 0.375rem;
            border-radius: 0.5rem; border: 1px solid rgb(209 213 219);
            background: rgb(255 255 255); color: rgb(55 65 81);
            padding: 0.375rem 0.75rem; font-size: 0.8125rem; font-weight: 500;
            cursor: pointer; }
        .dark .dp-print-btn { background: rgba(255, 255, 255, 0.05); border-color: rgba(255, 255, 255, 0.15); color: rgb(229 231 235); }

        /* Detalle de la máquina elegida en el select. Antes era un <details>
           por máquina; ahora es UNA sola tarjeta siempre abierta, así que no
           hay nada que desplegar (ni para leer ni para imprimir). */
        .dp-detail-list { margin-top: 1rem; border: 1px solid rgb(229 231 235);
            border-radius: 0.5rem; overflow: hidden; }
        .dp-detail-head { padding: 0.625rem 0.75rem; font-weight: 600;
            color: rgb(3 7 18); background: rgb(249 250 251);
            display: flex; justify-content: space-between;
            gap: 0.75rem; flex-wrap: wrap; }
        .dp-detail-figures { font-weight: 400; font-size: 0.8125rem; color: rgb(107 114 128); }
        .dp-detail-head b { color: rgb(3 7 18); }
        .dp-detail-body { padding: 0.75rem; }
        .dp-wo { border: 1px solid rgb(229 231 235); border-radius: 0.375rem; padding: 0.625rem 0.75rem; margin-bottom: 0.5rem; }
        .dp-wo-title { font-weight: 600; color: rgb(3 7 18); margin-bottom: 0.25rem; }
        .dp-wo-meta { font-size: 0.8125rem; color: rgb(75 85 99); margin-bottom: 0.25rem; }
        .dp-wo-meta b { color: rgb(3 7 18); }
        .dp-badge { display: inline-block; border-radius: 9999px; padding: 0.0625rem 0.5rem; font-size: 0.6875rem; font-weight: 600; }
        .dp-badge-ok { background: rgb(220 252 231); color: rgb(22 101 52); }
        .dp-badge-alert { background: rgb(254 240 138); color: rgb(113 63 18); }
        .dp-badge-muted { background: rgb(243 244 246); color: rgb(107 114 128); }
        .dp-alerts { background: rgb(254 242 242); border: 1px solid rgb(254 202 202); color: rgb(153 27 27);
            padding: 0.375rem 0.5rem; font-size: 0.8125rem; border-radius: 0.375rem; margin-top: 0.25rem; }
        .dp-alerts ul { margin: 0; padding-left: 1.1rem; }

        .dark .dp-detail-list { border-color: rgba(255, 255, 255, 0.1); }
        .dark .dp-detail-head { background: rgba(255, 255, 255, 0.05); color: rgb(255 255 255); }
        .dark .dp-detail-head b { color: rgb(255 255 255); }
        .dark .dp-detail-figures { color: rgb(156 163 175); }
        .dark .dp-wo { border-color: rgba(255, 255, 255, 0.08); }
        .dark .dp-wo-title { color: rgb(255 255 255); }
        /* Va ANTES de la regla del <b>: el selector con `b` tiene más
           especificidad, así que las negritas siguen saliendo en blanco. */
        .dark .dp-wo-meta { color: rgb(156 163 175); }
        .dark .dp-wo-meta b { color: rgb(255 255 255); }
        .dark .dp-alerts { background: rgba(239, 68, 68, 0.1); border-color: rgba(239, 68, 68, 0.25); color: rgb(252 165 165); }

        /* Al imprimir: se oculta el chrome del panel que no tiene sentido en
           papel y la tabla resumen deja de scrollear y se muestra completa.

           Nota histórica, para no repetir el diagnóstico: cuando el detalle era
           un <details> por máquina había que abrirlos con JS
           (`beforeprint`/`afterprint`), porque un <details> cerrado esconde su
           contenido en el pseudo-elemento `::details-content` y el navegador lo
           tapa con `content-visibility` — ningún `display` puesto en un hijo lo
           pisa. Al pasar el detalle a UNA máquina siempre abierta, ese problema
           dejó de existir y el script se sacó. Si algún día vuelve el acordeón,
           vuelve el script. */
        @media print {
            .dp-print-bar, .fi-sidebar, .fi-topbar, .fi-header-actions, nav { display: none !important; }
            .dp-scroll { overflow: visible !important; }
        }
    </style>

    {{ $this->form }}

    <div class="dp-print-bar">
        <button type="button" class="dp-print-btn" onclick="window.print()">
            <x-heroicon-o-printer style="width:1rem;height:1rem;" />
            {{ __('reports.print') }}
        </button>
    </div>

    {{-- Acá vivía un script que abría los <details> del detalle antes de
         imprimir. Se sacó junto con el acordeón (clienta, 2026-08-24): el
         detalle es ahora el de UNA máquina y está siempre abierto, así que lo
         que se ve en pantalla es exactamente lo que sale impreso. --}}

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
            <div class="dp-kpis dp-kpis-3">
                <div class="dp-kpi">
                    <div class="dp-kpi-label">{{ __('reports.total_spent') }}</div>
                    <div class="dp-kpi-value">${{ number_format($totals['parts_total'], 2) }}</div>
                    <div class="dp-kpi-foot">{{ __('reports.parts_only') }}</div>
                </div>
                <div class="dp-kpi">
                    <div class="dp-kpi-label">{{ __('reports.machines_with_spend') }}</div>
                    <div class="dp-kpi-value">{{ $totals['machine_count'] }}</div>
                </div>
                {{-- El conteo de órdenes de trabajo salió de acá y de la tabla
                     de abajo a pedido de la clienta (2026-08-24): lo que quiere
                     leer del resumen es cuánto gasta cada máquina, no cuántas
                     órdenes tuvo. Las órdenes siguen enteras en el detalle. --}}
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
                                <th class="dp-num">{{ __('reports.labor_hours') }}</th>
                                <th class="dp-num">{{ __('reports.machine_spend') }}</th>
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
                                    <td class="dp-num">{{ number_format($block['labor_hours'], 1) }}</td>
                                    <td class="dp-num"><strong>${{ number_format($block['parts_total'], 2) }}</strong></td>
                                </tr>
                            @endforeach
                        </tbody>
                        <tfoot>
                            <tr>
                                <td colspan="2">{{ __('reports.grand_total') }}</td>
                                <td class="dp-num">{{ number_format($totals['labor_hours'], 1) }}</td>
                                <td class="dp-num">${{ number_format($totals['parts_total'], 2) }}</td>
                            </tr>
                        </tfoot>
                    </table>
                </div>

                {{-- Detalle de UNA máquina, la que se elige en el select de arriba
                     (clienta, 2026-08-24). Antes era un acordeón con las 99 máquinas
                     del periodo, una debajo de la otra.

                     Sigue reusando el mismo $block/$wo que armó CostReportBuilder para
                     la fila del resumen y para el PDF/Excel — no hay una segunda
                     consulta ni una segunda fuente de totales. El checklist NO se
                     expande ítem por ítem (son ~61 por OT); solo su resultado
                     resumido, igual que en el PDF. --}}
                @php
                    $detalle = $this->detailMachineBlock();
                    $elegida = $this->data['detail_machine_id'] ?? null;
                @endphp

                <h3 class="dp-rep-title" style="margin-top:1.25rem;">{{ __('reports.detail') }}</h3>

                @if($detalle === null)
                    {{-- Dos vacíos distintos y con causas distintas: todavía no eligió
                         ninguna, o la que había elegido se cayó del reporte al cambiar
                         un filtro. Decir "elija una máquina" en el segundo caso haría
                         que parezca que no eligió. --}}
                    <div class="dp-empty">
                        {{ blank($elegida) ? __('reports.detail_none_selected') : __('reports.detail_machine_gone') }}
                    </div>
                @else
                    <div class="dp-detail-list">
                        <div class="dp-detail-head">
                            <span>
                                {{ $detalle['machine_id_code'] }}
                                @if($detalle['machine_description'])
                                    <span class="dp-desc">— {{ $detalle['machine_description'] }}</span>
                                @endif
                            </span>
                            <span class="dp-detail-figures">
                                <b>{{ __('reports.detail_spend') }}:</b>
                                ${{ number_format($detalle['parts_total'], 2) }}
                                &nbsp;·&nbsp; {{ number_format($detalle['labor_hours'], 1) }} h
                            </span>
                        </div>

                        <div class="dp-detail-body">
                            @foreach($detalle['work_orders'] as $wo)
                                    <div class="dp-wo">
                                        <div class="dp-wo-title">
                                            {{ $wo['code'] }} — {{ __('wo.'.$wo['type']) }}
                                            · {{ __('wo.'.$wo['status']) }}
                                        </div>
                                        {{-- "Descripción del trabajo jalarlo de work orders"
                                             (clienta, 2026-08-24): es el campo `description`
                                             de la orden. Ya venía en el reporte y solo lo
                                             imprimían el PDF y el Excel. --}}
                                        <div class="dp-wo-meta">
                                            <b>{{ __('reports.work_description') }}:</b>
                                            @if(filled($wo['description']))
                                                {{ $wo['description'] }}
                                            @else
                                                <span class="dp-zero">{{ __('reports.no_description') }}</span>
                                            @endif
                                        </div>
                                        <div class="dp-wo-meta">
                                            <b>{{ __('reports.completed_by') }}:</b>
                                            {{ $wo['completed_by'] ?? __('reports.not_recorded') }}
                                            @if($wo['assigned_to'])
                                                &nbsp;(<b>{{ __('wo.assigned_to') }}:</b> {{ $wo['assigned_to'] }})
                                            @endif
                                        </div>
                                        <div class="dp-wo-meta">
                                            {{-- El n.º de trabajo de la obra, rotulado aparte
                                                 y no solo embebido en "JOB-100 — Blount Rd"
                                                 (clienta, 2026-08-24: "en job sites el id de
                                                 trabajo"). --}}
                                            <b>{{ __('reports.job_number') }}:</b>
                                            {{ $wo['location_job_number'] ?? __('reports.not_recorded') }}
                                            &nbsp;|&nbsp;
                                            <b>{{ __('reports.location') }}:</b>
                                            {{ $wo['location'] ? $wo['location_label'] : __('reports.not_recorded') }}
                                            &nbsp;|&nbsp;
                                            <b>{{ __('wo.opened_at') }}:</b> {{ $wo['opened_at'] ?? '—' }}
                                            &nbsp;|&nbsp;
                                            <b>{{ __('wo.completed_at') }}:</b> {{ $wo['completed_at'] ?? '—' }}
                                            &nbsp;|&nbsp;
                                            <b>{{ __('wo.labor_hours') }}:</b> {{ number_format($wo['labor_hours'], 1) }} h
                                        </div>

                                        {{-- Resumen del checklist, nunca los ~61 ítems. --}}
                                        <div class="dp-wo-meta">
                                            <b>{{ __('reports.checklist') }}:</b>
                                            @if($wo['checklist_total'] > 0)
                                                @if($wo['checklist_alert'] > 0)
                                                    <span class="dp-badge dp-badge-alert">{{ __('reports.checklist_alert_badge') }}</span>
                                                @else
                                                    <span class="dp-badge dp-badge-ok">{{ __('reports.checklist_ok_badge') }}</span>
                                                @endif
                                                &nbsp;{{ $wo['checklist_total'] }} {{ __('reports.items') }} —
                                                {{ $wo['checklist_ok'] }} {{ __('checklist.result_ok') }},
                                                {{ $wo['checklist_alert'] }} {{ __('checklist.result_alert') }},
                                                {{ $wo['checklist_na'] }} {{ __('checklist.result_na') }}
                                            @else
                                                <span class="dp-badge dp-badge-muted">{{ __('reports.no_checklist') }}</span>
                                            @endif
                                        </div>
                                        @if($wo['checklist_alerts'] !== [])
                                            <div class="dp-alerts">
                                                <ul>
                                                    @foreach($wo['checklist_alerts'] as $alert)
                                                        <li>{{ $alert['label'] }}@if($alert['detail']): {{ $alert['detail'] }}@endif</li>
                                                    @endforeach
                                                </ul>
                                            </div>
                                        @endif

                                        @if($wo['parts'] !== [])
                                            <div class="dp-scroll" style="margin-top:0.5rem;">
                                                <table class="dp-table">
                                                    <thead>
                                                        <tr>
                                                            <th>{{ __('reports.part_number') }}</th>
                                                            <th>{{ __('reports.part_description') }}</th>
                                                            <th class="dp-num">{{ __('reports.quantity') }}</th>
                                                            <th class="dp-num">{{ __('reports.unit_cost') }}</th>
                                                            <th class="dp-num">{{ __('reports.subtotal') }}</th>
                                                        </tr>
                                                    </thead>
                                                    <tbody>
                                                        @foreach($wo['parts'] as $part)
                                                            <tr>
                                                                <td>{{ $part['part_number'] ?? '—' }}</td>
                                                                <td>{{ $part['description'] ?? '—' }}</td>
                                                                <td class="dp-num">{{ rtrim(rtrim(number_format($part['quantity'], 2), '0'), '.') }}</td>
                                                                <td class="dp-num">
                                                                    @if($part['unit_cost'] === null)
                                                                        <span class="dp-zero">{{ __('reports.no_cost_loaded') }}</span>
                                                                    @else
                                                                        ${{ number_format($part['unit_cost'], 2) }}
                                                                    @endif
                                                                </td>
                                                                <td class="dp-num">${{ number_format($part['subtotal'], 2) }}</td>
                                                            </tr>
                                                        @endforeach
                                                    </tbody>
                                                    <tfoot>
                                                        <tr>
                                                            <td colspan="4">{{ __('reports.wo_total') }}</td>
                                                            <td class="dp-num">${{ number_format($wo['parts_total'], 2) }}</td>
                                                        </tr>
                                                    </tfoot>
                                                </table>
                                            </div>
                                        @else
                                            <div class="dp-wo-meta dp-zero">{{ __('reports.no_parts') }}</div>
                                        @endif
                                    </div>
                            @endforeach
                        </div>
                    </div>

                    <p class="dp-note">{{ __('reports.detail_in_files') }}</p>
                @endif
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

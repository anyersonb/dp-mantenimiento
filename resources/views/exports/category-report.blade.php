<!DOCTYPE html>
<html lang="{{ app()->getLocale() }}">
<head>
    <meta charset="utf-8">
    <title>{{ __('reports.report_category_inventory') }}</title>
    <style>
        body { font-family: sans-serif; font-size: 11px; color: #1f2937; }
        .header { border-bottom: 2px solid #f59e0b; padding-bottom: 8px; margin-bottom: 14px; }
        .header img { height: 36px; vertical-align: middle; margin-right: 10px; }
        .header h1 { font-size: 17px; margin: 0; display: inline-block; vertical-align: middle; }
        .header .meta { font-size: 9px; color: #6b7280; margin-top: 4px; }
        table { width: 100%; border-collapse: collapse; margin-top: 8px; }
        th, td { border: 1px solid #d1d5db; padding: 4px 6px; text-align: left; font-size: 10px; }
        th { background-color: #fef3c7; }
        .text-right { text-align: right; }
        .zero { color: #9ca3af; }
        tfoot td { background: #1f2937; color: #fff; font-weight: bold; }
        .warn { background: #fffbeb; border: 1px solid #fcd34d; padding: 6px 8px; margin: 10px 0; font-size: 9px; color: #92400e; }
        footer { margin-top: 16px; font-size: 8px; color: #9ca3af; border-top: 1px solid #e5e7eb; padding-top: 4px; }
    </style>
</head>
<body>
    <div class="header">
        <img src="{{ $logoPath }}" alt="DP">
        <h1>{{ __('reports.report_category_inventory') }}</h1>
        <div class="meta">
            {{ __('mgmt.generated_at') }}: {{ $generatedAt }}
            @if($generatedBy) &nbsp;·&nbsp; {{ __('reports.generated_by') }}: {{ $generatedBy }} @endif
        </div>
    </div>

    @if($report['uncategorized'] > 0)
        <div class="warn">
            {{ __('reports.uncategorized_notice', ['count' => $report['uncategorized']]) }}
        </div>
    @endif

    <table>
        <thead>
            <tr>
                <th>{{ __('fleet.category') }}</th>
                <th class="text-right">{{ __('reports.total') }}</th>
                @foreach($report['statuses'] as $status)
                    <th class="text-right">{{ __('fleet.status_'.$status) }}</th>
                @endforeach
                <th class="text-right">{{ __('fleet.needs_review') }}</th>
            </tr>
        </thead>
        <tbody>
            @foreach($report['rows'] as $row)
                <tr class="{{ $row['total'] === 0 ? 'zero' : '' }}">
                    <td>{{ $row['category'] }}</td>
                    <td class="text-right"><strong>{{ $row['total'] }}</strong></td>
                    @foreach($report['statuses'] as $status)
                        <td class="text-right">{{ $row[$status] }}</td>
                    @endforeach
                    <td class="text-right">{{ $row['needs_review'] }}</td>
                </tr>
            @endforeach
        </tbody>
        <tfoot>
            <tr>
                <td>{{ __('reports.grand_total') }}</td>
                <td class="text-right">{{ $report['totals']['total'] }}</td>
                @foreach($report['statuses'] as $status)
                    <td class="text-right">{{ $report['totals'][$status] }}</td>
                @endforeach
                <td class="text-right">{{ $report['totals']['needs_review'] }}</td>
            </tr>
        </tfoot>
    </table>

    <footer>
        {{ __('reports.category_footer_note') }}
    </footer>
</body>
</html>

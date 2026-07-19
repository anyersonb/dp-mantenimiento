<!DOCTYPE html>
<html lang="{{ app()->getLocale() }}">
<head>
    <meta charset="utf-8">
    <title>{{ __('mgmt.fleet_report_title') }}</title>
    <style>
        body { font-family: sans-serif; font-size: 11px; color: #1f2937; }
        .header { display: flex; align-items: center; border-bottom: 2px solid #f59e0b; padding-bottom: 10px; margin-bottom: 16px; }
        .header img { height: 40px; margin-right: 12px; }
        .header h1 { font-size: 18px; margin: 0; }
        .header .meta { font-size: 10px; color: #6b7280; margin-top: 2px; }
        table { width: 100%; border-collapse: collapse; margin-top: 10px; }
        th, td { border: 1px solid #d1d5db; padding: 4px 6px; text-align: left; font-size: 10px; }
        th { background-color: #fef3c7; }
        .badge { padding: 1px 5px; border-radius: 3px; color: #fff; font-size: 9px; }
        .badge-ok { background-color: #10b981; }
        .badge-warning { background-color: #f59e0b; }
        .badge-danger { background-color: #ef4444; }
        .badge-gray { background-color: #6b7280; }
        .text-right { text-align: right; }
        footer { margin-top: 16px; font-size: 9px; color: #9ca3af; }
    </style>
</head>
<body>
    <div class="header">
        <img src="{{ $logoPath }}" alt="DP">
        <div>
            <h1>{{ __('mgmt.fleet_report_title') }}</h1>
            <div class="meta">{{ __('mgmt.generated_at') }}: {{ $generatedAt }}</div>
        </div>
    </div>

    <table>
        <thead>
            <tr>
                <th>{{ __('fleet.id_code') }}</th>
                <th>{{ __('fleet.category') }}</th>
                <th>{{ __('fleet.make') }}</th>
                <th>{{ __('fleet.model') }}</th>
                <th>{{ __('fleet.location') }}</th>
                <th class="text-right">{{ __('fleet.current_hours') }}</th>
                <th class="text-right">{{ __('fleet.remaining_hours') }}</th>
                <th>{{ __('fleet.status') }}</th>
                @if($includeCosts)
                    <th class="text-right">{{ __('mgmt.service_count') }}</th>
                    <th class="text-right">{{ __('mgmt.maintenance_cost') }}</th>
                @endif
            </tr>
        </thead>
        <tbody>
            @foreach($machines as $machine)
                <tr>
                    <td>{{ $machine->id_code }}</td>
                    <td>{{ $machine->category?->name }}</td>
                    <td>{{ $machine->make?->name }}</td>
                    <td>{{ $machine->model }}</td>
                    <td>{{ $machine->location?->name }}</td>
                    <td class="text-right">{{ $machine->current_hours !== null ? number_format($machine->current_hours).' h' : '—' }}</td>
                    <td class="text-right">{{ $machine->computed_remaining_hours !== null ? number_format($machine->computed_remaining_hours).' h' : '—' }}</td>
                    <td>
                        <span class="badge badge-{{ match($machine->service_status) {
                            'overdue' => 'danger',
                            'due_soon' => 'warning',
                            'ok' => 'ok',
                            default => 'gray',
                        } }}">{{ __('fleet.status_'.$machine->status) }}</span>
                    </td>
                    @if($includeCosts)
                        <td class="text-right">{{ $machine->completed_service_count }}</td>
                        <td class="text-right">${{ number_format((float) $machine->completed_parts_cost, 2) }}</td>
                    @endif
                </tr>
            @endforeach
        </tbody>
    </table>

    <footer>DP Fleet Maintenance &mdash; {{ __('mgmt.fleet_report_title') }}</footer>
</body>
</html>

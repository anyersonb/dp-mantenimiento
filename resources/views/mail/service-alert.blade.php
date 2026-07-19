<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
</head>
<body style="font-family: Arial, Helvetica, sans-serif; color:#1e293b; background:#f8fafc; padding:24px;">
    <div style="max-width:640px; margin:0 auto; background:#fff; border-radius:12px; padding:24px;">
        <h2 style="color:#b45309; margin-top:0;">{{ __('alerts.mail_heading') }}</h2>
        <p>{{ __('alerts.mail_intro', ['count' => $alerts->count()]) }}</p>

        <table style="width:100%; border-collapse: collapse; margin-top:16px;">
            <thead>
                <tr style="background:#fef3c7;">
                    <th style="text-align:left; padding:8px; border:1px solid #e2e8f0;">{{ __('fleet.id_code') }}</th>
                    <th style="text-align:left; padding:8px; border:1px solid #e2e8f0;">{{ __('fleet.remaining_hours') }}</th>
                    <th style="text-align:left; padding:8px; border:1px solid #e2e8f0;">{{ __('fleet.location') }}</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($alerts as $alert)
                    <tr>
                        <td style="padding:8px; border:1px solid #e2e8f0;">{{ $alert->machine?->id_code ?? '—' }}</td>
                        <td style="padding:8px; border:1px solid #e2e8f0;">{{ $alert->remaining_hours !== null ? number_format($alert->remaining_hours).' h' : '—' }}</td>
                        <td style="padding:8px; border:1px solid #e2e8f0;">{{ $alert->machine?->location?->name ?? '—' }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>

        <p style="margin-top:24px;">
            <a href="{{ url('/admin') }}" style="background:#f59e0b; color:#111827; padding:10px 18px; border-radius:8px; text-decoration:none; font-weight:600;">
                {{ __('alerts.mail_cta') }}
            </a>
        </p>
    </div>
</body>
</html>

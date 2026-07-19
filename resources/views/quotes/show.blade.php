<!DOCTYPE html>
<html lang="{{ app()->getLocale() }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>{{ $quote->title }} — DP Fleet Maintenance</title>
    <style>
        * { box-sizing: border-box; }
        body {
            margin: 0;
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            background: #f3f4f6;
            color: #1f2937;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 24px 16px;
        }
        .card {
            background: #fff;
            border-radius: 12px;
            box-shadow: 0 4px 16px rgba(0,0,0,.08);
            max-width: 480px;
            width: 100%;
            overflow: hidden;
        }
        .brand {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 20px 24px;
            border-bottom: 3px solid #f59e0b;
        }
        .brand img { height: 40px; border-radius: 6px; }
        .brand span { font-weight: 700; font-size: 15px; color: #1f2937; }
        .content { padding: 24px; }
        h1 { font-size: 20px; margin: 0 0 16px; }
        dl { margin: 0; }
        dl div { display: flex; justify-content: space-between; padding: 8px 0; border-bottom: 1px solid #f3f4f6; }
        dt { color: #6b7280; font-size: 13px; }
        dd { margin: 0; font-weight: 600; font-size: 14px; text-align: right; }
        .btn {
            display: block;
            text-align: center;
            margin-top: 20px;
            background: #f59e0b;
            color: #fff;
            padding: 12px 16px;
            border-radius: 8px;
            text-decoration: none;
            font-weight: 600;
        }
        .btn:hover { background: #d97706; }
        .notice {
            background: #fef2f2;
            color: #b91c1c;
            padding: 16px 24px;
            font-weight: 600;
            text-align: center;
        }
        .muted { color: #9ca3af; font-size: 13px; text-align: center; margin-top: 16px; }
    </style>
</head>
<body>
    <div class="card">
        <div class="brand">
            <img src="{{ asset('images/dp-logo.jpg') }}" alt="DP Development">
            <span>DP Fleet Maintenance</span>
        </div>

        @if($expired)
            <div class="notice">{{ __('mgmt.public_expired') }}</div>
        @else
            <div class="content">
                <h1>{{ $quote->title }}</h1>
                <dl>
                    @if($quote->vendor)
                        <div><dt>{{ __('mgmt.public_vendor') }}</dt><dd>{{ $quote->vendor }}</dd></div>
                    @endif
                    @if($quote->amount !== null)
                        <div><dt>{{ __('mgmt.public_amount') }}</dt><dd>${{ number_format((float) $quote->amount, 2) }}</dd></div>
                    @endif
                    @if($quote->expires_at)
                        <div><dt>{{ __('mgmt.public_expires_at') }}</dt><dd>{{ $quote->expires_at->format('Y-m-d H:i') }}</dd></div>
                    @endif
                </dl>

                @if($quote->file_path)
                    <a class="btn" href="{{ \Illuminate\Support\Facades\Storage::disk('public')->url($quote->file_path) }}" target="_blank" rel="noopener">
                        {{ __('mgmt.public_view_file') }}
                    </a>
                @else
                    <p class="muted">{{ __('mgmt.public_no_file') }}</p>
                @endif
            </div>
        @endif
    </div>
</body>
</html>

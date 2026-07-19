@props(['title' => null])
<!DOCTYPE html>
<html lang="{{ app()->getLocale() }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1, viewport-fit=cover">
    <title>{{ $title ? $title.' · DP Fleet' : 'DP Fleet Maintenance' }}</title>

    <link rel="manifest" href="/manifest.json">
    <meta name="theme-color" content="#f59e0b">
    <meta name="mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <link rel="apple-touch-icon" href="{{ asset('images/dp-logo.jpg') }}">

    @livewireStyles

    <style>
        :root { --brand:#f59e0b; --brand-dark:#b45309; }
        * { box-sizing: border-box; -webkit-tap-highlight-color: transparent; }
        html, body { margin:0; padding:0; }
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Arial, sans-serif;
            background:#f8fafc; color:#1e293b;
        }
        header.field-header {
            background:#111827; color:#fff; padding:.85rem 1rem;
            display:flex; align-items:center; gap:.75rem;
            position:sticky; top:0; z-index:20;
        }
        header.field-header img { height:2.25rem; width:auto; border-radius:.35rem; object-fit:cover; }
        header.field-header .title { font-weight:600; font-size:1rem; flex:1; }
        header.field-header .lang-link { color:#fcd34d; font-size:.8rem; text-decoration:none; font-weight:600; }

        main.field-main {
            max-width:480px; margin:0 auto; padding:1rem 1rem 5.5rem;
            min-height: calc(100vh - 3.8rem);
        }

        .field-card {
            background:#fff; border-radius:1rem; padding:1.25rem;
            box-shadow:0 1px 3px rgba(0,0,0,.08); margin-bottom:1rem;
        }

        .btn {
            display:flex; align-items:center; justify-content:center; gap:.5rem;
            width:100%; padding:1rem; border-radius:.85rem; font-size:1.05rem; font-weight:600;
            border:none; cursor:pointer; text-decoration:none; color:inherit;
        }
        .btn-primary { background: var(--brand); color:#111827; }
        .btn-primary:active { background: var(--brand-dark); }
        .btn-outline { background:#fff; border:1px solid #cbd5e1; color:#334155; }
        .btn-ok { background:#dcfce7; color:#15803d; border:2px solid transparent; }
        .btn-ok.active { border-color:#16a34a; }
        .btn-warn { background:#fef3c7; color:#b45309; border:2px solid transparent; }
        .btn-warn.active { border-color:#f59e0b; }
        .btn-danger { background:#fee2e2; color:#b91c1c; border:2px solid transparent; }
        .btn-danger.active { border-color:#dc2626; }

        input, textarea, select {
            width:100%; padding:.75rem; border:1px solid #cbd5e1; border-radius:.6rem;
            font-size:1rem; margin-top:.25rem; background:#fff; color:#1e293b;
        }
        label { font-weight:600; font-size:.9rem; color:#334155; display:block; margin-top:.85rem; }

        .field-nav {
            display:flex; gap:.6rem; padding:.6rem 1rem;
            position:fixed; bottom:0; left:0; right:0; background:#fff;
            border-top:1px solid #e2e8f0; max-width:480px; margin:0 auto;
        }
        .field-nav a, .field-nav button {
            flex:1; text-align:center; font-size:.85rem; padding:.6rem; border-radius:.6rem;
            background:#f1f5f9; color:#334155; border:none; text-decoration:none; cursor:pointer;
        }

        .badge { display:inline-block; padding:.15rem .55rem; border-radius:999px; font-size:.75rem; font-weight:700; }
        .badge-success { background:#dcfce7; color:#15803d; }
        .badge-warning { background:#fef3c7; color:#b45309; }
        .badge-danger { background:#fee2e2; color:#b91c1c; }

        .success-box { text-align:center; padding:2rem 1rem; }
        .success-box .check { font-size:3.5rem; }
        .muted { color:#64748b; font-size:.85rem; }

        .machine-list {
            border:1px solid #e2e8f0; border-radius:.6rem; margin-top:.35rem;
            max-height:12rem; overflow-y:auto;
        }
        .machine-list button {
            width:100%; text-align:left; padding:.65rem .75rem; border:none;
            background:#fff; border-bottom:1px solid #f1f5f9; font-size:.95rem;
        }
        .machine-list button:active { background:#fef3c7; }

        .selected-machine {
            background:#fffbeb; border:1px solid #fcd34d; border-radius:.6rem;
            padding:.6rem .75rem; margin-top:.35rem; font-weight:600;
            display:flex; align-items:center; justify-content:space-between; gap:.5rem;
        }
        .selected-machine button {
            background:none; border:none; color:#b45309; font-weight:600; cursor:pointer;
        }

        .error-msg { color:#b91c1c; font-size:.82rem; margin-top:.25rem; }
    </style>
</head>
<body>
    <header class="field-header">
        <img src="{{ asset('images/dp-logo.jpg') }}" alt="DP">
        <div class="title">{{ $title ?? 'DP Fleet' }}</div>
        <a class="lang-link" href="{{ url('/locale/'.(app()->getLocale() === 'es' ? 'en' : 'es')) }}">
            {{ app()->getLocale() === 'es' ? 'EN' : 'ES' }}
        </a>
    </header>

    <main class="field-main">
        {{ $slot }}
    </main>

    @auth
        <nav class="field-nav">
            <a href="{{ route('field.home') }}">🏠 {{ __('field.nav_home') }}</a>
            <form method="POST" action="{{ route('field.logout') }}" style="flex:1;">
                @csrf
                <button type="submit">🚪 {{ __('field.nav_logout') }}</button>
            </form>
        </nav>
    @endauth

    @livewireScripts
</body>
</html>

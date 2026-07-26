{{--
    Etapa 05, Bloque 5 (M6) — layout compartido por las páginas de error con
    marca. Antes no existía resources/views/errors/ y Laravel mostraba sus
    páginas por defecto (sin logo, en inglés, sin salida).

    El destino del botón de salida sigue la misma regla que ya usa
    App\Livewire\Field\Login::targetUrl(): si el usuario autenticado puede
    entrar al panel Filament (los 4 roles de escritorio/taller), vuelve al
    panel; si está autenticado pero es un rol de campo (canAccessPanel()
    devuelve false), va a /field y NO a /admin; si es un invitado, va al
    login.
--}}
@props(['code', 'heading', 'message'])
@php
    $user = auth()->user();

    if ($user) {
        $canAccessPanel = $user->canAccessPanel(\Filament\Facades\Filament::getDefaultPanel());
        $exitUrl = $canAccessPanel ? url('/admin') : route('field.home');
        $exitLabel = $canAccessPanel ? __('errors.exit_panel') : __('errors.exit_field');
    } else {
        $exitUrl = route('login');
        $exitLabel = __('errors.exit_login');
    }
@endphp
<!DOCTYPE html>
<html lang="{{ app()->getLocale() }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>{{ __('errors.title', ['code' => $code]) }} — DP Fleet Maintenance</title>
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
        .brand img { height: 40px; width: 40px; border-radius: 6px; object-fit: cover; }
        .brand span { font-weight: 700; font-size: 15px; color: #1f2937; }
        .content { padding: 24px; text-align: center; }
        .code {
            display: inline-block;
            background: #fef3c7;
            color: #b45309;
            font-weight: 700;
            font-size: 13px;
            letter-spacing: .04em;
            padding: 4px 12px;
            border-radius: 999px;
            margin-bottom: 16px;
        }
        h1 { font-size: 20px; margin: 0 0 12px; }
        .message { color: #4b5563; font-size: 14px; line-height: 1.5; margin: 0; }
        .btn {
            display: block;
            text-align: center;
            margin-top: 24px;
            background: #f59e0b;
            color: #fff;
            padding: 12px 16px;
            border-radius: 8px;
            text-decoration: none;
            font-weight: 600;
        }
        .btn:hover { background: #d97706; }

        @media (max-width: 400px) {
            .content { padding: 20px; }
            h1 { font-size: 18px; }
        }
    </style>
</head>
<body>
    <div class="card">
        <div class="brand">
            <img src="{{ asset('images/dp-logo.jpg') }}" alt="DP Development">
            <span>DP Fleet Maintenance</span>
        </div>

        <div class="content">
            <span class="code">{{ $code }}</span>
            <h1>{{ $heading }}</h1>
            <p class="message">{{ $message }}</p>
            <a class="btn" href="{{ $exitUrl }}">{{ $exitLabel }}</a>
        </div>
    </div>
</body>
</html>

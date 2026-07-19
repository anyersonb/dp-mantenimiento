<div>
    <div class="field-card">
        <p class="muted" style="margin:0;">{{ now()->translatedFormat('l, d M Y') }}</p>
        <h2 style="margin:.25rem 0 0;">{{ __('field.home_greeting', ['name' => $user->name]) }}</h2>
    </div>

    @if ($user->hasRole('operador_cisterna'))
        <a href="{{ route('field.fuel') }}" class="btn btn-primary" style="margin-bottom:.75rem;">
            ⛽ {{ __('field.home_go_fuel') }}
        </a>
    @endif

    @if ($user->hasRole('personal_mantenimiento'))
        <a href="{{ route('field.report') }}" class="btn btn-primary" style="margin-bottom:.75rem;">
            📋 {{ __('field.home_go_report') }}
        </a>
    @endif

    @if ($user->hasRole('foreman'))
        <a href="{{ route('field.foreman') }}" class="btn btn-primary" style="margin-bottom:.75rem;">
            📍 {{ __('field.home_go_foreman') }}
        </a>
    @endif

    @if ($canAccessAdmin)
        <a href="{{ url('/admin') }}" class="btn btn-outline" style="margin-bottom:.75rem;">
            🖥️ {{ __('field.home_go_admin') }}
        </a>
    @endif

    @unless ($user->hasAnyRole(['operador_cisterna', 'personal_mantenimiento', 'foreman']) || $canAccessAdmin)
        <div class="field-card muted">{{ __('field.home_no_access') }}</div>
    @endunless
</div>

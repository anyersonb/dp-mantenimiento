<div>
    <div class="field-card">
        <p class="muted" style="margin:0;">{{ now()->translatedFormat('l, d M Y') }}</p>
        <h2 style="margin:.25rem 0 0;">{{ __('field.home_greeting', ['name' => $user->name]) }}</h2>
    </div>

    {{-- Hallazgo A1 (Etapa 05): el menú se arma por PERMISO, no por nombre de
         rol, para que el editor de roles del panel gobierne también lo que
         se ve acá. --}}
    @if ($user->can('log_fuel'))
        <a href="{{ route('field.fuel') }}" class="btn btn-primary" style="margin-bottom:.75rem;">
            ⛽ {{ __('field.home_go_fuel') }}
        </a>
    @endif

    @if ($user->can('field_report'))
        <a href="{{ route('field.report') }}" class="btn btn-primary" style="margin-bottom:.75rem;">
            📋 {{ __('field.home_go_report') }}
        </a>
    @endif

    @if ($user->can('confirm_location') || $user->can('move_fleet'))
        <a href="{{ route('field.foreman') }}" class="btn btn-primary" style="margin-bottom:.75rem;">
            📍 {{ __('field.home_go_foreman') }}
        </a>
    @endif

    @if ($canAccessAdmin)
        <a href="{{ url('/admin') }}" class="btn btn-outline" style="margin-bottom:.75rem;">
            🖥️ {{ __('field.home_go_admin') }}
        </a>
    @endif

    @unless ($user->can('log_fuel') || $user->can('field_report') || $user->can('confirm_location') || $user->can('move_fleet') || $canAccessAdmin)
        <div class="field-card muted">{{ __('field.home_no_access') }}</div>
    @endunless
</div>

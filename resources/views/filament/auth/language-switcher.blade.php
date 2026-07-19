@php
    $current = app()->getLocale();
    $langs = ['es' => 'Español', 'en' => 'English'];
@endphp

<div class="fi-lang-switcher" style="margin-top:1.5rem;display:flex;flex-direction:column;align-items:center;gap:.5rem;">
    <span style="font-size:.75rem;text-transform:uppercase;letter-spacing:.05em;color:rgb(107 114 128);">
        {{ $current === 'es' ? 'Idioma' : 'Language' }}
    </span>
    <div style="display:inline-flex;border:1px solid rgb(209 213 219);border-radius:.5rem;overflow:hidden;">
        @foreach ($langs as $code => $label)
            <a href="{{ url('/locale/' . $code) }}"
               @style([
                   'padding:.4rem .9rem',
                   'font-size:.8rem',
                   'font-weight:600',
                   'text-decoration:none',
                   'transition:background .15s',
                   'background:rgb(245 158 11);color:#fff' => $current === $code,
                   'background:transparent;color:rgb(107 114 128)' => $current !== $code,
               ])>
                {{ $label }}
            </a>
        @endforeach
    </div>
</div>

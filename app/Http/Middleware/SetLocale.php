<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use Symfony\Component\HttpFoundation\Response;

class SetLocale
{
    public function handle(Request $request, Closure $next): Response
    {
        // Prioridad: preferencia del usuario autenticado -> sesión -> config por defecto
        $locale = config('app.locale');

        if ($user = $request->user()) {
            $locale = $user->locale ?: $locale;
        } elseif ($request->session()->has('locale')) {
            $locale = $request->session()->get('locale');
        }

        if (in_array($locale, ['en', 'es'], true)) {
            App::setLocale($locale);
        }

        return $next($request);
    }
}

<?php

use App\Http\Middleware\SetLocale;
use Illuminate\Contracts\Auth\Middleware\AuthenticatesRequests;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        /*
         * Etapa 05, Bloque 5 (M6, hallazgo de QA en vivo) — Filament\Http\Middleware\
         * Authenticate hace abort_if(403) DENTRO de su propio handle(), antes de
         * llamar a $next(). Laravel no ordena el pipeline de un grupo de rutas por el
         * orden en que se declaran los middleware (AdminPanelProvider::middleware()
         * pone SetLocale al final a proposito, despues de la sesion), sino por esta
         * lista de prioridad del kernel — y SetLocale no estaba en ella, asi que
         * quedaba siempre relegado al final del pipeline real, DESPUES del 403 de
         * Authenticate. Resultado: el 403 del panel se renderizaba en el locale por
         * defecto de la app, ignorando el idioma guardado en users.locale (verificado
         * con foreman@dp.local, locale=es, viendo "Access not allowed" en ingles).
         *
         * No se duplica la logica de idioma de SetLocale ni se toca su orden
         * declarado en AdminPanelProvider/routes/web.php: alcanza con adelantarla en
         * la prioridad para que corra antes que cualquier middleware que implemente
         * AuthenticatesRequests (interfaz que Filament\Http\Middleware\Authenticate
         * hereda de Illuminate\Auth\Middleware\Authenticate).
         */
        $middleware->prependToPriorityList(
            before: AuthenticatesRequests::class,
            prepend: SetLocale::class,
        );
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();

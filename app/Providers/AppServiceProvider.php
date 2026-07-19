<?php

namespace App\Providers;

use App\Models\ChecklistResult;
use App\Models\HorometerReading;
use App\Models\WorkOrderPart;
use App\Observers\ChecklistResultObserver;
use App\Observers\HorometerReadingObserver;
use App\Observers\WorkOrderPartObserver;
use Filament\Support\Facades\FilamentView;
use Filament\View\PanelsRenderHook;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        // Motor de alertas: cada lectura de horómetro recalcula la máquina y puede
        // disparar una alerta de servicio (ver Etapa 02).
        HorometerReading::observe(HorometerReadingObserver::class);

        // Etapa 03: recalcula parts_cost al agregar/editar/borrar partes de una OT.
        WorkOrderPart::observe(WorkOrderPartObserver::class);

        // Etapa 03: notifica al administrador (Alert type=checklist) cuando un ítem
        // del checklist ejecutado en una OT se marca como "alert".
        ChecklistResult::observe(ChecklistResultObserver::class);

        // PWA: manifest + theme-color + registro del service worker en el <head> del panel
        FilamentView::registerRenderHook(
            PanelsRenderHook::HEAD_START,
            fn (): string => Blade::render(<<<'BLADE'
                <link rel="manifest" href="/manifest.json">
                <meta name="theme-color" content="#f59e0b">
                <meta name="mobile-web-app-capable" content="yes">
                <meta name="apple-mobile-web-app-capable" content="yes">
                <link rel="apple-touch-icon" href="/images/dp-logo.jpg">
                <script>
                    if ('serviceWorker' in navigator) {
                        window.addEventListener('load', function () {
                            navigator.serviceWorker.register('/sw.js').catch(function () {});
                        });
                    }
                </script>
            BLADE),
        );
    }
}

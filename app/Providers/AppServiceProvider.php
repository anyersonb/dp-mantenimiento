<?php

namespace App\Providers;

use App\Models\ChecklistResult;
use App\Models\FieldReport;
use App\Models\HorometerReading;
use App\Models\Machine;
use App\Models\Setting;
use App\Models\WorkOrder;
use App\Models\WorkOrderPart;
use App\Observers\ChecklistResultObserver;
use App\Observers\FieldReportObserver;
use App\Observers\HorometerReadingObserver;
use App\Observers\MachineObserver;
use App\Observers\SettingObserver;
use App\Observers\WorkOrderObserver;
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

        // Hallazgo E6-08: sella opened_by y hours_at_open en TODOS los caminos
        // de creación (el formulario del panel no los ponía).
        WorkOrder::observe(WorkOrderObserver::class);

        // Etapa 03: recalcula parts_cost al agregar/editar/borrar partes de una OT.
        WorkOrderPart::observe(WorkOrderPartObserver::class);

        // Etapa 03: notifica al administrador (Alert type=checklist) cuando un ítem
        // del checklist ejecutado en una OT se marca como "alert".
        ChecklistResult::observe(ChecklistResultObserver::class);

        // Hallazgo A3 (QA Etapa 05): needs_review no puede modificarse sin el
        // permiso verify_data, ni por el form normal de edición ni por payload
        // manipulado (Machine usa $guarded = []).
        Machine::observe(MachineObserver::class);

        // Impuesto de repuestos (2026-09-01): invalida la caché de Setting::get()
        // en cuanto la tasa (u otro valor) se guarda o se borra.
        Setting::observe(SettingObserver::class);

        // Módulo de notificaciones: un reporte de campo crítico (y, si la
        // Configuración lo enciende, uno que "requiere atención") notifica a
        // quien tenga view_field_reports. Ver App\Support\Notifications\
        // NotificationRegistry para el registro y App\Observers\
        // FieldReportObserver para la regla de negocio.
        FieldReport::observe(FieldReportObserver::class);

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

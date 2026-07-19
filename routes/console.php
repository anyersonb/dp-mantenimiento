<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Motor de alertas: escaneo diario de máquinas próximas a servicio (<=100h)
// y envío del correo-resumen a los administradores.
Schedule::command('alerts:scan')->dailyAt('07:00');

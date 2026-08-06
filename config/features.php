<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Módulo de cotizaciones
    |--------------------------------------------------------------------------
    |
    | Apagado el 2026-08-06 a pedido del cliente: el CMMS ya no gestiona
    | cotizaciones. Se APAGA en lugar de borrarse porque la tabla `quotes` y
    | los archivos en disk('local')/quotes siguen existiendo y pueden hacer
    | falta para auditoría. Con esto en false:
    |
    |   - QuoteResource desaparece del menú y /admin/quotes responde 403
    |     (también /create y /{id}/edit);
    |   - los links públicos ya repartidos, /quotes/{token} y
    |     /quotes/{token}/archivo, responden 404.
    |
    | Para reactivarlo: FEATURE_QUOTES=true en .env y `php artisan config:clear`
    | (en producción, `php artisan config:cache`). No hace falta migración ni
    | recuperar código: nada se borró.
    |
    | Los tests que cubren la seguridad del módulo (subida de archivos, disco
    | privado, vencimiento del link) siguen corriendo: encienden el flag a mano
    | para no perder esa red si algún día se reactiva. QuotesModuleIsOffTest
    | es el que verifica lo contrario, que apagado no se llega a nada.
    |
    */

    'quotes' => (bool) env('FEATURE_QUOTES', false),

];

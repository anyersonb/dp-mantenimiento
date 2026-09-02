<?php

namespace App\Services;

use App\Models\Setting;

/**
 * Impuesto de repuestos (Florida, decisión de Anyerson 2026-09-01):
 *
 *   - Base: SOLO repuestos. La mano de obra y cualquier otro cargo quedan
 *     exentos (coincide con lo que ya hacía CostReportBuilder, que nunca
 *     sumó labor_hours al total).
 *   - Tasa: editable desde el panel (App\Filament\Pages\Configuration),
 *     7% por defecto. Vive en `settings`, no en config/ (ver App\Models\
 *     Setting).
 *   - Los precios cargados son NETOS: el impuesto se suma encima, y aplica
 *     también a las órdenes históricas — no hay una fecha de corte.
 *   - Se aplica sobre el subtotal ya sumado al final del reporte, en UNA
 *     sola operación de redondeo. Nunca línea por línea, para no acumular
 *     redondeos.
 *
 * Fuente única: ningún otro archivo del proyecto debe multiplicar por la
 * tasa. Si aparece otro `* 0.07` o `/ 100` de impuesto en otro sitio, es un
 * defecto — hay que llamar a este servicio.
 */
class TaxCalculator
{
    public const SETTING_KEY = 'tax_rate';

    public const SETTING_TYPE = 'float';

    public const DEFAULT_RATE = 7.0;

    /** Tasa vigente (porcentaje, ej. 7.0 = 7%). */
    public static function rate(): float
    {
        return (float) Setting::get(self::SETTING_KEY, self::DEFAULT_RATE);
    }

    /**
     * Impuesto sobre un subtotal ya sumado (nunca línea por línea).
     * Redondeo a 2 decimales, half-up, en una sola operación.
     */
    public static function calcular(float $subtotal): float
    {
        return round($subtotal * self::rate() / 100, 2, PHP_ROUND_HALF_UP);
    }
}

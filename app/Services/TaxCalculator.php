<?php

namespace App\Services;

use App\Models\Setting;
use Illuminate\Support\Facades\Log;

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

    /**
     * Tasa vigente (porcentaje, ej. 7.0 = 7%).
     *
     * El formulario de Configuration ya rechaza cualquier valor fuera de
     * 0-100 o no numérico, pero esta es la ÚNICA fuente de lectura y el
     * formulario no es el único camino de escritura: una fila corrupta por
     * SQL crudo, un `tinker`, o un import futuro puede dejar `settings.value`
     * en cualquier cosa. Comprobado (hallazgo de seguridad, 2026-09-01):
     * 'abc'/NULL facturaban sin impuesto en silencio, '-40' daba un total
     * MENOR que el subtotal, y '1e9' miles de millones sobre mil dólares.
     * Fallar abierto con un número inventado y sin avisar es peor que un
     * error visible, así que acá se acota y se loguea — nunca se absorbe
     * en silencio.
     */
    public static function rate(): float
    {
        $raw = Setting::get(self::SETTING_KEY, self::DEFAULT_RATE);

        if (! is_numeric($raw)) {
            Log::warning('TaxCalculator: tax_rate corrupto o ausente en settings, se usa el default', [
                'value' => $raw,
                'default' => self::DEFAULT_RATE,
            ]);

            return self::DEFAULT_RATE;
        }

        $rate = (float) $raw;

        if ($rate < 0 || $rate > 100) {
            $acotada = max(0.0, min(100.0, $rate));

            Log::warning('TaxCalculator: tax_rate fuera del rango 0-100 en settings, se acota', [
                'value' => $rate,
                'acotada' => $acotada,
            ]);

            return $acotada;
        }

        return $rate;
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

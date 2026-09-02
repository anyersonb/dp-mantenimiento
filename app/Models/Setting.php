<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Traits\LogsActivity;

/**
 * Configuración clave/valor con tipo, persistida en base de datos.
 *
 * Existe porque la tasa de impuesto de repuestos (2026-09-01) NO puede vivir
 * en `config/`: en producción el config cache queda congelado tras el
 * deploy, y ya complicó reactivar un módulo entero por un flag en config
 * (`features.quotes`, ver App\Support\AccessControl). Cualquier valor que el
 * cliente deba poder cambiar desde el panel sin depender de un deploy va acá.
 *
 * `get()`/`set()` son el ÚNICO camino de lectura/escritura soportado en el
 * proyecto — no consultar ni escribir la tabla directamente desde otro
 * sitio (ni `DB::table('settings')`, ni un `tinker`, ni un seeder futuro):
 * la caché de abajo y la bitácora de `LogsActivity` solo se disparan por acá.
 * Una escritura por SQL crudo dejaría a `rate()` sirviendo el valor viejo
 * hasta que la caché expire (ver el TTL corto más abajo) y sin ningún
 * rastro en Activity — hallazgo de seguridad, 2026-09-01.
 */
class Setting extends Model
{
    use LogsActivity;

    protected $guarded = [];

    /**
     * TTL corto en vez de `rememberForever` (hallazgo de seguridad,
     * 2026-09-01): el observer solo invalida la caché en escrituras por
     * Eloquent. Una escritura por SQL crudo (phpMyAdmin, un `tinker`, un
     * import) no dispara `saved`/`deleted` y antes dejaba el valor pegado
     * INDEFINIDAMENTE. Con TTL corto, el peor caso es servir un valor viejo
     * durante como máximo este tiempo, no para siempre.
     */
    private const CACHE_TTL_MINUTES = 5;

    public static function cacheKey(string $key): string
    {
        return 'setting.'.$key;
    }

    /**
     * Lee un valor por clave, cacheado con TTL corto. La invalidación por
     * escritura vía Eloquent vive en App\Observers\SettingObserver
     * (saved/deleted); el TTL es la red para cualquier otra vía de
     * escritura. Un valor que se guarda y sigue mostrando el viejo hasta
     * limpiar caché a mano no es un pendiente de configuración, es un
     * defecto.
     */
    public static function get(string $key, mixed $default = null): mixed
    {
        return Cache::remember(
            self::cacheKey($key),
            now()->addMinutes(self::CACHE_TTL_MINUTES),
            function () use ($key, $default) {
                $setting = static::query()->where('key', $key)->first();

                if (! $setting) {
                    return $default;
                }

                return self::castValue($setting->value, $setting->type);
            }
        );
    }

    /**
     * Bitácora del cambio (hallazgo de seguridad, bloqueante, 2026-09-01):
     * cambiar un valor de Configuración —hoy, la tasa de impuesto— no dejaba
     * ningún rastro de quién, cuándo, ni el valor anterior. `logOnlyDirty()`
     * deja la comparación vieja/nueva a Spatie: si `value` no cambió, no se
     * escribe ningún asiento. Mismo patrón que WorkOrder/Machine/
     * HorometerReading, no uno nuevo.
     */
    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['key', 'value'])
            ->logOnlyDirty()
            ->dontSubmitEmptyLogs();
    }

    /**
     * Crea o actualiza el valor. Dispara el evento `saved` de Eloquent, que
     * es lo que invalida la caché (ver SettingObserver).
     */
    public static function set(string $key, mixed $value, string $type = 'string'): self
    {
        return static::updateOrCreate(
            ['key' => $key],
            ['value' => self::stringifyValue($value, $type), 'type' => $type],
        );
    }

    private static function castValue(?string $value, string $type): mixed
    {
        if ($value === null) {
            return null;
        }

        return match ($type) {
            'integer' => (int) $value,
            // is_numeric(...) ? (float) : $value: un `(float) 'abc'` silencioso
            // devolvía 0.0 acá mismo, antes de que TaxCalculator::rate() (la
            // fuente única de validación de la tasa) llegara siquiera a ver
            // que el dato estaba corrupto. Pasar el crudo cuando no es
            // numérico es lo que le permite a rate() detectarlo y loguearlo
            // en vez de heredar un 0.0 inventado. Hallazgo de seguridad,
            // 2026-09-01.
            'float' => is_numeric($value) ? (float) $value : $value,
            'boolean' => filter_var($value, FILTER_VALIDATE_BOOLEAN),
            'json' => json_decode($value, true),
            default => $value,
        };
    }

    private static function stringifyValue(mixed $value, string $type): string
    {
        return match ($type) {
            'boolean' => $value ? '1' : '0',
            'json' => (string) json_encode($value),
            default => (string) $value,
        };
    }
}

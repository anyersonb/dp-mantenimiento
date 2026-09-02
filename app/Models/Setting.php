<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;

/**
 * Configuración clave/valor con tipo, persistida en base de datos.
 *
 * Existe porque la tasa de impuesto de repuestos (2026-09-01) NO puede vivir
 * en `config/`: en producción el config cache queda congelado tras el
 * deploy, y ya complicó reactivar un módulo entero por un flag en config
 * (`features.quotes`, ver App\Support\AccessControl). Cualquier valor que el
 * cliente deba poder cambiar desde el panel sin depender de un deploy va acá.
 *
 * `get()`/`set()` son el único punto de lectura/escritura esperado en el
 * proyecto — no consultar la tabla directamente desde otro sitio, o la caché
 * de abajo queda desincronizada.
 */
class Setting extends Model
{
    protected $guarded = [];

    public static function cacheKey(string $key): string
    {
        return 'setting.'.$key;
    }

    /**
     * Lee un valor por clave, cacheado indefinidamente. La invalidación vive
     * en App\Observers\SettingObserver (saved/deleted), no acá: un valor que
     * se guarda y sigue mostrando el viejo hasta limpiar caché a mano no es
     * un pendiente de configuración, es un defecto.
     */
    public static function get(string $key, mixed $default = null): mixed
    {
        return Cache::rememberForever(
            self::cacheKey($key),
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
            'float' => (float) $value,
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

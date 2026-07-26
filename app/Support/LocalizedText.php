<?php

namespace App\Support;

use Illuminate\Contracts\Support\Arrayable;
use JsonSerializable;
use Stringable;

/**
 * Texto que se guarda en la base **sin idioma** y se renderiza en el del que
 * lee. Hallazgo E6-10.
 *
 * El sistema venía guardando frases ya traducidas, y por eso el idioma del
 * texto quedaba congelado en el del que lo escribió. Medido en la base real:
 *
 *   - las 6 alertas están en **inglés** ("Service due soon: LD015"), porque las
 *     disparó una cuenta en inglés, y el administrador trabaja en español;
 *   - las 93 notas de lectura de horómetro están en **español**, escritas por el
 *     importador;
 *   - la bitácora tiene 4 descripciones distintas en **español**, dos de ellas
 *     hardcodeadas sin pasar por los archivos de idioma.
 *
 * Cambiar el idioma del panel no cambiaba nada de eso: el texto ya estaba en la
 * tabla. Es la tercera aparición del mismo defecto (el `locale` por defecto de
 * las cuentas, la bitácora y las alertas) y por eso el arreglo es uno solo:
 * **clave + parámetros al guardar, `__()` al mostrar.**
 *
 * Se guarda como un sobre JSON reconocible. Lo que ya está guardado como frase
 * suelta sigue funcionando igual (ver App\Casts\AsLocalizedText): el texto
 * libre que escribe una persona —una observación, un motivo— no es traducible y
 * tiene que pasar de largo.
 */
final class LocalizedText implements Arrayable, JsonSerializable, Stringable
{
    /**
     * Marca del sobre. Con una clave propia y no un JSON pelado para poder
     * distinguir sin ambigüedad un texto localizable de una nota que un usuario
     * escribió y que casualmente parece JSON.
     */
    public const ENVELOPE_KEY = '__i18n';

    /**
     * @param  array<string, mixed>  $params
     */
    public function __construct(
        public readonly string $key,
        public readonly array $params = [],
    ) {}

    /**
     * @param  array<string, mixed>  $params
     */
    public static function of(string $key, array $params = []): self
    {
        return new self($key, $params);
    }

    /**
     * Reconstruye el sobre si el valor guardado es uno; devuelve null si es
     * texto libre (o está vacío).
     */
    public static function fromStored(mixed $stored): ?self
    {
        if (! is_string($stored) || ! str_contains($stored, self::ENVELOPE_KEY)) {
            return null;
        }

        $decoded = json_decode($stored, true);

        if (! is_array($decoded) || ! isset($decoded[self::ENVELOPE_KEY]['key'])) {
            return null;
        }

        return new self(
            (string) $decoded[self::ENVELOPE_KEY]['key'],
            (array) ($decoded[self::ENVELOPE_KEY]['params'] ?? []),
        );
    }

    /**
     * Lo que va a la columna.
     */
    public function encode(): string
    {
        return json_encode($this->toArray(), JSON_UNESCAPED_UNICODE);
    }

    /**
     * Renderiza el texto en el idioma activo. Si la clave no existe, `__()`
     * devuelve la clave misma, que es visible y rastreable — mejor que una
     * cadena vacía que se lee como "no pasó nada".
     */
    public function render(?string $locale = null): string
    {
        return (string) __($this->key, $this->params, $locale);
    }

    public function __toString(): string
    {
        return $this->render();
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public function toArray(): array
    {
        return [self::ENVELOPE_KEY => ['key' => $this->key, 'params' => $this->params]];
    }

    public function jsonSerialize(): mixed
    {
        return $this->toArray();
    }
}

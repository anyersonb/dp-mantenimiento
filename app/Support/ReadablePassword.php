<?php

namespace App\Support;

/**
 * Contraseña provisoria que se pueda dictar por teléfono sin equivocarse.
 *
 * La usa la acción "Generar clave" de Usuarios, que existe por el comentario de
 * la clienta del 2026-08-24: cuando a un operario se le olvida la suya, el
 * administrador tiene que poder darle una nueva en el momento, sin correo de
 * por medio (la mayoría de la gente de campo no tiene casilla cargada).
 *
 * Dos decisiones que no son estéticas:
 *
 *  - **Fuera los caracteres que se confunden al dictar**: 0/O, 1/l/I, 5/S.
 *    Este dato viaja por radio o por WhatsApp, y una clave que hay que deletrear
 *    tres veces termina anotada en un papel pegado a la máquina.
 *  - **`random_int` y no `rand`/`array_rand`**: es el generador criptográfico
 *    del sistema. Una clave predecible acá vale lo mismo que no tener clave.
 *
 * El formato es letras-dígitos-letras (ej. `khtr-462-mpqz`): se lee en tres
 * golpes y entra cómodo en los 8 caracteres mínimos que pide cualquier política
 * razonable.
 */
final class ReadablePassword
{
    /** Sin 'i', 'l', 'o' — se confunden con 1 y 0 al dictarlas. */
    private const LETTERS = 'abcdefghjkmnpqrstuvwxyz';

    /** Sin 0 ni 1 por lo mismo, y sin 5 porque se oye como "S". */
    private const DIGITS = '2346789';

    public static function make(): string
    {
        return self::pick(self::LETTERS, 4)
            .'-'.self::pick(self::DIGITS, 3)
            .'-'.self::pick(self::LETTERS, 4);
    }

    private static function pick(string $alphabet, int $length): string
    {
        $out = '';
        $last = strlen($alphabet) - 1;

        for ($i = 0; $i < $length; $i++) {
            $out .= $alphabet[random_int(0, $last)];
        }

        return $out;
    }
}

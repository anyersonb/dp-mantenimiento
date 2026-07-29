<?php

declare(strict_types=1);

/*
 * Mensajes de autenticación en español. Laravel solo los trae en inglés, así
 * que sin este archivo el login en español mostraba "These credentials do not
 * match our records." al errarle a la contraseña.
 */

return [

    'failed' => 'Estas credenciales no coinciden con nuestros registros.',
    'password' => 'La contraseña indicada es incorrecta.',
    'throttle' => 'Demasiados intentos de acceso. Vuelva a intentar en :seconds segundos.',

];

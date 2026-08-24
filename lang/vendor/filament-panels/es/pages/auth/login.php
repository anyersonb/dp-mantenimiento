<?php

/*
 * Copia del archivo del paquete (filament/filament) con UN cambio: la etiqueta
 * del primer campo dice "Usuario" y no "Correo electrónico" (pedido de la
 * clienta 2026-08-24: "email cambiarlo a username").
 *
 * Hay que copiar el archivo ENTERO y no solo la clave que cambia: las
 * traducciones de paquete se reemplazan por archivo, no se fusionan clave por
 * clave. Si Filament agrega una clave nueva en una actualización, hay que
 * traerla acá o saldrá en blanco en el login.
 *
 * Ojo: lo que cambia es el RÓTULO. El campo sigue siendo el correo del usuario
 * y sigue validándose como correo — cambiar eso es otra cosa (una columna
 * `username` propia) y no es lo que se pidió.
 */
return [

    'title' => 'Acceso',

    'heading' => 'Entre a su cuenta',

    'actions' => [

        'register' => [
            'before' => 'o',
            'label' => 'Abrir una cuenta',
        ],

        'request_password_reset' => [
            'label' => '¿Ha olvidado su contraseña?',
        ],

    ],

    'form' => [

        'email' => [
            'label' => 'Usuario',
        ],

        'password' => [
            'label' => 'Contraseña',
        ],

        'remember' => [
            'label' => 'Recordarme',
        ],

        'actions' => [

            'authenticate' => [
                'label' => 'Entrar',
            ],

        ],

    ],

    'messages' => [

        'failed' => 'Estas credenciales no coinciden con nuestros registros.',

    ],

    'notifications' => [

        'throttled' => [
            'title' => 'Demasiados intentos. Intente de nuevo en :seconds segundos.',
            'body' => 'Intente de nuevo en :seconds segundos.',
        ],

    ],

];

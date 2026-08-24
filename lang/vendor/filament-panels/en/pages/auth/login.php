<?php

/*
 * Copia del archivo del paquete (filament/filament) con UN cambio: la etiqueta
 * del primer campo dice "Username" y no "Email address" (pedido de la clienta
 * 2026-08-24: "email cambiarlo a username").
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

    'title' => 'Login',

    'heading' => 'Sign in',

    'actions' => [

        'register' => [
            'before' => 'or',
            'label' => 'sign up for an account',
        ],

        'request_password_reset' => [
            'label' => 'Forgot password?',
        ],

    ],

    'form' => [

        'email' => [
            'label' => 'Username',
        ],

        'password' => [
            'label' => 'Password',
        ],

        'remember' => [
            'label' => 'Remember me',
        ],

        'actions' => [

            'authenticate' => [
                'label' => 'Sign in',
            ],

        ],

    ],

    'messages' => [

        'failed' => 'These credentials do not match our records.',

    ],

    'notifications' => [

        'throttled' => [
            'title' => 'Too many login attempts',
            'body' => 'Please try again in :seconds seconds.',
        ],

    ],

];

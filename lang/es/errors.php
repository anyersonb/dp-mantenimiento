<?php

/*
 * Etapa 05, Bloque 5 (hallazgo M6) — resources/views/errors/ no existía y la
 * app mostraba las páginas por defecto de Laravel: en inglés, sin marca y sin
 * ninguna salida. Acá el 403 es la respuesta de diseño (no un caso raro) cada
 * vez que un rol toca algo que no le corresponde, así que necesita texto
 * claro y un botón de vuelta.
 */
return [
    'title' => 'Error :code',

    '403_heading' => 'Acceso no permitido',
    '403_message' => 'Tu usuario no tiene permiso para ver esta página. Si crees que es un error, comunícate con un administrador.',

    '404_heading' => 'Página no encontrada',
    '404_message' => 'La página que buscás no existe o fue movida.',

    '419_heading' => 'La página expiró',
    '419_message' => 'Tu sesión de trabajo expiró por inactividad. Volvé atrás e intentá de nuevo.',

    '500_heading' => 'Error del servidor',
    '500_message' => 'Ocurrió un problema inesperado. Ya quedó registrado; intentá de nuevo en unos minutos.',

    '503_heading' => 'Servicio en mantenimiento',
    '503_message' => 'El sistema está en mantenimiento programado. Volvé a intentarlo en unos minutos.',

    'name_already_used' => 'Ya existe un registro con el nombre ":name". Elegí otro nombre.',

    'exit_panel' => 'Volver al panel',
    'exit_field' => 'Volver al inicio',
    'exit_login' => 'Ir a iniciar sesión',
];

<?php

return [
    // Login
    'login_title' => 'Iniciar sesión',
    'login_email' => 'Usuario',
    'login_password' => 'Contraseña',
    'login_submit' => 'Entrar',
    'login_error' => 'Correo o contraseña incorrectos.',
    'login_throttled' => 'Demasiados intentos. Espera :seconds segundos antes de volver a intentar.',

    // Nav
    'nav_home' => 'Inicio',
    'nav_logout' => 'Salir',
    'back' => 'Volver',

    // Home
    'home_title' => 'Inicio',
    'home_greeting' => 'Hola, :name',
    'home_go_fuel' => 'Registrar combustible',
    'home_go_report' => 'Reporte de campo',
    'home_go_foreman' => 'Mi obra',
    'home_go_admin' => 'Ir al panel administrativo',
    'home_go_notifications' => 'Notificaciones',
    'home_no_access' => 'Aún no tienes una pantalla asignada. Contacta a tu supervisor.',

    // Notificaciones (bandeja de /field; misma tabla que la campanita del panel)
    'notifications_title' => 'Notificaciones',
    'notifications_empty' => 'No tienes notificaciones.',
    'notifications_mark_read' => 'Marcar como leída',
    'notifications_mark_all_read' => 'Marcar todas como leídas',

    // Selector de máquina (compartido)
    'machine_search_label' => 'Máquina',
    'machine_search_placeholder' => 'Escribe el ID de la máquina…',
    'machine_change' => 'Cambiar',
    'validation_required_machine' => 'Selecciona una máquina.',
    'hours_regressive' => 'La lectura (:hours h) es menor a la última registrada (:current h). Verifica el valor antes de enviar.',
    'hours_above_next' => 'La lectura (:hours h) es mayor que una posterior ya registrada (:next h). El horómetro no puede bajar con el tiempo: revisa la fecha o el valor.',

    // Geolocalización
    'geolocation_capturing' => 'Obteniendo tu ubicación…',
    'geolocation_ok' => 'Ubicación capturada',
    'geolocation_error_denied' => 'No autorizaste el acceso a tu ubicación. Actívalo en los permisos del navegador para que se capture.',
    'geolocation_error_unavailable' => 'No se pudo obtener tu ubicación. Puede ser la señal en este lugar: intenta de nuevo.',
    'geolocation_error_unsupported' => 'Este dispositivo no puede obtener tu ubicación. Igual puedes enviar el reporte, pero quedará sin ubicación.',
    'geolocation_retry' => 'Reintentar',

    // Combustible
    'fuel_title' => 'Registrar combustible',
    'fuel_gallons' => 'Galones',
    'fuel_hours' => 'Lectura de horómetro',
    'fuel_note' => 'Nota (opcional)',
    'fuel_note_placeholder' => 'Algo que quieras mencionar…',
    'fuel_submit' => 'Guardar',
    'fuel_success' => 'Registrado ✓',
    'fuel_success_detail' => 'El registro de combustible se guardó correctamente.',
    'fuel_success_no_location' => 'Registrado, pero sin ubicación',
    'fuel_will_submit_without_location' => 'Aún no se capturó tu ubicación: si envías ahora, el registro quedará guardado sin ella.',
    'fuel_new' => 'Registrar otro',

    // Reporte de campo
    'report_title' => 'Reporte de campo',
    'report_condition' => 'Estado de la máquina',
    'report_condition_ok' => 'OK',
    'report_condition_attention' => 'Requiere atención',
    'report_condition_critical' => 'Crítico',
    'report_hours' => 'Lectura de horómetro (opcional)',
    'report_notes' => 'Notas',
    'report_submit' => 'Enviar reporte',
    'report_success' => 'Reporte enviado ✓',
    'report_success_no_location' => 'Reporte enviado, pero sin ubicación',
    'report_will_submit_without_location' => 'Aún no se capturó tu ubicación: si envías ahora, el reporte quedará guardado sin ella.',
    'report_new' => 'Enviar otro',

    // Foreman
    'foreman_title' => 'Mi obra',
    'foreman_my_machines' => 'Máquinas en mi obra',
    'foreman_no_machines' => 'Aún no hay máquinas asignadas a esta obra.',
    'foreman_new_location' => 'Ubicación',
    'foreman_hours' => 'Lectura de horómetro (opcional)',
    'foreman_submit' => 'Actualizar',
    'foreman_success' => 'Actualizado ✓',
];

<?php

return [
    'alerts' => 'Alertas',
    'alert' => 'Alerta',
    'type' => 'Tipo',
    'title' => 'Título',
    'message' => 'Mensaje',
    'status' => 'Estado',
    'notified_at' => 'Notificada',
    'created_at' => 'Creada',
    'acknowledge' => 'Reconocer',
    'resolve' => 'Resolver',
    'empty_heading' => 'Sin alertas',
    'empty_desc' => 'Aquí aparecerán las alertas de servicio, checklist y horómetro.',

    'status_open' => 'Abierta',
    'status_acknowledged' => 'Reconocida',
    'status_resolved' => 'Resuelta',

    'type_service' => 'Servicio',
    'type_checklist' => 'Checklist',
    'type_hourmeter' => 'Horómetro',
    'type_other' => 'Otro',

    'auto_title' => 'Próximo a servicio: :machine',
    'auto_message' => 'La máquina :machine tiene :hours h restantes para su próximo servicio programado.',

    'checklist_title' => 'Alerta de checklist: :machine (:code)',

    'create_work_order' => 'Crear orden de trabajo',
    'create_work_order_confirm' => 'Esto abrirá una nueva orden de trabajo preventiva para esta máquina y marcará la alerta como reconocida.',

    'mail_subject' => ':count máquina(s) próxima(s) a servicio',
    'mail_heading' => 'Máquinas próximas a servicio',
    'mail_intro' => ':count máquina(s) están a 100 horas o menos de su próximo servicio programado.',
    'mail_cta' => 'Abrir el panel de flota',
];

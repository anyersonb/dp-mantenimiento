<?php

// Pantalla de solo lectura "Reportes de campo" (/admin/field-reports).
// Los reportes los crea el operario desde /field/report; ver lang/es/field.php
// para el copy de esa app móvil.
return [
    'nav' => 'Reportes de campo',
    'model_singular' => 'Reporte de campo',
    'model_plural' => 'Reportes de campo',

    // Columnas / filtros
    'column_condition' => 'Estado',
    'column_reporter' => 'Reportado por',
    'column_hours' => 'Horómetro',
    'column_date' => 'Fecha',
    'column_location_status' => 'Ubicación',
    'filter_condition' => 'Estado',

    'condition_ok' => 'OK',
    'condition_attention' => 'Requiere atención',
    'condition_critical' => 'Crítico',

    'location_yes' => 'Con ubicación',
    'location_no' => 'Sin ubicación',

    // Detalle (ViewAction / infolist)
    'detail_notes' => 'Notas',
    'detail_no_notes' => 'Sin notas',
    'detail_location' => 'Ubicación',
    'detail_map_link' => 'Ver en el mapa',
    // Sección de solo lectura (2026-09-22): OT abiertas a partir de este reporte.
    'detail_work_orders' => 'Órdenes de trabajo asociadas',

    'empty_heading' => 'No hay reportes de campo',
    'empty_desc' => 'Los reportes que envíe el personal desde la aplicación de campo van a aparecer acá.',

    // Notificaciones (canal database — ver App\Support\Notifications\NotificationRegistry)
    'notification_title_critical' => '🔴 Reporte crítico — :machine',
    'notification_title_attention' => '🟡 Reporte requiere atención — :machine',
    'notification_body' => 'Enviado por :reporter',
    'notification_action' => 'Ver reportes de campo',
    'unknown_reporter' => 'Desconocido',
];

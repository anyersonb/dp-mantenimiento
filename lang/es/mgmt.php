<?php

return [
    // Dashboard de costos / reportes
    'cost_by_machine' => 'Costo de mantenimiento por máquina',
    'total_fleet_cost' => 'Costo total de mantenimiento de la flota',
    'service_count' => 'Servicios',
    'maintenance_cost' => 'Costo de mantenimiento',

    // Movimiento de flota
    'move_machine' => 'Mover',
    'move_machines' => 'Mover a obra',
    'move_to' => 'Destino',
    'move_confirm' => 'Esto cambia la ubicación actual de la máquina. Quedará registrado en la bitácora.',
    'move_success' => 'Ubicación actualizada.',
    'move_success_bulk' => 'Ubicaciones actualizadas.',

    // Bitácora
    'audit_log' => 'Bitácora',
    'audit_logs' => 'Bitácora',
    'date' => 'Fecha',
    'causer' => 'Usuario',
    'event' => 'Evento',
    'description' => 'Descripción',
    'subject_type' => 'Tipo de registro',
    'subject_id' => 'Registro #',
    'changes' => 'Cambios',
    'properties' => 'Detalle',
    'attribute' => 'Campo',
    'old_value' => 'Valor anterior',
    'new_value' => 'Valor nuevo',
    'no_causer' => 'Sistema',
    'event_created' => 'Creado',
    'event_updated' => 'Actualizado',
    'event_deleted' => 'Eliminado',
    'event_approved' => 'Aprobado',
    'event_imported' => 'Importado',

    // Cotizaciones
    'quotes' => 'Cotizaciones',
    'quote' => 'Cotización',
    'title' => 'Título',
    'vendor' => 'Proveedor',
    'amount' => 'Monto',
    'machine' => 'Máquina',
    'work_order' => 'Orden de trabajo',
    'file' => 'Archivo',
    'expires_at' => 'Vence',
    'share_link' => 'Link para compartir',
    'copy_link' => 'Copiar link',
    'link_copied' => 'Link copiado al portapapeles.',
    'public_expired' => 'Este enlace ha expirado.',
    'public_view_file' => 'Ver / descargar archivo',
    'public_no_file' => 'Esta cotización no tiene archivo adjunto.',
    'public_amount' => 'Monto',
    'public_vendor' => 'Proveedor',
    'public_expires_at' => 'Válido hasta',

    // Reportes / exportación
    'export_pdf' => 'Exportar PDF',
    'export_excel' => 'Exportar Excel',
    'fleet_report_title' => 'Reporte de Estado de Flota',
    'generated_at' => 'Generado el',

    // Importador del PM Service Report
    'import_pm_report' => 'Importar PM Service Report',
    'import_pm_report_modal_heading' => 'Importar PM Service Report',
    'import_pm_report_modal_description' => 'Sube el Excel del PM Service Report para actualizar horas y lecturas de las máquinas que ya existen en el sistema. Las máquinas del reporte que no coincidan con ninguna existente NO se crean: quedan listadas como "sin coincidencia" para revisión manual.',
    'import_pm_report_submit' => 'Importar',
    'import_pm_report_file_label' => 'Archivo Excel (.xlsx)',
    'import_pm_report_file_help' => 'Formato "PM Service Report" (hoja Sheet1, 2 filas por máquina).',
    'import_pm_report_notif_title' => 'Importación del PM Service Report',
    'import_pm_report_summary' => ':updated actualizadas · :unmatched sin coincidencia · :warnings avisos',
    'import_pm_report_unmatched_list' => 'Sin coincidencia: :ids',
    'import_pm_report_log' => 'Importación de PM Service Report: :updated actualizadas, :unmatched sin coincidencia',

    // Mapa de flota
    'fleet_map' => 'Mapa de flota',
    'coords_approx_notice' => 'Las coordenadas mostradas son aproximadas (zona sur de Florida) y están pendientes de confirmación por el cliente.',
    'no_coords' => 'Aún no hay obras con coordenadas.',
];

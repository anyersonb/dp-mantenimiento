<?php

return [
    // Dashboard de costos / reportes
    'cost_by_machine' => 'Costo de mantenimiento por máquina',
    // "(sin impuesto)": son métricas internas (repuestos por su costo cargado
    // en la OT, columna espejo work_orders.parts_cost), no facturación. El
    // impuesto de repuestos (2026-09-01) solo se aplica en el reporte de
    // costos (CostReportBuilder::totals()), nunca acá — para que nadie
    // compare estas dos cifras con el total del reporte como si fueran lo
    // mismo.
    'total_fleet_cost' => 'Costo total de mantenimiento de la flota (sin impuesto)',
    'service_count' => 'Servicios',
    'maintenance_cost' => 'Costo de mantenimiento (sin impuesto)',

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
    'date_from' => 'Desde',
    'date_until' => 'Hasta',
    'causer' => 'Usuario',
    'event' => 'Evento',
    'description' => 'Descripción',
    'subject_type' => 'Tipo de registro',
    // Tipos de registro de la bitácora. La clave es el nombre de la clase del
    // modelo en snake_case; si falta una, se muestra el nombre de la clase.
    'subject_machine' => 'Máquina',
    'subject_work_order' => 'Orden de trabajo',
    'subject_role' => 'Rol',
    'subject_horometer_reading' => 'Lectura de horómetro',
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
    'event_hourmeter_replaced' => 'Reemplazo de horómetro',
    'event_location_moved' => 'Máquina movida',
    'event_location_confirmed' => 'Ubicación confirmada',

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

    // Reemplazo de horómetro
    'hourmeter_replaced_log' => 'Reemplazo de horómetro en :machine: :old h (viejo) -> :new h (nuevo)',

    // Mapa de flota
    'fleet_map' => 'Mapa de flota',
    'coords_approx_notice' => 'Las coordenadas mostradas son aproximadas (zona sur de Florida) y están pendientes de confirmación por el cliente.',
    'no_coords' => 'Aún no hay obras con coordenadas.',
    'event_discarded' => 'Descartada',
    'machine_moved_log' => 'Máquina movida a otra obra',
    'location_confirmed_log' => 'Ubicación de la máquina confirmada sin cambios',
    'machine_approved_log' => 'Datos verificados y aprobados',
    'machine_discarded_log' => 'Maquina :machine descartada: queda inactiva y fuera de revision',

    // Un administrador le dio una clave nueva a alguien. La clave NO se guarda
    // en la bitácora: queda el hecho y quién lo hizo, no el secreto.
    'event_password_generated' => 'Clave regenerada',
    'event_role_deleted' => 'Rol borrado',
    'user_password_generated_log' => 'Se generó una clave nueva para :user',
    'role_deleted_log' => 'Se borró el rol :role y se movieron :count usuario(s)',

    'import_duplicate_row' => ':machine aparece más de una vez en el reporte: se toma la lectura de :kept_hours h del :kept_date y se descarta la de :dropped_hours h del :dropped_date. Revisá el archivo con el cliente.',
    'import_incoherent_reading' => ':machine: la lectura de :hours h del :date contradice el historial (:reason). La lectura NO se cargó; el resto de la fila sí.',
    'import_stale_reading' => ':machine: el reporte trae :report_hours h del :report_date, más viejo que las :kept_hours h que ya tenía la máquina. Se conservan las :kept_hours h y las restantes se recalculan desde el ancla del reporte.',

    /*
     * Avisos del importador. Estaban escritos en español fijo dentro de
     * PmServiceReportImporter, así que un usuario en inglés los recibía en
     * español mientras el resto de la interfaz estaba traducida (y al revés no
     * había forma de verlos en otro idioma). Se renderizan en el idioma de
     * quien corre el import.
     */
    'import_warn_no_reading' => ':machine (fila :row): coincide pero el reporte no trae ninguna lectura legible para esta fila, no se actualizó nada.',
    'import_warn_update_failed' => ':machine (fila :row): error al actualizar — :error',
    'import_warn_no_id' => 'Fila :row: no se pudo identificar un ID de máquina en ":text", se omite.',
    'import_warn_unreadable_last_service' => ':machine (fila :row): último servicio ilegible (":raw"), se conserva el valor actual.',
    'import_warn_unreadable_latest_reading' => ':machine (fila :row): última lectura ilegible (":raw"), se conserva el valor actual.',
    'import_warn_unreadable_remaining' => ':machine (fila :row): horas restantes ilegibles (":raw"), se conserva el valor actual.',
    'import_warn_orphan_description' => 'Fila :row: la máquina ":machine" no tiene fila de datos (fin de archivo), se omite.',
];

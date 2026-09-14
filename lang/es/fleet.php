<?php

return [
    // Navegación / grupos
    'group_fleet' => 'Flota',
    'group_operations' => 'Operaciones',
    'group_admin' => 'Administración',
    'group_management' => 'Gerencia',
    'machines' => 'Máquinas',
    'machine' => 'Máquina',

    // Secciones
    'identification' => 'Identificación',
    'status_service' => 'Estado y servicio',
    'technical' => 'Ficha técnica',
    'images' => 'Imágenes',
    'data_control' => 'Control de datos',

    // Campos
    'id_code' => 'ID',
    'category' => 'Categoría',
    'make' => 'Marca',
    'model' => 'Modelo',
    'serial' => 'Serie / PIN',
    'serial_type' => 'Tipo de serie',
    'year' => 'Año',
    'location' => 'Ubicación',
    'description' => 'Descripción',
    'status' => 'Estado',
    'hourmeter_status' => 'Horómetro',
    'hours_adjustment' => 'Ajuste de horas',
    'current_hours' => 'Horas actuales',
    'current_hours_date' => 'Fecha de lectura',
    'service_interval' => 'Intervalo de servicio',
    'last_service_hours' => 'Último servicio (h)',
    'last_service_date' => 'Fecha último servicio',
    'remaining_hours' => 'Restantes',
    'remaining_help' => 'Horas para el próximo servicio (referencia del PM report).',
    'engine_model' => 'Modelo de motor',
    'engine_serial' => 'Serie de motor',
    'electrical' => 'Sistema eléctrico',
    'tires' => 'Neumáticos',
    'oil_capacity' => 'Capacidad de aceite',
    'spec_sheet' => 'Ficha técnica (Machinery Info Book)',
    'spec_sheet_help' => 'Texto íntegro tal cual figura en el Info Book, no editar salvo corrección de datos.',
    'image' => 'Imagen principal',
    'gallery' => 'Galería',
    'needs_review' => 'Requiere revisión',
    'review' => 'Revisión',
    'review_note' => 'Nota de revisión',
    'notes' => 'Notas',

    // Verificación de datos (permiso verify_data)
    'approve_data' => 'Aprobar datos',
    'approve_confirm' => 'Se marcará esta máquina como verificada y quedará registrado en la bitácora. ¿Confirmas?',
    'approved_ok' => 'Datos verificados y aprobados.',
    'approve_bulk' => 'Aprobar seleccionadas',
    'approved_bulk_ok' => 'Se aprobaron :count máquina(s).',

    // Estados
    'status_active' => 'Activa',
    'status_not_in_service' => 'Fuera de servicio',
    'status_down' => 'Averiada',
    'status_inactive' => 'Inactiva',
    'status_unknown' => 'Desconocido',
    'hm_broken' => 'Roto',
    'hm_no_info' => 'Sin info',
    'hm_replaced' => 'Reemplazado',
    'due_soon' => 'Próximo a servicio (<= 100 h)',

    // Reemplazo de horómetro (evento, no una lectura más)
    'replace_hourmeter' => 'Registrar reemplazo de horómetro',
    'replace_hourmeter_old_hours' => 'Última lectura del horómetro viejo',
    'replace_hourmeter_new_hours' => 'Lectura inicial del horómetro nuevo',
    'replace_hourmeter_note' => 'Nota (opcional)',
    'replace_hourmeter_confirm' => 'Esto marca el horómetro como reemplazado, re-ancla el seguimiento de servicio a la escala nueva y queda registrado en la bitácora. ¿Confirmas?',
    'replace_hourmeter_success' => 'Reemplazo de horómetro registrado.',

    // Partes
    'parts_catalog' => 'Catálogo de partes',
    'part' => 'Parte',
    'parts' => 'Partes',
    'part_category' => 'Tipo',
    'change_interval' => 'Intervalo de cambio',
    'detail' => 'Detalle',

    // Lecturas
    'horometer_history' => 'Historial de horómetro',
    // Ver la nota en el archivo en inglés: sin estas dos, los modales de esta
    // sección salen con "horometer reading" en cualquier idioma.
    'reading_singular' => 'Lectura de horómetro',
    'reading_plural' => 'Lecturas de horómetro',
    'hours' => 'Horas',
    'read_at' => 'Fecha',
    'source' => 'Origen',
    'gallons' => 'Galones',
    'verified' => 'Verificado',
    'note' => 'Nota',
    'src_fuel' => 'Cisterna',
    'src_maintenance' => 'Mantenimiento',
    'src_workshop' => 'Taller',
    'src_manual' => 'Manual',
    'discard_data' => 'Descartar',
    'discard_confirm' => 'La maquina queda inactiva y sale de la lista de revision. Su historial (ordenes de trabajo, lecturas y costos) se conserva completo.',
    'discard_reason' => 'Motivo del descarte',
    'discarded_ok' => 'Maquina descartada. Su historial se conservo.',
    'delete_heading' => 'Borrar la maquina :machine',
    'delete_warning' => 'Esto envía :machine a la Papelera: NO es una baja definitiva. La máquina queda oculta y se puede restaurar en cualquier momento, y su historial no se toca ni se pierde ahora —hoy tiene :work_orders orden(es) de trabajo con sus costos, :readings lectura(s) de horómetro, :alerts alerta(s), :parts parte(s) del catálogo y :field_reports reporte(s) de campo, todos intactos—. Ese historial solo se pierde, en cascada y sin vuelta atrás, si más adelante un administrador la elimina DEFINITIVAMENTE desde la Papelera. Para dar de baja una máquina sin pasar por la papelera usa el estado Inactiva, o la acción Descartar si está en revisión.',
    'delete_confirm_button' => 'Sí, enviar a la papelera',

    // Papelera — eliminacion DEFINITIVA (borrado duro real, sin vuelta atras).
    // Distinto del aviso de arriba: ese es el soft-delete normal (recuperable
    // desde la papelera); este es el que de verdad no tiene marcha atras.
    'force_delete_warning' => 'Esta máquina (:machine) tiene :work_orders orden(es) de trabajo, :readings lectura(s) de horómetro, :parts parte(s), :alerts alerta(s) y :field_reports reporte(s) de campo. El borrado definitivo los eliminará para siempre y no se puede deshacer.',
    'force_delete_bulk_warning' => 'Estas :count máquinas tienen en total :work_orders orden(es) de trabajo, :readings lectura(s) de horómetro, :parts parte(s), :alerts alerta(s) y :field_reports reporte(s) de campo. El borrado definitivo los eliminará para siempre y no se puede deshacer.',

    'imported_reading_note' => 'Lectura importada del PM Service Report',
    'imported_reading_from_file_note' => 'Lectura importada del PM Service Report (:file)',

    // Filtro por número de máquina (pedido del cliente 2026-08-05)
    'machine_number' => 'N.º de máquina',
    'machine_number_placeholder' => 'Ej.: EX010, o solo EX',

    // Módulo de Complementos (Attachments) — ver spec-complementos-dp.md.
    // Registro autónomo, sin vínculo con máquinas. Reutiliza este mismo
    // archivo con prefijo `attachment_*` para no romper la paridad ES/EN
    // que vigila TranslationParitySentinelTest, y comparte las claves
    // genéricas de arriba (model, serial, serial_type, year, location,
    // description, status, needs_review, review_note, notes, spec_sheet,
    // image, gallery, make) porque son los mismos campos que en Máquinas.
    'attachments' => 'Complementos',
    'attachment' => 'Complemento',
    'attachment_status_section' => 'Estado',
    'attachment_technical' => 'Técnico',
    'attachment_documents' => 'Documentos',
    'attachment_documents_uploaded' => 'Documentos ya guardados',
    // Etiqueta propia y distinta de "ID" (fleet.id_code, la de Máquinas):
    // es el campo que el cliente pidió explícitamente por nombre.
    'attachment_id_code' => 'ID del complemento',
    'attachment_name' => 'Nombre',
    'attachment_type' => 'Tipo',
    'attachment_type_bucket' => 'Cucharón',
    'attachment_type_hammer' => 'Martillo hidráulico',
    'attachment_type_grapple' => 'Garra',
    'attachment_type_auger' => 'Barrenadora',
    'attachment_type_broom' => 'Escoba',
    'attachment_type_ripper' => 'Escarificador',
    'attachment_type_fork' => 'Horquilla',
    'attachment_type_blade' => 'Cuchilla',
    'attachment_type_compactor' => 'Compactador',
    'attachment_type_other' => 'Otro',
    'attachment_weight' => 'Peso',
    'attachment_dimensions' => 'Dimensiones',
    'attachment_compatibility' => 'Compatibilidad',
    'attachment_acquisition_date' => 'Fecha de adquisición',
    'attachment_condition_note' => 'Nota de condición',
    'attachment_number' => 'N.º de complemento',
    'attachment_number_placeholder' => 'Ej.: BKT-01, o solo BKT',
];

<?php

// Centro de reportes (pedido del cliente 2026-08-05).
// Ojo: TranslationParitySentinelTest falla si una clave existe acá y no en
// lang/en/reports.php, o al revés.
return [
    // Navegación / pantalla
    'nav' => 'Reportes',
    'title' => 'Reportes',
    'which_report' => 'Qué reporte',
    'report' => 'Reporte',
    'report_costs' => 'Costos de mantenimiento',
    'report_category_inventory' => 'Inventario por categoría',

    // Periodo
    'period' => 'Periodo',
    'period_help' => 'El gasto se cuenta por la fecha de cierre de la orden de trabajo. Las órdenes incluidas que todavía no cerraron se cuentan por su fecha de apertura.',
    'quick_period' => 'Atajo',
    'this_month' => 'Este mes',
    'last_month' => 'Mes pasado',
    'last_3_months' => 'Últimos 3 meses',
    'this_year' => 'Este año',
    'custom' => 'Personalizado',
    'from' => 'Desde',
    'to' => 'Hasta',

    // Filtros
    'filters' => 'Filtros',
    'id_code_help' => 'Acepta un fragmento: "EX" trae todas las excavadoras, "EX010" solo esa.',
    'machines' => 'Máquinas',
    'locations' => 'Obras',
    'location_help' => 'La obra que quedó registrada en la orden de trabajo, no dónde está la máquina hoy.',
    'completed_by' => 'Hizo el mantenimiento',
    'completed_by_help' => 'Quien cerró la orden de trabajo. Las órdenes cerradas antes del 5 de agosto de 2026 no tienen este dato.',
    'statuses_help' => 'Por defecto solo las completadas: el costo de una orden abierta todavía puede cambiar.',
    'selected' => 'seleccionadas',
    'applied_filters' => 'Filtros aplicados',

    // Descargas
    'download_pdf' => 'Descargar PDF',
    'download_excel' => 'Descargar Excel',
    'generated_by' => 'Generado por',

    // Totales
    'total_spent' => 'Gasto total',
    'parts_only' => 'solo repuestos',
    'machines_with_spend' => 'Máquinas',
    'work_orders' => 'Órdenes de trabajo',
    'labor_hours' => 'Horas de trabajo',
    'not_priced' => 'sin valorizar',
    'parts_spend' => 'Gasto en repuestos',
    'grand_total' => 'Total general',
    'total' => 'Total',

    // Detalle
    'detail' => 'Detalle por orden de trabajo',
    'detail_in_files' => 'El detalle completo (quién, dónde, checklist y repuestos uno por uno) va en el PDF y en el Excel.',
    'location' => 'Obra',
    'checklist' => 'Checklist',
    'checklist_total' => 'Ítems de checklist',
    'checklist_alerts' => 'Ítems con alerta',
    'items' => 'ítems',
    'no_checklist' => 'Sin checklist ejecutado.',
    'part_number' => 'N.º de parte',
    'part_description' => 'Repuesto',
    'quantity' => 'Cantidad',
    'unit_cost' => 'Costo unitario',
    'subtotal' => 'Subtotal',
    'wo_total' => 'Total de la orden',
    'no_parts' => 'Sin repuestos cargados.',
    'no_results' => 'No hay órdenes de trabajo que cumplan estos filtros en este periodo.',

    // Avisos de integridad
    'not_recorded' => 'Sin registrar',
    'no_cost_loaded' => 'sin costo cargado',
    'data_gaps' => 'Lo que este reporte no puede responder',
    'gap_parts_without_cost' => ':count repuesto(s) cargado(s) sin costo unitario: son gasto real que el total NO incluye.',
    'gap_unknown_completer' => ':count orden(es) sin registro de quién hizo el mantenimiento.',
    'gap_unknown_location' => ':count orden(es) sin registro de en qué obra se hizo.',
    'uncategorized_notice' => 'Hay :count máquina(s) sin tipo asignado, así que la suma por categorías no da el total de la flota.',

    // Pies de página
    'footer_note' => 'El gasto son repuestos: cantidad × costo unitario de cada repuesto cargado en la orden de trabajo. La mano de obra se informa en horas y no se valoriza porque el sistema no tiene una tarifa por hora definida. Generado por el Sistema de Gestión de Mantenimiento de DP Development.',
    'category_footer_note' => 'Incluye las categorías sin máquinas, para que se pueda verificar cuáles están vacías. No incluye las máquinas dadas de baja. Generado por el Sistema de Gestión de Mantenimiento de DP Development.',
];

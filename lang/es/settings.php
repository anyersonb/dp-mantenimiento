<?php

// Configuración del panel (impuesto de repuestos, 2026-09-01).
// Ojo: TranslationParitySentinelTest falla si una clave existe acá y no en
// lang/en/settings.php, o al revés.
return [
    'nav' => 'Configuración',
    'title' => 'Configuración',
    'tax_section' => 'Impuesto de repuestos',
    'tax_section_help' => 'Se aplica solo a repuestos, sobre el subtotal ya sumado del reporte de costos. La mano de obra y cualquier otro cargo quedan exentos.',
    'tax_rate' => 'Tasa de impuesto (%)',
    'tax_rate_help' => 'Aplica solo a repuestos (no a mano de obra). Los precios cargados en el sistema son netos: el impuesto se suma encima, incluso para órdenes de trabajo anteriores a este cambio.',
    'notifications_section' => 'Notificaciones',
    'notifications_section_help' => 'Un reporte de campo Crítico siempre notifica a quien puede ver Reportes de campo. Acá se decide si uno que "Requiere atención" también avisa.',
    'notify_on_attention' => 'Notificar también los reportes "Requiere atención"',
    'notify_on_attention_help' => 'Apagado por defecto: solo lo Crítico interrumpe. Enciéndalo si además quiere un aviso cuando el personal marca una máquina como "Requiere atención".',
    'save' => 'Guardar',
    'saved' => 'Configuración guardada.',
];

<?php

return [
    // Navegación / modelo
    // Nota: reutiliza el grupo fleet.group_admin ("Administración"), igual que UserResource.
    'nav' => 'Roles y permisos',
    'model_role' => 'Rol',
    'model_roles' => 'Roles y permisos',

    // Campos del formulario
    'field_name' => 'Nombre',
    'field_description' => 'Para qué es este rol',
    'field_description_help' => 'Explique en una frase qué hace la persona que tiene este rol. Se muestra al asignarlo a un usuario, para que quien cree la cuenta sepa qué está dando.',
    'field_description_locked_hint' => 'Los roles del sistema traen su descripción traducida al español y al inglés; es la que se muestra debajo.',
    'field_permissions' => 'Permisos',
    'field_permissions_help' => 'Debajo de cada permiso está lo que habilita. Marque solo lo que esta persona necesita para su trabajo.',
    'name_locked_hint' => 'Los roles del sistema no pueden renombrarse (el código los referencia por nombre).',

    // Tabla
    'description' => 'Para qué es',
    'no_description' => 'Sin descripción',
    'permissions_count' => 'Permisos',
    'users_count' => 'Usuarios',

    // Borrado de roles (pedido de la clienta 2026-08-24)
    'delete_blocked_system_title' => 'Este rol no se puede borrar',
    'delete_blocked_system_body' => 'Es uno de los siete roles del sistema: el código lo busca por nombre para decidir quién entra a cada pantalla, así que borrarlo dejaría partes del sistema sin dueño. Si no lo usa, quítele los permisos o desactive a los usuarios que lo tienen.',
    'delete_blocked_users_title' => 'Primero hay que dejar el rol sin usuarios',
    'delete_blocked_users_body' => 'El rol :role todavía está asignado a :count usuario(s). Cámbieles el rol (o desactive esas cuentas) y después bórrelo; así ninguna cuenta se queda sin permisos de un momento a otro.',
    'delete_reason_system' => 'Rol del sistema: no se puede borrar.',
    'delete_reason_has_users' => 'Tiene :count usuario(s) asignado(s).',
    'delete_blocked_understood' => 'Entendido',
    'bulk_skipped_title' => 'Algunos roles no se borraron',
    'bulk_skipped_body' => 'Se borraron :deleted rol(es). Quedaron :skipped sin borrar porque son del sistema o todavía tienen usuarios asignados.',

    // Etiquetas amigables de permisos (Spatie)
    'perm_view_fleet' => 'Ver flota',
    'perm_manage_machines' => 'Gestionar máquinas',
    'perm_view_costs' => 'Ver costos',
    'perm_manage_users' => 'Gestionar usuarios',
    'perm_verify_data' => 'Verificar/aprobar datos',
    'perm_manage_quotes' => 'Gestionar cotizaciones',
    'perm_create_work_order' => 'Crear órdenes de trabajo',
    'perm_execute_work_order' => 'Ejecutar órdenes de trabajo',
    'perm_log_horometer' => 'Registrar horómetro',
    'perm_log_fuel' => 'Registrar combustible',
    'perm_field_report' => 'Reporte de campo',
    'perm_confirm_location' => 'Confirmar ubicación',
    'perm_move_fleet' => 'Mover flota',
    'perm_view_reports' => 'Ver reportes',
    'perm_view_audit_log' => 'Ver bitácora',

    // Qué habilita cada permiso (pedido de la clienta 2026-08-24). Cada texto
    // describe lo que el permiso controla REALMENTE en el código, no lo que su
    // nombre sugiere.
    'perm_desc_view_fleet' => 'Ver el listado de máquinas, la ficha de cada una, el mapa de flota, las obras, las marcas y las categorías. Es solo lectura y es el permiso mínimo para entrar al sistema.',
    'perm_desc_manage_machines' => 'Crear y editar máquinas, y administrar obras, marcas y categorías. Borrar una máquina queda reservado al administrador, porque se lleva su historial completo.',
    'perm_desc_view_costs' => 'Ver los importes: costo de los repuestos de cada orden, el reporte de costos y las facturas adjuntas. Sin este permiso la persona ve el trabajo hecho pero no cuánto costó.',
    'perm_desc_manage_users' => 'Crear usuarios, asignarles roles, y crear o editar roles y permisos. Es el permiso que da acceso a esta misma pantalla.',
    'perm_desc_verify_data' => 'Aprobar o descartar los datos de una máquina marcada "para revisar", y encender o apagar esa marca. Es el visto bueno sobre los datos que cargó otra persona.',
    'perm_desc_manage_quotes' => 'Gestionar cotizaciones. El módulo de cotizaciones está apagado a pedido de DP, así que hoy este permiso no abre ninguna pantalla.',
    'perm_desc_create_work_order' => 'Abrir órdenes de trabajo, a mano o desde una alerta de servicio. No incluye ejecutarlas ni cerrarlas.',
    'perm_desc_execute_work_order' => 'Trabajar la orden y cerrarla: cargar repuestos, completar el checklist, subir adjuntos y marcarla como completada.',
    'perm_desc_log_horometer' => 'Registrar lecturas del horómetro (hour meter). Hoy la lectura se toma dentro del reporte de campo, del tablero del capataz y de la carga de combustible, así que este permiso acompaña a esos tres y por sí solo no abre ninguna pantalla.',
    'perm_desc_log_fuel' => 'Cargar combustible desde el celular (pantalla "Combustible" de la aplicación de campo), con las horas de la máquina en el mismo formulario.',
    'perm_desc_field_report' => 'Enviar el reporte de campo desde el celular: en qué estado está la máquina, novedades y, si la sabe, la lectura de horas.',
    'perm_desc_confirm_location' => 'Confirmar desde el celular en qué obra está cada máquina (tablero del capataz). Es lo que mantiene la ubicación al día.',
    'perm_desc_move_fleet' => 'Mover una máquina de una obra a otra desde el panel, de a una o de a varias.',
    'perm_desc_view_reports' => 'Entrar al centro de reportes y descargar el PDF y el Excel. Ver los importes del reporte de costos exige además "Ver costos".',
    'perm_desc_view_audit_log' => 'Ver la bitácora: quién hizo cada cambio y cuándo. Es solo lectura y no se puede borrar.',

    // Para qué es cada rol del sistema (pedido de la clienta 2026-08-24). Los
    // roles que ella cree desde el panel llevan la descripción que escriba en
    // el formulario; estos siete la traen traducida a los dos idiomas.
    'role_desc_administrador' => 'Acceso total. Administra usuarios, roles, máquinas, costos y reportes, y es el único que puede borrar una máquina.',
    'role_desc_responsable_mantenimiento' => 'Planifica el mantenimiento: administra las máquinas, abre órdenes de trabajo, ve los costos y saca los reportes. No administra usuarios ni cierra órdenes en el taller.',
    'role_desc_foreman' => 'Capataz de obra. Desde el celular confirma en qué obra está cada máquina, reporta su estado y registra las horas.',
    'role_desc_operador_cisterna' => 'Operador de la cisterna. Desde el celular registra las cargas de combustible con las horas de la máquina.',
    'role_desc_personal_mantenimiento' => 'Personal de mantenimiento en campo. Desde el celular reporta el estado de las máquinas y registra las horas.',
    'role_desc_taller' => 'Taller. Ejecuta y cierra las órdenes de trabajo —repuestos, checklist y adjuntos— y ve los costos de lo que carga.',
    'role_desc_gerencia' => 'Gerencia. Mira: flota, costos y reportes, y puede mover máquinas de obra. No carga ni cierra trabajo.',
];

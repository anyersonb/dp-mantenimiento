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
    'name_locked_hint' => 'Los roles del sistema no pueden renombrarse: su descripción traducida y el restaurador de permisos los buscan por nombre. Borrarlos sí se puede.',

    // Tabla
    'description' => 'Para qué es',
    'no_description' => 'Sin descripción',
    'permissions_count' => 'Permisos',
    'users_count' => 'Usuarios',

    // Borrado de roles. Ya no hay roles indestructibles: lo que queda por
    // cuidar es que ninguna cuenta se quede sin permisos, y que el sistema no
    // se quede sin nadie capaz de administrarlo.
    'delete_heading' => 'Borrar el rol :role',
    'delete_description_empty' => 'Este rol no tiene usuarios asignados. Se borrará y no se puede deshacer.',
    'delete_description_with_users' => 'Este rol está asignado a :count usuario(s). Elija a qué rol pasan antes de borrarlo: ninguna cuenta puede quedarse sin ningún rol.',
    'reassign_label' => 'Mover esos usuarios a',
    'reassign_help' => 'Si alguna de esas cuentas tenía además otros roles, los conserva: solo se le cambia éste.',
    'bulk_reassign_help' => 'Entre los roles seleccionados hay :count usuario(s). Todos pasan al rol que elija acá.',
    'delete_done_title' => 'Rol borrado',
    'delete_done_empty' => 'El rol se borró. No tenía usuarios asignados.',
    'delete_done_moved' => 'El rol se borró y :count usuario(s) pasaron a :role.',
    'delete_blocked_last_admin_title' => 'Así el sistema se quedaría sin administrador',
    'delete_blocked_last_admin_body' => 'Después de este borrado no quedaría ninguna cuenta activa capaz de entrar al panel y administrar usuarios y roles, y eso desde el panel ya no tiene vuelta atrás. Antes de borrarlo, déle a otro rol los permisos "Entrar al panel" y "Gestionar usuarios", y asigne ese rol a alguien.',
    'delete_reason_last_admin' => 'Es el único acceso que queda para administrar el sistema.',
    'bulk_done_title' => 'Roles borrados',
    'bulk_done_body' => 'Se borraron :deleted rol(es) y se movieron :moved usuario(s).',
    'bulk_skipped_title' => 'Algunos roles no se borraron',
    'bulk_skipped_body' => 'Quedaron :skipped sin borrar: eran el único acceso que quedaba para administrar el sistema.',

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
    'perm_access_panel' => 'Entrar al panel',
    'perm_view_alerts' => 'Ver alertas',
    'perm_delete_machines' => 'Borrar máquinas',
    'perm_delete_work_orders' => 'Borrar órdenes de trabajo',
    'perm_receive_alerts_digest' => 'Recibir el aviso de alertas',

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
    'perm_desc_access_panel' => 'Entrar al panel de escritorio. Sin este permiso la cuenta se autentica pero el panel no abre: es lo que separa a quien trabaja en la computadora de quien usa solo la aplicación de campo del celular.',
    'perm_desc_view_alerts' => 'Ver la pantalla de Alertas —máquinas con el servicio por vencer o vencido— y abrir una orden de trabajo desde ahí.',
    'perm_desc_delete_machines' => 'Borrar una máquina. Se lleva en cascada sus órdenes, lecturas, alertas y costos históricos, así que el camino normal de baja es marcarla inactiva.',
    'perm_desc_delete_work_orders' => 'Borrar una orden de trabajo, con todo lo que tenga cargado.',
    'perm_desc_receive_alerts_digest' => 'Recibir por correo el resumen diario de alertas de servicio. No abre ninguna pantalla: solo decide a quién le llega ese correo.',

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

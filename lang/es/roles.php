<?php

return [
    // Navegación / modelo
    // Nota: reutiliza el grupo fleet.group_admin ("Administración"), igual que UserResource.
    'nav' => 'Roles y permisos',
    'model_role' => 'Rol',
    'model_roles' => 'Roles y permisos',

    // Campos del formulario
    'field_name' => 'Nombre',
    'field_permissions' => 'Permisos',
    'name_locked_hint' => 'Los roles del sistema no pueden renombrarse (el código los referencia por nombre).',

    // Tabla
    'permissions_count' => 'Permisos',
    'users_count' => 'Usuarios',

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
];

<?php

return [
    // Navigation / model
    // Note: reuses the fleet.group_admin group ("Administration"), same as UserResource.
    'nav' => 'Roles & permissions',
    'model_role' => 'Role',
    'model_roles' => 'Roles & permissions',

    // Form fields
    'field_name' => 'Name',
    'field_permissions' => 'Permissions',
    'name_locked_hint' => 'System roles cannot be renamed (the code references them by name).',

    // Table
    'permissions_count' => 'Permissions',
    'users_count' => 'Users',

    // Friendly permission labels (Spatie)
    'perm_view_fleet' => 'View fleet',
    'perm_manage_machines' => 'Manage machines',
    'perm_view_costs' => 'View costs',
    'perm_manage_users' => 'Manage users',
    'perm_verify_data' => 'Verify/approve data',
    'perm_manage_quotes' => 'Manage quotes',
    'perm_create_work_order' => 'Create work orders',
    'perm_execute_work_order' => 'Execute work orders',
    'perm_log_horometer' => 'Log hour-meter',
    'perm_log_fuel' => 'Log fuel',
    'perm_field_report' => 'Field report',
    'perm_confirm_location' => 'Confirm location',
    'perm_move_fleet' => 'Move fleet',
    'perm_view_reports' => 'View reports',
    'perm_view_audit_log' => 'View audit log',
];

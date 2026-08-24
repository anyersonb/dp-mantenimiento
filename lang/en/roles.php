<?php

return [
    // Navigation / model
    // Note: reuses the fleet.group_admin group ("Administration"), same as UserResource.
    'nav' => 'Roles & permissions',
    'model_role' => 'Role',
    'model_roles' => 'Roles & permissions',

    // Form fields
    'field_name' => 'Name',
    'field_description' => 'What this role is for',
    'field_description_help' => 'Explain in one sentence what the person holding this role does. It is shown when the role is assigned to a user, so whoever creates the account knows what they are granting.',
    'field_description_locked_hint' => 'System roles ship with their description translated into English and Spanish; that is the one shown below.',
    'field_permissions' => 'Permissions',
    'field_permissions_help' => 'What each permission enables is written under it. Tick only what this person needs for their job.',
    'name_locked_hint' => 'System roles cannot be renamed (the code references them by name).',

    // Table
    'description' => 'What it is for',
    'no_description' => 'No description',
    'permissions_count' => 'Permissions',
    'users_count' => 'Users',

    // Role deletion (client request 2026-08-24)
    'delete_blocked_system_title' => 'This role cannot be deleted',
    'delete_blocked_system_body' => 'It is one of the seven system roles: the code looks it up by name to decide who gets into each screen, so deleting it would leave parts of the system with no owner. If you do not use it, take its permissions away or deactivate the users who hold it.',
    'delete_blocked_users_title' => 'Leave the role with no users first',
    'delete_blocked_users_body' => 'Role :role is still assigned to :count user(s). Move them to another role (or deactivate those accounts) and then delete it, so no account is left with no permissions from one moment to the next.',
    'delete_reason_system' => 'System role: it cannot be deleted.',
    'delete_reason_has_users' => 'It has :count user(s) assigned.',
    'delete_blocked_understood' => 'Got it',
    'bulk_skipped_title' => 'Some roles were not deleted',
    'bulk_skipped_body' => ':deleted role(s) deleted. :skipped were left because they are system roles or still have users assigned.',

    // Friendly permission labels (Spatie)
    'perm_view_fleet' => 'View fleet',
    'perm_manage_machines' => 'Manage machines',
    'perm_view_costs' => 'View costs',
    'perm_manage_users' => 'Manage users',
    'perm_verify_data' => 'Verify/approve data',
    'perm_manage_quotes' => 'Manage quotes',
    'perm_create_work_order' => 'Create work orders',
    'perm_execute_work_order' => 'Execute work orders',
    'perm_log_horometer' => 'Log hour meter',
    'perm_log_fuel' => 'Log fuel',
    'perm_field_report' => 'Field report',
    'perm_confirm_location' => 'Confirm location',
    'perm_move_fleet' => 'Move fleet',
    'perm_view_reports' => 'View reports',
    'perm_view_audit_log' => 'View audit log',

    // What each permission actually enables (client request 2026-08-24). Each
    // text describes what the permission REALLY gates in the code, not what its
    // name suggests.
    'perm_desc_view_fleet' => 'See the machine list, each machine record, the fleet map, job sites, makes and categories. Read only, and the minimum permission to get into the system.',
    'perm_desc_manage_machines' => 'Create and edit machines, and manage job sites, makes and categories. Deleting a machine stays with the administrator, because it takes the whole history with it.',
    'perm_desc_view_costs' => 'See the money: parts cost on each work order, the cost report and attached invoices. Without it a person sees the work done but not what it cost.',
    'perm_desc_manage_users' => 'Create users, assign them roles, and create or edit roles and permissions. It is the permission that opens this very screen.',
    'perm_desc_verify_data' => 'Approve or discard the data of a machine flagged "needs review", and turn that flag on or off. It is the sign-off on data somebody else entered.',
    'perm_desc_manage_quotes' => 'Manage quotes. The quotes module is switched off at DP request, so today this permission opens no screen.',
    'perm_desc_create_work_order' => 'Open work orders, by hand or from a service alert. It does not include executing or closing them.',
    'perm_desc_execute_work_order' => 'Work the order and close it: add parts, fill in the checklist, upload attachments and mark it completed.',
    'perm_desc_log_horometer' => 'Log hour meter readings (the machine hours). Today the reading is taken inside the field report, the foreman board and the fuel log, so this permission goes along with those three and on its own opens no screen.',
    'perm_desc_log_fuel' => 'Log fuel from the phone ("Fuel" screen of the field app), with the machine hours in the same form.',
    'perm_desc_field_report' => 'Send the field report from the phone: what condition the machine is in, findings and, if known, the hours reading.',
    'perm_desc_confirm_location' => 'Confirm from the phone which job site each machine is on (foreman board). It is what keeps the location current.',
    'perm_desc_move_fleet' => 'Move a machine from one job site to another from the panel, one at a time or several at once.',
    'perm_desc_view_reports' => 'Get into the reports centre and download the PDF and the Excel file. Seeing the figures of the cost report also requires "View costs".',
    'perm_desc_view_audit_log' => 'See the audit log: who changed what, and when. Read only, and it cannot be deleted.',

    // What each system role is for (client request 2026-08-24). Roles she
    // creates from the panel carry the description she writes in the form;
    // these seven ship translated into both languages.
    'role_desc_administrador' => 'Full access. Manages users, roles, machines, costs and reports, and is the only one who can delete a machine.',
    'role_desc_responsable_mantenimiento' => 'Plans maintenance: manages machines, opens work orders, sees costs and pulls the reports. Does not manage users and does not close orders in the shop.',
    'role_desc_foreman' => 'Job site foreman. From the phone, confirms which site each machine is on, reports its condition and logs the hours.',
    'role_desc_operador_cisterna' => 'Tanker operator. From the phone, logs fuel with the machine hours.',
    'role_desc_personal_mantenimiento' => 'Maintenance staff in the field. From the phone, reports machine condition and logs the hours.',
    'role_desc_taller' => 'Workshop. Executes and closes work orders — parts, checklist and attachments — and sees the cost of what it enters.',
    'role_desc_gerencia' => 'Management. Looks: fleet, costs and reports, and can move machines between job sites. Does not enter or close work.',
];

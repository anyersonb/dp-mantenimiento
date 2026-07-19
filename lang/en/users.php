<?php

return [
    // Navigation / model
    // Note: the navigation group reuses fleet.group_admin ("Administration"),
    // already used by LocationResource, MakeResource and MachineCategoryResource.
    'nav' => 'Users',
    'model_user' => 'User',
    'model_users' => 'Users',

    // Form fields
    'field_name' => 'Name',
    'field_email' => 'Email',
    'field_password' => 'Password',
    'field_password_confirmation' => 'Confirm password',
    'field_password_hint' => 'Leave blank to keep current',
    'field_phone' => 'Phone',
    'field_locale' => 'Language',
    'locale_es' => 'Spanish',
    'locale_en' => 'English',
    'field_location' => 'Location',
    'field_active' => 'Active',
    'field_roles' => 'Roles',
    'field_created_at' => 'Created',

    // Filters
    'filter_role' => 'Role',
    'filter_active' => 'Active',

    // Friendly role labels (Spatie)
    'role_administrador' => 'Administrator',
    'role_responsable_mantenimiento' => 'Maintenance manager',
    'role_foreman' => 'Foreman',
    'role_operador_cisterna' => 'Tanker operator',
    'role_personal_mantenimiento' => 'Maintenance staff',
    'role_taller' => 'Workshop',
    'role_gerencia' => 'Management',
];

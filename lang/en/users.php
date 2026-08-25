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
    'field_email' => 'Username',
    'field_password' => 'Password',
    'field_password_confirmation' => 'Confirm password',
    'field_password_hint' => 'Leave blank to keep current. The current password cannot be shown: the system stores it encrypted and there is no way to read it back. If someone forgot theirs, use "Generate password" in the list.',
    'field_phone' => 'Phone',
    'field_locale' => 'Language',
    'locale_es' => 'Spanish',
    'locale_en' => 'English',
    'field_location' => 'Location',
    'field_active' => 'Active',
    'field_roles' => 'Roles',
    'field_roles_help' => 'What the holder can do is written under each role. More than one can be ticked.',
    'field_created_at' => 'Created',

    // Filters
    'filter_role' => 'Role',
    'filter_active' => 'Active',

    /*
     * Generate password. See the long comment in UserResource: the password in
     * use cannot be read back (bcrypt), so what is offered is handing out a new
     * one. The wording talks to the administrator in those terms, no jargon.
     */
    'generate_password' => 'Generate password',
    'generate_password_heading' => 'Generate a new password for :name',
    'generate_password_confirm' => 'The password this person is using cannot be shown: the system stores it encrypted and nobody, not even the administrator, can read it back. What you can do is hand them a new one right now. Once you continue, the old password stops working and the new one appears on screen so you can pass it on.',
    'generate_password_submit' => 'Generate and show the password',
    'generate_password_done' => 'New password for :name',
    'generate_password_body' => 'The new password is: :password — write it down or pass it on now, because this notice will not show it again. If it gets lost, generate another one.',

    // Friendly role labels (Spatie)
    'role_administrador' => 'Administrator',
    'role_responsable_mantenimiento' => 'Maintenance manager',
    'role_foreman' => 'Foreman',
    'role_operador_cisterna' => 'Tanker operator',
    'role_personal_mantenimiento' => 'Maintenance staff',
    'role_taller' => 'Workshop',
    'role_gerencia' => 'Management',
];

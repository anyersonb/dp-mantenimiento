<?php

return [
    // Navegación / modelo
    // Nota: el grupo de navegación reutiliza fleet.group_admin ("Administración"),
    // ya usado por LocationResource, MakeResource y MachineCategoryResource.
    'nav' => 'Usuarios',
    'model_user' => 'Usuario',
    'model_users' => 'Usuarios',

    // Campos del formulario
    'field_name' => 'Nombre',
    'field_email' => 'Correo electrónico',
    'field_password' => 'Contraseña',
    'field_password_confirmation' => 'Confirmar contraseña',
    'field_password_hint' => 'Dejar en blanco para no cambiarla',
    'field_phone' => 'Teléfono',
    'field_locale' => 'Idioma',
    'locale_es' => 'Español',
    'locale_en' => 'Inglés',
    'field_location' => 'Ubicación',
    'field_active' => 'Activo',
    'field_roles' => 'Roles',
    'field_created_at' => 'Creado',

    // Filtros
    'filter_role' => 'Rol',
    'filter_active' => 'Activo',

    // Etiquetas amigables de roles (Spatie)
    'role_administrador' => 'Administrador',
    'role_responsable_mantenimiento' => 'Responsable de mantenimiento',
    'role_foreman' => 'Capataz',
    'role_operador_cisterna' => 'Operador de cisterna',
    'role_personal_mantenimiento' => 'Personal de mantenimiento',
    'role_taller' => 'Taller',
    'role_gerencia' => 'Gerencia',
];

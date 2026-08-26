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
    'field_email' => 'Usuario',
    'field_password' => 'Contraseña',
    'field_password_confirmation' => 'Confirmar contraseña',
    'field_password_hint' => 'Dejar en blanco para no cambiarla. La clave actual no se puede ver: el sistema la guarda cifrada y no hay forma de recuperarla. Si alguien la olvidó, usá "Generar clave" en el listado.',
    'field_phone' => 'Teléfono',
    'field_locale' => 'Idioma',
    'locale_es' => 'Español',
    'locale_en' => 'Inglés',
    'field_location' => 'Ubicación',
    'field_active' => 'Activo',
    'deactivate_blocked_last_admin' => 'No se puede desactivar esta cuenta: es la ultima que puede entrar al panel y gestionar usuarios. Deje primero a otra persona con esos permisos.',
    'roles_blocked_last_admin' => 'Con esos roles esta cuenta deja de poder administrar, y es la ultima que podia. Deje primero a otra persona con permiso para entrar al panel y gestionar usuarios.',
    'field_roles' => 'Roles',
    'field_roles_help' => 'Debajo de cada rol está lo que puede hacer quien lo tenga. Se puede marcar más de uno.',
    'field_created_at' => 'Creado',

    // Filtros
    'filter_role' => 'Rol',
    'filter_active' => 'Activo',

    /*
     * Generar clave. Ver el comentario largo en UserResource: la clave puesta
     * no se puede leer (bcrypt), así que lo que se ofrece es darle una nueva.
     * El texto le habla al administrador en esos términos, sin tecnicismos.
     */
    'generate_password' => 'Generar clave',
    'generate_password_heading' => 'Generar una clave nueva para :name',
    'generate_password_confirm' => 'La clave que esta persona tiene puesta no se puede leer: el sistema la guarda cifrada y nadie, ni el administrador, puede recuperarla. Lo que sí se puede es darle una nueva ahora mismo. Al continuar, la clave anterior deja de funcionar y la nueva aparece en pantalla para dictársela.',
    'generate_password_submit' => 'Generar y mostrar la clave',
    'generate_password_done' => 'Clave nueva de :name',
    'generate_password_body' => 'La clave nueva es: :password — anotala o pasásela ahora, porque este aviso no vuelve a mostrarla. Si se pierde, generá otra.',

    // Etiquetas amigables de roles (Spatie)
    'role_administrador' => 'Administrador',
    'role_responsable_mantenimiento' => 'Responsable de mantenimiento',
    'role_foreman' => 'Capataz',
    'role_operador_cisterna' => 'Operador de cisterna',
    'role_personal_mantenimiento' => 'Personal de mantenimiento',
    'role_taller' => 'Taller',
    'role_gerencia' => 'Gerencia',
];

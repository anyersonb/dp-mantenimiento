<?php

return [

    /*
     * Cuentas de sistema (no personas).
     *
     * El nombre de la cuenta genérica de administrador sembrada por
     * RolesAndPermissionsSeeder no es el de una persona real, así que en vez
     * de imprimirse crudo desde `users.name` se traduce con el idioma activo
     * del panel (ver App\Models\User::getFilamentName()).
     *
     * Se ancla al EMAIL, no al nombre: comparar contra el string
     * "Administrador DP" hardcodeado rompería el día que alguien le cambie el
     * nombre a la cuenta, o confundiría a una persona real que se llamara
     * igual. El email sale de acá (config, cacheable) y no del modelo.
     */
    'system_admin_email' => env('SYSTEM_ADMIN_EMAIL', 'admin@dp.local'),

];

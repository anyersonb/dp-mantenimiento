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

    /*
     * Clave con la que RolesAndPermissionsSeeder crea las 7 cuentas demo
     * (una por rol). Hallazgo 4 (auditoría 2026-09-18): antes era el literal
     * 'password' cableado en el seeder, que corrió en producción con el
     * repositorio en público.
     *
     * Sin valor en .env, el default es 'password' SOLO bajo APP_ENV=testing,
     * para que la suite (que loguea con admin@dp.local/password) no cambie de
     * comportamiento. En cualquier otro entorno (local, staging, producción)
     * hay que declarar DEMO_SEED_PASSWORD a propósito — y en producción el
     * seeder igual se niega a sembrar usuarios sin importar esta clave (ver
     * RolesAndPermissionsSeeder::run()).
     */
    // env('APP_ENV') directo y NO app()->environment(): este archivo se carga
    // en LoadConfiguration, antes de que el binding 'env' del contenedor
    // exista — app()->environment() ahí revienta con
    // "Target class [env] does not exist" (BindingResolutionException).
    'demo_seed_password' => env('DEMO_SEED_PASSWORD', env('APP_ENV') === 'testing' ? 'password' : null),

];

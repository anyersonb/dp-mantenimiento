<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([
            RolesAndPermissionsSeeder::class,
            FleetSeeder::class,
            MachineSpecSeeder::class,
            ChecklistSeeder::class,
            // Va al final porque necesita las obras ya creadas por FleetSeeder.
            // Estaba sin registrar: en el primer despliegue real (hosting del
            // cliente, 2026-07-27) la pagina Fleet map salio vacia con "No
            // jobsites with coordinates yet" porque en una instalacion limpia
            // nadie lo corria. Es idempotente, ver la cabecera del seeder.
            LocationCoordinatesSeeder::class,
        ]);
    }
}

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
        ]);
    }
}

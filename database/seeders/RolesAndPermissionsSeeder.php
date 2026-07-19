<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

class RolesAndPermissionsSeeder extends Seeder
{
    public function run(): void
    {
        app()['cache']->forget('spatie.permission.cache');

        // -------- Permisos --------
        $permissions = [
            'view_fleet',            // ver estado de flota
            'manage_machines',       // alta/edición de máquinas
            'view_costs',            // ver montos (repuestos/reparación)
            'manage_users',          // crear usuarios y asignar roles
            'verify_data',           // aprobar cargas manuales
            'manage_quotes',         // adjuntar/compartir cotizaciones
            'create_work_order',     // abrir y asignar OT
            'execute_work_order',    // ejecutar OT + checklist (taller)
            'log_horometer',         // registrar lectura de horómetro
            'log_fuel',              // registrar abastecimiento (galones)
            'field_report',          // reportar estado/novedades de campo
            'confirm_location',      // confirmar ubicación de máquinas
            'move_fleet',            // decidir movimiento de flota entre obras
            'view_reports',          // consultar/exportar reportes
            'view_audit_log',        // ver bitácora de cambios
        ];
        foreach ($permissions as $p) {
            Permission::firstOrCreate(['name' => $p]);
        }

        // -------- Roles y su matriz de permisos --------
        $matrix = [
            'administrador' => $permissions, // acceso total
            'responsable_mantenimiento' => [
                'view_fleet', 'manage_machines', 'view_costs', 'create_work_order',
                'view_reports', 'view_audit_log',
            ],
            'foreman' => [
                'view_fleet', 'log_horometer', 'field_report', 'confirm_location',
            ],
            'operador_cisterna' => [
                'view_fleet', 'log_horometer', 'log_fuel',
            ],
            'personal_mantenimiento' => [
                'view_fleet', 'log_horometer', 'field_report',
            ],
            'taller' => [
                'view_fleet', 'view_costs', 'execute_work_order', 'log_horometer',
            ],
            'gerencia' => [
                'view_fleet', 'view_costs', 'move_fleet', 'view_reports',
            ],
        ];

        foreach ($matrix as $roleName => $perms) {
            $role = Role::firstOrCreate(['name' => $roleName]);
            $role->syncPermissions($perms);
        }

        // -------- Usuarios demo (uno por rol) --------
        // NOTA: credenciales de demostración — cambiar antes de producción.
        $users = [
            ['name' => 'Administrador DP',        'email' => 'admin@dp.local',        'role' => 'administrador',            'locale' => 'en'],
            ['name' => 'Responsable Mtto',        'email' => 'responsable@dp.local',  'role' => 'responsable_mantenimiento', 'locale' => 'en'],
            ['name' => 'Foreman',                 'email' => 'foreman@dp.local',      'role' => 'foreman',                  'locale' => 'es'],
            ['name' => 'Operador Cisterna',       'email' => 'combustible@dp.local',  'role' => 'operador_cisterna',        'locale' => 'es'],
            ['name' => 'Personal Mtto',           'email' => 'campo@dp.local',        'role' => 'personal_mantenimiento',   'locale' => 'es'],
            ['name' => 'Taller / Técnico',        'email' => 'taller@dp.local',       'role' => 'taller',                   'locale' => 'en'],
            ['name' => 'Gerencia',                'email' => 'gerencia@dp.local',     'role' => 'gerencia',                 'locale' => 'en'],
        ];

        foreach ($users as $u) {
            $user = User::firstOrCreate(
                ['email' => $u['email']],
                [
                    'name' => $u['name'],
                    'password' => Hash::make('password'),
                    'locale' => $u['locale'],
                    'active' => true,
                ]
            );
            $user->syncRoles([$u['role']]);
        }
    }
}

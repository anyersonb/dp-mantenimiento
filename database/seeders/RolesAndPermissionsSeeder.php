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
            'access_panel',          // entrar al panel de escritorio
            'view_alerts',           // ver la pantalla de Alertas y recibir sus avisos
            'delete_machines',       // borrar una maquina (se lleva su historial)
            'delete_work_orders',    // borrar una orden de trabajo
            'receive_alerts_digest', // recibir por correo el resumen diario de alertas
            'manage_settings',       // editar la Configuración del panel (tasa de impuesto, etc.)

            // Papelera (Lote A) — ver papelera / restaurar / eliminar definitivamente,
            // granulares por recurso. Solo administrador (ver migración
            // 2026_09_08_120100_add_papelera_permissions para el detalle de la decisión).
            'view_trash_machines', 'restore_machines', 'force_delete_machines',
            'view_trash_work_orders', 'restore_work_orders', 'force_delete_work_orders',
            'view_trash_quotes', 'restore_quotes', 'force_delete_quotes',
            'view_trash_locations', 'restore_locations', 'force_delete_locations',
            'view_trash_machine_categories', 'restore_machine_categories', 'force_delete_machine_categories',
            'view_trash_makes', 'restore_makes', 'force_delete_makes',
            'view_trash_users', 'restore_users', 'force_delete_users',
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
                'access_panel', 'view_alerts',
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
                'access_panel',
            ],
            'gerencia' => [
                'view_fleet', 'view_costs', 'move_fleet', 'view_reports',
                'access_panel',
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

<?php

namespace Tests\Feature\Management;

use App\Models\Location;
use App\Models\Machine;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * La bitácora (ActivityResource) solo debe ser visible para quien tenga el
 * permiso "view_audit_log": administrador y responsable_mantenimiento SÍ,
 * gerencia NO (matriz de permisos confirmada por el cliente).
 */
class AuditLogAccessTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);
    }

    public function test_administrator_can_view_the_audit_log(): void
    {
        $admin = User::where('email', 'admin@dp.local')->firstOrFail();

        $this->actingAs($admin)->get('/admin/activities')->assertOk();
    }

    public function test_responsable_de_mantenimiento_can_view_the_audit_log(): void
    {
        $responsable = User::where('email', 'responsable@dp.local')->firstOrFail();

        $this->actingAs($responsable)->get('/admin/activities')->assertOk();
    }

    public function test_gerencia_cannot_view_the_audit_log(): void
    {
        $gerencia = User::where('email', 'gerencia@dp.local')->firstOrFail();

        $this->actingAs($gerencia)->get('/admin/activities')->assertForbidden();
    }

    public function test_taller_cannot_view_the_audit_log(): void
    {
        $taller = User::where('email', 'taller@dp.local')->firstOrFail();

        $this->actingAs($taller)->get('/admin/activities')->assertForbidden();
    }

    public function test_machine_changes_are_recorded_in_the_activity_log_and_show_up_in_the_resource(): void
    {
        $admin = User::where('email', 'admin@dp.local')->firstOrFail();
        $location = Location::create(['name' => 'Yard A', 'slug' => 'yard-a-'.uniqid()]);
        $machine = Machine::create([
            'id_code' => 'AUD-'.random_int(1000, 9999),
            'status' => 'active',
            'current_location_id' => $location->id,
        ]);

        $machine->update(['status' => 'down']);

        $this->assertDatabaseHas('activity_log', [
            'subject_type' => Machine::class,
            'subject_id' => $machine->id,
        ]);

        $this->actingAs($admin)->get('/admin/activities')->assertOk();
    }
}

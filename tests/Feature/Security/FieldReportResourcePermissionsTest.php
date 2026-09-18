<?php

namespace Tests\Feature\Security;

use App\Filament\Resources\FieldReportResource;
use App\Models\FieldReport;
use App\Models\Location;
use App\Models\Machine;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Gate de `view_field_reports` — la pantalla nueva "Reportes de campo".
 *
 * El reparto es EXACTAMENTE el de `access_panel` (administrador,
 * responsable_mantenimiento, taller, gerencia): ver
 * App\Support\AccessControl::LEGACY_ROLE_FALLBACK.
 *
 * Como los cuatro roles con `access_panel` reciben también este permiso, no
 * hay ningún rol "de fábrica" que entre al panel pero se quede afuera de esta
 * pantalla — para probar ese lado (403 real del RECURSO, no del panel) hace
 * falta un rol sintético con `access_panel` y sin `view_field_reports`,
 * mismo patrón que `FleetAttachmentPermissionsTest::userWithoutAttachmentsAccess()`.
 */
class FieldReportResourcePermissionsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);
    }

    private function fieldReport(): FieldReport
    {
        $location = Location::create(['name' => 'Yard FR', 'slug' => 'yard-fr-'.uniqid()]);
        $machine = Machine::create([
            'id_code' => 'FR-'.random_int(1000, 9999),
            'status' => 'active',
            'current_location_id' => $location->id,
        ]);
        $worker = User::where('email', 'campo@dp.local')->firstOrFail();

        return FieldReport::create([
            'machine_id' => $machine->id,
            'reported_by' => $worker->id,
            'location_id' => $location->id,
            'condition' => 'critical',
        ]);
    }

    /**
     * Rol de control: entra al panel (`access_panel`) pero NO tiene
     * `view_field_reports` — así el 403 solo puede venir del gate real del
     * recurso, no del gate del panel (mismo hallazgo que
     * FleetAttachmentPermissionsTest::test_create_and_edit_urls...).
     */
    private function userWithAccessPanelButNoFieldReports(): User
    {
        $role = Role::firstOrCreate(['name' => 'sin_field_reports']);
        $role->syncPermissions(['access_panel']);

        $user = User::factory()->create(['active' => true]);
        $user->assignRole('sin_field_reports');

        return $user;
    }

    public function test_the_four_roles_with_access_panel_can_view_field_reports(): void
    {
        foreach (['admin@dp.local', 'responsable@dp.local', 'taller@dp.local', 'gerencia@dp.local'] as $email) {
            $user = User::where('email', $email)->firstOrFail();
            $this->actingAs($user);

            $this->assertTrue(
                FieldReportResource::canViewAny(),
                "{$email} debería poder ver Reportes de campo (tiene access_panel)."
            );
        }
    }

    public function test_field_crew_roles_without_the_permission_cannot_view_field_reports(): void
    {
        foreach (['foreman@dp.local', 'combustible@dp.local', 'campo@dp.local'] as $email) {
            $user = User::where('email', $email)->firstOrFail();
            $this->actingAs($user);

            $this->assertFalse(
                FieldReportResource::canViewAny(),
                "{$email} NO debería poder ver Reportes de campo."
            );
        }
    }

    public function test_a_role_without_the_permission_gets_a_403_on_direct_url_access(): void
    {
        $user = $this->userWithAccessPanelButNoFieldReports();
        $this->assertFalse($user->can('view_field_reports'));

        $this->actingAs($user)->get('/admin/field-reports')->assertForbidden();
    }

    public function test_a_role_with_the_permission_gets_a_200_on_direct_url_access(): void
    {
        $this->fieldReport();
        $admin = User::where('email', 'admin@dp.local')->firstOrFail();

        $this->actingAs($admin)->get('/admin/field-reports')->assertOk();
    }

    /**
     * La pantalla es de solo lectura: no se crea, edita ni borra desde acá.
     */
    public function test_the_resource_is_read_only_even_for_the_administrator(): void
    {
        $report = $this->fieldReport();
        $admin = User::where('email', 'admin@dp.local')->firstOrFail();
        $this->actingAs($admin);

        $this->assertFalse(FieldReportResource::canCreate());
        $this->assertFalse(FieldReportResource::canEdit($report));
        $this->assertFalse(FieldReportResource::canDelete($report));
        $this->assertFalse(FieldReportResource::canDeleteAny());
    }

    public function test_the_navigation_gate_is_closed_without_the_permission_and_open_with_it(): void
    {
        $this->actingAs($this->userWithAccessPanelButNoFieldReports());
        $this->assertFalse(FieldReportResource::canAccess());

        $this->actingAs(User::where('email', 'admin@dp.local')->firstOrFail());
        $this->assertTrue(FieldReportResource::canAccess());
    }
}

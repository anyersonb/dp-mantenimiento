<?php

namespace Tests\Feature\Management;

use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Exportación del reporte de estado de flota, visible solo con permiso
 * "view_reports" (gerencia, responsable_mantenimiento, administrador).
 */
class FleetReportsExportTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);
    }

    public function test_gerencia_can_download_the_pdf_fleet_report(): void
    {
        $gerencia = User::where('email', 'gerencia@dp.local')->firstOrFail();

        $response = $this->actingAs($gerencia)->get('/reports/fleet.pdf');

        $response->assertOk();
        $response->assertHeader('content-type', 'application/pdf');
    }

    public function test_gerencia_can_download_the_excel_fleet_report(): void
    {
        $gerencia = User::where('email', 'gerencia@dp.local')->firstOrFail();

        $response = $this->actingAs($gerencia)->get('/reports/fleet.xlsx');

        $response->assertOk();
    }

    public function test_a_user_without_view_reports_permission_is_forbidden(): void
    {
        // foreman: solo campo, sin view_reports.
        $foreman = User::where('email', 'foreman@dp.local')->firstOrFail();

        $this->actingAs($foreman)->get('/reports/fleet.pdf')->assertForbidden();
        $this->actingAs($foreman)->get('/reports/fleet.xlsx')->assertForbidden();
    }

    public function test_guests_are_redirected_to_login(): void
    {
        $this->get('/reports/fleet.pdf')->assertRedirect('/field/login');
    }
}

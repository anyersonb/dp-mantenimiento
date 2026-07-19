<?php

namespace Tests\Feature\Management;

use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Boot smoke test for every new Stage 04 screen, across the three demo
 * users named in the brief.
 *
 * NOTE: each user is exercised in its own test method (not a loop making
 * several real HTTP requests with different actingAs() calls in a row).
 * Filament's AuthenticateSession middleware compares the password hash
 * stored in the shared test session against the "current" user on every
 * request; switching actingAs() mid-test without a fresh session trips
 * it and forces a spurious logout redirect that has nothing to do with
 * the app's actual authorization.
 */
class SmokeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);
    }

    public function test_dashboard_and_machines_boot_for_administrador(): void
    {
        $user = User::where('email', 'admin@dp.local')->firstOrFail();

        $this->actingAs($user)->get('/admin')->assertOk();
        $this->actingAs($user)->get('/admin/machines')->assertOk();
    }

    public function test_dashboard_and_machines_boot_for_gerencia(): void
    {
        $user = User::where('email', 'gerencia@dp.local')->firstOrFail();

        $this->actingAs($user)->get('/admin')->assertOk();
        $this->actingAs($user)->get('/admin/machines')->assertOk();
    }

    public function test_dashboard_and_machines_boot_for_responsable_de_mantenimiento(): void
    {
        $user = User::where('email', 'responsable@dp.local')->firstOrFail();

        $this->actingAs($user)->get('/admin')->assertOk();
        $this->actingAs($user)->get('/admin/machines')->assertOk();
    }

    public function test_quotes_resource_boots_for_admin(): void
    {
        $admin = User::where('email', 'admin@dp.local')->firstOrFail();

        $this->actingAs($admin)->get('/admin/quotes')->assertOk();
    }

    public function test_quotes_resource_is_forbidden_for_gerencia(): void
    {
        $gerencia = User::where('email', 'gerencia@dp.local')->firstOrFail();

        $this->actingAs($gerencia)->get('/admin/quotes')->assertForbidden();
    }

    public function test_fleet_map_boots_for_a_user_with_view_fleet(): void
    {
        $admin = User::where('email', 'admin@dp.local')->firstOrFail();

        $this->actingAs($admin)->get('/admin/fleet-map')->assertOk();
    }
}

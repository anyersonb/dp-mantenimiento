<?php

namespace Tests\Feature\Management;

use App\Filament\Pages\FleetMap;
use App\Models\Location;
use App\Models\Machine;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Regression test for the Fleet Map page.
 *
 * The original view embedded a raw <script> block with JS template
 * literals containing literal HTML-looking substrings (`<div ...>`,
 * `<strong>`, `<br>`) plus a multi-line @json(...) directive call.
 * Livewire/Blade's naive root-element / bracket-balance scanning choked
 * on that content and threw:
 *   - a Blade compile error ("Unclosed '[' ... does not match ')'"), and
 *   - Livewire\Features\SupportMultipleRootElementDetection\
 *     MultipleRootElementsDetectedException ("Multiple root elements
 *     detected for component: [app.filament.pages.fleet-map]").
 *
 * Fix: the Leaflet <link>/<script src> tags now go through
 *
 * @push('styles') / @push('scripts') (rendered by the panel layout, not
 * by this component's own view), and the map initialization JS moved
 * from an inline <script> block into an Alpine `x-init` attribute with
 * HTML-entity-encoded markup fragments (so no `<tag>`-looking substrings
 * appear literally in the component's compiled HTML output). The page
 * now has a single root element: <x-filament-panels::page>.
 *
 * Both a full HTTP visit and a direct Livewire component test are
 * covered here, since Livewire::test() is the most direct way to
 * reproduce/guard against MultipleRootElementsDetectedException.
 */
class FleetMapTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);
    }

    public function test_the_fleet_map_page_renders_via_http_for_a_user_with_view_fleet(): void
    {
        $admin = User::where('email', 'admin@dp.local')->firstOrFail();

        $this->actingAs($admin)->get('/admin/fleet-map')->assertOk();
    }

    public function test_the_fleet_map_livewire_component_renders_with_no_located_jobsites(): void
    {
        $admin = User::where('email', 'admin@dp.local')->firstOrFail();

        Livewire::actingAs($admin)
            ->test(FleetMap::class)
            ->assertOk()
            ->assertSee(__('mgmt.no_coords'));
    }

    public function test_the_fleet_map_livewire_component_renders_with_geolocated_machines(): void
    {
        $admin = User::where('email', 'admin@dp.local')->firstOrFail();

        $location = Location::create([
            'name' => 'Davie Yd. Test',
            'slug' => 'davie-yd-test-'.uniqid(),
            'latitude' => 26.0765,
            'longitude' => -80.2521,
        ]);
        Machine::create([
            'id_code' => 'MAP-'.random_int(1000, 9999),
            'status' => 'active',
            'current_location_id' => $location->id,
        ]);

        Livewire::actingAs($admin)
            ->test(FleetMap::class)
            ->assertOk()
            ->assertSee('Davie Yd. Test');
    }

    public function test_a_user_without_view_fleet_permission_cannot_access_the_fleet_map(): void
    {
        // Usuario sin ningún rol/permiso asignado.
        $userWithoutFleetAccess = User::factory()->create(['active' => true]);

        $this->actingAs($userWithoutFleetAccess)->get('/admin/fleet-map')->assertForbidden();
    }
}

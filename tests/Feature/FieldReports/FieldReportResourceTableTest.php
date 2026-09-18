<?php

namespace Tests\Feature\FieldReports;

use App\Filament\Resources\FieldReportResource;
use App\Filament\Resources\FieldReportResource\Pages\ListFieldReports;
use App\Models\FieldReport;
use App\Models\Location;
use App\Models\Machine;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Pantalla "Reportes de campo": listado, badge de estado, filtro por
 * condición, y el defecto puntual del brief — que un reporte sin coordenadas
 * se vea marcado EXPLÍCITAMENTE como "sin ubicación", no como una celda
 * vacía indistinguible de "todavía no cargó".
 */
class FieldReportResourceTableTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);
    }

    private function machine(): Machine
    {
        $location = Location::create(['name' => 'Yard FR', 'slug' => 'yard-fr-'.uniqid()]);

        return Machine::create([
            'id_code' => 'FRT-'.random_int(1000, 9999),
            'status' => 'active',
            'current_location_id' => $location->id,
        ]);
    }

    private function report(Machine $machine, string $condition, bool $withLocation): FieldReport
    {
        $worker = User::where('email', 'campo@dp.local')->firstOrFail();

        return FieldReport::create([
            'machine_id' => $machine->id,
            'reported_by' => $worker->id,
            'location_id' => $machine->current_location_id,
            'condition' => $condition,
            'latitude' => $withLocation ? 26.1 : null,
            'longitude' => $withLocation ? -80.1 : null,
        ]);
    }

    private function list(): Testable
    {
        $admin = User::where('email', 'admin@dp.local')->firstOrFail();

        return Livewire::actingAs($admin)->test(ListFieldReports::class);
    }

    public function test_the_administrator_can_see_field_reports_in_the_list(): void
    {
        $machine = $this->machine();
        $critical = $this->report($machine, 'critical', true);
        $ok = $this->report($machine, 'ok', true);

        $this->list()->assertCanSeeTableRecords([$critical, $ok]);
    }

    public function test_the_condition_filter_isolates_critical_reports_in_one_click(): void
    {
        $machine = $this->machine();
        $critical = $this->report($machine, 'critical', true);
        $ok = $this->report($machine, 'ok', true);
        $attention = $this->report($machine, 'attention', true);

        $this->list()
            ->filterTable('condition', 'critical')
            ->assertCanSeeTableRecords([$critical])
            ->assertCanNotSeeTableRecords([$ok, $attention]);
    }

    public function test_the_machine_filter_works(): void
    {
        $machineA = $this->machine();
        $machineB = $this->machine();
        $reportA = $this->report($machineA, 'ok', true);
        $reportB = $this->report($machineB, 'ok', true);

        $this->list()
            ->filterTable('machine', $machineA->id)
            ->assertCanSeeTableRecords([$reportA])
            ->assertCanNotSeeTableRecords([$reportB]);
    }

    /**
     * El defecto puntual del brief: sin ubicación tiene que verse un texto
     * explícito ("Sin ubicación"), no una celda en blanco.
     */
    public function test_a_report_without_coordinates_is_explicitly_marked_as_having_no_location(): void
    {
        $machine = $this->machine();
        $withoutLocation = $this->report($machine, 'attention', false);
        $withLocation = $this->report($machine, 'attention', true);

        $this->list()
            ->assertSee(__('field_reports.location_no'))
            ->assertSee(__('field_reports.location_yes'));

        $this->assertNull($withoutLocation->latitude);
        $this->assertNotNull($withLocation->latitude);
    }

    public function test_the_detail_view_shows_the_full_notes_and_an_explicit_no_location_notice(): void
    {
        $machine = $this->machine();
        $report = $this->report($machine, 'critical', false);
        $report->update(['notes' => 'Hydraulic hose leaking badly, needs immediate service']);

        // mountTableAction() (NO callTableAction()): el `action()` de
        // ViewAction es un no-op que se auto-ejecuta y CIERRA el modal en el
        // mismo request, así que callTableAction() monta y desmonta antes de
        // poder leer el contenido — mount deja el modal abierto para poder
        // aserear sobre él.
        $this->list()
            ->mountTableAction('view', $report)
            ->assertSee('Hydraulic hose leaking badly, needs immediate service')
            ->assertSee(__('field_reports.location_no'))
            ->assertDontSee(__('field_reports.detail_map_link'));
    }

    public function test_the_detail_view_offers_a_map_link_when_coordinates_exist(): void
    {
        $machine = $this->machine();
        $report = $this->report($machine, 'critical', true);

        $this->list()
            ->mountTableAction('view', $report)
            ->assertSee(__('field_reports.detail_map_link'))
            ->assertDontSee(__('field_reports.location_no'));
    }

    public function test_the_list_defaults_to_newest_first(): void
    {
        $machine = $this->machine();
        $older = $this->report($machine, 'ok', true);
        $older->update(['created_at' => now()->subDays(2)]);
        $newer = $this->report($machine, 'ok', true);
        $newer->update(['created_at' => now()]);

        $this->list()->assertCanSeeTableRecords([$newer, $older], inOrder: true);
    }

    public function test_there_is_no_create_action_in_the_header(): void
    {
        $this->list()->assertActionDoesNotExist('create');
    }

    /**
     * El badge de navegación cuenta críticos SIN ATENDER — definido como
     * "notificación del evento field_report.needs_attention, todavía no
     * leída, para quien mira" — y baja apenas se marca como leída. No hay un
     * campo de estado en `field_reports` para esto; se apoya en el módulo de
     * notificaciones a propósito (ver FieldReportResource::getNavigationBadge()).
     */
    public function test_the_navigation_badge_counts_unread_critical_notifications_and_drops_after_reading(): void
    {
        $admin = User::where('email', 'admin@dp.local')->firstOrFail();
        $this->actingAs($admin);

        $this->assertNull(FieldReportResource::getNavigationBadge());

        $machine = $this->machine();
        $this->report($machine, 'critical', true);
        $this->report($machine, 'critical', true);
        // Un 'attention' no cuenta para este badge (solo crítico), aunque
        // también genere notificación si la Configuración lo tuviera encendido.
        $this->report($machine, 'ok', true);

        $this->assertSame('2', FieldReportResource::getNavigationBadge());

        $admin->unreadNotifications()->first()->markAsRead();

        $this->assertSame('1', FieldReportResource::getNavigationBadge());

        $admin->unreadNotifications->markAsRead();

        $this->assertNull(FieldReportResource::getNavigationBadge());
    }
}

<?php

namespace Tests\Feature\Management;

use App\Filament\Pages\Reports;
use App\Models\Location;
use App\Models\Machine;
use App\Models\MachineCategory;
use App\Models\User;
use App\Models\WorkOrder;
use App\Models\WorkOrderPart;
use App\Services\Reports\CostReportBuilder;
use App\Services\Reports\CostReportFilters;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Reporte de costos "viewable online without exporting" (pedido del cliente
 * 2026-08-06): el detalle por orden de trabajo — antes solo en el PDF/Excel —
 * ahora también se ve en pantalla, colapsado por máquina.
 *
 * Lo que estos tests protegen:
 *
 *   1. El detalle REALMENTE se renderiza en pantalla (código de OT, repuestos,
 *      obra con su n.º de trabajo), no solo el resumen que ya existía.
 *   2. Los números que aparecen en pantalla son los del MISMO CostReportBuilder
 *      que usan el PDF y el Excel — no una segunda fuente que pueda discrepar.
 *   3. `view_costs` sigue siendo la puerta: sin el permiso, no hay detalle de
 *      costos en pantalla (cae al inventario por categoría).
 */
class ReportsScreenDetailTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);
    }

    private function location(string $name, ?string $jobNumber): Location
    {
        return Location::create([
            'name' => $name,
            'slug' => str($name)->slug().'-'.uniqid(),
            'job_number' => $jobNumber,
        ]);
    }

    private function machine(string $idCode, Location $location): Machine
    {
        return Machine::create([
            'id_code' => $idCode,
            'description' => $idCode.' TEST MACHINE',
            'machine_category_id' => MachineCategory::create([
                'name' => 'Excavator', 'slug' => 'excavator-'.uniqid(), 'default_service_interval' => 500,
            ])->id,
            'status' => 'active',
            'hourmeter_status' => 'ok',
            'current_location_id' => $location->id,
            'current_hours' => 1000,
            'service_interval_hours' => 500,
            'hours_adjustment' => 0,
        ]);
    }

    private function completedWorkOrder(Machine $machine, string $code): WorkOrder
    {
        return WorkOrder::create([
            'code' => $code,
            'machine_id' => $machine->id,
            'location_id' => $machine->current_location_id,
            'type' => 'corrective',
            'status' => 'completed',
            'opened_at' => '2026-08-10',
            'completed_at' => '2026-08-10',
            'labor_hours' => 2,
        ]);
    }

    public function test_the_screen_detail_shows_work_orders_parts_and_the_job_number_collapsed_by_machine(): void
    {
        $admin = User::where('email', 'admin@dp.local')->firstOrFail();
        $site = $this->location('Blount Rd', 'JOB-555');
        $machine = $this->machine('EX099', $site);
        $wo = $this->completedWorkOrder($machine, 'WO-SCREEN-001');

        WorkOrderPart::create([
            'work_order_id' => $wo->id, 'part_number' => 'FIL-9001',
            'description' => 'Filtro de aceite', 'quantity' => 2, 'unit_cost' => 31.25,
        ]);

        Livewire::actingAs($admin)
            ->test(Reports::class)
            ->set('data.quick_period', 'custom')
            ->set('data.from', '2026-08-01')
            ->set('data.to', '2026-08-31')
            // Antes de este cambio, el código de OT y el repuesto solo
            // existían en el PDF/Excel: si esto no aparece en pantalla, el
            // detalle no se está renderizando.
            ->assertSee('WO-SCREEN-001')
            ->assertSee('FIL-9001')
            // Pedido 1 ("everything is job numbers"): la obra en el detalle
            // en pantalla también lleva su número, número primero.
            ->assertSee('JOB-555 — Blount Rd')
            ->assertSee(__('reports.print'));
    }

    public function test_the_screen_totals_match_the_costreportbuilder_totals_exactly(): void
    {
        $admin = User::where('email', 'admin@dp.local')->firstOrFail();

        $siteA = $this->location('Blount Rd', 'JOB-100');
        $siteB = $this->location('Davie Yd', 'JOB-200');
        $machineA = $this->machine('EX100', $siteA);
        $machineB = $this->machine('EX101', $siteB);

        $woA = $this->completedWorkOrder($machineA, 'WO-SCREEN-A');
        WorkOrderPart::create(['work_order_id' => $woA->id, 'quantity' => 1, 'unit_cost' => 20.00]);

        $woB = $this->completedWorkOrder($machineB, 'WO-SCREEN-B');
        WorkOrderPart::create(['work_order_id' => $woB->id, 'quantity' => 1, 'unit_cost' => 35.00]);

        // La verdad de referencia: el MISMO builder que consume el PDF, el
        // Excel y (ahora) la pantalla.
        $filters = CostReportFilters::fromArray(['from' => '2026-08-01', 'to' => '2026-08-31']);
        $expected = CostReportBuilder::build($filters);

        $this->assertSame(55.0, $expected['totals']['parts_total']);

        $grandTotal = number_format($expected['totals']['parts_total'], 2);
        $totalA = number_format($expected['machines'][0]['parts_total'], 2);
        $totalB = number_format($expected['machines'][1]['parts_total'], 2);

        Livewire::actingAs($admin)
            ->test(Reports::class)
            ->set('data.quick_period', 'custom')
            ->set('data.from', '2026-08-01')
            ->set('data.to', '2026-08-31')
            // El total general de la pantalla es el del builder...
            ->assertSee('$'.$grandTotal)
            // ...y también lo son los totales por máquina que arma el
            // detalle colapsado. Si la pantalla alguna vez calculara los
            // suyos por su cuenta, uno de estos dos números no va a
            // coincidir con lo que dice CostReportBuilder.
            ->assertSee('$'.$totalA)
            ->assertSee('$'.$totalB);
    }

    /**
     * QA encontró que `@media print { .dp-detail-body { display: block !important; } }`
     * NO abre un `<details>` cerrado (el contenido vive en el pseudo-elemento
     * interno `::details-content`, que ningún `display` en un hijo puede
     * pisar). El fix real abre/cierra el `<details>` de verdad con JS en
     * `beforeprint`/`afterprint`.
     *
     * Un test PHPUnit no ejecuta ese JS (no hay motor de navegador acá), pero
     * sí puede probar lo que SÍ puede fallar en silencio sin que ningún test
     * lo note: que el script viaje con la página completa (y no se pierda
     * por ir en `@push('scripts')`, que un layout distinto podría no resolver)
     * y que el selector que usa (`.dp-detail-list details.dp-detail`)
     * encuentre de verdad nodos `<details>` en el HTML real — si alguien
     * renombra la clase de un lado y no del otro, este test cae.
     */
    public function test_the_print_script_ships_with_the_page_and_its_selector_matches_real_details_nodes(): void
    {
        $admin = User::where('email', 'admin@dp.local')->firstOrFail();
        $site = $this->location('Blount Rd', 'JOB-900');
        $machine = $this->machine('EX900', $site);
        $wo = $this->completedWorkOrder($machine, 'WO-PRINT-CHECK');
        WorkOrderPart::create(['work_order_id' => $wo->id, 'quantity' => 1, 'unit_cost' => 15.00]);

        // GET real y completo (no Livewire::test(), que solo renderiza el
        // componente): así el HTML incluye el layout del panel con
        // @stack('scripts') resuelto, que es lo que realmente le llega al
        // navegador.
        $html = $this->actingAs($admin)->get(Reports::getUrl())->getContent();

        $this->assertStringContainsString("addEventListener('beforeprint'", $html);
        $this->assertStringContainsString("addEventListener('afterprint'", $html);
        $this->assertStringContainsString('.dp-detail-list details.dp-detail', $html);

        // Y el selector no apunta al aire: hay de verdad un <details
        // class="dp-detail"> dentro de un contenedor .dp-detail-list en ESTE
        // mismo documento.
        libxml_use_internal_errors(true);
        $dom = new \DOMDocument;
        $dom->loadHTML($html);
        libxml_use_internal_errors(false);

        $xpath = new \DOMXPath($dom);
        $nodes = $xpath->query(
            "//*[contains(concat(' ', normalize-space(@class), ' '), ' dp-detail-list ')]".
            "//details[contains(concat(' ', normalize-space(@class), ' '), ' dp-detail ')]"
        );

        $this->assertGreaterThan(0, $nodes->length);
    }

    public function test_a_user_without_view_costs_gets_no_cost_detail_on_screen(): void
    {
        $gerencia = User::where('email', 'gerencia@dp.local')->firstOrFail();

        $site = $this->location('Blount Rd', 'JOB-777');
        $machine = $this->machine('EX199', $site);
        $wo = $this->completedWorkOrder($machine, 'WO-SCREEN-SECRET');
        WorkOrderPart::create(['work_order_id' => $wo->id, 'quantity' => 1, 'unit_cost' => 999.00]);

        // gerencia trae view_costs por defecto; se lo revocamos para probar el
        // corte por PERMISO, no por rol (la matriz se edita por pantalla).
        $gerencia->roles->first()->revokePermissionTo('view_costs');
        $gerencia->forgetCachedPermissions();

        Livewire::actingAs($gerencia)
            ->test(Reports::class)
            ->assertDontSee('WO-SCREEN-SECRET')
            ->assertDontSee('999.00')
            ->assertSee(__('reports.report_category_inventory'));
    }
}

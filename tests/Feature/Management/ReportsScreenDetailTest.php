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

    private function completedWorkOrder(Machine $machine, string $code, ?string $description = null): WorkOrder
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
            'description' => $description,
        ]);
    }

    public function test_the_screen_detail_shows_the_picked_machine_with_its_work_orders_parts_job_number_and_work_description(): void
    {
        $admin = User::where('email', 'admin@dp.local')->firstOrFail();
        $site = $this->location('Blount Rd', 'JOB-555');
        $machine = $this->machine('EX099', $site);
        $wo = $this->completedWorkOrder($machine, 'WO-SCREEN-001', 'Cambio de mangueras del brazo');

        WorkOrderPart::create([
            'work_order_id' => $wo->id, 'part_number' => 'FIL-9001',
            'description' => 'Filtro de aceite', 'quantity' => 2, 'unit_cost' => 31.25,
        ]);

        Livewire::actingAs($admin)
            ->test(Reports::class)
            ->set('data.quick_period', 'custom')
            ->set('data.from', '2026-08-01')
            ->set('data.to', '2026-08-31')
            ->set('data.detail_machine_id', $machine->id)
            // Antes de que el detalle existiera en pantalla, el código de OT y
            // el repuesto solo estaban en el PDF/Excel: si esto no aparece, el
            // detalle no se está renderizando.
            ->assertSee('WO-SCREEN-001')
            ->assertSee('FIL-9001')
            // Pedido 1 ("everything is job numbers"): la obra en el detalle en
            // pantalla también lleva su número, número primero...
            ->assertSee('JOB-555 — Blount Rd')
            // ...y desde 2026-08-24 el n.º de trabajo va además rotulado
            // aparte, que es lo que pidió la clienta ("en job sites el id de
            // trabajo").
            ->assertSee(__('reports.job_number'))
            // "Descripción del trabajo jalarlo de work orders" (clienta,
            // 2026-08-24): el campo `description` de la OT, que hasta ese día
            // solo imprimían el PDF y el Excel.
            ->assertSee('Cambio de mangueras del brazo')
            ->assertSee(__('reports.print'));
    }

    /**
     * El detalle es de UNA máquina y hay que elegirla (clienta, 2026-08-24:
     * "work order detail un select"). Sin elegir no se muestra el detalle de
     * nadie — antes se desplegaban las 99 máquinas del periodo, una debajo de
     * la otra.
     *
     * El test afirma las dos mitades: sin elección, el aviso y NINGÚN código de
     * OT; con elección, el de esa máquina y no el de la otra. La segunda mitad
     * es la que importa: un detalle que igual mostrara todo pasaría la primera
     * si el aviso se renderizara de más.
     */
    public function test_the_detail_shows_only_the_picked_machine_and_asks_for_one_when_none_is_picked(): void
    {
        $admin = User::where('email', 'admin@dp.local')->firstOrFail();

        $site = $this->location('Blount Rd', 'JOB-321');
        $elegida = $this->machine('EX321', $site);
        $otra = $this->machine('EX322', $site);

        $this->completedWorkOrder($elegida, 'WO-PICKED');
        $this->completedWorkOrder($otra, 'WO-NOT-PICKED');

        $componente = Livewire::actingAs($admin)
            ->test(Reports::class)
            ->set('data.quick_period', 'custom')
            ->set('data.from', '2026-08-01')
            ->set('data.to', '2026-08-31');

        // Sin máquina elegida: el aviso, y ninguna OT.
        $componente
            ->assertSee(__('reports.detail_none_selected'))
            ->assertDontSee('WO-PICKED')
            ->assertDontSee('WO-NOT-PICKED')
            // El resumen de arriba sigue mostrando TODAS las máquinas: lo que
            // se elige es el detalle, no el reporte.
            ->assertSee('EX321')
            ->assertSee('EX322');

        // Con una elegida: la suya y solo la suya.
        $componente
            ->set('data.detail_machine_id', $elegida->id)
            ->assertSee('WO-PICKED')
            ->assertDontSee('WO-NOT-PICKED')
            ->assertDontSee(__('reports.detail_none_selected'));
    }

    /**
     * El filtro de máquina es el select que sale de Máquinas, y ya no hay una
     * caja de texto para escribir el número a mano (clienta, 2026-08-24).
     *
     * Se afirma sobre el ESTADO del formulario y no sobre el HTML: la etiqueta
     * "N.º de máquina" sigue apareciendo en la pantalla como encabezado de la
     * tabla resumen, así que buscarla en el HTML daría un falso negativo
     * eterno.
     */
    public function test_the_free_text_machine_number_filter_is_gone_from_the_screen(): void
    {
        $admin = User::where('email', 'admin@dp.local')->firstOrFail();

        $componente = Livewire::actingAs($admin)->test(Reports::class);

        $campos = array_keys(
            $componente->instance()->form->getFlatFields(withHidden: true)
        );

        $this->assertNotContains('id_code', $campos);
        $this->assertContains('machine_ids', $campos);
        $this->assertContains('detail_machine_id', $campos);
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
     * Este test reemplaza al que cuidaba el script de impresión.
     *
     * Historia, porque es la razón de que exista: cuando el detalle era un
     * `<details>` por máquina, imprimir no mostraba nada de lo colapsado.
     * `@media print { .dp-detail-body { display: block !important; } }` NO abre
     * un `<details>` cerrado —el contenido vive en el pseudo-elemento interno
     * `::details-content` y ningún `display` puesto en un hijo lo pisa—, así
     * que hubo que abrirlos con JS en `beforeprint`/`afterprint`, y un test
     * cuidaba que ese script viajara con la página.
     *
     * Al pasar el detalle a UNA máquina siempre abierta (clienta, 2026-08-24)
     * el problema desapareció por construcción, y con él el script. Lo que hay
     * que cuidar ahora es lo contrario: que NO vuelva a aparecer un `<details>`
     * en el detalle sin que vuelva también el script, porque eso imprimiría
     * páginas vacías sin que nada falle.
     */
    public function test_the_detail_prints_what_is_on_screen_because_nothing_is_collapsed(): void
    {
        $admin = User::where('email', 'admin@dp.local')->firstOrFail();
        $site = $this->location('Blount Rd', 'JOB-900');
        $machine = $this->machine('EX900', $site);
        $wo = $this->completedWorkOrder($machine, 'WO-PRINT-CHECK');
        WorkOrderPart::create(['work_order_id' => $wo->id, 'quantity' => 1, 'unit_cost' => 15.00]);

        // GET real y completo (no Livewire::test(), que solo renderiza el
        // componente): así el HTML incluye el layout del panel resuelto, que es
        // lo que realmente le llega al navegador.
        $html = Livewire::actingAs($admin)
            ->test(Reports::class)
            ->set('data.quick_period', 'custom')
            ->set('data.from', '2026-08-01')
            ->set('data.to', '2026-08-31')
            ->set('data.detail_machine_id', $machine->id)
            ->assertSee('WO-PRINT-CHECK')
            ->html();

        libxml_use_internal_errors(true);
        $dom = new \DOMDocument;
        $dom->loadHTML('<?xml encoding="utf-8" ?>'.$html);
        libxml_use_internal_errors(false);

        $xpath = new \DOMXPath($dom);

        // Ni un solo <details> dentro del detalle: si alguien reintroduce el
        // acordeón, este test cae y obliga a decidir qué pasa al imprimir.
        $colapsables = $xpath->query(
            "//*[contains(concat(' ', normalize-space(@class), ' '), ' dp-detail-list ')]//details"
        );

        $this->assertSame(0, $colapsables->length);

        // Y el cuerpo del detalle está en el documento, no escondido detrás de
        // nada que haya que abrir.
        $cuerpo = $xpath->query(
            "//*[contains(concat(' ', normalize-space(@class), ' '), ' dp-detail-body ')]"
        );

        $this->assertGreaterThan(0, $cuerpo->length);
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

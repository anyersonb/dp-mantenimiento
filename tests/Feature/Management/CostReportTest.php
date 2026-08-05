<?php

namespace Tests\Feature\Management;

use App\Models\ChecklistResult;
use App\Models\Location;
use App\Models\Machine;
use App\Models\MachineCategory;
use App\Models\User;
use App\Models\WorkOrder;
use App\Models\WorkOrderPart;
use App\Services\Reports\CategoryInventoryReportBuilder;
use App\Services\Reports\CostReportBuilder;
use App\Services\Reports\CostReportFilters;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Reporte de costos de mantenimiento y reporte de inventario por categoría
 * (pedido del cliente 2026-08-05).
 *
 * Lo que estos tests protegen, en orden de importancia:
 *
 *   1. **El total sale del detalle.** Si el reporte imprime cinco repuestos y un
 *      total, el total tiene que ser la suma de esos cinco. Un reporte cuyo total
 *      no se puede reconstruir a mano desde su propio detalle es un reporte que
 *      no se puede auditar.
 *   2. **Un repuesto sin costo no vale cero en silencio.** No suma al total —no
 *      hay número que sumar— pero queda contado en un aviso. Sin eso, el reporte
 *      se lee como completo estando corto.
 *   3. **Los filtros filtran de verdad**, incluido el periodo, que es la pregunta
 *      que hizo el cliente ("cuánto gastamos en el mes X").
 *   4. **El dinero necesita los dos permisos.** `view_reports` para pedir un
 *      reporte y `view_costs` para que traiga cifras. La matriz se edita por
 *      pantalla, así que esto tiene que seguir siendo cierto después.
 */
class CostReportTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);
    }

    private function category(string $name): MachineCategory
    {
        return MachineCategory::create([
            'name' => $name,
            'slug' => str($name)->slug().'-'.uniqid(),
            'default_service_interval' => 500,
        ]);
    }

    private function location(string $name, ?string $jobNumber = null): Location
    {
        return Location::create([
            'name' => $name,
            'slug' => str($name)->slug().'-'.uniqid(),
            'job_number' => $jobNumber,
        ]);
    }

    private function machine(string $idCode, ?MachineCategory $category = null, ?Location $location = null): Machine
    {
        return Machine::create([
            'id_code' => $idCode,
            'description' => $idCode.' TEST MACHINE',
            'machine_category_id' => $category?->id,
            'status' => 'active',
            'hourmeter_status' => 'ok',
            'current_location_id' => $location?->id,
            'current_hours' => 1000,
            'last_service_hours' => 500,
            'service_interval_hours' => 500,
            'hours_adjustment' => 0,
        ]);
    }

    /**
     * OT ya cerrada, creada sin sesión a propósito en la mayoría de los casos:
     * así `completed_by` queda NULL y se puede afirmar sobre el aviso de dato
     * faltante, que es el estado real de las OT anteriores al 2026-08-05.
     */
    private function completedWorkOrder(Machine $machine, string $completedAt, array $overrides = []): WorkOrder
    {
        return WorkOrder::create(array_merge([
            'code' => 'WO-T'.random_int(10000, 99999),
            'machine_id' => $machine->id,
            'type' => 'corrective',
            'status' => 'completed',
            'opened_at' => $completedAt,
            'completed_at' => $completedAt,
            'labor_hours' => 3.5,
        ], $overrides));
    }

    private function filters(array $overrides = []): CostReportFilters
    {
        return CostReportFilters::fromArray(array_merge([
            'from' => '2026-08-01',
            'to' => '2026-08-31',
        ], $overrides));
    }

    /* ------------------------------------------------------------------ *
     * 1. El total sale del detalle.
     * ------------------------------------------------------------------ */

    public function test_the_machine_total_is_the_sum_of_its_part_lines(): void
    {
        $machine = $this->machine('EX010');
        $wo = $this->completedWorkOrder($machine, '2026-08-10');

        WorkOrderPart::create([
            'work_order_id' => $wo->id, 'part_number' => 'F-1', 'description' => 'Filtro',
            'quantity' => 2, 'unit_cost' => 25.50,
        ]);
        WorkOrderPart::create([
            'work_order_id' => $wo->id, 'part_number' => 'A-9', 'description' => 'Aceite',
            'quantity' => 3, 'unit_cost' => 10.00,
        ]);

        $report = CostReportBuilder::build($this->filters());

        // 2 × 25.50 + 3 × 10.00 = 81.00. A mano, desde el detalle impreso.
        $this->assertCount(1, $report['machines']);
        $this->assertSame(81.0, $report['machines'][0]['parts_total']);
        $this->assertSame(81.0, $report['totals']['parts_total']);

        $lines = $report['machines'][0]['work_orders'][0]['parts'];
        $this->assertSame(81.0, array_sum(array_column($lines, 'subtotal')));
    }

    public function test_a_part_without_unit_cost_adds_nothing_but_is_reported_as_a_gap(): void
    {
        $machine = $this->machine('LD022');
        $wo = $this->completedWorkOrder($machine, '2026-08-12');

        WorkOrderPart::create([
            'work_order_id' => $wo->id, 'part_number' => 'OK-1',
            'quantity' => 1, 'unit_cost' => 40.00,
        ]);
        // Repuesto comprado y cargado, pero sin costo: gasto real que el total
        // no puede incluir porque nadie escribió el número.
        WorkOrderPart::create([
            'work_order_id' => $wo->id, 'part_number' => 'SIN-COSTO',
            'quantity' => 5, 'unit_cost' => null,
        ]);

        $report = CostReportBuilder::build($this->filters());

        $this->assertSame(40.0, $report['totals']['parts_total']);
        $this->assertSame(1, $report['totals']['parts_without_cost']);
        $this->assertSame(1, $report['machines'][0]['parts_without_cost']);
    }

    public function test_a_work_order_with_no_parts_still_counts_as_a_work_order(): void
    {
        $machine = $this->machine('RL016');
        $this->completedWorkOrder($machine, '2026-08-15');

        $report = CostReportBuilder::build($this->filters());

        $this->assertSame(1, $report['totals']['work_order_count']);
        $this->assertSame(0.0, $report['totals']['parts_total']);
        $this->assertSame(3.5, $report['totals']['labor_hours']);
    }

    /* ------------------------------------------------------------------ *
     * 2. Los filtros.
     * ------------------------------------------------------------------ */

    public function test_the_period_filter_excludes_work_orders_closed_outside_the_range(): void
    {
        $machine = $this->machine('EX011');

        $inside = $this->completedWorkOrder($machine, '2026-08-10');
        $outside = $this->completedWorkOrder($machine, '2026-07-10');

        WorkOrderPart::create(['work_order_id' => $inside->id, 'quantity' => 1, 'unit_cost' => 100]);
        WorkOrderPart::create(['work_order_id' => $outside->id, 'quantity' => 1, 'unit_cost' => 999]);

        $august = CostReportBuilder::build($this->filters());
        $this->assertSame(100.0, $august['totals']['parts_total']);
        $this->assertSame(1, $august['totals']['work_order_count']);

        // Y el mes anterior trae la otra, no las dos: si el rango se ignorara,
        // este assert pasaría igual con 1099.
        $july = CostReportBuilder::build($this->filters(['from' => '2026-07-01', 'to' => '2026-07-31']));
        $this->assertSame(999.0, $july['totals']['parts_total']);
        $this->assertSame(1, $july['totals']['work_order_count']);
    }

    public function test_only_completed_work_orders_are_counted_by_default(): void
    {
        $machine = $this->machine('EX012');

        $open = WorkOrder::create([
            'code' => 'WO-OPEN1', 'machine_id' => $machine->id, 'type' => 'corrective',
            'status' => 'in_progress', 'opened_at' => '2026-08-05',
        ]);
        WorkOrderPart::create(['work_order_id' => $open->id, 'quantity' => 1, 'unit_cost' => 500]);

        $default = CostReportBuilder::build($this->filters());
        $this->assertSame(0, $default['totals']['work_order_count']);

        // Pedirla explícitamente sí la trae, contada por su fecha de apertura.
        $withOpen = CostReportBuilder::build($this->filters(['statuses' => ['completed', 'in_progress']]));
        $this->assertSame(1, $withOpen['totals']['work_order_count']);
        $this->assertSame(500.0, $withOpen['totals']['parts_total']);
    }

    public function test_the_machine_number_filter_accepts_a_fragment(): void
    {
        $excavator = $this->machine('EX010');
        $loader = $this->machine('LD022');

        $woEx = $this->completedWorkOrder($excavator, '2026-08-10');
        $woLd = $this->completedWorkOrder($loader, '2026-08-11');
        WorkOrderPart::create(['work_order_id' => $woEx->id, 'quantity' => 1, 'unit_cost' => 10]);
        WorkOrderPart::create(['work_order_id' => $woLd->id, 'quantity' => 1, 'unit_cost' => 20]);

        $all = CostReportBuilder::build($this->filters());
        $this->assertSame(2, $all['totals']['machine_count']);

        $onlyEx = CostReportBuilder::build($this->filters(['id_code' => 'EX']));
        $this->assertSame(1, $onlyEx['totals']['machine_count']);
        $this->assertSame(10.0, $onlyEx['totals']['parts_total']);

        $exact = CostReportBuilder::build($this->filters(['id_code' => 'LD022']));
        $this->assertSame(20.0, $exact['totals']['parts_total']);
    }

    public function test_the_category_and_location_filters_narrow_the_report(): void
    {
        $excavators = $this->category('Excavator');
        $loaders = $this->category('Wheel Loader');
        $yard = $this->location('Broadview yd', 'JOB-100');
        $site = $this->location('Blount Rd', 'JOB-200');

        $ex = $this->machine('EX020', $excavators, $yard);
        $ld = $this->machine('LD030', $loaders, $site);

        $woEx = $this->completedWorkOrder($ex, '2026-08-10');
        $woLd = $this->completedWorkOrder($ld, '2026-08-10');
        WorkOrderPart::create(['work_order_id' => $woEx->id, 'quantity' => 1, 'unit_cost' => 11]);
        WorkOrderPart::create(['work_order_id' => $woLd->id, 'quantity' => 1, 'unit_cost' => 22]);

        $byCategory = CostReportBuilder::build($this->filters(['category_ids' => [$excavators->id]]));
        $this->assertSame(11.0, $byCategory['totals']['parts_total']);

        // La obra se filtra por la que quedó SELLADA en la OT (el observer la
        // tomó de la máquina al crearla), no por dónde esté la máquina hoy.
        $byLocation = CostReportBuilder::build($this->filters(['location_ids' => [$site->id]]));
        $this->assertSame(22.0, $byLocation['totals']['parts_total']);
    }

    public function test_a_broken_date_in_the_query_string_falls_back_to_the_current_month(): void
    {
        // Un query string manipulado no debe tumbar el reporte con un 500.
        $filters = CostReportFilters::fromArray(['from' => 'no-es-una-fecha', 'to' => '']);

        $this->assertSame(now()->startOfMonth()->toDateString(), $filters->from->toDateString());
        $this->assertSame(now()->endOfMonth()->toDateString(), $filters->to->toDateString());
    }

    public function test_an_inverted_range_is_straightened_instead_of_returning_nothing(): void
    {
        $filters = CostReportFilters::fromArray(['from' => '2026-08-31', 'to' => '2026-08-01']);

        $this->assertSame('2026-08-01', $filters->from->toDateString());
        $this->assertSame('2026-08-31', $filters->to->toDateString());
    }

    /* ------------------------------------------------------------------ *
     * 3. Quién, dónde y el checklist.
     * ------------------------------------------------------------------ */

    public function test_the_report_names_who_closed_the_work_order_and_where(): void
    {
        $taller = User::where('email', 'taller@dp.local')->firstOrFail();
        $site = $this->location('Blount Rd', 'JOB-777');
        $machine = $this->machine('EX030', null, $site);

        $wo = $this->completedWorkOrder($machine, '2026-08-10', [
            'status' => 'open',
            'completed_at' => null,
        ]);

        // Cierre real, con sesión: es el camino que sella al ejecutor.
        $this->actingAs($taller);
        $wo->update(['status' => 'completed', 'completed_at' => '2026-08-10']);

        $report = CostReportBuilder::build($this->filters());
        $row = $report['machines'][0]['work_orders'][0];

        $this->assertSame('Taller / Técnico', $row['completed_by']);
        $this->assertSame('Blount Rd', $row['location']);
        $this->assertSame('JOB-777', $row['location_job_number']);
        $this->assertSame(0, $report['totals']['unknown_completer']);
        $this->assertSame(0, $report['totals']['unknown_location']);
    }

    public function test_work_orders_without_who_or_where_are_counted_as_gaps(): void
    {
        // Sin obra en la máquina y sin sesión al cerrar: es exactamente el estado
        // de las OT anteriores a que existieran las dos columnas.
        $machine = $this->machine('EX031');
        $this->completedWorkOrder($machine, '2026-08-10');

        $report = CostReportBuilder::build($this->filters());

        $this->assertNull($report['machines'][0]['work_orders'][0]['completed_by']);
        $this->assertNull($report['machines'][0]['work_orders'][0]['location']);
        $this->assertSame(1, $report['totals']['unknown_completer']);
        $this->assertSame(1, $report['totals']['unknown_location']);
    }

    public function test_the_report_summarises_the_checklist_and_lists_only_the_flagged_items(): void
    {
        $machine = $this->machine('TF005');
        $wo = $this->completedWorkOrder($machine, '2026-08-10');

        ChecklistResult::create(['work_order_id' => $wo->id, 'label' => 'Brakes - Service', 'result' => 'ok']);
        ChecklistResult::create(['work_order_id' => $wo->id, 'label' => 'Lights - Head', 'result' => 'ok']);
        ChecklistResult::create(['work_order_id' => $wo->id, 'label' => 'Trailer - Coupling', 'result' => 'na']);
        ChecklistResult::create([
            'work_order_id' => $wo->id, 'label' => 'Tires', 'result' => 'alert',
            'alert_detail' => 'Banda lateral cortada',
        ]);

        $row = CostReportBuilder::build($this->filters())['machines'][0]['work_orders'][0];

        $this->assertSame(4, $row['checklist_total']);
        $this->assertSame(2, $row['checklist_ok']);
        $this->assertSame(1, $row['checklist_na']);
        $this->assertSame(1, $row['checklist_alert']);
        $this->assertCount(1, $row['checklist_alerts']);
        $this->assertSame('Tires', $row['checklist_alerts'][0]['label']);
        $this->assertSame('Banda lateral cortada', $row['checklist_alerts'][0]['detail']);
    }

    /* ------------------------------------------------------------------ *
     * 4. Permisos.
     * ------------------------------------------------------------------ */

    public function test_roles_with_view_reports_and_view_costs_can_download_the_cost_report(): void
    {
        foreach (['admin@dp.local', 'responsable@dp.local', 'gerencia@dp.local'] as $email) {
            $user = User::where('email', $email)->firstOrFail();

            $pdf = $this->actingAs($user)->get('/reports/costs.pdf?from=2026-08-01&to=2026-08-31');
            $pdf->assertOk();
            $pdf->assertHeader('content-type', 'application/pdf');

            $this->actingAs($user)->get('/reports/costs.xlsx?from=2026-08-01&to=2026-08-31')->assertOk();
        }
    }

    public function test_taller_has_view_costs_but_no_view_reports_and_is_forbidden(): void
    {
        // Control importante: taller SÍ ve costos dentro de una OT. Lo que no
        // puede es pedir el reporte consolidado de toda la flota.
        $taller = User::where('email', 'taller@dp.local')->firstOrFail();

        $this->assertTrue($taller->can('view_costs'));
        $this->assertFalse($taller->can('view_reports'));

        $this->actingAs($taller)->get('/reports/costs.pdf')->assertForbidden();
        $this->actingAs($taller)->get('/reports/costs.xlsx')->assertForbidden();
    }

    public function test_field_roles_are_forbidden_from_every_report_route(): void
    {
        foreach (['foreman@dp.local', 'combustible@dp.local', 'campo@dp.local'] as $email) {
            $user = User::where('email', $email)->firstOrFail();

            $this->actingAs($user)->get('/reports/costs.pdf')->assertForbidden();
            $this->actingAs($user)->get('/reports/costs.xlsx')->assertForbidden();
            $this->actingAs($user)->get('/reports/categories.pdf')->assertForbidden();
        }
    }

    public function test_a_user_with_view_reports_but_without_view_costs_cannot_get_the_cost_report(): void
    {
        // Hoy los tres roles con view_reports tienen también view_costs, pero la
        // matriz se edita por pantalla: esto prueba que el corte es por permiso y
        // no por rol.
        $gerencia = User::where('email', 'gerencia@dp.local')->firstOrFail();
        $gerencia->roles->first()->revokePermissionTo('view_costs');
        $gerencia->forgetCachedPermissions();

        $this->actingAs($gerencia)->get('/reports/costs.pdf')->assertForbidden();

        // Y el de categorías, que no lleva dinero, sigue accesible.
        $this->actingAs($gerencia)->get('/reports/categories.pdf')->assertOk();
    }

    public function test_guests_are_redirected_to_login(): void
    {
        $this->get('/reports/costs.pdf')->assertRedirect('/field/login');
        $this->get('/reports/categories.pdf')->assertRedirect('/field/login');
    }

    /* ------------------------------------------------------------------ *
     * 4b. Los archivos, CON datos.
     *
     * Los tests de permisos de arriba corren sobre una base vacía, así que solo
     * ejercitan la rama "no hay resultados" de las plantillas. Un error en el
     * bloque de detalle —el que imprime repuestos, checklist y totales— no se
     * vería. Estos tres lo cubren.
     * ------------------------------------------------------------------ */

    /**
     * @return array{0: \App\Models\User, 1: WorkOrder}
     */
    private function reportWithFullDetail(): array
    {
        $admin = User::where('email', 'admin@dp.local')->firstOrFail();
        $site = $this->location('Blount Rd', 'JOB-555');
        $machine = $this->machine('EX099', $this->category('Excavator'), $site);

        $wo = $this->completedWorkOrder($machine, '2026-08-14', ['status' => 'open', 'completed_at' => null]);

        WorkOrderPart::create([
            'work_order_id' => $wo->id, 'part_number' => 'FIL-9001',
            'description' => 'Filtro de aceite', 'quantity' => 2, 'unit_cost' => 31.25,
        ]);
        WorkOrderPart::create([
            'work_order_id' => $wo->id, 'part_number' => 'SIN-PRECIO',
            'description' => 'Manguera', 'quantity' => 1, 'unit_cost' => null,
        ]);
        ChecklistResult::create(['work_order_id' => $wo->id, 'label' => 'Brakes', 'result' => 'ok']);
        ChecklistResult::create([
            'work_order_id' => $wo->id, 'label' => 'Tires', 'result' => 'alert',
            'alert_detail' => 'Banda lateral cortada',
        ]);

        $this->actingAs($admin);
        $wo->update(['status' => 'completed', 'completed_at' => '2026-08-14']);

        return [$admin, $wo->fresh()];
    }

    public function test_the_pdf_renders_the_full_detail_without_blowing_up(): void
    {
        [$admin] = $this->reportWithFullDetail();

        $response = $this->actingAs($admin)->get('/reports/costs.pdf?from=2026-08-01&to=2026-08-31');

        $response->assertOk();
        $response->assertHeader('content-type', 'application/pdf');
        // Un PDF de dompdf con el detalle dentro pesa bastante más que la página
        // de "sin resultados": si la rama de detalle no se hubiera recorrido, esto
        // caería muy por debajo.
        $this->assertGreaterThan(5000, strlen($response->getContent()));
    }

    public function test_the_pdf_template_prints_the_who_the_where_and_every_part(): void
    {
        // Se renderiza la plantilla como HTML para poder afirmar sobre su
        // contenido: dentro del PDF el texto va comprimido y no se puede leer.
        [$admin] = $this->reportWithFullDetail();
        $this->actingAs($admin);

        $html = view('exports.cost-report', [
            'report' => CostReportBuilder::build($this->filters()),
            'generatedAt' => '2026-08-14 10:00',
            'generatedBy' => $admin->name,
            'logoPath' => public_path('images/dp-logo.jpg'),
        ])->render();

        // 2 × 31.25 = 62.50, y la manguera sin precio no suma.
        $this->assertStringContainsString('62.50', $html);
        $this->assertStringContainsString('EX099', $html);
        $this->assertStringContainsString('Administrador DP', $html);   // quién
        $this->assertStringContainsString('Blount Rd', $html);          // dónde
        $this->assertStringContainsString('JOB-555', $html);            // n.º de trabajo
        $this->assertStringContainsString('FIL-9001', $html);           // repuesto con precio
        $this->assertStringContainsString('SIN-PRECIO', $html);         // repuesto sin precio
        $this->assertStringContainsString('Banda lateral cortada', $html); // detalle de la alerta
        // Y el aviso de que hay un repuesto sin costo, para que el total no se
        // lea como completo.
        $this->assertStringContainsString(__('reports.data_gaps'), $html);
    }

    public function test_the_excel_has_one_row_per_part_with_the_context_repeated(): void
    {
        [$admin] = $this->reportWithFullDetail();
        $this->actingAs($admin);

        $rows = (new \App\Exports\CostReportExport($this->filters()))->collection();

        // Dos repuestos → dos filas, para poder pivotear por repuesto.
        $this->assertCount(2, $rows);

        foreach ($rows as $row) {
            // El contexto se repite en cada fila: sin eso la tabla dinámica no
            // puede agrupar por máquina, obra ni responsable.
            $this->assertSame('EX099', $row[0]);
            $this->assertSame('Administrador DP', $row[8]);
            $this->assertSame('JOB-555', $row[10]);
            $this->assertSame('Blount Rd', $row[11]);
        }

        $conPrecio = collect($rows)->firstWhere(15, 'FIL-9001');
        $this->assertSame(62.5, $conPrecio[19]);

        // El repuesto sin costo NO va con 0 en la columna de costo unitario: eso
        // afirmaría que fue gratis. Va con el aviso, y su subtotal sí es 0.
        $sinPrecio = collect($rows)->firstWhere(15, 'SIN-PRECIO');
        $this->assertSame(__('reports.no_cost_loaded'), $sinPrecio[18]);
        $this->assertSame(0.0, $sinPrecio[19]);
    }

    /* ------------------------------------------------------------------ *
     * 5. Inventario por categoría.
     * ------------------------------------------------------------------ */

    public function test_the_category_inventory_includes_categories_with_zero_machines(): void
    {
        $excavators = $this->category('Excavator');
        $empty = $this->category('Cold Planer');

        $this->machine('EX040', $excavators);
        $this->machine('EX041', $excavators);

        $report = CategoryInventoryReportBuilder::build();

        $rows = collect($report['rows'])->keyBy('category_raw');

        $this->assertSame(2, $rows['Excavator']['total']);
        // El widget del escritorio esconde las categorías en cero; el reporte NO,
        // porque "no tenemos ninguna" es justo lo que DP necesita poder verificar.
        $this->assertArrayHasKey('Cold Planer', $rows->all());
        $this->assertSame(0, $rows['Cold Planer']['total']);
    }

    public function test_the_category_inventory_breaks_the_count_down_by_status(): void
    {
        $rollers = $this->category('Roller');

        $this->machine('RL050', $rollers);
        $down = $this->machine('RL051', $rollers);
        $down->update(['status' => 'down']);

        $report = CategoryInventoryReportBuilder::build();
        $row = collect($report['rows'])->firstWhere('category_raw', 'Roller');

        $this->assertSame(2, $row['total']);
        $this->assertSame(1, $row['active']);
        $this->assertSame(1, $row['down']);
    }

    public function test_machines_without_a_category_are_reported_so_the_totals_can_be_reconciled(): void
    {
        $this->machine('SIN-TIPO-1');

        $report = CategoryInventoryReportBuilder::build();

        $this->assertSame(1, $report['uncategorized']);
        $this->assertSame(1, $report['totals']['total']);
    }
}

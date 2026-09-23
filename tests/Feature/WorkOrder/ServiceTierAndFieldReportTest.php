<?php

namespace Tests\Feature\WorkOrder;

use App\Filament\Resources\AlertResource\Pages\ListAlerts;
use App\Filament\Resources\FieldReportResource\Pages\ListFieldReports;
use App\Filament\Resources\WorkOrderResource\Pages\CreateWorkOrder;
use App\Filament\Resources\WorkOrderResource\Pages\EditWorkOrder;
use App\Models\Alert;
use App\Models\FieldReport;
use App\Models\Location;
use App\Models\Machine;
use App\Models\User;
use App\Models\WorkOrder;
use App\Services\Reports\CostReportBuilder;
use App\Services\Reports\CostReportFilters;
use App\Services\WorkOrderCompletionService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Pedido del cliente (2026-09-22): "en services tier dejarlo repair ya que el
 * número de orden no es mantenimiento, es reparación correctiva o upgrade. La
 * orden debe estar asociada, pero no de forma obligatoria, a un field report."
 *
 * Dos piezas, cubiertas por separado:
 *
 *   1. `service_tier` suma 'repair' (default en el form manual) y 'upgrade' a
 *      las cuatro horas que ya existían. La lista de opciones NO es una
 *      restricción del dato: una OT nacida de una alerta sigue llevando el
 *      intervalo LIBRE de la máquina (`service_interval_hours`, que no está
 *      limitado a 500/1000/2000/4000 — ver `MachineResource::form()`), así
 *      que el rechazo de valores fuera de lista vive SOLO en el Select del
 *      form manual, nunca en el Observer. Ver el docblock de
 *      `WorkOrder::serviceTierOptions()`.
 *   2. `field_report_id`, FK opcional a `field_reports`, con dos barreras:
 *      la regla `exists(...)->where('machine_id', ...)` del Select (payload
 *      manipulado del form) y `WorkOrderObserver::guardFieldReportBelongsToMachine()`
 *      (cualquier otro camino de escritura, `$guarded = []` mediante).
 */
class ServiceTierAndFieldReportTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);
    }

    private function machine(array $overrides = []): Machine
    {
        $location = Location::create(['name' => 'Yard TIER', 'slug' => 'yard-tier-'.uniqid()]);

        return Machine::create(array_merge([
            'id_code' => 'TIER-'.random_int(1000, 9999),
            'status' => 'active',
            'hourmeter_status' => 'ok',
            'current_location_id' => $location->id,
            'current_hours' => 1000,
            'last_service_hours' => 900,
            'service_interval_hours' => 500,
        ], $overrides));
    }

    private function fieldReport(Machine $machine, array $overrides = []): FieldReport
    {
        $worker = User::where('email', 'campo@dp.local')->firstOrFail();

        return FieldReport::create(array_merge([
            'machine_id' => $machine->id,
            'reported_by' => $worker->id,
            'condition' => 'attention',
            'notes' => 'QA field report',
        ], $overrides));
    }

    private function workOrder(Machine $machine, array $overrides = []): WorkOrder
    {
        return WorkOrder::create(array_merge([
            'code' => 'TIER-WO-'.random_int(1000, 9999),
            'machine_id' => $machine->id,
            'type' => 'preventive',
            'status' => 'open',
            'priority' => 'normal',
            'opened_at' => now()->toDateString(),
        ], $overrides));
    }

    /* ------------------------------------------------------------------ *
     * 1) Default 'repair' en la creación manual.
     * ------------------------------------------------------------------ */

    public function test_a_manually_created_work_order_defaults_its_service_tier_to_repair(): void
    {
        $machine = $this->machine();
        $responsable = User::where('email', 'responsable@dp.local')->firstOrFail();

        Livewire::actingAs($responsable)
            ->test(CreateWorkOrder::class)
            ->fillForm([
                'code' => 'TIER-OT-01',
                'machine_id' => $machine->id,
                'type' => 'corrective',
                'status' => 'open',
                'priority' => 'normal',
                'opened_at' => now()->toDateString(),
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertDatabaseHas('work_orders', [
            'code' => 'TIER-OT-01',
            'service_tier' => 'repair',
        ]);
    }

    /* ------------------------------------------------------------------ *
     * 2) La OT nacida de una alerta conserva las horas de la máquina, aunque
     *    el intervalo sea "libre" (no una de las cuatro opciones del form).
     * ------------------------------------------------------------------ */

    public function test_the_alert_action_still_stamps_the_machines_free_service_interval_as_the_tier(): void
    {
        $machine = $this->machine(['service_interval_hours' => 750, 'current_hours' => 1450]);

        $alert = Alert::create([
            'machine_id' => $machine->id,
            'type' => 'service',
            'title' => 'QA',
            'message' => 'QA',
            'remaining_hours' => 50,
            'status' => 'open',
        ]);

        Livewire::actingAs(User::where('email', 'admin@dp.local')->firstOrFail())
            ->test(ListAlerts::class)
            ->callTableAction('create_work_order', $alert);

        $nueva = WorkOrder::where('machine_id', $machine->id)->firstOrFail();

        // 750 no es ni una de las cuatro opciones de horas ni 'repair'/'upgrade':
        // es el número real de la máquina, y sigue llegando intacto.
        $this->assertSame('750', (string) $nueva->service_tier);
        $this->assertNull($nueva->field_report_id);
    }

    /* ------------------------------------------------------------------ *
     * 3) El form rechaza un tier fuera de la lista (payload manipulado).
     * ------------------------------------------------------------------ */

    public function test_the_form_rejects_a_service_tier_outside_the_allowed_list(): void
    {
        $machine = $this->machine();
        $responsable = User::where('email', 'responsable@dp.local')->firstOrFail();

        Livewire::actingAs($responsable)
            ->test(CreateWorkOrder::class)
            ->fillForm([
                'code' => 'TIER-OT-02',
                'machine_id' => $machine->id,
                'type' => 'corrective',
                'status' => 'open',
                'priority' => 'normal',
                'opened_at' => now()->toDateString(),
                'service_tier' => 'not-a-real-tier',
            ])
            ->call('create')
            ->assertHasFormErrors(['service_tier']);

        $this->assertDatabaseMissing('work_orders', ['code' => 'TIER-OT-02']);
    }

    /* ------------------------------------------------------------------ *
     * 4) field_report_id: pertenece a la máquina, u OT sin reporte, o rechazo.
     * ------------------------------------------------------------------ */

    public function test_a_work_order_can_be_created_without_any_field_report(): void
    {
        $machine = $this->machine();
        $responsable = User::where('email', 'responsable@dp.local')->firstOrFail();

        Livewire::actingAs($responsable)
            ->test(CreateWorkOrder::class)
            ->fillForm([
                'code' => 'TIER-OT-03',
                'machine_id' => $machine->id,
                'type' => 'corrective',
                'status' => 'open',
                'priority' => 'normal',
                'opened_at' => now()->toDateString(),
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertDatabaseHas('work_orders', [
            'code' => 'TIER-OT-03',
            'field_report_id' => null,
        ]);
    }

    public function test_a_work_order_can_be_created_with_a_field_report_of_its_own_machine(): void
    {
        $machine = $this->machine();
        $report = $this->fieldReport($machine);
        $responsable = User::where('email', 'responsable@dp.local')->firstOrFail();

        Livewire::actingAs($responsable)
            ->test(CreateWorkOrder::class)
            ->fillForm([
                'code' => 'TIER-OT-04',
                'machine_id' => $machine->id,
                'type' => 'corrective',
                'status' => 'open',
                'priority' => 'normal',
                'opened_at' => now()->toDateString(),
                'field_report_id' => $report->id,
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertDatabaseHas('work_orders', [
            'code' => 'TIER-OT-04',
            'field_report_id' => $report->id,
        ]);
    }

    public function test_the_form_rejects_a_field_report_that_belongs_to_a_different_machine(): void
    {
        $machine = $this->machine();
        $otherMachine = $this->machine();
        $foreignReport = $this->fieldReport($otherMachine);
        $responsable = User::where('email', 'responsable@dp.local')->firstOrFail();

        Livewire::actingAs($responsable)
            ->test(CreateWorkOrder::class)
            ->fillForm([
                'code' => 'TIER-OT-05',
                'machine_id' => $machine->id,
                'type' => 'corrective',
                'status' => 'open',
                'priority' => 'normal',
                'opened_at' => now()->toDateString(),
                'field_report_id' => $foreignReport->id,
            ])
            ->call('create')
            ->assertHasFormErrors(['field_report_id']);

        $this->assertDatabaseMissing('work_orders', ['code' => 'TIER-OT-05']);
    }

    /**
     * Blindaje de integridad ANTE payload manipulado / escritura directa: el
     * form ya lo rechaza (test anterior), pero `WorkOrder` usa `$guarded = []`
     * y hay caminos que no pasan por el form (tinker, un import, un job). El
     * Observer limpia el dato roto en vez de dejarlo pasar.
     */
    public function test_a_raw_write_with_a_mismatched_field_report_is_silently_cleared_by_the_observer(): void
    {
        $machine = $this->machine();
        $otherMachine = $this->machine();
        $foreignReport = $this->fieldReport($otherMachine);

        $workOrder = $this->workOrder($machine, ['field_report_id' => $foreignReport->id]);

        $this->assertNull($workOrder->fresh()->field_report_id);
    }

    /**
     * Y el camino inverso: si la OT cambia de máquina por escritura directa
     * (sin pasar por el `afterStateUpdated` del Select, que es solo del
     * form), el reporte que ya no le pertenece se limpia igual.
     */
    public function test_changing_the_machine_by_a_raw_write_clears_a_field_report_that_no_longer_belongs(): void
    {
        $machine = $this->machine();
        $otherMachine = $this->machine();
        $report = $this->fieldReport($machine);

        $workOrder = $this->workOrder($machine, ['field_report_id' => $report->id]);
        $this->assertSame($report->id, $workOrder->fresh()->field_report_id);

        $workOrder->update(['machine_id' => $otherMachine->id]);

        $this->assertNull($workOrder->fresh()->field_report_id);
    }

    /**
     * Borrar el reporte de campo no debe llevarse la OT: `nullOnDelete()`.
     */
    public function test_deleting_the_field_report_leaves_the_work_order_with_a_null_reference(): void
    {
        $machine = $this->machine();
        $report = $this->fieldReport($machine);
        $workOrder = $this->workOrder($machine, ['field_report_id' => $report->id]);

        $report->delete();

        $this->assertDatabaseHas('work_orders', ['id' => $workOrder->id, 'field_report_id' => null]);
    }

    /* ------------------------------------------------------------------ *
     * 5) El Select solo ofrece los reportes de la máquina elegida, y se
     *    refresca al cambiar de máquina.
     * ------------------------------------------------------------------ */

    public function test_the_field_report_select_only_offers_reports_of_the_selected_machine(): void
    {
        $machineA = $this->machine();
        $machineB = $this->machine();

        $reportA = $this->fieldReport($machineA, ['notes' => 'Report of A']);
        $reportB = $this->fieldReport($machineB, ['notes' => 'Report of B']);

        $admin = User::where('email', 'admin@dp.local')->firstOrFail();

        $componente = Livewire::actingAs($admin)
            ->test(CreateWorkOrder::class)
            ->fillForm(['machine_id' => $machineA->id])
            ->instance();

        $opciones = $componente->form->getFlatFields()['field_report_id']->getOptions();

        $this->assertArrayHasKey($reportA->id, $opciones);
        $this->assertArrayNotHasKey($reportB->id, $opciones);
    }

    /* ------------------------------------------------------------------ *
     * 6) Permiso: sin view_field_reports, el Select queda oculto y no se
     *    pueden enumerar reportes a través de él.
     * ------------------------------------------------------------------ */

    public function test_the_field_report_select_is_hidden_without_the_view_field_reports_permission(): void
    {
        // Con la matriz vigente, todos los roles que llegan a esta página
        // (create_work_order/execute_work_order) tienen view_field_reports.
        // Se lo quitamos a mano a responsable_mantenimiento para probar la
        // barrera en sí, no la coincidencia de la matriz de hoy.
        Role::where('name', 'responsable_mantenimiento')->first()->revokePermissionTo('view_field_reports');

        $responsable = User::where('email', 'responsable@dp.local')->firstOrFail();

        Livewire::actingAs($responsable)
            ->test(CreateWorkOrder::class)
            ->assertFormFieldIsHidden('field_report_id');
    }

    public function test_the_field_report_select_is_visible_with_the_permission(): void
    {
        $responsable = User::where('email', 'responsable@dp.local')->firstOrFail();

        Livewire::actingAs($responsable)
            ->test(CreateWorkOrder::class)
            ->assertFormFieldIsVisible('field_report_id');
    }

    /* ------------------------------------------------------------------ *
     * 7) Completar una correctiva con tier 'repair' no toca el ciclo de
     *    servicio: la regla mira `type`, no `service_tier` (decisión de
     *    Anyerson, no tocar WorkOrderCompletionService::complete()).
     * ------------------------------------------------------------------ */

    public function test_completing_a_corrective_work_order_with_repair_tier_does_not_reset_the_service_cycle(): void
    {
        $machine = $this->machine(['current_hours' => 1200, 'last_service_hours' => 900, 'remaining_hours' => 200]);

        $workOrder = $this->workOrder($machine, [
            'type' => 'corrective',
            'service_tier' => WorkOrder::SERVICE_TIER_REPAIR,
            'completed_at' => now()->toDateString(),
        ]);

        WorkOrderCompletionService::complete($workOrder->fresh('machine'));

        $machine->refresh();
        $this->assertSame(900, $machine->last_service_hours);
        $this->assertSame(200, $machine->remaining_hours);
        $this->assertDatabaseMissing('horometer_readings', ['machine_id' => $machine->id]);
    }

    /* ------------------------------------------------------------------ *
     * 8) El reporte de costos muestra el tier traducido, no "(repair h)".
     * ------------------------------------------------------------------ */

    public function test_the_cost_report_shows_the_translated_tier_label_instead_of_a_raw_repair_h(): void
    {
        $machine = $this->machine();

        $repairOrder = $this->workOrder($machine, [
            'code' => 'TIER-COST-01',
            'type' => 'corrective',
            'service_tier' => WorkOrder::SERVICE_TIER_REPAIR,
            'status' => 'completed',
            'completed_at' => '2026-09-10',
        ]);

        $hourOrder = $this->workOrder($machine, [
            'code' => 'TIER-COST-02',
            'type' => 'preventive',
            'service_tier' => '500',
            'status' => 'completed',
            'completed_at' => '2026-09-11',
        ]);

        $filters = CostReportFilters::fromArray(['from' => '2026-09-01', 'to' => '2026-09-30']);

        $html = view('exports.cost-report', [
            'report' => CostReportBuilder::build($filters),
            'generatedAt' => '2026-09-22 10:00',
            'generatedBy' => 'QA',
            'logoPath' => public_path('images/dp-logo.jpg'),
        ])->render();

        $this->assertStringContainsString(__('wo.service_tier_repair'), $html);
        $this->assertStringContainsString('(500 h)', $html);
        $this->assertStringNotContainsString('repair h', $html);

        // Ambos códigos deben aparecer: si uno se cayera del reporte por un
        // error de formateo esto lo dejaría pasar igual, así que se confirma.
        $this->assertStringContainsString($repairOrder->code, $html);
        $this->assertStringContainsString($hourOrder->code, $html);
    }

    /* ------------------------------------------------------------------ *
     * 9) La pantalla de solo lectura de reportes de campo muestra las OT
     *    asociadas, sin abrir ninguna acción de escritura nueva.
     * ------------------------------------------------------------------ */

    public function test_the_field_report_detail_view_shows_its_associated_work_orders(): void
    {
        $machine = $this->machine();
        $report = $this->fieldReport($machine);
        $workOrder = $this->workOrder($machine, [
            'code' => 'TIER-LINKED-01',
            'status' => 'in_progress',
            'field_report_id' => $report->id,
        ]);

        $admin = User::where('email', 'admin@dp.local')->firstOrFail();

        // mountTableAction() (no callTableAction()): el action() de ViewAction
        // es un no-op que se auto-ejecuta y cierra el modal en el mismo
        // request (ver FieldReportResourceTableTest).
        Livewire::actingAs($admin)
            ->test(ListFieldReports::class)
            ->mountTableAction('view', $report)
            ->assertSee(__('field_reports.detail_work_orders'))
            ->assertSee($workOrder->code)
            ->assertSee(__('wo.in_progress'));
    }

    public function test_the_field_report_detail_view_hides_the_section_without_any_associated_work_order(): void
    {
        $machine = $this->machine();
        $report = $this->fieldReport($machine);

        $admin = User::where('email', 'admin@dp.local')->firstOrFail();

        Livewire::actingAs($admin)
            ->test(ListFieldReports::class)
            ->mountTableAction('view', $report)
            ->assertDontSee(__('field_reports.detail_work_orders'));
    }

    /* ------------------------------------------------------------------ *
     * Vuelta 2 (2026-09-22) — regresión ALTA encontrada por seguridad:
     * una OT nacida de una alerta guarda el intervalo LIBRE de la máquina
     * (`service_interval_hours`, TextInput numérico sin restricción — ver
     * MachineResource::form()) como `service_tier`. El ->in() original del
     * Select rechazaba el guardado de CUALQUIER edición de esa OT, aunque
     * nadie tocara el campo. En producción ya hay OTs viejas así.
     * ------------------------------------------------------------------ */

    public function test_editing_a_work_order_with_a_legacy_free_tier_saves_without_touching_it(): void
    {
        $machine = $this->machine();
        $workOrder = $this->workOrder($machine, ['service_tier' => '750']);
        $taller = User::where('email', 'taller@dp.local')->firstOrFail();

        Livewire::actingAs($taller)
            ->test(EditWorkOrder::class, ['record' => $workOrder->getKey()])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertDatabaseHas('work_orders', [
            'id' => $workOrder->id,
            'service_tier' => '750',
        ]);
    }

    public function test_editing_a_work_order_to_a_brand_new_out_of_list_tier_is_still_rejected(): void
    {
        $machine = $this->machine();
        $workOrder = $this->workOrder($machine, ['service_tier' => '750']);
        $taller = User::where('email', 'taller@dp.local')->firstOrFail();

        Livewire::actingAs($taller)
            ->test(EditWorkOrder::class, ['record' => $workOrder->getKey()])
            ->fillForm(['service_tier' => '999'])
            ->call('save')
            ->assertHasFormErrors(['service_tier']);

        $this->assertDatabaseHas('work_orders', ['id' => $workOrder->id, 'service_tier' => '750']);
    }

    /**
     * El valor libre del tier se muestra con el mismo formato "N h" que las
     * opciones fijas, tanto en el Select de edición como en la tabla y el
     * reporte de costos (WorkOrder::serviceTierLabel()).
     */
    public function test_a_legacy_free_tier_is_labeled_with_the_same_hours_format_as_the_fixed_options(): void
    {
        $this->assertSame('750 h', WorkOrder::serviceTierLabel('750'));

        $machine = $this->machine();
        $workOrder = $this->workOrder($machine, ['service_tier' => '750']);
        $admin = User::where('email', 'admin@dp.local')->firstOrFail();

        Livewire::actingAs($admin)
            ->test(EditWorkOrder::class, ['record' => $workOrder->getKey()])
            ->assertFormSet(['service_tier' => '750'])
            ->assertSee('750 h');
    }

    /* ------------------------------------------------------------------ *
     * Vuelta 2 (2026-09-22) — fecha del selector de reporte de campo.
     *
     * Causa real confirmada por lectura de código y reproducción con
     * Carbon::setTestNow(): `config('app.timezone')` está fijo en 'UTC'
     * (`config/app.php:68`, no lee env) — decisión ABIERTA con Anyerson
     * (E6-16), fuera del alcance de este lote, que NO se toca acá.
     *
     * `fieldReportOptionLabel()` no aplica ninguna conversión de huso
     * propia: usa el `created_at` tal cual lo entrega Eloquent (ya en UTC,
     * por el mismo `date_default_timezone_set` que Laravel arma con
     * `config('app.timezone')`). Es EXACTAMENTE el mismo criterio con el
     * que Filament pinta cualquier columna ->dateTime()/->date() sin
     * ->timezone() explícito (`CanFormatState::getTimezone()` cae a
     * `config('app.timezone')` por defecto) — por eso FieldReportResource
     * muestra el mismo día calendario para el mismo instante. No hay
     * divergencia que arreglar EN ESTE selector: el desfase que reportó
     * seguridad es sistémico (cualquier evento de la noche en Lima, UTC-5,
     * cae en el día siguiente en TODO el panel), no un bug de este campo.
     * ------------------------------------------------------------------ */

    public function test_the_field_report_option_label_matches_the_same_calendar_day_the_rest_of_the_panel_shows(): void
    {
        // Instante elegido a propósito para exponer el desfase si existiera
        // SOLO en este selector: 22:30 hora Lima (UTC-5) del 22/09 es
        // 2026-09-23 03:30:00 en UTC, el huso en el que corre TODA la app.
        Carbon::setTestNow(Carbon::parse('2026-09-23 03:30:00'));

        try {
            $machine = $this->machine();
            $report = $this->fieldReport($machine);
            $admin = User::where('email', 'admin@dp.local')->firstOrFail();

            $componente = Livewire::actingAs($admin)
                ->test(CreateWorkOrder::class)
                ->fillForm(['machine_id' => $machine->id])
                ->instance();

            $opciones = $componente->form->getFlatFields()['field_report_id']->getOptions();
            $etiqueta = $opciones[$report->id];

            // La etiqueta usa el mismo día calendario que el created_at
            // crudo del registro: no le aplica una conversión propia que la
            // corra un día respecto al dato real.
            $this->assertStringStartsWith($report->created_at->format('Y-m-d'), $etiqueta);

            // Y ese día calendario es el que muestra el resto del panel
            // (FieldReportResource) para el MISMO instante: se reconstruye
            // EXACTAMENTE el mismo cálculo que hace Filament para su columna
            // ->dateTime() (CanFormatState::getTimezone() cae a
            // config('app.timezone') sin un ->timezone() explícito, que
            // ninguno de los dos Resources declara), en vez de asumir un
            // formato ISO que la UI no usa.
            $fechaEsperadaEnTabla = $report->created_at->clone()
                ->setTimezone(config('app.timezone'))
                ->translatedFormat(\Filament\Tables\Table::$defaultDateTimeDisplayFormat);

            Livewire::actingAs($admin)
                ->test(ListFieldReports::class)
                ->assertSee($fechaEsperadaEnTabla);

            // Documentado, no corregido acá (E6-16): con este instante de
            // prueba, el día que muestra TODO el panel (UTC) es el
            // SIGUIENTE al día calendario de Lima en el que un humano real
            // hizo el reporte.
            $this->assertSame('2026-09-23', $report->created_at->format('Y-m-d'));
            $this->assertSame(
                '2026-09-22',
                $report->created_at->clone()->setTimezone('America/Lima')->format('Y-m-d')
            );
        } finally {
            Carbon::setTestNow();
        }
    }

    /* ------------------------------------------------------------------ *
     * Vuelta 2 (2026-09-22) — Bajos de seguridad.
     * ------------------------------------------------------------------ */

    /**
     * Hallazgo Bajo 2: la sección "OT asociadas" del detalle de un reporte
     * de campo exige TAMBIÉN `view_fleet`, no solo `view_field_reports`. Hoy
     * los cuatro roles con `view_field_reports` tienen los dos, así que se
     * le quita `view_fleet` a uno a mano para probar la barrera en sí, no la
     * coincidencia de la matriz vigente (mismo patrón que el test del
     * permiso del Select de arriba).
     */
    public function test_the_associated_work_orders_section_is_hidden_without_view_fleet(): void
    {
        Role::where('name', 'taller')->first()->revokePermissionTo('view_fleet');

        $machine = $this->machine();
        $report = $this->fieldReport($machine);
        $workOrder = $this->workOrder($machine, [
            'code' => 'TIER-LINKED-02',
            'status' => 'in_progress',
            'field_report_id' => $report->id,
        ]);

        $taller = User::where('email', 'taller@dp.local')->firstOrFail();

        Livewire::actingAs($taller)
            ->test(ListFieldReports::class)
            ->mountTableAction('view', $report)
            ->assertDontSee(__('field_reports.detail_work_orders'))
            ->assertDontSee($workOrder->code);
    }

    /**
     * Hallazgo Bajo 3: el Observer deja rastro (`Log::warning`) con el id de
     * la OT, la máquina, el reporte rechazado y el usuario, antes de anular
     * un `field_report_id` que no pertenece a la máquina.
     *
     * Escritura directa sobre una OT YA PERSISTIDA (un `update()`, el mismo
     * camino que "tinker, un job, un payload manipulado" que documenta el
     * Observer) a propósito, y no una creación: en `creating`/`saving` de
     * una OT nueva la base todavía no asignó el id (por eso el log también
     * suma `work_order_code`, que sí está disponible en ese momento), así
     * que probar sobre un `update()` deja comparar el id real sin ambigüedad.
     */
    public function test_the_observer_logs_a_warning_before_clearing_a_mismatched_field_report(): void
    {
        $machine = $this->machine();
        $otherMachine = $this->machine();
        $foreignReport = $this->fieldReport($otherMachine);
        $admin = User::where('email', 'admin@dp.local')->firstOrFail();
        $workOrder = $this->workOrder($machine);

        $this->actingAs($admin);
        Log::spy();

        $workOrder->update(['field_report_id' => $foreignReport->id]);

        Log::shouldHaveReceived('warning')
            ->once()
            ->withArgs(function (string $message, array $context) use ($workOrder, $machine, $foreignReport, $admin): bool {
                return $message === 'work_order.field_report_mismatch_cleared'
                    && $context['work_order_id'] === $workOrder->id
                    && $context['work_order_code'] === $workOrder->code
                    && $context['machine_id'] === $machine->id
                    && $context['rejected_field_report_id'] === $foreignReport->id
                    && $context['user_id'] === $admin->id;
            });

        $this->assertNull($workOrder->fresh()->field_report_id);
    }

    /**
     * Control negativo: sin manipular nada, no se emite ningún warning (que
     * el test de arriba no pase por casualidad si el guard avisara siempre).
     */
    public function test_the_observer_does_not_warn_when_the_field_report_belongs_to_the_machine(): void
    {
        Log::spy();

        $machine = $this->machine();
        $report = $this->fieldReport($machine);
        $this->workOrder($machine, ['field_report_id' => $report->id]);

        Log::shouldNotHaveReceived('warning');
    }
}

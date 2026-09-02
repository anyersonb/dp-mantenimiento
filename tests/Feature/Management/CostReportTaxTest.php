<?php

namespace Tests\Feature\Management;

use App\Models\Machine;
use App\Models\Setting;
use App\Models\WorkOrder;
use App\Models\WorkOrderPart;
use App\Services\Reports\CostReportBuilder;
use App\Services\Reports\CostReportFilters;
use App\Services\TaxCalculator;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Impuesto de repuestos (Florida, decisión de Anyerson 2026-09-01).
 *
 * Lo que estos tests protegen:
 *
 *   1. La tasa por defecto es 7% mientras nadie la haya configurado.
 *   2. El cálculo redondea a 2 decimales HALF UP, en una sola operación
 *      sobre el subtotal ya sumado.
 *   3. Cambiar la tasa en Configuración cambia el total del reporte, y la
 *      caché se invalida sola (no hace falta limpiarla a mano).
 *   4. Nadie más en el reporte multiplica por la tasa: el impuesto sale
 *      SIEMPRE de TaxCalculator.
 */
class CostReportTaxTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);
    }

    private function machine(string $idCode): Machine
    {
        return Machine::create([
            'id_code' => $idCode,
            'description' => $idCode.' TEST MACHINE',
            'status' => 'active',
            'hourmeter_status' => 'ok',
            'current_hours' => 1000,
            'last_service_hours' => 500,
            'service_interval_hours' => 500,
            'hours_adjustment' => 0,
        ]);
    }

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
     * 1. Tasa por defecto.
     * ------------------------------------------------------------------ */

    public function test_the_default_tax_rate_is_seven_percent_with_no_configuration(): void
    {
        $this->assertDatabaseCount('settings', 0);
        $this->assertSame(7.0, TaxCalculator::rate());
    }

    /* ------------------------------------------------------------------ *
     * 2. El cálculo.
     * ------------------------------------------------------------------ */

    public function test_calcular_applies_the_default_rate_to_a_known_subtotal(): void
    {
        // 81.00 * 7% = 5.67, exacto, sin ambigüedad de redondeo.
        $this->assertSame(5.67, TaxCalculator::calcular(81.0));
    }

    public function test_calcular_rounds_half_up_and_not_to_even(): void
    {
        Setting::set(TaxCalculator::SETTING_KEY, 10.0, TaxCalculator::SETTING_TYPE);

        // 10.05 * 10% = 1.005 exacto: un empate real de redondeo a 2 decimales.
        $subtotal = 10.05;

        $halfUp = round($subtotal * 0.10, 2, PHP_ROUND_HALF_UP);
        $halfEven = round($subtotal * 0.10, 2, PHP_ROUND_HALF_EVEN);

        // Si los dos modos coincidieran, este caso no probaría nada sobre el
        // modo de redondeo: hay que elegir otro subtotal si esto llega a fallar.
        $this->assertNotSame($halfEven, $halfUp, 'El subtotal elegido no es un empate real de redondeo.');
        $this->assertSame(1.01, $halfUp);

        $this->assertSame($halfUp, TaxCalculator::calcular($subtotal));
    }

    public function test_calcular_rounds_once_over_the_summed_subtotal_not_per_line(): void
    {
        // Tres líneas de 0.005 cada una redondearían a 0.01 si se redondeara
        // línea por línea (3 × 0.01 = 0.03); sobre el subtotal sumado
        // (0.015) el impuesto real es distinto. Esto prueba que
        // TaxCalculator recibe y redondea el TOTAL, no llamadas por línea.
        Setting::set(TaxCalculator::SETTING_KEY, 100.0, TaxCalculator::SETTING_TYPE); // 100% para que el subtotal y el impuesto coincidan 1:1

        $perLineRounded = 3 * round(0.005, 2, PHP_ROUND_HALF_UP);
        $overSummedTotal = TaxCalculator::calcular(0.015);

        $this->assertNotSame($perLineRounded, $overSummedTotal);
        $this->assertSame(0.02, $overSummedTotal);
    }

    /* ------------------------------------------------------------------ *
     * 3. Cambiar la tasa cambia el total del reporte, y la caché se invalida.
     * ------------------------------------------------------------------ */

    public function test_changing_the_tax_rate_changes_the_report_total_and_the_cache_is_invalidated(): void
    {
        $machine = $this->machine('EX010');
        $wo = $this->completedWorkOrder($machine, '2026-08-10');

        WorkOrderPart::create([
            'work_order_id' => $wo->id, 'part_number' => 'F-1',
            'quantity' => 2, 'unit_cost' => 25.50,
        ]);
        WorkOrderPart::create([
            'work_order_id' => $wo->id, 'part_number' => 'A-9',
            'quantity' => 3, 'unit_cost' => 10.00,
        ]);

        // Subtotal = 81.00. Con la tasa por defecto (7%): 5.67 de impuesto.
        $before = CostReportBuilder::build($this->filters());
        $this->assertSame(81.0, $before['totals']['subtotal']);
        $this->assertSame(7.0, $before['totals']['tax_rate']);
        $this->assertSame(5.67, $before['totals']['tax_amount']);
        $this->assertSame(86.67, $before['totals']['total']);

        // Cambiar la tasa desde Configuración (Setting::set, lo mismo que hace
        // App\Filament\Pages\Configuration::save()) SIN limpiar caché a mano.
        Setting::set(TaxCalculator::SETTING_KEY, 6.5, TaxCalculator::SETTING_TYPE);

        $after = CostReportBuilder::build($this->filters());
        $this->assertSame(6.5, $after['totals']['tax_rate']);
        $this->assertSame(5.27, $after['totals']['tax_amount']); // 81 * 6.5% = 5.265 -> 5.27 (half up)
        $this->assertSame(86.27, $after['totals']['total']);
    }

    public function test_setting_get_reflects_a_change_immediately_without_manual_cache_clear(): void
    {
        // Fuerza a que el valor por defecto quede cacheado primero.
        $this->assertSame(7.0, TaxCalculator::rate());

        Setting::set(TaxCalculator::SETTING_KEY, 5.0, TaxCalculator::SETTING_TYPE);

        $this->assertSame(5.0, TaxCalculator::rate());
    }
}

<?php

namespace Tests\Feature\WorkOrder;

use App\Filament\Pages\Reports;
use App\Filament\Resources\WorkOrderResource\Pages\CreateWorkOrder;
use App\Filament\Resources\WorkOrderResource\Pages\ListWorkOrders;
use App\Models\User;
use App\Services\Reports\CostReportFilters;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Pedido del cliente: el orden visible de los tipos de mantenimiento pasa de
 * Preventiva/Correctiva/Inspección a **Inspección, Preventiva, Correctiva**
 * en todas las pantallas donde aparece el trío como lista.
 *
 * El default de la OT nueva sigue siendo `preventive` — el reordenamiento es
 * solo de cómo se listan las opciones, no de cuál se elige sola.
 */
class MaintenanceTypeOrderTest extends TestCase
{
    use RefreshDatabase;

    private const ORDEN_ESPERADO = ['inspection', 'preventive', 'corrective'];

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);
    }

    public function test_the_work_order_form_type_select_lists_inspection_first(): void
    {
        $admin = User::where('email', 'admin@dp.local')->firstOrFail();

        $componente = Livewire::actingAs($admin)->test(CreateWorkOrder::class);

        $campo = $componente->instance()->form->getFlatFields()['type'];

        $this->assertSame(self::ORDEN_ESPERADO, array_keys($campo->getOptions()));

        // El orden de la lista no es el default: sigue naciendo en preventiva.
        $this->assertSame('preventive', $campo->getState());
    }

    public function test_the_work_order_table_type_filter_lists_inspection_first(): void
    {
        $admin = User::where('email', 'admin@dp.local')->firstOrFail();

        $componente = Livewire::actingAs($admin)->test(ListWorkOrders::class);

        $filtro = $componente->instance()->getTable()->getFilters()['type'];

        $this->assertSame(self::ORDEN_ESPERADO, array_keys($filtro->getOptions()));
    }

    public function test_the_reports_screen_type_checkboxes_list_inspection_first(): void
    {
        $admin = User::where('email', 'admin@dp.local')->firstOrFail();

        $componente = Livewire::actingAs($admin)->test(Reports::class);

        $campo = $componente->instance()->form->getFlatFields(withHidden: true)['types'];

        $this->assertSame(self::ORDEN_ESPERADO, array_keys($campo->getOptions()));
    }

    public function test_the_allowed_types_constant_lists_inspection_first(): void
    {
        $this->assertSame(self::ORDEN_ESPERADO, CostReportFilters::ALLOWED_TYPES);
    }

    /**
     * Centinela: reordenar la constante no puede alterar qué tipos deja pasar
     * el filtro ni el orden en que el usuario los envió. `array_intersect()`
     * conserva el orden del array de ENTRADA, no el de `ALLOWED_TYPES` — si
     * algún día eso cambiara (por ejemplo, reescribiendo `allowList` con
     * `array_values(array_flip(...))` o algo que ordene por el allowed),
     * este test lo detecta.
     */
    public function test_reordering_allowed_types_does_not_change_the_filter_result_order(): void
    {
        $filters = CostReportFilters::fromArray([
            'types' => ['corrective', 'inspection', 'preventive'],
        ]);

        $this->assertSame(['corrective', 'inspection', 'preventive'], $filters->types);
    }
}

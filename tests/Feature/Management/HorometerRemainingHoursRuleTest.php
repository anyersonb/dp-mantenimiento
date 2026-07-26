<?php

namespace Tests\Feature\Management;

use App\Filament\Resources\MachineResource\Pages\ListMachines;
use App\Models\Alert;
use App\Models\HorometerReading;
use App\Models\Machine;
use App\Models\User;
use App\Services\HourmeterReplacementService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;

/**
 * Hallazgo A4 (qa-etapa05/regla-horometro.md): remaining_hours se ancla al
 * dato verificado del PM Service Report y se descuenta desde ahí, en vez de
 * recalcularse desde cero con una fórmula que aplicaba hours_adjustment de
 * un solo lado y no validaba que last_service_hours y current_hours
 * estuvieran en la misma escala de horómetro.
 */
class HorometerRemainingHoursRuleTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);
    }

    /**
     * EX013: hours_adjustment = 5714, remaining_hours = 234 (el que trajo el
     * PM report). Con la fórmula vieja, la próxima lectura de campo lo dejaba
     * en 500 - ((5808 + 5714) - 5542) = -5480. Con el ancla, debe descontar
     * 234 - (5850 - 5808) = 192.
     */
    public function test_ex013_field_reading_after_the_anchor_does_not_go_negative(): void
    {
        $machine = Machine::create([
            'id_code' => 'EX013',
            'status' => 'active',
            'hourmeter_status' => 'ok',
            'current_hours' => 5808,
            'last_service_hours' => 5542,
            'service_interval_hours' => 500,
            'hours_adjustment' => 5714,
            'remaining_hours' => 234,
            'remaining_anchor_hours' => 234,
            'remaining_anchor_at_hours' => 5808,
        ]);

        HorometerReading::create([
            'machine_id' => $machine->id,
            'hours' => 5850,
            'read_at' => now()->toDateString(),
            'source' => 'maintenance',
        ]);

        $machine->refresh();

        $this->assertSame(192, $machine->remaining_hours);
        $this->assertNotSame(-5480, $machine->remaining_hours);
    }

    /**
     * PJ001: horómetro roto, last_service_hours (10455) > current_hours
     * (4901): escalas distintas. La fórmula vieja daba 500 - (4901 - 10455)
     * = 6054 ("no necesita servicio nunca"). Debe quedar en NULL.
     */
    public function test_pj001_stays_null_when_the_hourmeter_is_broken_and_scales_dont_match(): void
    {
        $machine = Machine::create([
            'id_code' => 'PJ001',
            'status' => 'active',
            'hourmeter_status' => 'broken',
            'current_hours' => 4901,
            'last_service_hours' => 10455,
            'service_interval_hours' => 500,
            'hours_adjustment' => 0,
            'remaining_hours' => null,
        ]);

        HorometerReading::create([
            'machine_id' => $machine->id,
            'hours' => 4950,
            'read_at' => now()->toDateString(),
            'source' => 'maintenance',
        ]);

        $machine->refresh();

        $this->assertNull($machine->remaining_hours);
        $this->assertNotSame(6054, $machine->remaining_hours);
        $this->assertNotSame(6005, $machine->remaining_hours);
    }

    /** MS003: sin ninguna información de horómetro. Debe seguir en NULL. */
    public function test_ms003_without_hourmeter_info_stays_null(): void
    {
        $machine = Machine::create([
            'id_code' => 'MS003',
            'status' => 'active',
            'hourmeter_status' => 'no_info',
            'current_hours' => null,
            'last_service_hours' => null,
            'service_interval_hours' => 500,
            'remaining_hours' => null,
        ]);

        HorometerReading::create([
            'machine_id' => $machine->id,
            'hours' => 50,
            'read_at' => now()->toDateString(),
            'source' => 'maintenance',
        ]);

        $this->assertNull($machine->refresh()->remaining_hours);
    }

    /** Una lectura de campo normal (sin ancla, escalas coherentes) sí baja las horas restantes. */
    public function test_a_normal_field_reading_lowers_the_remaining_hours(): void
    {
        $machine = Machine::create([
            'id_code' => 'TST-'.random_int(1000, 9999),
            'status' => 'active',
            'hourmeter_status' => 'ok',
            'current_hours' => 100,
            'last_service_hours' => 50,
            'service_interval_hours' => 500,
            'remaining_hours' => 450,
        ]);

        HorometerReading::create([
            'machine_id' => $machine->id,
            'hours' => 150,
            'read_at' => now()->toDateString(),
            'source' => 'maintenance',
        ]);

        $machine->refresh();

        $this->assertSame(400, $machine->remaining_hours);
        $this->assertLessThan(450, $machine->remaining_hours);
    }

    /**
     * EX010 (caso sano, invariante #2 del spec): el ancla que dejó el PM
     * report (415 @ 9793) sobrevive a una lectura de campo posterior, que
     * descuenta desde ahí: 415 - (9850 - 9793) = 358.
     */
    public function test_the_pm_report_anchor_survives_a_later_field_reading(): void
    {
        $machine = Machine::create([
            'id_code' => 'EX010',
            'status' => 'active',
            'hourmeter_status' => 'ok',
            'current_hours' => 9793,
            'last_service_hours' => 9708,
            'service_interval_hours' => 500,
            'remaining_hours' => 415,
            'remaining_anchor_hours' => 415,
            'remaining_anchor_at_hours' => 9793,
        ]);

        HorometerReading::create([
            'machine_id' => $machine->id,
            'hours' => 9850,
            'read_at' => now()->toDateString(),
            'source' => 'maintenance',
        ]);

        $this->assertSame(358, $machine->refresh()->remaining_hours);
    }

    /**
     * Evento de reemplazo de horómetro (sec. 2.2): re-ancla el seguimiento
     * de servicio a la escala nueva y queda en la bitácora como su propio
     * evento, no como un "updated" más.
     */
    public function test_hourmeter_replacement_service_reanchors_and_logs_its_own_event(): void
    {
        $admin = User::where('email', 'admin@dp.local')->firstOrFail();

        $machine = Machine::create([
            'id_code' => 'MS-TEMP-02',
            'status' => 'active',
            'hourmeter_status' => 'ok',
            'current_hours' => 520,
            'last_service_hours' => 100,
            'service_interval_hours' => 500,
            'hours_adjustment' => 0,
            'remaining_hours' => 80,
        ]);

        app(HourmeterReplacementService::class)->replace($machine, 520, 3, 'Swapped after failure', $admin);

        $machine->refresh();

        $this->assertSame('replaced', $machine->hourmeter_status);
        $this->assertSame(3, $machine->current_hours);
        $this->assertSame(3, $machine->last_service_hours);
        $this->assertSame(500, $machine->remaining_anchor_hours);
        $this->assertSame(3, $machine->remaining_anchor_at_hours);
        $this->assertSame(500, $machine->remaining_hours);

        $this->assertDatabaseHas('activity_log', [
            'subject_type' => Machine::class,
            'subject_id' => $machine->id,
            'event' => 'hourmeter_replaced',
            'causer_id' => $admin->id,
        ]);

        // No debe quedar además registrado como un "updated" genérico.
        $this->assertSame(
            0,
            Activity::where('subject_type', Machine::class)
                ->where('subject_id', $machine->id)
                ->where('event', 'updated')
                ->count()
        );
    }

    public function test_manage_machines_can_run_the_replace_hourmeter_action_from_filament(): void
    {
        $admin = User::where('email', 'admin@dp.local')->firstOrFail();

        $machine = Machine::create([
            'id_code' => 'MS-TEMP-03',
            'status' => 'active',
            'hourmeter_status' => 'ok',
            'current_hours' => 700,
            'last_service_hours' => 200,
            'service_interval_hours' => 500,
            'remaining_hours' => 0,
        ]);

        Livewire::actingAs($admin)
            ->test(ListMachines::class)
            ->callTableAction('replaceHourmeter', $machine, data: [
                'old_final_hours' => 700,
                'new_initial_hours' => 0,
                'note' => 'Broken beyond repair',
            ])
            ->assertHasNoTableActionErrors();

        $machine->refresh();
        $this->assertSame('replaced', $machine->hourmeter_status);
        $this->assertSame(0, $machine->current_hours);
    }

    public function test_a_user_without_manage_machines_does_not_see_the_replace_hourmeter_action(): void
    {
        $taller = User::where('email', 'taller@dp.local')->firstOrFail();

        $machine = Machine::create([
            'id_code' => 'MS-TEMP-04',
            'status' => 'active',
            'hourmeter_status' => 'ok',
            'current_hours' => 100,
        ]);

        Livewire::actingAs($taller)
            ->test(ListMachines::class)
            ->assertTableActionHidden('replaceHourmeter', $machine);
    }

    /**
     * Pendiente detectado tras el fix: Machine::getComputedRemainingHoursAttribute()
     * tenía una segunda copia de la fórmula rota (el "lector"), usada como
     * fallback cuando remaining_hours es NULL. Aunque el observer (el
     * "escritor") ya dejaba a PJ001 en NULL, el semáforo/alertas seguían
     * mostrando 6054 porque consultaban este accessor. Ahora ambos llaman a
     * Machine::calculateRemainingHours(), una sola implementación.
     */
    public function test_pj001_computed_remaining_hours_and_service_status_are_unknown_not_a_number(): void
    {
        $machine = Machine::create([
            'id_code' => 'PJ001',
            'status' => 'active',
            'hourmeter_status' => 'broken',
            'current_hours' => 4901,
            'last_service_hours' => 10455,
            'service_interval_hours' => 500,
            'hours_adjustment' => 0,
            'remaining_hours' => null,
        ]);

        $this->assertNull($machine->computed_remaining_hours);
        $this->assertNotSame(6054, $machine->computed_remaining_hours);
        $this->assertSame('unknown', $machine->service_status);
        $this->assertFalse($machine->is_due_soon);
        $this->assertFalse($machine->is_overdue);
    }

    /** Antes del fix, alerts:scan usaba computed_remaining_hours y abría una alerta con el 6054 inventado. */
    public function test_alerts_scan_does_not_create_an_alert_for_pj001(): void
    {
        Machine::create([
            'id_code' => 'PJ001',
            'status' => 'active',
            'hourmeter_status' => 'broken',
            'current_hours' => 4901,
            'last_service_hours' => 10455,
            'service_interval_hours' => 500,
            'hours_adjustment' => 0,
            'remaining_hours' => null,
        ]);

        Artisan::call('alerts:scan');

        $this->assertSame(0, Alert::where('type', 'service')->count());
    }

    /** El accessor no debe verse afectado por hours_adjustment (mismo defecto que tenía el observer). */
    public function test_ex013_computed_remaining_hours_is_not_affected_by_hours_adjustment(): void
    {
        $machine = Machine::create([
            'id_code' => 'EX013',
            'status' => 'active',
            'hourmeter_status' => 'ok',
            'current_hours' => 5808,
            'last_service_hours' => 5542,
            'service_interval_hours' => 500,
            'hours_adjustment' => 5714,
            'remaining_hours' => null, // fuerza el fallback del accessor
        ]);

        // Clásico sin ancla: 500 - (5808 - 5542) = 234. Con el defecto viejo
        // habría dado 500 - ((5808 + 5714) - 5542) = -5480.
        $this->assertSame(234, $machine->computed_remaining_hours);
    }

    /** El accessor sigue devolviendo tal cual el remaining_hours verificado cuando no es NULL. */
    public function test_a_verified_remaining_hours_is_returned_as_is_by_the_accessor(): void
    {
        $machine = Machine::create([
            'id_code' => 'EX010',
            'status' => 'active',
            'hourmeter_status' => 'ok',
            'current_hours' => 9793,
            'last_service_hours' => 9708,
            'service_interval_hours' => 500,
            'remaining_hours' => 415,
        ]);

        $this->assertSame(415, $machine->computed_remaining_hours);
    }
}

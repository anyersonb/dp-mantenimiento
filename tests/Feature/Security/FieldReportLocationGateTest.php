<?php

namespace Tests\Feature\Security;

use App\Filament\Resources\FieldReportResource\Pages\ListFieldReports;
use App\Models\FieldReport;
use App\Models\Location;
use App\Models\Machine;
use App\Models\User;
use App\Support\AccessControl;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Permiso `view_field_report_location` (hallazgo 2, auditoria 2026-09-18):
 * la ubicacion GPS dentro del detalle de un reporte de campo es seguimiento
 * de personal, no mantenimiento de flota. Reparto MAS ANGOSTO que
 * `view_field_reports`: solo administrador y responsable_mantenimiento ven
 * esa seccion; taller y gerencia ven el resto del detalle completo (maquina,
 * estado, notas, horometro, reportero) pero SIN la ubicacion.
 *
 * Trampa conocida (ver feedback_asserdontsee_texto_compartido_dos_secciones
 * en la memoria del agente): el texto de "Sin ubicacion"
 * (field_reports.location_no) aparece TAMBIEN en el badge del listado,
 * visible para todos a proposito -- un assertDontSee simple da falso rojo.
 * Se resuelve contando apariciones exactas en el HTML crudo: 1 = solo el
 * badge del listado, 2 = badge + seccion de detalle.
 */
class FieldReportLocationGateTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);
    }

    private function reportWithLocation(): FieldReport
    {
        $location = Location::create(['name' => 'GPS Yard', 'slug' => 'gps-yard-'.uniqid()]);
        $machine = Machine::create([
            'id_code' => 'GPS-'.random_int(1000, 9999),
            'status' => 'active',
            'current_location_id' => $location->id,
        ]);
        $worker = User::where('email', 'campo@dp.local')->firstOrFail();

        return FieldReport::create([
            'machine_id' => $machine->id,
            'reported_by' => $worker->id,
            'location_id' => $location->id,
            'condition' => 'critical',
            'notes' => 'Brake fluid leak on the left side',
            'hours' => 999,
            'latitude' => 26.1111111,
            'longitude' => -80.1111111,
        ]);
    }

    private function reportWithoutLocation(): FieldReport
    {
        $location = Location::create(['name' => 'No GPS Yard', 'slug' => 'no-gps-yard-'.uniqid()]);
        $machine = Machine::create([
            'id_code' => 'NOGPS-'.random_int(1000, 9999),
            'status' => 'active',
            'current_location_id' => $location->id,
        ]);
        $worker = User::where('email', 'campo@dp.local')->firstOrFail();

        return FieldReport::create([
            'machine_id' => $machine->id,
            'reported_by' => $worker->id,
            'location_id' => $location->id,
            'condition' => 'ok',
        ]);
    }

    /**
     * El gate de negocio: quien puede y quien no, via el punto unico
     * (App\Support\AccessControl::allows()).
     */
    public function test_the_permission_gate_matches_the_narrower_distribution(): void
    {
        foreach (['admin@dp.local', 'responsable@dp.local'] as $email) {
            $user = User::where('email', $email)->firstOrFail();
            $this->assertTrue(
                AccessControl::allows($user, 'view_field_report_location'),
                "{$email} deberia ver la ubicacion GPS."
            );
        }

        foreach (['taller@dp.local', 'gerencia@dp.local'] as $email) {
            $user = User::where('email', $email)->firstOrFail();
            $this->assertFalse(
                AccessControl::allows($user, 'view_field_report_location'),
                "{$email} NO deberia ver la ubicacion GPS."
            );
        }
    }

    /**
     * Administrador y responsable_mantenimiento ven el enlace al mapa (la
     * seccion de ubicacion) dentro del detalle.
     */
    public function test_admin_and_responsable_see_the_map_link_in_the_report_detail(): void
    {
        $report = $this->reportWithLocation();

        foreach (['admin@dp.local', 'responsable@dp.local'] as $email) {
            $user = User::where('email', $email)->firstOrFail();

            $html = Livewire::actingAs($user)->test(ListFieldReports::class)
                ->mountTableAction('view', $report)
                ->html();

            $this->assertStringContainsString(__('field_reports.detail_map_link'), $html);
        }
    }

    /**
     * Taller y gerencia NO ven el enlace al mapa NI la latitud/longitud en
     * crudo, pero SI ven el resto del detalle completo (maquina, notas,
     * horometro, reportero) -- el permiso mas angosto no les cierra la
     * pantalla, solo la seccion de ubicacion.
     */
    public function test_taller_and_gerencia_see_the_rest_of_the_detail_but_never_the_location(): void
    {
        $report = $this->reportWithLocation();

        foreach (['taller@dp.local', 'gerencia@dp.local'] as $email) {
            $user = User::where('email', $email)->firstOrFail();

            $html = Livewire::actingAs($user)->test(ListFieldReports::class)
                ->mountTableAction('view', $report)
                ->html();

            // Resto del detalle sigue completo.
            $this->assertStringContainsString($report->machine->id_code, $html, "{$email} deberia ver la maquina.");
            $this->assertStringContainsString('Brake fluid leak on the left side', $html, "{$email} deberia ver las notas.");
            $this->assertStringContainsString($report->reporter->name, $html, "{$email} deberia ver el reportero.");
            $this->assertStringContainsString(__('field_reports.condition_critical'), $html, "{$email} deberia ver el estado.");

            // Pero nada de la ubicacion: ni el enlace, ni la latitud/longitud crudas.
            $this->assertStringNotContainsString(__('field_reports.detail_map_link'), $html, "{$email} NO deberia ver el enlace al mapa.");
            $this->assertStringNotContainsString((string) $report->latitude, $html, "{$email} NO deberia ver la latitud cruda.");
            $this->assertStringNotContainsString((string) $report->longitude, $html, "{$email} NO deberia ver la longitud cruda.");
        }
    }

    /**
     * La trampa del texto compartido: "Sin ubicacion" aparece 1 vez (badge
     * del listado, visible para todos) para quien NO tiene el permiso, y 2
     * veces (badge + seccion de detalle) para quien SI lo tiene. Si el
     * permiso se revirtiera y la seccion de detalle se mostrara a todos, el
     * conteo de taller subiria a 2 y este test lo atraparia.
     */
    public function test_the_shared_no_location_text_appears_once_as_badge_and_twice_with_the_detail_section(): void
    {
        $report = $this->reportWithoutLocation();

        $taller = User::where('email', 'taller@dp.local')->firstOrFail();
        $tallerHtml = Livewire::actingAs($taller)->test(ListFieldReports::class)
            ->mountTableAction('view', $report)
            ->html();

        $this->assertSame(
            1,
            substr_count($tallerHtml, __('field_reports.location_no')),
            'taller solo deberia ver el badge del listado (1 aparicion).'
        );

        $admin = User::where('email', 'admin@dp.local')->firstOrFail();
        $adminHtml = Livewire::actingAs($admin)->test(ListFieldReports::class)
            ->mountTableAction('view', $report)
            ->html();

        $this->assertSame(
            2,
            substr_count($adminHtml, __('field_reports.location_no')),
            'admin deberia ver el badge del listado MAS el aviso de la seccion de detalle (2 apariciones).'
        );
    }
}

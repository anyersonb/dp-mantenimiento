<?php

namespace Tests\Feature\Security;

use App\Models\FieldReport;
use App\Models\Location;
use App\Models\Machine;
use App\Support\Notifications\NotificationRegistry;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

/**
 * Hallazgo 1 (auditoria 2026-09-18): en la ventana de despliegue por FTP
 * (permiso todavia sin migrar), `AccessControl::activeUsersWith()` puede
 * devolver cero filas y `NotificationRegistry::dispatch()` se quedaba en un
 * `return` silencioso -- un aviso critico se perdia sin dejar rastro.
 *
 * Reproduce el escenario sembrando SOLO roles/permisos (sin los usuarios
 * demo, como en produccion) para que `view_field_reports` no tenga a nadie
 * detras, y llama a `NotificationRegistry::dispatch()` directo.
 */
class NotificationRegistryFallbackWarningTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Roles y permisos si se siembran en produccion; los usuarios demo
        // no -- mismo mecanismo que el hallazgo 4. Sin usuarios, ningun rol
        // tiene a nadie detras y activeUsersWith('view_field_reports') queda
        // vacio, que es exactamente el escenario que este test cubre.
        $this->app['env'] = 'production';
        (new RolesAndPermissionsSeeder)->run();
        $this->app['env'] = 'testing';

        $this->assertSame(0, \App\Models\User::count(), 'precondicion: cero usuarios, para que dispatch() no tenga a quien notificar.');
    }

    /**
     * Condicion 'ok' A PROPOSITO: FieldReportObserver::created() tambien
     * llama a NotificationRegistry::dispatch() para reportes 'critical', y
     * eso duplicaria el warning que estos tests cuentan (el disparo real del
     * observer YA esta cubierto por FieldReportNotificationsTest). Acá se
     * llama dispatch() UNA sola vez, explicito, para aislar el
     * comportamiento del propio NotificationRegistry.
     */
    private function subjectWithSensitiveData(): FieldReport
    {
        $location = Location::create(['name' => 'No Recipients Yard', 'slug' => 'nr-yard-'.uniqid()]);
        $machine = Machine::create([
            'id_code' => 'NR-'.random_int(1000, 9999),
            'status' => 'active',
            'current_location_id' => $location->id,
        ]);

        return FieldReport::create([
            'machine_id' => $machine->id,
            'reported_by' => null,
            'location_id' => $location->id,
            'condition' => 'ok',
            'notes' => 'Secret brake fluid leak, do not disclose',
            'latitude' => -12.0464000,
            'longitude' => -77.0428000,
        ]);
    }

    public function test_dispatch_without_recipients_logs_a_warning_instead_of_failing_silently(): void
    {
        Log::spy();

        NotificationRegistry::dispatch('field_report.needs_attention', $this->subjectWithSensitiveData());

        Log::shouldHaveReceived('warning')
            ->withArgs(function (string $message, array $context): bool {
                return $message === 'notification_registry.no_recipients'
                    && $context['event'] === 'field_report.needs_attention'
                    && $context['permission'] === 'view_field_reports'
                    && isset($context['subject_type'], $context['subject_id']);
            })
            ->once();
    }

    /**
     * Falsacion explicita: el contexto del log NO debe filtrar el contenido
     * del reporte (notas, coordenadas) -- solo lo minimo para diagnosticar
     * (evento, tipo/id del modelo, permiso).
     */
    public function test_the_logged_context_never_includes_notes_or_coordinates(): void
    {
        Log::spy();

        NotificationRegistry::dispatch('field_report.needs_attention', $this->subjectWithSensitiveData());

        Log::shouldHaveReceived('warning')
            ->withArgs(function (string $message, array $context): bool {
                $serialized = json_encode($context);

                return ! array_key_exists('notes', $context)
                    && ! array_key_exists('latitude', $context)
                    && ! array_key_exists('longitude', $context)
                    && ! str_contains($serialized, 'brake fluid leak')
                    && ! str_contains($serialized, '-12.0464')
                    && ! str_contains($serialized, '-77.0428');
            })
            ->once();
    }

    public function test_dispatch_does_not_throw_and_creates_no_notification_rows(): void
    {
        NotificationRegistry::dispatch('field_report.needs_attention', $this->subjectWithSensitiveData());

        $this->assertSame(0, DB::table('notifications')->count());
    }

    /**
     * Control: con al menos un destinatario real, NO se emite el warning de
     * "sin destinatarios" (para que el test de arriba no pase por casualidad
     * si el guard se rompiera al reves, avisando siempre).
     */
    public function test_it_does_not_warn_when_there_is_at_least_one_recipient(): void
    {
        $this->seed(RolesAndPermissionsSeeder::class);

        Log::spy();

        NotificationRegistry::dispatch('field_report.needs_attention', $this->subjectWithSensitiveData());

        Log::shouldNotHaveReceived('warning');
    }
}

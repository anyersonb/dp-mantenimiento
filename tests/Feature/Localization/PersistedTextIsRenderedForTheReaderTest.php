<?php

namespace Tests\Feature\Localization;

use App\Models\ActivityLog;
use App\Models\Alert;
use App\Models\ChecklistResult;
use App\Models\HorometerReading;
use App\Models\Location;
use App\Models\Machine;
use App\Models\User;
use App\Models\WorkOrder;
use App\Services\HourmeterReplacementService;
use App\Services\WorkOrderCompletionService;
use App\Support\LocalizedText;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Hallazgo E6-10 — el texto que se guarda no tiene idioma.
 *
 * Medido en la base real antes del fix: las 6 alertas en inglés (las disparó una
 * cuenta en inglés; el administrador trabaja en español), las 93 notas de
 * lectura en español, y 4 descripciones distintas de bitácora en español, dos de
 * ellas hardcodeadas sin pasar por los archivos de idioma. Cambiar el idioma del
 * panel no cambiaba nada: el texto ya estaba en la tabla.
 *
 * Las aserciones son sobre **lo guardado** y sobre **lo que lee cada idioma**,
 * nunca sobre un mensaje de pantalla.
 */
class PersistedTextIsRenderedForTheReaderTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);
    }

    private function machine(array $overrides = []): Machine
    {
        $location = Location::create(['name' => 'Patio', 'slug' => 'patio-'.uniqid()]);

        return Machine::create(array_merge([
            'id_code' => 'I18N-'.random_int(1000, 9999),
            'status' => 'active',
            'hourmeter_status' => 'ok',
            'current_location_id' => $location->id,
            'current_hours' => 1000,
            'last_service_hours' => 600,
            'service_interval_hours' => 500,
            'hours_adjustment' => 0,
        ], $overrides));
    }

    /* ------------------------------------------------------------------ *
     * Alertas: el caso exacto del hallazgo.
     * ------------------------------------------------------------------ */

    public function test_an_alert_raised_in_english_is_read_in_spanish(): void
    {
        App::setLocale('en');

        // Una lectura que cruza el umbral levanta la alerta por el camino real.
        $machine = $this->machine(['current_hours' => 1000, 'last_service_hours' => 600]);
        HorometerReading::create([
            'machine_id' => $machine->id,
            'hours' => 1050,
            'read_at' => now()->toDateString(),
            'source' => 'manual',
        ]);

        $alert = Alert::where('machine_id', $machine->id)->firstOrFail();

        // 1. Lo GUARDADO no es una frase: es la clave con sus parámetros.
        $guardado = DB::table('alerts')->where('id', $alert->id)->value('title');
        $this->assertStringContainsString(LocalizedText::ENVELOPE_KEY, $guardado);
        $this->assertStringContainsString('alerts.auto_title', $guardado);
        $this->assertStringNotContainsString('Service due soon', $guardado);

        // 2. Cada idioma lee lo suyo, sobre la MISMA fila.
        App::setLocale('en');
        $this->assertSame(
            __('alerts.auto_title', ['machine' => $machine->id_code], 'en'),
            $alert->fresh()->title
        );

        App::setLocale('es');
        $enEspanol = $alert->fresh()->title;
        $this->assertSame(__('alerts.auto_title', ['machine' => $machine->id_code], 'es'), $enEspanol);
        $this->assertStringContainsString('Próximo a servicio', $enEspanol);
        $this->assertStringContainsString($machine->id_code, $enEspanol);
    }

    public function test_the_alert_message_carries_its_parameters(): void
    {
        App::setLocale('en');

        $machine = $this->machine(['current_hours' => 1000, 'last_service_hours' => 600]);
        HorometerReading::create([
            'machine_id' => $machine->id,
            'hours' => 1050,
            'read_at' => now()->toDateString(),
            'source' => 'manual',
        ]);

        $alert = Alert::where('machine_id', $machine->id)->firstOrFail();

        App::setLocale('es');
        $mensaje = $alert->fresh()->message;

        // 500 - (1050 - 600) = 50 h restantes
        $this->assertStringContainsString('50', $mensaje);
        $this->assertStringContainsString($machine->id_code, $mensaje);
    }

    /* ------------------------------------------------------------------ *
     * Lo que ya estaba guardado, y el texto de una persona.
     * ------------------------------------------------------------------ */

    public function test_a_plain_sentence_already_stored_keeps_reading_the_same(): void
    {
        $machine = $this->machine();

        // Fila "vieja": texto suelto escrito antes del fix.
        $id = DB::table('alerts')->insertGetId([
            'machine_id' => $machine->id,
            'type' => 'service',
            'title' => 'Service due soon: VIEJA-01',
            'message' => 'texto viejo',
            'status' => 'open',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        App::setLocale('es');
        $this->assertSame('Service due soon: VIEJA-01', Alert::find($id)->title);
    }

    public function test_free_text_written_by_a_person_is_not_touched(): void
    {
        $machine = $this->machine();

        $reading = HorometerReading::create([
            'machine_id' => $machine->id,
            'hours' => 1100,
            'read_at' => now()->toDateString(),
            'source' => 'manual',
            'note' => 'Se cambió el filtro y quedó goteando aceite',
        ]);

        App::setLocale('en');
        $this->assertSame('Se cambió el filtro y quedó goteando aceite', $reading->fresh()->note);
        $this->assertSame(
            'Se cambió el filtro y quedó goteando aceite',
            DB::table('horometer_readings')->where('id', $reading->id)->value('note')
        );
    }

    /* ------------------------------------------------------------------ *
     * Bitácora.
     * ------------------------------------------------------------------ */

    public function test_the_audit_log_description_is_read_in_the_language_of_the_reader(): void
    {
        App::setLocale('en');

        $machine = $this->machine(['current_hours' => 1800]);
        $admin = User::where('email', 'admin@dp.local')->firstOrFail();

        app(HourmeterReplacementService::class)->replace($machine, 1800, 5, null, $admin);

        $asiento = ActivityLog::where('event', 'hourmeter_replaced')->latest('id')->firstOrFail();

        $guardado = DB::table('activity_log')->where('id', $asiento->id)->value('description');
        $this->assertStringContainsString(LocalizedText::ENVELOPE_KEY, $guardado);

        App::setLocale('es');
        $this->assertStringContainsString('Reemplazo de horómetro', $asiento->fresh()->description);

        App::setLocale('en');
        $this->assertStringContainsString(
            __('mgmt.hourmeter_replaced_log', ['machine' => $machine->id_code, 'old' => 1800, 'new' => 5], 'en'),
            $asiento->fresh()->description
        );
    }

    /* ------------------------------------------------------------------ *
     * Las dos búsquedas que dependían del texto: no se pueden romper.
     * ------------------------------------------------------------------ */

    public function test_the_checklist_alert_is_not_duplicated_when_the_locale_changes(): void
    {
        $machine = $this->machine();

        $workOrder = WorkOrder::create([
            'code' => 'I18N-OT-1',
            'machine_id' => $machine->id,
            'type' => 'preventive',
            'status' => 'in_progress',
            'priority' => 'normal',
            'opened_at' => now()->toDateString(),
        ]);

        App::setLocale('en');
        ChecklistResult::create([
            'work_order_id' => $workOrder->id,
            'label' => 'Fuga hidráulica',
            'result' => 'alert',
            'alert_detail' => 'gotea',
        ]);

        // Segundo ítem con el panel en el otro idioma: si la identidad de la
        // alerta fuera el texto traducido, acá nacería una alerta duplicada.
        App::setLocale('es');
        ChecklistResult::create([
            'work_order_id' => $workOrder->id,
            'label' => 'Filtro de aire',
            'result' => 'alert',
            'alert_detail' => 'sucio',
        ]);

        $this->assertSame(
            1,
            Alert::where('machine_id', $machine->id)->where('type', 'checklist')->count(),
            'La alerta de checklist se identifica por lo guardado, no por el idioma.'
        );
    }

    public function test_the_closing_reading_is_not_duplicated_when_the_locale_changes(): void
    {
        $machine = $this->machine(['current_hours' => 1500, 'last_service_hours' => 1000]);

        $workOrder = WorkOrder::create([
            'code' => 'I18N-OT-2',
            'machine_id' => $machine->id,
            'type' => 'preventive',
            'status' => 'in_progress',
            'priority' => 'normal',
            'completed_at' => now()->toDateString(),
            'opened_at' => now()->toDateString(),
        ]);

        App::setLocale('en');
        WorkOrderCompletionService::complete($workOrder->fresh('machine'));

        App::setLocale('es');
        WorkOrderCompletionService::complete($workOrder->fresh('machine'));

        $this->assertSame(
            1,
            HorometerReading::where('machine_id', $machine->id)->where('source', 'workshop')->count(),
            'El cierre es idempotente aunque cambie el idioma entre las dos invocaciones.'
        );
    }

    /* ------------------------------------------------------------------ *
     * Centinela: que no vuelva a aparecer.
     * ------------------------------------------------------------------ */

    public function test_no_writer_persists_an_already_translated_or_hardcoded_description(): void
    {
        $ofensores = [];

        foreach ($this->phpFiles(app_path()) as $archivo) {
            $codigo = file_get_contents($archivo);

            // ->log(__('...'))  o  ->log('frase suelta')
            if (preg_match('/->log\(\s*(__\(|[\'"])/', $codigo, $m, PREG_OFFSET_CAPTURE)) {
                $linea = substr_count(substr($codigo, 0, $m[0][1]), "\n") + 1;
                $ofensores[] = str_replace(base_path().DIRECTORY_SEPARATOR, '', $archivo).':'.$linea;
            }
        }

        $this->assertSame([], $ofensores, implode("\n", [
            'Hay asientos de bitácora que se guardan con el texto ya resuelto:',
            ...array_map(fn ($o) => "  - {$o}", $ofensores),
            '',
            'El idioma del texto quedaría congelado en el del que escribió (hallazgo E6-10).',
            'Usá ->log(LocalizedText::of(\'clave\', [...])->encode()).',
        ]));
    }

    /**
     * @return array<int, string>
     */
    private function phpFiles(string $directorio): array
    {
        $archivos = [];

        $iterador = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($directorio));

        foreach ($iterador as $archivo) {
            if ($archivo->isFile() && $archivo->getExtension() === 'php') {
                $archivos[] = $archivo->getPathname();
            }
        }

        return $archivos;
    }
}

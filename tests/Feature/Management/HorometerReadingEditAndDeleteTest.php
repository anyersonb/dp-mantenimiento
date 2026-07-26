<?php

namespace Tests\Feature\Management;

use App\Filament\Resources\MachineResource\Pages\EditMachine;
use App\Filament\Resources\MachineResource\RelationManagers\ReadingsRelationManager;
use App\Models\HorometerReading;
use App\Models\Location;
use App\Models\Machine;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;

/**
 * Los cuatro casos (a)(b)(c)(d) de la Sesión 1 de la Etapa 06, cada uno con el
 * ESTADO EN BASE DE DATOS como aserción — nunca el mensaje de la UI.
 *
 * Ese detalle no es estilo: durante la sesión que encontró estos hallazgos la
 * automatización informó éxito tres veces sin que pasara nada (el pie del modal
 * de borrado invierte el orden y `.first()` cancelaba), y el "✅ Updated" del
 * tablero del capataz sale igual con horómetro y sin horómetro. La pantalla
 * miente; la tabla no.
 *
 * Escenario común, calcado del que se usó a mano sobre QA-RESP-01:
 * ancla 500 h @ 20 h, y lecturas de 60, 100 y 140 h.
 * Estado esperado de partida: current_hours = 140, remaining_hours = 380,
 * que es 500 − (140 − 20).
 */
class HorometerReadingEditAndDeleteTest extends TestCase
{
    use RefreshDatabase;

    private Machine $machine;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);

        $location = Location::create(['name' => 'Hor Yard', 'slug' => 'hor-yard-'.uniqid()]);

        $this->machine = Machine::create([
            'id_code' => 'HOR-'.random_int(1000, 9999),
            'status' => 'active',
            'hourmeter_status' => 'ok',
            'current_location_id' => $location->id,
            'current_hours' => 20,
            'current_hours_date' => '2026-07-01',
            'last_service_hours' => 20,
            'service_interval_hours' => 500,
            'hours_adjustment' => 0,
            'remaining_hours' => 500,
            'remaining_anchor_hours' => 500,
            'remaining_anchor_at_hours' => 20,
        ]);

        foreach ([['60', '2026-07-10'], ['100', '2026-07-15'], ['140', '2026-07-20']] as [$hours, $date]) {
            HorometerReading::create([
                'machine_id' => $this->machine->id,
                'hours' => (int) $hours,
                'read_at' => $date,
                'source' => 'manual',
                'verified' => true,
            ]);
        }

        $this->machine->refresh();

        // Punto de partida, verificado antes de cada caso.
        $this->assertSame(140, $this->machine->current_hours);
        $this->assertSame(380, $this->machine->remaining_hours);
    }

    private function reading(int $hours): HorometerReading
    {
        return HorometerReading::where('machine_id', $this->machine->id)
            ->where('hours', $hours)
            ->firstOrFail();
    }

    private function asResponsible(): \Livewire\Features\SupportTesting\Testable
    {
        return Livewire::actingAs(User::where('email', 'responsable@dp.local')->firstOrFail())
            ->test(ReadingsRelationManager::class, [
                'ownerRecord' => $this->machine,
                'pageClass' => EditMachine::class,
            ]);
    }

    /* ------------------------------------------------------------------ *
     * (a) Editar la última lectura hacia arriba SÍ recalcula la máquina.
     * ------------------------------------------------------------------ */

    public function test_editing_the_last_reading_recalculates_current_and_remaining_hours(): void
    {
        $this->asResponsible()
            ->callTableAction('edit', $this->reading(140), data: [
                'hours' => 300,
                'read_at' => '2026-07-20',
                'source' => 'manual',
            ])
            ->assertHasNoTableActionErrors();

        $this->machine->refresh();

        // 500 − (300 − 20) = 220
        $this->assertSame(300, $this->machine->current_hours);
        $this->assertSame(220, $this->machine->remaining_hours);
        $this->assertDatabaseHas('horometer_readings', [
            'machine_id' => $this->machine->id,
            'hours' => 300,
        ]);
    }

    /**
     * El caso exacto que se encontró a mano: una lectura intermedia editada por
     * ENCIMA de la última dejaba la máquina 60 h atrasada y sin aviso. Ahora la
     * regla de coherencia lo rechaza, y —esto es lo que se afirma— la base no
     * cambia ni en la lectura ni en la máquina.
     */
    public function test_editing_an_intermediate_reading_above_the_next_one_is_rejected_and_changes_nothing(): void
    {
        $this->asResponsible()
            ->callTableAction('edit', $this->reading(100), data: [
                'hours' => 200,
                'read_at' => '2026-07-15',
                'source' => 'manual',
            ])
            ->assertHasTableActionErrors(['hours']);

        $this->machine->refresh();

        $this->assertDatabaseHas('horometer_readings', ['machine_id' => $this->machine->id, 'hours' => 100]);
        $this->assertDatabaseMissing('horometer_readings', ['machine_id' => $this->machine->id, 'hours' => 200]);
        $this->assertSame(140, $this->machine->current_hours);
        $this->assertSame(380, $this->machine->remaining_hours);
    }

    /* ------------------------------------------------------------------ *
     * (b) Borrar la última lectura revierte el horómetro.
     * ------------------------------------------------------------------ */

    public function test_deleting_the_last_reading_reverts_the_machine_to_the_surviving_readings(): void
    {
        $ultima = $this->reading(140);

        $this->asResponsible()->callTableAction('delete', $ultima);

        $this->assertDatabaseMissing('horometer_readings', ['id' => $ultima->id]);

        $this->machine->refresh();

        // La más alta que sobrevive es 100 h del 15/jul: 500 − (100 − 20) = 420.
        $this->assertSame(100, $this->machine->current_hours);
        $this->assertSame('2026-07-15', $this->machine->current_hours_date->toDateString());
        $this->assertSame(420, $this->machine->remaining_hours);
    }

    /**
     * Sin ninguna lectura, `current_hours` NO se toca: 34 máquinas reales no
     * tienen lecturas y su valor viene del PM Service Report. Recalcular a cero
     * sería destruir dato verificado a mano.
     */
    public function test_deleting_every_reading_does_not_wipe_the_verified_machine_value(): void
    {
        foreach ([140, 100, 60] as $hours) {
            $this->asResponsible()->callTableAction('delete', $this->reading($hours));
        }

        $this->assertSame(0, HorometerReading::where('machine_id', $this->machine->id)->count());

        $this->machine->refresh();

        // Queda en el ancla verificada, no en cero ni en null.
        $this->assertSame(20, $this->machine->current_hours);
        $this->assertSame(500, $this->machine->remaining_hours);
    }

    /* ------------------------------------------------------------------ *
     * (c) La coherencia se valida también por el panel.
     * ------------------------------------------------------------------ */

    public function test_the_panel_rejects_a_reading_that_makes_the_hourmeter_go_backwards_in_time(): void
    {
        // 10 h el 15/jul, cuando el 10/jul ya marcaba 60 h.
        $this->asResponsible()
            ->callTableAction('edit', $this->reading(100), data: [
                'hours' => 10,
                'read_at' => '2026-07-15',
                'source' => 'manual',
            ])
            ->assertHasTableActionErrors(['hours']);

        $this->assertDatabaseMissing('horometer_readings', ['machine_id' => $this->machine->id, 'hours' => 10]);
        $this->assertDatabaseHas('horometer_readings', ['machine_id' => $this->machine->id, 'hours' => 100]);
    }

    public function test_the_panel_rejects_creating_a_reading_below_an_earlier_one(): void
    {
        $this->asResponsible()
            ->callTableAction('create', data: [
                'hours' => 30,
                'read_at' => '2026-07-22',
                'source' => 'manual',
            ])
            ->assertHasTableActionErrors(['hours']);

        $this->assertSame(3, HorometerReading::where('machine_id', $this->machine->id)->count());
    }

    /* ------------------------------------------------------------------ *
     * (d) La bitácora guarda quién, cuándo y el VALOR ANTERIOR.
     * ------------------------------------------------------------------ */

    public function test_editing_a_reading_leaves_an_audit_entry_with_the_previous_value(): void
    {
        $responsable = User::where('email', 'responsable@dp.local')->firstOrFail();
        $ultima = $this->reading(140);

        $antes = Activity::where('subject_type', HorometerReading::class)->count();

        Livewire::actingAs($responsable)
            ->test(ReadingsRelationManager::class, [
                'ownerRecord' => $this->machine,
                'pageClass' => EditMachine::class,
            ])
            ->callTableAction('edit', $ultima, data: [
                'hours' => 300,
                'read_at' => '2026-07-20',
                'source' => 'manual',
            ]);

        $asientos = Activity::where('subject_type', HorometerReading::class)
            ->where('subject_id', $ultima->id)
            ->where('event', 'updated')
            ->get();

        $this->assertGreaterThan($antes, Activity::where('subject_type', HorometerReading::class)->count());
        $this->assertCount(1, $asientos);

        $asiento = $asientos->first();

        $this->assertSame($responsable->id, $asiento->causer_id, 'La bitácora tiene que decir QUIÉN.');
        $this->assertSame(140, (int) $asiento->properties['old']['hours'], 'La bitácora tiene que decir DESDE QUÉ VALOR.');
        $this->assertSame(300, (int) $asiento->properties['attributes']['hours']);
        $this->assertNotNull($asiento->created_at, 'La bitácora tiene que decir CUÁNDO.');
    }

    public function test_deleting_a_reading_leaves_an_audit_entry(): void
    {
        $responsable = User::where('email', 'responsable@dp.local')->firstOrFail();
        $ultima = $this->reading(140);

        Livewire::actingAs($responsable)
            ->test(ReadingsRelationManager::class, [
                'ownerRecord' => $this->machine,
                'pageClass' => EditMachine::class,
            ])
            ->callTableAction('delete', $ultima);

        $asiento = Activity::where('subject_type', HorometerReading::class)
            ->where('subject_id', $ultima->id)
            ->where('event', 'deleted')
            ->first();

        $this->assertNotNull($asiento, 'Borrar una lectura de horómetro no puede pasar sin dejar rastro.');
        $this->assertSame($responsable->id, $asiento->causer_id);

        // En el borrado Spatie mueve `attributes` a `old` y borra `attributes`
        // (LogsActivity.php:329-331). El valor borrado se lee en `old`.
        $this->assertSame(140, (int) $asiento->properties['old']['hours']);
    }
}

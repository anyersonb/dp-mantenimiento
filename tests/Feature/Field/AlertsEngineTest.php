<?php

namespace Tests\Feature\Field;

use App\Mail\ServiceAlertMail;
use App\Models\Alert;
use App\Models\HorometerReading;
use App\Models\Location;
use App\Models\Machine;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class AlertsEngineTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);
    }

    protected function makeMachine(array $overrides = []): Machine
    {
        $location = Location::create(['name' => 'Test Yard', 'slug' => 'test-yard-'.uniqid()]);

        return Machine::create(array_merge([
            'id_code' => 'TST-'.random_int(1000, 9999),
            'status' => 'active',
            'current_location_id' => $location->id,
            'current_hours' => 50,
            'last_service_hours' => 100,
            'service_interval_hours' => 500,
            'hours_adjustment' => 0,
            'remaining_hours' => 550,
        ], $overrides));
    }

    public function test_a_higher_reading_updates_the_machine_and_recalculates_remaining_hours(): void
    {
        $machine = $this->makeMachine();

        HorometerReading::create([
            'machine_id' => $machine->id,
            'hours' => 520,
            'read_at' => now()->toDateString(),
            'source' => 'manual',
        ]);

        $machine->refresh();

        $this->assertSame(520, $machine->current_hours);
        // 500 - ((520 + 0) - 100) = 80
        $this->assertSame(80, $machine->remaining_hours);
    }

    public function test_an_open_service_alert_is_created_when_remaining_hours_drops_to_the_threshold(): void
    {
        $machine = $this->makeMachine();

        HorometerReading::create([
            'machine_id' => $machine->id,
            'hours' => 520,
            'read_at' => now()->toDateString(),
            'source' => 'manual',
        ]);

        $this->assertDatabaseHas('alerts', [
            'machine_id' => $machine->id,
            'type' => 'service',
            'status' => 'open',
            'remaining_hours' => 80,
        ]);
    }

    public function test_a_lower_or_equal_reading_is_ignored_and_does_not_touch_the_machine(): void
    {
        $machine = $this->makeMachine(['current_hours' => 500]);

        HorometerReading::create([
            'machine_id' => $machine->id,
            'hours' => 400,
            'read_at' => now()->toDateString(),
            'source' => 'manual',
        ]);

        $machine->refresh();

        $this->assertSame(500, $machine->current_hours);
    }

    public function test_a_second_reading_does_not_duplicate_an_already_open_alert(): void
    {
        $machine = $this->makeMachine();

        HorometerReading::create([
            'machine_id' => $machine->id, 'hours' => 520, 'read_at' => now()->toDateString(), 'source' => 'manual',
        ]);
        HorometerReading::create([
            'machine_id' => $machine->id, 'hours' => 530, 'read_at' => now()->toDateString(), 'source' => 'manual',
        ]);

        $this->assertSame(1, Alert::where('machine_id', $machine->id)->where('type', 'service')->count());
    }

    public function test_alerts_scan_creates_alerts_for_due_machines_and_emails_administrators(): void
    {
        Mail::fake();

        // Máquina activa, sin lecturas nuevas, pero con snapshot de remaining_hours ya bajo el umbral.
        $machine = $this->makeMachine(['remaining_hours' => 90]);

        Artisan::call('alerts:scan');

        $this->assertDatabaseHas('alerts', [
            'machine_id' => $machine->id,
            'type' => 'service',
            'status' => 'open',
        ]);

        $alert = Alert::where('machine_id', $machine->id)->firstOrFail();
        $this->assertNotNull($alert->notified_at);

        Mail::assertQueued(ServiceAlertMail::class);
    }

    public function test_alerts_scan_is_idempotent_on_a_second_run(): void
    {
        Mail::fake();

        $this->makeMachine(['remaining_hours' => 90]);

        Artisan::call('alerts:scan');
        $this->assertSame(1, Alert::count());

        Artisan::call('alerts:scan');
        $this->assertSame(1, Alert::count());
    }
}

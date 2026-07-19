<?php

namespace Tests\Feature\Management;

use App\Models\Location;
use App\Models\Machine;
use App\Models\WorkOrder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MaintenanceCostTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);
    }

    protected function machine(): Machine
    {
        $location = Location::create(['name' => 'Test Yard', 'slug' => 'test-yard-'.uniqid()]);

        return Machine::create([
            'id_code' => 'TST-'.random_int(1000, 9999),
            'status' => 'active',
            'current_location_id' => $location->id,
            'current_hours' => 520,
            'last_service_hours' => 100,
            'service_interval_hours' => 500,
            'hours_adjustment' => 0,
            'remaining_hours' => 80,
        ]);
    }

    public function test_maintenance_cost_sums_only_completed_work_orders_parts_cost(): void
    {
        $machine = $this->machine();

        WorkOrder::create([
            'code' => 'WO-1001', 'machine_id' => $machine->id, 'type' => 'corrective',
            'status' => 'completed', 'parts_cost' => 120.50, 'opened_at' => now()->toDateString(),
        ]);
        WorkOrder::create([
            'code' => 'WO-1002', 'machine_id' => $machine->id, 'type' => 'preventive',
            'status' => 'completed', 'parts_cost' => 79.50, 'opened_at' => now()->toDateString(),
        ]);
        // Not completed: must not count towards the cost.
        WorkOrder::create([
            'code' => 'WO-1003', 'machine_id' => $machine->id, 'type' => 'corrective',
            'status' => 'open', 'parts_cost' => 999, 'opened_at' => now()->toDateString(),
        ]);

        $this->assertEquals(200.00, round($machine->refresh()->maintenance_cost, 2));
        $this->assertEquals(2, $machine->service_count);
    }

    public function test_maintenance_cost_is_zero_when_there_are_no_completed_work_orders(): void
    {
        $machine = $this->machine();

        $this->assertEquals(0.0, $machine->maintenance_cost);
        $this->assertEquals(0, $machine->service_count);
    }
}

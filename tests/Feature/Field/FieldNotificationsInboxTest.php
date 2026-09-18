<?php

namespace Tests\Feature\Field;

use App\Livewire\Field\NotificationsInbox;
use App\Models\FieldReport;
use App\Models\Location;
use App\Models\Machine;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Bandeja de notificaciones de /field. Tiene que leer la MISMA tabla
 * `notifications` que la campanita de /admin — no un segundo almacén — y
 * permitir marcar como leída.
 *
 * Los cuatro roles con `view_field_reports` (administrador, responsable,
 * taller, gerencia) tienen también `access_panel`, así que en la práctica el
 * único evento cableado hoy sale por /admin. Para probar la bandeja de
 * /field con datos reales, este test le da el permiso a `foreman` (que SÍ
 * usa /field) sin tocar la matriz de producción — es un actor de prueba, no
 * un cambio de reparto.
 */
class FieldNotificationsInboxTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);
    }

    private function machine(): Machine
    {
        $location = Location::create(['name' => 'Yard I', 'slug' => 'yard-i-'.uniqid()]);

        return Machine::create([
            'id_code' => 'INB-'.random_int(1000, 9999),
            'status' => 'active',
            'current_location_id' => $location->id,
        ]);
    }

    private function foremanWithFieldReportsAccess(): User
    {
        $foreman = User::where('email', 'foreman@dp.local')->firstOrFail();
        $foreman->givePermissionTo('view_field_reports');

        return $foreman;
    }

    public function test_the_inbox_is_empty_when_there_are_no_notifications(): void
    {
        $foreman = $this->foremanWithFieldReportsAccess();

        Livewire::actingAs($foreman)
            ->test(NotificationsInbox::class)
            ->assertSee(__('field.notifications_empty'));
    }

    public function test_the_inbox_shows_a_notification_created_through_the_same_table_the_panel_uses(): void
    {
        $foreman = $this->foremanWithFieldReportsAccess();
        $machine = $this->machine();
        $otherWorker = User::where('email', 'campo@dp.local')->firstOrFail();

        FieldReport::create([
            'machine_id' => $machine->id,
            'reported_by' => $otherWorker->id,
            'location_id' => $machine->current_location_id,
            'condition' => 'critical',
        ]);

        $this->assertSame(1, $foreman->notifications()->count());

        $notification = $foreman->notifications()->first();

        Livewire::actingAs($foreman)
            ->test(NotificationsInbox::class)
            ->assertSee($notification->data['title']);
    }

    public function test_marking_a_notification_as_read_persists_and_is_reflected_in_the_unread_count(): void
    {
        $foreman = $this->foremanWithFieldReportsAccess();
        $machine = $this->machine();
        $otherWorker = User::where('email', 'campo@dp.local')->firstOrFail();

        FieldReport::create([
            'machine_id' => $machine->id,
            'reported_by' => $otherWorker->id,
            'location_id' => $machine->current_location_id,
            'condition' => 'critical',
        ]);

        $notification = $foreman->notifications()->first();
        $this->assertSame(1, $foreman->unreadNotifications()->count());

        Livewire::actingAs($foreman)
            ->test(NotificationsInbox::class)
            ->call('markAsRead', $notification->id);

        $this->assertSame(0, $foreman->unreadNotifications()->count());
        $this->assertNotNull($notification->fresh()->read_at);
    }

    /**
     * Un usuario no puede marcar como leída una notificación AJENA pasando
     * su id a mano: solo opera sobre las suyas propias
     * (`Auth::user()->notifications()`).
     */
    public function test_a_user_cannot_mark_someone_elses_notification_as_read(): void
    {
        $admin = User::where('email', 'admin@dp.local')->firstOrFail();
        $foreman = $this->foremanWithFieldReportsAccess();
        $machine = $this->machine();
        $otherWorker = User::where('email', 'campo@dp.local')->firstOrFail();

        FieldReport::create([
            'machine_id' => $machine->id,
            'reported_by' => $otherWorker->id,
            'location_id' => $machine->current_location_id,
            'condition' => 'critical',
        ]);

        $adminNotification = $admin->notifications()->first();

        Livewire::actingAs($foreman)
            ->test(NotificationsInbox::class)
            ->call('markAsRead', $adminNotification->id);

        $this->assertNull($adminNotification->fresh()->read_at);
    }
}

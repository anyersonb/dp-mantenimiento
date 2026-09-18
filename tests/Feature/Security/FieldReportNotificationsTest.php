<?php

namespace Tests\Feature\Security;

use App\Models\FieldReport;
use App\Models\Location;
use App\Models\Machine;
use App\Models\Setting;
use App\Models\User;
use App\Observers\FieldReportObserver;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Módulo de notificaciones — el único evento cableado: un reporte de campo
 * crítico (y, si la Configuración lo enciende, uno "requiere atención")
 * notifica a quien tiene `view_field_reports`.
 *
 * El disparo vive en App\Observers\FieldReportObserver, enganchado al evento
 * `created` del modelo (no en ReportForm::save()), así que estos tests crean
 * el `FieldReport` DIRECTO con `FieldReport::create()` — a propósito, para
 * probar que el aviso sale sin pasar por el formulario de /field (que es
 * justo la garantía que pidió el brief: "un reporte creado por cualquier
 * otra vía también notifica").
 */
class FieldReportNotificationsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);
    }

    private function machine(): Machine
    {
        $location = Location::create(['name' => 'Yard N', 'slug' => 'yard-n-'.uniqid()]);

        return Machine::create([
            'id_code' => 'NTF-'.random_int(1000, 9999),
            'status' => 'active',
            'current_location_id' => $location->id,
        ]);
    }

    private function reportBy(User $author, string $condition): FieldReport
    {
        $machine = $this->machine();

        return FieldReport::create([
            'machine_id' => $machine->id,
            'reported_by' => $author->id,
            'location_id' => $machine->current_location_id,
            'condition' => $condition,
        ]);
    }

    /** Los cuatro roles que tienen view_field_reports. */
    private function recipients(): array
    {
        return [
            User::where('email', 'admin@dp.local')->firstOrFail(),
            User::where('email', 'responsable@dp.local')->firstOrFail(),
            User::where('email', 'taller@dp.local')->firstOrFail(),
            User::where('email', 'gerencia@dp.local')->firstOrFail(),
        ];
    }

    public function test_a_critical_report_notifies_every_recipient_with_the_permission(): void
    {
        $author = User::where('email', 'campo@dp.local')->firstOrFail();
        $this->reportBy($author, 'critical');

        foreach ($this->recipients() as $recipient) {
            $this->assertSame(
                1,
                $recipient->notifications()->count(),
                "{$recipient->email} debería tener exactamente una notificación."
            );

            $notification = $recipient->notifications()->first();
            $this->assertSame('field_report.needs_attention', $notification->data['event']);
            $this->assertSame('critical', $notification->data['condition']);
            $this->assertNull($notification->read_at);
        }
    }

    /**
     * El autor NUNCA se notifica a sí mismo — aunque el autor tenga el
     * permiso (caso: un administrador que también reporta desde /field).
     */
    public function test_the_authors_own_account_is_never_notified_even_if_it_has_the_permission(): void
    {
        $adminAsAuthor = User::where('email', 'admin@dp.local')->firstOrFail();
        $this->reportBy($adminAsAuthor, 'critical');

        $this->assertSame(0, $adminAsAuthor->notifications()->count());
    }

    public function test_an_ok_report_never_notifies_anyone(): void
    {
        $author = User::where('email', 'campo@dp.local')->firstOrFail();
        $this->reportBy($author, 'ok');

        foreach ($this->recipients() as $recipient) {
            $this->assertSame(0, $recipient->notifications()->count());
        }
    }

    public function test_an_attention_report_does_not_notify_when_the_setting_is_off(): void
    {
        $this->assertFalse(FieldReportObserver::notifiesOnAttention());

        $author = User::where('email', 'campo@dp.local')->firstOrFail();
        $this->reportBy($author, 'attention');

        foreach ($this->recipients() as $recipient) {
            $this->assertSame(0, $recipient->notifications()->count());
        }
    }

    public function test_an_attention_report_notifies_when_the_setting_is_on(): void
    {
        Setting::set(FieldReportObserver::NOTIFY_ON_ATTENTION_KEY, true, 'boolean');
        $this->assertTrue(FieldReportObserver::notifiesOnAttention());

        $author = User::where('email', 'campo@dp.local')->firstOrFail();
        $this->reportBy($author, 'attention');

        foreach ($this->recipients() as $recipient) {
            $this->assertSame(1, $recipient->notifications()->count());
        }
    }

    /**
     * `field_crew` (foreman, operador_cisterna, personal_mantenimiento) NO
     * tiene `view_field_reports`: no se les notifica un crítico, aunque sean
     * quienes más reportan desde el celular.
     */
    public function test_field_crew_roles_without_the_permission_are_never_notified(): void
    {
        $author = User::where('email', 'campo@dp.local')->firstOrFail();
        $this->reportBy($author, 'critical');

        foreach (['foreman@dp.local', 'combustible@dp.local'] as $email) {
            $user = User::where('email', $email)->firstOrFail();
            $this->assertSame(0, $user->notifications()->count());
        }
    }

    /**
     * El aviso llega marcado con el formato que la campanita de Filament
     * necesita para mostrarlo (`data->format === 'filament'`), no un array
     * cualquiera.
     */
    public function test_the_notification_is_shaped_for_the_filament_bell(): void
    {
        $author = User::where('email', 'campo@dp.local')->firstOrFail();
        $this->reportBy($author, 'critical');

        $admin = User::where('email', 'admin@dp.local')->firstOrFail();
        $notification = $admin->notifications()->first();

        $this->assertSame('filament', $notification->data['format']);
        $this->assertNotEmpty($notification->data['title']);
    }

    /**
     * Un usuario INACTIVO con el permiso no debe recibir avisos — mismo
     * criterio que App\Support\AccessControl::activeUsersWith(), que ya usa
     * el digest de alertas por correo.
     */
    public function test_an_inactive_user_with_the_permission_is_not_notified(): void
    {
        $admin = User::where('email', 'admin@dp.local')->firstOrFail();
        $admin->forceFill(['active' => false])->save();

        $author = User::where('email', 'campo@dp.local')->firstOrFail();
        $this->reportBy($author, 'critical');

        $this->assertSame(0, $admin->notifications()->count());
    }
}

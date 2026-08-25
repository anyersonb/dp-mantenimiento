<?php

namespace Tests\Feature\Management;

use App\Filament\Resources\UserResource\Pages\ListUsers;
use App\Models\User;
use App\Support\ReadablePassword;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * "El administrador podrá ver las claves de cada usuario para que si se les
 * olvida podamos darles de nuevo" (comentario de la clienta, 2026-08-24).
 *
 * VER la que tienen puesta no se puede: `password` se guarda con bcrypt y un
 * hash no se deshace. Lo que resuelve el problema real —alguien olvidó la suya
 * y hay que devolverle el acceso hoy— es la acción "Generar clave": el
 * administrador saca una nueva, la ve una vez y se la dicta.
 *
 * El test que importa es el primero, y no es un detalle: lo fácil de romper acá
 * es que la clave que se MUESTRA no sea la que se GUARDA. Eso no lo canta
 * ningún error —la acción "funciona", la notificación aparece— y se descubre
 * cuando el operario no puede entrar. Por eso el test saca la clave del texto
 * que ve el administrador y con ESA prueba el login.
 */
class UserPasswordResetTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);
    }

    private function admin(): User
    {
        return User::where('email', 'admin@dp.local')->firstOrFail();
    }

    /**
     * La clave que el administrador lee en pantalla. Filament apila las
     * notificaciones en la sesión (Notification::send()), así que se leen de
     * ahí y se busca el formato de ReadablePassword: letras-dígitos-letras.
     */
    private function passwordShownOnScreen(): ?string
    {
        $texto = json_encode(session('filament.notifications', []), JSON_UNESCAPED_UNICODE);

        return preg_match('/[a-z]{4}-[2-9]{3}-[a-z]{4}/', (string) $texto, $m) ? $m[0] : null;
    }

    public function test_the_password_the_administrator_is_shown_is_the_one_that_actually_works(): void
    {
        $objetivo = User::where('email', 'foreman@dp.local')->firstOrFail();
        $hashViejo = $objetivo->password;

        Livewire::actingAs($this->admin())
            ->test(ListUsers::class)
            ->callTableAction('generate_password', $objetivo);

        $mostrada = $this->passwordShownOnScreen();

        $this->assertNotNull(
            $mostrada,
            'La acción no dejó ninguna clave a la vista: el administrador no tendría qué dictarle al operario.',
        );

        $objetivo->refresh();

        // Lo central: la de la pantalla entra.
        $this->assertTrue(
            Hash::check($mostrada, $objetivo->password),
            "La clave mostrada ({$mostrada}) no es la que quedó guardada: el operario no podría entrar con ella.",
        );

        // Y la de antes ya no. Sin esto, un "generar" que no guarda nada
        // pasaría la mitad de arriba si la clave vieja siguiera siendo válida.
        $this->assertNotSame($hashViejo, $objetivo->password);
        $this->assertFalse(Hash::check('password', $objetivo->password));

        // Guardada hasheada, no en texto plano.
        $this->assertNotSame($mostrada, $objetivo->password);
    }

    public function test_the_new_password_is_recorded_as_a_fact_in_the_log_but_never_the_secret_itself(): void
    {
        $objetivo = User::where('email', 'foreman@dp.local')->firstOrFail();

        Livewire::actingAs($this->admin())
            ->test(ListUsers::class)
            ->callTableAction('generate_password', $objetivo);

        $mostrada = $this->passwordShownOnScreen();
        $this->assertNotNull($mostrada);

        $asiento = \App\Models\ActivityLog::query()->latest('id')->firstOrFail();

        $this->assertSame('password_generated', $asiento->event);
        $this->assertSame($this->admin()->id, $asiento->causer_id);
        $this->assertSame($objetivo->id, $asiento->subject_id);

        // La bitácora la puede leer cualquiera con permiso de auditoría, que no
        // es lo mismo que administrar usuarios. La clave no va ahí.
        $fila = json_encode($asiento->getAttributes(), JSON_UNESCAPED_UNICODE);
        $this->assertStringNotContainsString($mostrada, (string) $fila);
    }

    /**
     * Sobre uno mismo la acción no aparece, y no es una preferencia: el panel
     * corre con AuthenticateSession, así que cambiarse la propia clave cierra
     * la sesión en la petición siguiente y se lleva puesta la notificación que
     * la mostraba. El administrador quedaría afuera sin saber la nueva.
     */
    public function test_an_administrator_is_not_offered_to_regenerate_their_own_password(): void
    {
        $admin = $this->admin();
        $otro = User::where('email', 'foreman@dp.local')->firstOrFail();

        Livewire::actingAs($admin)
            ->test(ListUsers::class)
            ->assertTableActionHidden('generate_password', $admin)
            ->assertTableActionVisible('generate_password', $otro);
    }

    public function test_the_generated_password_can_be_dictated_out_loud_without_ambiguity(): void
    {
        // 200 tiradas: si se colara un carácter de los que se confunden al
        // dictar (0/O, 1/l/i, 5/S) la probabilidad de no verlo es despreciable.
        for ($i = 0; $i < 200; $i++) {
            $clave = ReadablePassword::make();

            $this->assertMatchesRegularExpression('/^[a-z]{4}-[2-9]{3}-[a-z]{4}$/', $clave);
            $this->assertDoesNotMatchRegularExpression('/[ilo015]/', $clave);
        }

        // Y no siempre la misma, que sería el peor defecto posible acá.
        $this->assertGreaterThan(
            90,
            count(array_unique(array_map(fn () => ReadablePassword::make(), range(1, 100)))),
        );
    }
}

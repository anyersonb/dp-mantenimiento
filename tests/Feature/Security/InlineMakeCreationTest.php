<?php

namespace Tests\Feature\Security;

use App\Filament\Resources\FleetAttachmentResource\Pages\CreateFleetAttachment;
use App\Filament\Resources\MachineResource\Pages\CreateMachine;
use App\Models\Make;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Hallazgo Alto (QA en navegador, ronda de auditoría de Complementos): el
 * botón "+" junto al desplegable de Marca (`make_id`) para dar de alta una
 * marca en línea (`->createOptionForm()`) reventaba con 500 --
 * "SQLSTATE 23000 — Column 'slug' cannot be null". El `Hidden::make('slug')`
 * del formulario emergente nunca recibía valor: nada lo derivaba del nombre.
 *
 * Ocurría en los DOS sitios que usan `createOptionForm` en todo el repo
 * (búsqueda exhaustiva, únicos dos resultados): `FleetAttachmentResource` y
 * `MachineResource`, ambos para `make_id`. El de Máquinas es preexistente a
 * este lote, no lo introdujo -- se corrige acá porque se despliega junto.
 *
 * El patrón correcto YA existía, en `MakeResource::form()` (hallazgo E6-07):
 * `->afterStateUpdated()` sobre el campo visible ('name') deriva el slug con
 * `Str::slug()`, y `UniqueSlugFrom` evita el 500 MUDO si dos nombres
 * distintos slugifican igual (el índice único real vive en `makes.slug`).
 * El fix reutiliza esa misma regla, no inventa una nueva.
 */
class InlineMakeCreationTest extends TestCase
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

    public function test_creating_a_make_inline_from_the_fleet_attachment_form_persists_its_slug(): void
    {
        Livewire::actingAs($this->admin())
            ->test(CreateFleetAttachment::class)
            ->callFormComponentAction('make_id', 'createOption', data: [
                'name' => 'Caterpillar Inline FA',
            ])
            ->assertHasNoFormComponentActionErrors();

        $make = Make::where('name', 'Caterpillar Inline FA')->firstOrFail();
        $this->assertSame(Str::slug('Caterpillar Inline FA'), $make->slug);
    }

    /**
     * Mismo escenario, en MachineResource -- preexistente, pero con el
     * mismo defecto y el mismo fix.
     */
    public function test_creating_a_make_inline_from_the_machine_form_persists_its_slug(): void
    {
        Livewire::actingAs($this->admin())
            ->test(CreateMachine::class)
            ->callFormComponentAction('make_id', 'createOption', data: [
                'name' => 'Caterpillar Inline Machine',
            ])
            ->assertHasNoFormComponentActionErrors();

        $make = Make::where('name', 'Caterpillar Inline Machine')->firstOrFail();
        $this->assertSame(Str::slug('Caterpillar Inline Machine'), $make->slug);
    }

    /**
     * Colisión de slug: "Cat" y "cat!!" slugifican igual ('cat'). El índice
     * único real vive en `makes.slug`, así que sin `UniqueSlugFrom` el
     * segundo alta hubiera sido OTRO 500 (mudo, sobre un campo Hidden que el
     * usuario ni ve) en vez de un error de validación legible sobre el
     * nombre. Se prueba en el sitio de Complementos; el mecanismo es
     * idéntico en Máquinas (misma regla, mismo campo).
     */
    public function test_a_colliding_slug_is_rejected_with_a_validation_error_not_a_500(): void
    {
        Make::create(['name' => 'Cat', 'slug' => Str::slug('Cat')]);

        Livewire::actingAs($this->admin())
            ->test(CreateFleetAttachment::class)
            ->callFormComponentAction('make_id', 'createOption', data: [
                'name' => 'cat!!',
            ])
            ->assertHasFormComponentActionErrors(['name']);

        // Ni el 500 ni un segundo registro colado: sigue habiendo UNA sola
        // marca con ese slug.
        $this->assertSame(1, Make::where('slug', Str::slug('Cat'))->count());
    }
}

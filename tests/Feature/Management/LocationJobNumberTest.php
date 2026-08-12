<?php

namespace Tests\Feature\Management;

use App\Filament\Resources\LocationResource\Pages\CreateLocation;
use App\Filament\Resources\LocationResource\Pages\EditLocation;
use App\Filament\Resources\MachineResource\Pages\CreateMachine;
use App\Models\Location;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Número de trabajo de la obra (pedido del cliente 2026-08-05): obligatorio y
 * que no se repita.
 *
 * La decisión que estos tests fijan: **obligatorio en el formulario, nullable en
 * la base, único en la base.** No es una contradicción — es lo que permite
 * exigirlo sin haber inventado números para las obras que ya existían. Ver el
 * comentario de la migración.
 *
 * El caso de la unicidad se prueba en los dos niveles a propósito. Solo con la
 * validación del formulario, cualquier otro camino de escritura (un import, un
 * seeder, una acción futura) podría duplicar el número; solo con el índice, el
 * usuario se comería un 500 mudo, que es exactamente el hallazgo E6-07.
 */
class LocationJobNumberTest extends TestCase
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

    public function test_the_form_refuses_to_save_a_job_site_without_a_job_number(): void
    {
        Livewire::actingAs($this->admin())
            ->test(CreateLocation::class)
            ->fillForm([
                'name' => 'Obra Nueva',
                'type' => 'jobsite',
            ])
            ->call('create')
            ->assertHasFormErrors(['job_number' => 'required']);

        $this->assertDatabaseMissing('locations', ['name' => 'Obra Nueva']);
    }

    public function test_the_form_refuses_a_job_number_that_already_exists(): void
    {
        Location::create(['name' => 'Obra A', 'slug' => 'obra-a', 'job_number' => 'JOB-100']);

        Livewire::actingAs($this->admin())
            ->test(CreateLocation::class)
            ->fillForm([
                'name' => 'Obra B',
                'type' => 'jobsite',
                'job_number' => 'JOB-100',
            ])
            ->call('create')
            ->assertHasFormErrors(['job_number' => 'unique']);

        $this->assertDatabaseMissing('locations', ['name' => 'Obra B']);
    }

    public function test_a_job_site_can_be_created_with_a_free_job_number(): void
    {
        Livewire::actingAs($this->admin())
            ->test(CreateLocation::class)
            ->fillForm([
                'name' => 'Obra Libre',
                'type' => 'jobsite',
                'job_number' => 'JOB-777',
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $this->assertDatabaseHas('locations', ['name' => 'Obra Libre', 'job_number' => 'JOB-777']);
    }

    public function test_editing_a_job_site_does_not_collide_with_its_own_job_number(): void
    {
        $location = Location::create(['name' => 'Obra C', 'slug' => 'obra-c', 'job_number' => 'JOB-300']);

        Livewire::actingAs($this->admin())
            ->test(EditLocation::class, ['record' => $location->getRouteKey()])
            ->fillForm([
                'name' => 'Obra C renombrada',
                'job_number' => 'JOB-300',
            ])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertDatabaseHas('locations', ['id' => $location->id, 'job_number' => 'JOB-300']);
    }

    public function test_editing_an_old_job_site_without_a_number_forces_one(): void
    {
        // Éste es el caso real de producción: las obras cargadas antes de que el
        // campo existiera. Se pueden seguir listando, pero no se pueden guardar
        // sin el número.
        $legacy = Location::create(['name' => 'Obra Vieja', 'slug' => 'obra-vieja']);

        $this->assertNull($legacy->job_number);

        Livewire::actingAs($this->admin())
            ->test(EditLocation::class, ['record' => $legacy->getRouteKey()])
            ->fillForm(['name' => 'Obra Vieja editada'])
            ->call('save')
            ->assertHasFormErrors(['job_number' => 'required']);
    }

    public function test_the_database_itself_rejects_a_duplicate_job_number(): void
    {
        Location::create(['name' => 'Obra D', 'slug' => 'obra-d', 'job_number' => 'JOB-400']);

        // Sin pasar por el formulario: cualquier otro camino de escritura choca
        // contra el índice único, no contra la validación.
        $this->expectException(QueryException::class);

        Location::create(['name' => 'Obra E', 'slug' => 'obra-e', 'job_number' => 'JOB-400']);
    }

    public function test_several_job_sites_may_share_the_empty_job_number(): void
    {
        // La contracara del índice único sobre una columna nullable, y la razón
        // por la que no se hizo NOT NULL: las obras existentes conviven sin
        // número hasta que DP los pase, sin que yo haya inventado ninguno.
        Location::create(['name' => 'Vieja 1', 'slug' => 'vieja-1']);
        Location::create(['name' => 'Vieja 2', 'slug' => 'vieja-2']);

        $this->assertSame(2, Location::whereNull('job_number')->count());
    }

    /* ------------------------------------------------------------------ *
     * display_name: pedido del cliente 2026-08-06 ("everything is job
     * numbers"). El número va PRIMERO porque es el identificador que usa el
     * cliente, y una obra sin número todavía (dato faltante real) se ve por
     * su nombre solo — nunca con un guion suelto ni "Not set" dentro de un
     * rótulo que se usa en selects, tablas y mapas.
     * ------------------------------------------------------------------ */

    public function test_display_name_puts_the_job_number_first_when_it_exists(): void
    {
        $location = Location::create([
            'name' => 'Blount Rd',
            'slug' => 'blount-rd-'.uniqid(),
            'job_number' => 'JOB-555',
        ]);

        $this->assertSame('JOB-555 — Blount Rd', $location->display_name);
    }

    public function test_display_name_falls_back_to_the_name_alone_without_a_job_number(): void
    {
        $location = Location::create(['name' => 'Obra Sin Número', 'slug' => 'obra-sin-numero-'.uniqid()]);

        $this->assertNull($location->job_number);
        $this->assertSame('Obra Sin Número', $location->display_name);
        // Nunca un guion suelto ni un "Not set" mezclado en el rótulo: en un
        // <select> eso se lee como un registro roto, no como dato pendiente.
        $this->assertStringNotContainsString('—', $location->display_name);
        $this->assertStringNotContainsString('Not set', $location->display_name);
    }

    /* ------------------------------------------------------------------ *
     * Buscador de obra por número: QA no pudo determinar del lado del
     * navegador si tipear "2411" filtraba de verdad el <select> de Filament
     * (no distinguió un bug real de un artefacto de Playwright con ese
     * widget). Se cubre del lado servidor, sobre el mismo callable que
     * Filament invoca al buscar.
     * ------------------------------------------------------------------ */

    public function test_location_select_options_finds_a_job_site_by_its_job_number(): void
    {
        $blount = Location::create(['name' => 'Blount Rd', 'slug' => 'blount-rd-'.uniqid(), 'job_number' => '2411']);
        $davie = Location::create(['name' => 'Davie Yd', 'slug' => 'davie-yd-'.uniqid(), 'job_number' => '2415']);

        $results = Location::locationSelectOptions('2411');

        $this->assertSame([$blount->id => '2411 — Blount Rd'], $results);
        $this->assertArrayNotHasKey($davie->id, $results);
    }

    public function test_location_select_options_finds_a_job_site_by_its_name_too(): void
    {
        $blount = Location::create(['name' => 'Blount Rd', 'slug' => 'blount-rd-'.uniqid(), 'job_number' => '2411']);
        Location::create(['name' => 'Davie Yd', 'slug' => 'davie-yd-'.uniqid(), 'job_number' => '2415']);

        $results = Location::locationSelectOptions('Blount');

        $this->assertSame([$blount->id => '2411 — Blount Rd'], $results);
    }

    public function test_location_select_options_returns_nothing_for_a_number_that_does_not_match(): void
    {
        Location::create(['name' => 'Blount Rd', 'slug' => 'blount-rd-'.uniqid(), 'job_number' => '2411']);
        Location::create(['name' => 'Davie Yd', 'slug' => 'davie-yd-'.uniqid(), 'job_number' => '2415']);

        $this->assertSame([], Location::locationSelectOptions('9999'));
    }

    /**
     * La misma búsqueda, pero invocada exactamente como Filament la invoca:
     * a través de `Select::getSearchResults()` del componente real montado
     * en el formulario de "Crear máquina" — no una llamada directa al método
     * estático que podría estar bien mientras el cableado en el Select
     * estuviera roto y este test no lo notaría.
     */
    public function test_the_machine_forms_location_select_search_is_wired_to_the_real_filament_component(): void
    {
        $blount = Location::create(['name' => 'Blount Rd', 'slug' => 'blount-rd-'.uniqid(), 'job_number' => '2411']);
        $davie = Location::create(['name' => 'Davie Yd', 'slug' => 'davie-yd-'.uniqid(), 'job_number' => '2415']);

        $test = Livewire::actingAs($this->admin())->test(CreateMachine::class);

        // getComponent() recorre TODO el árbol, incluidas las Section que lo
        // envuelven y que no tienen getName(): hay que filtrar antes de
        // preguntar el nombre.
        $component = $test->instance()
            ->getForm('form')
            ->getComponent(fn ($c) => method_exists($c, 'getName') && $c->getName() === 'current_location_id');

        $this->assertNotNull($component, 'El Select de obra no está en el formulario de Crear Máquina.');

        $byNumber = $component->getSearchResults('2411');
        $this->assertSame([$blount->id => '2411 — Blount Rd'], $byNumber);
        $this->assertArrayNotHasKey($davie->id, $byNumber);

        $byName = $component->getSearchResults('Davie');
        $this->assertSame([$davie->id => '2415 — Davie Yd'], $byName);

        $this->assertSame([], $component->getSearchResults('9999'));
    }
}

<?php

namespace Tests\Feature\Management;

use App\Filament\Resources\LocationResource\Pages\CreateLocation;
use App\Filament\Resources\LocationResource\Pages\EditLocation;
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
}

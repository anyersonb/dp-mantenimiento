<?php

namespace Tests\Feature\Trash;

use App\Filament\Resources\LocationResource;
use App\Filament\Resources\MachineCategoryResource;
use App\Filament\Resources\MakeResource;
use App\Filament\Resources\QuoteResource;
use App\Filament\Resources\UserResource;
use App\Models\Location;
use App\Models\MachineCategory;
use App\Models\Make;
use App\Models\Quote;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Papelera (Lote A) — barrido del RESTO del inventario (Location,
 * MachineCategory, Make, Quote, User): todos comparten el mismo trait
 * (`App\Filament\Concerns\HasPapeleraActions`) que WorkOrder/Machine, así que
 * esto prueba que el cableado es genérico y no algo que solo funciona para el
 * recurso más probado. Cada caso: soft delete real, gate solo-administrador,
 * y restaurar trae la fila de vuelta.
 */
class PapeleraAcrossResourcesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);

        // El módulo de cotizaciones está apagado por decisión del cliente
        // (config/features.php); se enciende SOLO para este test, que
        // verifica el cableado de papelera de QuoteResource, no si el módulo
        // debe estar prendido.
        config(['features.quotes' => true]);
    }

    private function admin(): User
    {
        return User::where('email', 'admin@dp.local')->firstOrFail();
    }

    private function taller(): User
    {
        return User::where('email', 'taller@dp.local')->firstOrFail();
    }

    /**
     * @return array<int, array{0: class-string, 1: \Closure(): Model}>
     */
    public static function resourceProvider(): array
    {
        return [
            'Location' => [LocationResource::class, fn () => Location::create([
                'name' => 'Papelera Yard', 'slug' => 'papelera-yard-'.uniqid(),
            ])],
            'MachineCategory' => [MachineCategoryResource::class, fn () => MachineCategory::create([
                'name' => 'Papelera Category', 'slug' => 'papelera-category-'.uniqid(),
            ])],
            'Make' => [MakeResource::class, fn () => Make::create([
                'name' => 'Papelera Make', 'slug' => 'papelera-make-'.uniqid(),
            ])],
            'Quote' => [QuoteResource::class, fn () => Quote::create([
                'title' => 'Papelera Quote',
            ])],
        ];
    }

    /**
     * @dataProvider resourceProvider
     */
    public function test_soft_delete_restore_and_gates_for_each_catalog_resource(string $resourceClass, \Closure $factory): void
    {
        $record = $factory();
        $table = $record::class;

        // --- Soft delete real, no destructivo ---
        $record->delete();
        $this->assertSoftDeleted($table, ['id' => $record->id]);
        $this->assertNull($table::find($record->id));

        // --- Gate: solo administrador ---
        $this->actingAs($this->taller());
        $this->assertFalse($resourceClass::canRestore($record), "{$resourceClass}: taller no debería poder restaurar.");
        $this->assertFalse($resourceClass::canForceDelete($record), "{$resourceClass}: taller no debería poder eliminar definitivamente.");

        $this->actingAs($this->admin());
        $this->assertTrue($resourceClass::canRestore($record), "{$resourceClass}: administrador debería poder restaurar.");
        $this->assertTrue($resourceClass::canForceDelete($record), "{$resourceClass}: administrador debería poder eliminar definitivamente.");

        // --- Restaurar trae la fila de vuelta ---
        $record->restore();
        $this->assertNotNull($table::find($record->id), "{$resourceClass}: el registro no volvió al restaurar.");

        // --- Eliminar definitivamente sí borra ---
        $record->delete();
        $record->forceDelete();
        $this->assertDatabaseMissing($table, ['id' => $record->id]);
    }

    /**
     * User aparte: no usa el data provider porque además hay que verificar
     * que sigue sin poder autenticarse (cubierto en
     * TrashedUserCannotAuthenticateTest) y que no se puede auto-restaurar/
     * auto-eliminar por accidente.
     */
    public function test_soft_delete_restore_and_gates_for_user_resource(): void
    {
        $user = User::create([
            'name' => 'Papelera User',
            'email' => 'papelera-user-'.uniqid().'@dp.local',
            'password' => bcrypt('password'),
        ]);
        $user->assignRole('taller');

        $user->delete();
        $this->assertSoftDeleted('users', ['id' => $user->id]);

        $this->actingAs($this->taller());
        $this->assertFalse(UserResource::canRestore($user));
        $this->assertFalse(UserResource::canForceDelete($user));

        $this->actingAs($this->admin());
        $this->assertTrue(UserResource::canRestore($user));
        $this->assertTrue(UserResource::canForceDelete($user));

        $user->restore();
        $this->assertNotNull(User::find($user->id));

        $user->delete();
        $user->forceDelete();
        $this->assertDatabaseMissing('users', ['id' => $user->id]);
    }
}

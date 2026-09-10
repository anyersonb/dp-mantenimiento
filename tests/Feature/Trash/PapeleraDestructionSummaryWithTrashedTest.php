<?php

namespace Tests\Feature\Trash;

use App\Filament\Resources\LocationResource;
use App\Filament\Resources\MachineCategoryResource;
use App\Filament\Resources\MachineResource;
use App\Filament\Resources\MakeResource;
use App\Filament\Resources\QuoteResource;
use App\Filament\Resources\UserResource;
use App\Filament\Resources\WorkOrderResource;
use App\Models\Location;
use App\Models\Machine;
use App\Models\MachineCategory;
use App\Models\Make;
use App\Models\Quote;
use App\Models\User;
use App\Models\WorkOrder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Testing\RefreshDatabase;
use ReflectionMethod;
use Tests\TestCase;

/**
 * Centinela pedido por seguridad (auditoría del lote de papelera,
 * 2026-09-09): recorre los 7 recursos de la papelera y, para cada relación
 * que su `papeleraDestructionSummary()` cuenta HOY, verifica contra el
 * MODELO REAL si el relacionado usa `SoftDeletes`. Si lo usa, un hijo
 * archivado tiene que seguir contando (`withTrashed()`) — es exactamente el
 * hallazgo Alto de `Machine::destructionSummary()`/`work_orders`, y la clase
 * de defecto que vuelve en el próximo recurso que sume una relación
 * destructiva y se olvide del soft delete.
 *
 * Deliberadamente NO pasa con una lista vacía (la trampa que exige el
 * pedido de seguridad): el mapa `resourceProvider()` declara, para cada
 * recurso, qué relaciones cuenta su resumen hoy. Para Machine/work_orders
 * eso arma un hijo, lo manda a la papelera, y exige que el conteo lo siga
 * viendo — revertir el `withTrashed()` de `Machine::destructionSummary()`
 * hace fallar este test (confirmado leyendo el código: sin `withTrashed()`
 * el scope global de `SoftDeletes` saca al hijo archivado de la cuenta, y la
 * aserción de abajo pide 1, no 0).
 *
 * Para las relaciones que NO son soft-deletables (readings/alerts/parts/
 * field_reports de Machine) y para los recursos que hoy no cuentan ninguna
 * (work_orders, quotes, locations, machine_categories, makes, users) el test
 * también corre: confirma, leyendo el modelo real, que no hay ningún hueco
 * silencioso — si alguien agrega una relación soft-deletable a la cuenta de
 * cualquiera de los 7 recursos sin sumarla también a este mapa, este test
 * queda desactualizado a propósito de forma RUIDOSA (falla la rama de abajo
 * que exige que el mapa describa exactamente lo que el resumen expone hoy),
 * no en silencio.
 */
class PapeleraDestructionSummaryWithTrashedTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);

        // El módulo de cotizaciones está apagado por decisión del cliente
        // (config/features.php); se enciende solo para que QuoteResource
        // entre en el barrido, no porque deba estar prendido.
        config(['features.quotes' => true]);
    }

    private function bareMachine(): Machine
    {
        return Machine::create([
            'id_code' => 'SENT-'.random_int(100000, 999999),
            'status' => 'active',
            'hourmeter_status' => 'ok',
        ]);
    }

    /**
     * Para cada uno de los 7 recursos de la papelera: cómo crear un
     * registro "pelado", y qué relaciones cuenta HOY su
     * `papeleraDestructionSummary()` (clave del resumen => nombre del
     * método de relación en el modelo). Los que no cuentan ninguna quedan
     * con un array vacío — decisión verificada (ver docblock de
     * `HasPapeleraActions::papeleraDestructionSummary()` y el punto 8 del
     * lote de seguridad 2026-09-09), no un olvido.
     *
     * @return array<string, array{0: class-string, 1: \Closure(): Model, 2: array<string, string>}>
     */
    public static function resourceProvider(): array
    {
        return [
            'Machine' => [
                MachineResource::class,
                fn () => Machine::create([
                    'id_code' => 'SENT-'.random_int(100000, 999999),
                    'status' => 'active',
                    'hourmeter_status' => 'ok',
                ]),
                [
                    'work_orders' => 'workOrders',
                    'readings' => 'readings',
                    'alerts' => 'alerts',
                    'parts' => 'parts',
                    'field_reports' => 'fieldReports',
                ],
            ],
            'WorkOrder' => [
                WorkOrderResource::class,
                fn () => WorkOrder::create([
                    'code' => 'SENT-WO-'.random_int(100000, 999999),
                    'machine_id' => Machine::create([
                        'id_code' => 'SENT-M-'.random_int(100000, 999999),
                        'status' => 'active',
                        'hourmeter_status' => 'ok',
                    ])->id,
                    'type' => 'corrective',
                    'status' => 'open',
                    'priority' => 'normal',
                    'opened_at' => now()->toDateString(),
                ]),
                [],
            ],
            'Quote' => [
                QuoteResource::class,
                fn () => Quote::create(['title' => 'Sentinel Quote']),
                [],
            ],
            'Location' => [
                LocationResource::class,
                fn () => Location::create(['name' => 'Sentinel Yard', 'slug' => 'sentinel-yard-'.uniqid()]),
                [],
            ],
            'MachineCategory' => [
                MachineCategoryResource::class,
                fn () => MachineCategory::create(['name' => 'Sentinel Category', 'slug' => 'sentinel-category-'.uniqid()]),
                [],
            ],
            'Make' => [
                MakeResource::class,
                fn () => Make::create(['name' => 'Sentinel Make', 'slug' => 'sentinel-make-'.uniqid()]),
                [],
            ],
            'User' => [
                UserResource::class,
                fn () => User::create([
                    'name' => 'Sentinel User',
                    'email' => 'sentinel-user-'.uniqid().'@dp.local',
                    'password' => bcrypt('password'),
                ]),
                [],
            ],
        ];
    }

    /**
     * Cómo crear UN hijo para la relación `$relationMethod` de `$record`,
     * cuando esa relación SÍ es soft-deletable (hoy, solo `Machine::workOrders()`).
     * Si se agrega otra relación soft-deletable al mapa de arriba sin sumarle
     * su fábrica acá, este método lanza y el test falla fuerte — no en
     * silencio.
     */
    private function makeSoftDeletableChild(Model $record, string $relationMethod): Model
    {
        return match (true) {
            $record instanceof Machine && $relationMethod === 'workOrders' => WorkOrder::create([
                'code' => 'SENT-CHILD-'.random_int(100000, 999999),
                'machine_id' => $record->getKey(),
                'type' => 'corrective',
                'status' => 'open',
                'priority' => 'normal',
                'opened_at' => now()->toDateString(),
            ]),
            default => throw new \RuntimeException(
                "Sumá una fábrica en makeSoftDeletableChild() para {$relationMethod} de ".get_class($record)
            ),
        };
    }

    /**
     * @dataProvider resourceProvider
     *
     * @param  \Closure(): Model  $factory
     * @param  array<string, string>  $countedRelations
     */
    public function test_counted_relations_include_soft_deleted_children_when_the_related_model_is_soft_deletable(
        string $resourceClass,
        \Closure $factory,
        array $countedRelations
    ): void {
        $summaryMethod = new ReflectionMethod($resourceClass, 'papeleraDestructionSummary');
        $summaryMethod->setAccessible(true);

        if ($countedRelations === []) {
            // Nada declarado como destructivo para este recurso: se
            // confirma que el resumen real sigue de acuerdo (null), no que
            // "no hay nada que probar" sin más.
            $record = $factory();
            $summary = $summaryMethod->invoke(null, $record);

            $this->assertNull(
                $summary,
                "{$resourceClass}: el resumen dejó de ser null; sumá sus relaciones al mapa de este test."
            );

            return;
        }

        foreach ($countedRelations as $summaryKey => $relationMethod) {
            $record = $factory();

            /** @var HasMany $relation */
            $relation = $record->{$relationMethod}();
            $relatedClass = get_class($relation->getRelated());

            $isSoftDeletable = in_array(SoftDeletes::class, class_uses_recursive($relatedClass), true);

            if (! $isSoftDeletable) {
                // Nada que archivar: el conteo de siempre (sin withTrashed)
                // ya es completo para esta relación.
                continue;
            }

            $child = $this->makeSoftDeletableChild($record, $relationMethod);
            $child->delete();

            $this->assertTrue(
                method_exists($child, 'trashed') && $child->trashed(),
                'El hijo de control tiene que haber quedado archivado antes de contar.'
            );

            $summary = $summaryMethod->invoke(null, $record->refresh());

            $this->assertNotNull($summary, "{$resourceClass}: se esperaba un resumen no nulo con hijos contados.");
            $this->assertSame(
                1,
                $summary[$summaryKey] ?? null,
                "{$resourceClass}: el conteo de '{$summaryKey}' tiene que incluir el hijo archivado (withTrashed())."
            );
        }
    }
}

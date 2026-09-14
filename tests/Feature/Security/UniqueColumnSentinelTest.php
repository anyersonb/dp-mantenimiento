<?php

namespace Tests\Feature\Security;

use App\Filament\Resources\FleetAttachmentResource\Pages\CreateFleetAttachment;
use App\Filament\Resources\LocationResource\Pages\CreateLocation;
use App\Filament\Resources\MachineCategoryResource\Pages\CreateMachineCategory;
use App\Filament\Resources\MachineResource\Pages\CreateMachine;
use App\Filament\Resources\MakeResource\Pages\CreateMake;
use App\Filament\Resources\RoleResource\Pages\CreateRole;
use App\Filament\Resources\UserResource\Pages\CreateUser;
use App\Filament\Resources\WorkOrderResource\Pages\CreateWorkOrder;
use App\Models\FleetAttachment;
use App\Models\Location;
use App\Models\Machine;
use App\Models\MachineCategory;
use App\Models\Make;
use App\Models\User;
use App\Models\WorkOrder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Centinela de columnas únicas — hallazgo E6-07.
 *
 * Reproducido dos veces con dos tablas distintas: un valor duplicado en una
 * columna con índice único devolvía **500 en `/livewire/update`** y la pantalla
 * no mostraba **nada** (ni error de campo, ni notificación, ni página de error).
 * El usuario apretaba "Crear" y no pasaba nada. Cinco de las siete columnas
 * únicas que se editan desde el panel no tenían validación.
 *
 * Este centinela existe porque arreglar campo por campo no alcanza: el próximo
 * Resource nace otra vez sin la validación. Va contra **el esquema**, no contra
 * una lista escrita a mano:
 *
 *  1. `test_every_unique_index_is_declared` enumera los índices únicos reales de
 *     la base y falla si alguno no está ni en `RECETAS` (se prueba) ni en
 *     `FUERA_DE_ALCANCE` (se justifica por escrito). Agregar una columna única
 *     nueva obliga a decidir cuál de las dos cosas es.
 *  2. `test_a_duplicate_is_rejected_with_a_visible_message` prueba el
 *     comportamiento por cada receta: crea una fila, intenta crear otra igual
 *     **por el formulario** y exige un error de validación visible en un campo
 *     y ninguna fila nueva en la tabla.
 *
 * Se prueba comportamiento y no la presencia de `->unique()` a propósito: en las
 * tres tablas con `slug` el índice está en una columna `Hidden` derivada del
 * nombre, así que la protección correcta vive en OTRO campo (ver
 * App\Rules\UniqueSlugFrom). Un centinela que mirara el nombre de la columna las
 * daría por desprotegidas, o peor, aceptaría un `->unique()` sobre un campo
 * invisible cuyo error nadie ve.
 */
class UniqueColumnSentinelTest extends TestCase
{
    use RefreshDatabase;

    /**
     * tabla => [página de crear, campo del formulario que se prueba, valor,
     *           datos del resto del formulario, cómo crear la fila que ya existe]
     */
    private const RECETAS = [
        'machines' => 'machines',
        'work_orders' => 'work_orders',
        'locations' => 'locations',
        'machine_categories' => 'machine_categories',
        'makes' => 'makes',
        'users' => 'users',
        'roles' => 'roles',
        'fleet_attachments' => 'fleet_attachments',
    ];

    /**
     * Índices únicos que NO se prueban, cada uno con su motivo. No es una
     * válvula de escape: es una decisión escrita.
     */
    private const FUERA_DE_ALCANCE = [
        'failed_jobs' => 'infraestructura de Laravel (uuid), sin formulario en el panel.',
        'permissions' => 'índice compuesto name+guard_name; los permisos los crea el seeder, no hay formulario.',
        'quotes' => 'share_token lo genera el sistema y no existe como campo del formulario.',
        'migrations' => 'tabla interna del framework.',
        'settings' => 'clave/valor interna (tax_rate, etc.); no hay formulario del panel que cree filas por key. '
            .'App\Models\Setting::set() hace updateOrCreate por código, así que un duplicado de key nunca puede '
            .'llegar desde una pantalla.',
    ];

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);
    }

    /**
     * Índices únicos reales del esquema, leídos con la API de Schema y no con
     * `information_schema`: la suite corre sobre SQLite en memoria y producción
     * es MySQL, así que una consulta específica de un motor mediría el esquema
     * equivocado (o ninguno).
     *
     * @return array<int, array{table: string, index: string, columns: string}>
     */
    private function uniqueIndexes(): array
    {
        $indices = [];

        foreach (Schema::getTableListing() as $tabla) {
            // Los listados traen el prefijo del esquema en algunos drivers.
            $tabla = str_contains($tabla, '.') ? substr($tabla, strrpos($tabla, '.') + 1) : $tabla;

            foreach (Schema::getIndexes($tabla) as $indice) {
                if (! ($indice['unique'] ?? false) || ($indice['primary'] ?? false)) {
                    continue;
                }

                $indices[] = [
                    'table' => $tabla,
                    'index' => $indice['name'] ?? '(sin nombre)',
                    'columns' => implode(',', $indice['columns'] ?? []),
                ];
            }
        }

        return $indices;
    }

    /**
     * Antes de exigir nada hay que probar que el centinela VE algo: si
     * `Schema::getIndexes()` devolviera vacío en el driver de la suite, el test
     * de abajo pasaría sin medir nada y sería peor que no tenerlo.
     */
    public function test_the_sentinel_actually_reads_the_schema(): void
    {
        $tablas = array_unique(array_column($this->uniqueIndexes(), 'table'));

        foreach (['machines', 'work_orders', 'locations', 'makes', 'users'] as $esperada) {
            $this->assertContains(
                $esperada,
                $tablas,
                "El centinela no está viendo el índice único de {$esperada}: mediría el vacío."
            );
        }
    }

    public function test_every_unique_index_is_declared(): void
    {
        $sinDeclarar = [];

        foreach ($this->uniqueIndexes() as $indice) {
            $tabla = $indice['table'];

            if (isset(self::RECETAS[$tabla]) || isset(self::FUERA_DE_ALCANCE[$tabla])) {
                continue;
            }

            $sinDeclarar[] = "{$tabla}.{$indice['columns']} (índice {$indice['index']})";
        }

        $this->assertSame([], $sinDeclarar, implode("\n", [
            'Hay índices únicos que este centinela no cubre:',
            ...array_map(fn ($s) => "  - {$s}", $sinDeclarar),
            '',
            'Un duplicado en una columna única sin validación en el formulario es un 500',
            'sin ningún mensaje en pantalla (hallazgo E6-07, reproducido dos veces).',
            'Agregá una receta en RECETAS con su prueba de comportamiento, o una entrada',
            'en FUERA_DE_ALCANCE con el motivo por escrito.',
        ]));
    }

    public static function recetas(): array
    {
        return array_map(fn ($t) => [$t], array_values(self::RECETAS));
    }

    #[DataProvider('recetas')]
    public function test_a_duplicate_is_rejected_with_a_visible_message(string $tabla): void
    {
        [$pagina, $campo, $datos] = $this->receta($tabla);

        // El conteo se toma DESPUÉS del montaje: la receta crea la fila que ya
        // existe, y lo que se mide es que el intento duplicado no agregue otra.
        $antes = DB::table($tabla)->count();

        Livewire::actingAs(User::where('email', 'admin@dp.local')->firstOrFail())
            ->test($pagina)
            ->fillForm($datos)
            ->call('create')
            ->assertHasFormErrors([$campo]);

        $this->assertSame(
            $antes,
            DB::table($tabla)->count(),
            "El duplicado en {$tabla} no debe llegar a la base."
        );
    }

    /**
     * Monta la fila que ya existe y devuelve [página de crear, campo, datos del
     * formulario] que reproducen el duplicado.
     *
     * @return array{0: class-string, 1: string, 2: array<string, mixed>}
     */
    private function receta(string $tabla): array
    {
        switch ($tabla) {
            case 'machines':
                $ubicacion = Location::create(['name' => 'Patio', 'slug' => 'patio-'.uniqid()]);
                Machine::create([
                    'id_code' => 'DUP-001', 'status' => 'active',
                    'current_location_id' => $ubicacion->id, 'hourmeter_status' => 'ok',
                ]);

                return [
                    CreateMachine::class,
                    'id_code',
                    ['id_code' => 'DUP-001', 'status' => 'active', 'current_location_id' => $ubicacion->id],
                ];

            case 'work_orders':
                $ubicacion = Location::create(['name' => 'Patio', 'slug' => 'patio-'.uniqid()]);
                $maquina = Machine::create([
                    'id_code' => 'WO-DUP-M', 'status' => 'active',
                    'current_location_id' => $ubicacion->id, 'hourmeter_status' => 'ok', 'current_hours' => 100,
                ]);
                WorkOrder::create([
                    'code' => 'DUP-OT-1', 'machine_id' => $maquina->id, 'type' => 'preventive',
                    'status' => 'open', 'priority' => 'normal', 'opened_at' => now()->toDateString(),
                ]);

                return [
                    CreateWorkOrder::class,
                    'code',
                    [
                        'code' => 'DUP-OT-1', 'machine_id' => $maquina->id, 'type' => 'preventive',
                        'status' => 'open', 'priority' => 'normal', 'opened_at' => now()->toDateString(),
                    ],
                ];

            case 'locations':
                Location::create(['name' => 'Patio Norte', 'slug' => 'patio-norte', 'type' => 'yard']);

                return [
                    CreateLocation::class,
                    'name',
                    ['name' => 'Patio Norte', 'type' => 'yard'],
                ];

            case 'machine_categories':
                MachineCategory::create(['name' => 'Excavadora', 'slug' => 'excavadora']);

                return [
                    CreateMachineCategory::class,
                    'name',
                    ['name' => 'Excavadora'],
                ];

            case 'makes':
                Make::create(['name' => 'Caterpillar', 'slug' => 'caterpillar']);

                return [
                    CreateMake::class,
                    'name',
                    ['name' => 'Caterpillar'],
                ];

            case 'users':
                // admin@dp.local ya existe (seeder).
                return [
                    CreateUser::class,
                    'email',
                    ['name' => 'Otro Admin', 'email' => 'admin@dp.local', 'password' => 'password123', 'locale' => 'es'],
                ];

            case 'roles':
                // el rol "taller" ya existe (seeder).
                return [
                    CreateRole::class,
                    'name',
                    ['name' => 'taller'],
                ];

            case 'fleet_attachments':
                FleetAttachment::create(['id_code' => 'DUP-ATT-1', 'status' => 'active']);

                return [
                    CreateFleetAttachment::class,
                    'id_code',
                    ['id_code' => 'DUP-ATT-1', 'status' => 'active'],
                ];
        }

        $this->fail("Receta sin implementar para la tabla {$tabla}.");
    }
}

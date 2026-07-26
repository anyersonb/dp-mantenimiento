<?php

namespace Tests\Feature\Security;

use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * Test centinela — Etapa 06 (hallazgo A7, reconciliado).
 *
 * A7 (Etapa 05) nunca entró en un bloque de fix: escribir un número negativo
 * en un campo que alimenta una columna `int unsigned` no daba error de
 * validación, reventaba contra MySQL (`SQLSTATE 22003`, HTTP 500). El fix
 * puntual fue agregar `->minValue(0)` a los TextInput de Filament que
 * alimentan esas columnas. Este test evita que la próxima columna
 * `unsigned` nueva (o el próximo TextInput que la exponga) se cuele sin
 * piso, sin depender de que alguien la recuerde barrer a mano — así fue
 * como se encontró, en este mismo pase, que `ReadingsRelationManager`
 * exponía `horometer_readings.hours` (unsigned) sin `minValue(0)` pese a
 * que ya existía `min:0` en los 3 formularios de campo del mismo dato.
 *
 * CÓMO FUNCIONA (y su límite, con honestidad):
 * 1. Lee todas las migraciones y junta los nombres de columna declarados
 *    `unsigned*` (`unsignedInteger`, `unsignedBigInteger`, etc.), excluyendo
 *    `id` y cualquier columna que termine en `_id` (esas son PK/FK de
 *    framework — Filament ya las maneja con Select/relationship, no con un
 *    TextInput numérico con piso de negocio).
 * 2. Para cada nombre de columna así detectado, escanea TODO el código
 *    fuente de `app/Filament/**` buscando `TextInput::make('esa_columna')`
 *    y exige que la cadena fluida de ese componente contenga `minValue(`.
 *
 * Es un análisis estático por texto (regex), no una introspección real del
 * `Form` de Filament ya construido — no ejecuta `::form()` ni instancia
 * componentes. Dos límites conocidos, aceptados a propósito:
 *   a) Si dos tablas distintas tuvieran una columna `unsigned` con el MISMO
 *      nombre y una de ellas SÍ necesitara piso y la otra no, este test las
 *      trataría igual (falso positivo posible). No ocurre hoy en el schema.
 *   b) Solo verifica que exista `minValue(` en la cadena, no que el valor
 *      sea exactamente `0` (aceptaría por error un `minValue(-5)`). Cubre
 *      el caso real de A7 (ausencia total de piso), no una validación de
 *      qué piso exacto se declaró.
 *
 * `remaining_hours` y `hours_adjustment` (int FIRMADO, a propósito: una
 * máquina vencida tiene horas restantes negativas) nunca entran a esta
 * lista porque no son columnas `unsigned` — no hace falta excepción manual.
 */
class UnsignedColumnFloorSentinelTest extends TestCase
{
    /**
     * Columnas `unsigned` que hoy NO tienen ningún TextInput de Filament
     * (son puramente programáticas o solo de seeder). El test no necesita
     * excluirlas a mano: al no existir ningún `TextInput::make('...')` que
     * las nombre, simplemente no generan ninguna cadena para revisar y por
     * lo tanto no pueden fallar. Se documentan igual para que quede
     * explícito por qué no aparecen nunca en el listado de fallos:
     *
     * - `machines.remaining_anchor_at_hours`: solo la escriben
     *   HourmeterReplacementService / PmServiceReportImporter.
     * - `work_orders.hours_at_open`: solo lo escribe la acción de
     *   AlertResource (`'hours_at_open' => $machine->current_hours`).
     * - `checklist_template_items.sort`: solo seeder, sin UI.
     * - `jobs.attempts` / `reserved_at` / `available_at` / `created_at`:
     *   columnas internas de la cola, no de negocio.
     */
    public function test_every_filament_text_input_feeding_an_unsigned_column_declares_a_floor(): void
    {
        $unsignedColumns = $this->discoverUnsignedColumnNames();
        $this->assertNotEmpty($unsignedColumns, 'No se detectó ninguna columna unsigned en las migraciones — revisá el regex del test.');

        $failures = [];

        foreach ($this->discoverFilamentPhpFiles() as $file) {
            $source = File::get($file);

            foreach ($unsignedColumns as $column) {
                foreach ($this->textInputChainsFor($source, $column) as $chain) {
                    if (! str_contains($chain, 'minValue(')) {
                        $failures[] = sprintf(
                            '%s :: TextInput::make(\'%s\') alimenta una columna unsigned pero no declara ->minValue(0). Hallazgo A7.',
                            $this->relativePath($file),
                            $column
                        );
                    }
                }
            }
        }

        $this->assertSame([], $failures, "TextInput sin piso sobre columnas unsigned:\n - ".implode("\n - ", $failures));
    }

    /**
     * @return array<int, string>
     */
    private function discoverUnsignedColumnNames(): array
    {
        $columns = [];

        foreach (File::glob(database_path('migrations/*.php')) as $file) {
            $source = File::get($file);

            if (preg_match_all(
                '/->unsigned(?:BigInteger|Integer|TinyInteger|SmallInteger|MediumInteger)\(\s*[\'"]([a-zA-Z0-9_]+)[\'"]/',
                $source,
                $matches
            )) {
                foreach ($matches[1] as $column) {
                    if ($column === 'id' || str_ends_with($column, '_id')) {
                        continue;
                    }

                    $columns[$column] = true;
                }
            }
        }

        return array_keys($columns);
    }

    /**
     * @return array<int, string>
     */
    private function discoverFilamentPhpFiles(): array
    {
        $files = [];

        foreach (File::allFiles(app_path('Filament')) as $file) {
            if ($file->getExtension() === 'php') {
                $files[] = $file->getPathname();
            }
        }

        return $files;
    }

    /**
     * Devuelve el resto de la cadena fluida de cada
     * `TextInput::make('column')` encontrado en $source, hasta justo antes
     * de la siguiente invocación `::make(` (de cualquier componente) o el
     * final del archivo.
     *
     * @return array<int, string>
     */
    private function textInputChainsFor(string $source, string $column): array
    {
        $pattern = '/TextInput::make\(\s*[\'"]'.preg_quote($column, '/').'[\'"]\s*\)(.*?)(?=::make\(|\z)/s';

        if (! preg_match_all($pattern, $source, $matches)) {
            return [];
        }

        return $matches[1];
    }

    private function relativePath(string $absolute): string
    {
        return str_replace(base_path().DIRECTORY_SEPARATOR, '', $absolute);
    }
}

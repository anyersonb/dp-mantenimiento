<?php

namespace Tests\Feature\Security;

use Tests\TestCase;

/**
 * Centinela de los caminos de escritura de horómetro — hallazgo E6-13.
 *
 * Historia de este defecto, que es siempre el mismo:
 *
 *   C3    — la regla existía en un camino y no en el otro.
 *   A4    — la fórmula del horómetro estaba duplicada.
 *   E6-03 — el rechazo de lectura regresiva vivía solo en `app/Livewire/Field/`
 *           y no en el panel; se extrajo a `App\Rules\CoherentHorometerReading`.
 *   E6-13 — el **importador** tampoco la tenía, y encima forzaba `current_hours`
 *           con la lectura del reporte aunque fuera más vieja. Le pasó a EX027
 *           con el archivo real del cliente.
 *
 * Cuatro veces el mismo patrón. Este centinela existe para que la quinta no
 * dependa de que alguien se acuerde: enumera **todos** los archivos que crean
 * lecturas de horómetro o escriben `current_hours`, y exige que cada uno consuma
 * la regla compartida o esté declarado con su motivo por escrito.
 */
class HorometerWritePathSentinelTest extends TestCase
{
    /**
     * Marcadores que acreditan que el archivo pasa por la regla compartida.
     */
    private const MARCADORES = [
        'CoherentHorometerReading',          // la regla, directo
        'RejectsIncoherentReadings',         // el adaptador del camino de campo
        'recalculateHoursFromReadings',      // el recálculo completo, que respeta el piso
        'computeHoursFromReadings',
        // Un cambio de ESCALA es la única forma legítima de bajar el horómetro,
        // y solo vale si declara desde cuándo corre la escala nueva: sin esa
        // frontera, el recálculo vuelve a la escala vieja (E6-13, séptimo camino).
        'hours_scale_since',
    ];

    /**
     * Caminos que NO consumen la regla, con el motivo. No es una válvula de
     * escape: cada línea es una decisión que alguien tiene que poder discutir.
     */
    private const EXCEPCIONES = [
        'app/Models/Machine.php' => 'es la implementación de la regla, no un consumidor.',
        'app/Rules/CoherentHorometerReading.php' => 'es la regla misma.',
        'app/Console/Commands/AuditRemainingHours.php' => 'solo lee y reporta; no escribe.',
        'app/Filament/Widgets/DueSoonMachines.php' => 'widget de solo lectura.',
        'app/Filament/Resources/MachineResource/Pages/ViewMachine.php' => 'pantalla de solo lectura.',
        'database/seeders/FleetSeeder.php' => 'carga inicial completa sobre base vacía: no hay historial previo que contradecir.',
        'database/migrations' => 'las migraciones son estado declarado, no un camino de escritura de usuario.',
    ];

    /**
     * @return array<int, string>
     */
    private function archivosQueEscribenHorometro(): array
    {
        $encontrados = [];

        foreach ([app_path(), base_path('database')] as $raiz) {
            $iterador = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($raiz));

            foreach ($iterador as $archivo) {
                if (! $archivo->isFile() || $archivo->getExtension() !== 'php') {
                    continue;
                }

                $codigo = file_get_contents($archivo->getPathname());
                $relativo = str_replace('\\', '/', str_replace(base_path().DIRECTORY_SEPARATOR, '', $archivo->getPathname()));

                $creaLectura = preg_match('/HorometerReading::create\(|readings\(\)->create\(/', $codigo) === 1;
                $escribeHoras = preg_match('/[\'"]current_hours[\'"]\s*=>/', $codigo) === 1
                    || preg_match('/->current_hours\s*=[^=]/', $codigo) === 1;
                // El relation manager del panel escribe lecturas por el form de
                // Filament, sin un ::create() literal.
                $esRelationManagerDeLecturas = str_contains($relativo, 'ReadingsRelationManager');

                if ($creaLectura || $escribeHoras || $esRelationManagerDeLecturas) {
                    $encontrados[$relativo] = $codigo;
                }
            }
        }

        return $encontrados;
    }

    public function test_the_sentinel_sees_the_known_write_paths(): void
    {
        $archivos = array_keys($this->archivosQueEscribenHorometro());

        // Si el descubrimiento se rompe, el test de abajo pasaría en vacío.
        foreach ([
            'app/Services/PmServiceReportImporter.php',
            'app/Livewire/Field/ReportForm.php',
            'app/Filament/Resources/MachineResource/RelationManagers/ReadingsRelationManager.php',
        ] as $esperado) {
            $this->assertContains($esperado, $archivos, "El centinela no está viendo {$esperado}.");
        }
    }

    public function test_every_horometer_write_path_consumes_the_shared_rule(): void
    {
        $sinRegla = [];

        foreach ($this->archivosQueEscribenHorometro() as $relativo => $codigo) {
            $exento = false;
            foreach (array_keys(self::EXCEPCIONES) as $patron) {
                if (str_starts_with($relativo, $patron)) {
                    $exento = true;
                    break;
                }
            }

            if ($exento) {
                continue;
            }

            $tieneMarcador = false;
            foreach (self::MARCADORES as $marcador) {
                if (str_contains($codigo, $marcador)) {
                    $tieneMarcador = true;
                    break;
                }
            }

            if (! $tieneMarcador) {
                $sinRegla[] = $relativo;
            }
        }

        $this->assertSame([], $sinRegla, implode("\n", [
            'Estos caminos escriben horómetro sin pasar por la regla compartida:',
            ...array_map(fn ($f) => "  - {$f}", $sinRegla),
            '',
            'Es el defecto de C3 / A4 / E6-03 / E6-13: la regla en un camino y no en los demás.',
            'Consumí App\Rules\CoherentHorometerReading (o el recálculo completo de Machine),',
            'o agregá el archivo a EXCEPCIONES con el motivo por escrito.',
        ]));
    }
}

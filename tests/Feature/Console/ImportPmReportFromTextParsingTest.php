<?php

namespace Tests\Feature\Console;

use App\Console\Commands\ImportPmReportFromText;
use Tests\TestCase;

/**
 * `pm:import-text` convierte el .txt de `pdftotext -table` al mismo formato
 * que ya lee PmServiceReportImporter. Estas pruebas cubren los 5 defectos
 * encontrados contra el informe real del 9/04/2026 (80 máquinas en papel,
 * "66 fila(s) leídas" en la corrida vieja, sin ningún indicio de las 14 que
 * se perdían en silencio):
 *
 *   A. El paréntesis del kilometraje ("5700 Hrs (79637Mi.) 8/14/26") rompía
 *      el emparejado número/fecha y dejaba último servicio y última lectura
 *      vacíos en cuatro volquetes (TD006-TD009).
 *   B. Las celdas del informe dicen "Mi"/"Mi." (nunca "Mls"); una lectura en
 *      millas debe rechazarse con motivo visible, nunca guardarse en
 *      `horometer_readings.hours` (esa columna no tiene unidad).
 *   C. Toda fila descartada se tiraba en silencio; ahora se informa con
 *      motivo + renglón crudo, y el resumen distingue "máquinas en el texto"
 *      de "máquinas con datos legibles".
 *   D. Una lectura con fecha posterior a la del informe, o más vieja que 3
 *      años, se rechaza (típico error de tipeo: TD007 trae "8/21/22" en vez
 *      de "8/21/26").
 *   E. Una lectura enteramente entre paréntesis ("(29959 Mi) 10/29/25") es
 *      una estimación declarada por el cliente ("Odometer broken"), no una
 *      medición firme: se rechaza con motivo propio, distinto de "millas".
 *
 * Se prueba llamando al método privado `parse()` por reflexión (evita
 * depender de máquinas en la base de datos: el comando en modo simulación
 * no escribe nada) y, para el defecto C, también de punta a punta por la
 * salida de consola, que es lo que de verdad ve quien corre el comando.
 */
class ImportPmReportFromTextParsingTest extends TestCase
{
    private function parse(string $fixture): array
    {
        $command = new ImportPmReportFromText;

        $method = new \ReflectionMethod($command, 'parse');
        $method->setAccessible(true);

        $lineas = file(__DIR__.'/../../fixtures/'.$fixture, FILE_IGNORE_NEW_LINES);

        return $method->invoke($command, $lineas);
    }

    private function porId(array $registros, string $id): ?array
    {
        foreach ($registros as $r) {
            if ($r['id'] === $id) {
                return $r;
            }
        }

        return null;
    }

    private function descartePorId(array $descartes, string $id): ?array
    {
        foreach ($descartes as $d) {
            if ($d['id'] === $id) {
                return $d;
            }
        }

        return null;
    }

    /** Defecto A: el paréntesis del kilometraje ya no rompe el emparejado. */
    public function test_defecto_a_el_parentesis_no_rompe_el_emparejado_numero_fecha(): void
    {
        $resultado = $this->parse('pm_report_defecto_a_td00x.txt');

        // El caso normal (sin paréntesis) sigue funcionando: control de que
        // el fix no rompió lo que ya andaba.
        $ex010 = $this->porId($resultado['registros'], 'EX010');
        $this->assertNotNull($ex010);
        $this->assertSame('9708 Hrs 5/04/26', $ex010['last']);
        $this->assertSame('9867 Hrs 9/03/26', $ex010['reading']);
        $this->assertSame('341 Hrs', $ex010['remaining']);

        // TD006, TD008, TD009: el margen en millas se descarta, las horas
        // quedan pobladas en los dos pares.
        foreach (['TD006' => ['5700 Hrs 8/14/26', '5700 Hrs 8/14/26', '500 Hrs'],
            'TD008' => ['9800 Hrs 8/21/26', '9800 Hrs 8/21/26', '500 Hrs'],
            'TD009' => ['8935 Hrs 8/03/26', '8935 Hrs 8/03/26', '500 Hrs'],
        ] as $id => [$last, $reading, $remaining]) {
            $r = $this->porId($resultado['registros'], $id);
            $this->assertNotNull($r, "{$id} debería quedar con datos legibles.");
            $this->assertSame($last, $r['last'], "{$id}.last");
            $this->assertSame($reading, $r['reading'], "{$id}.reading");
            $this->assertSame($remaining, $r['remaining'], "{$id}.remaining");
        }
    }

    /**
     * Verificación directa pedida en el brief: contra la línea con el
     * paréntesis en medio, la regex real del comando pasa de 0 a 2
     * coincidencias, y contra el caso normal (sin paréntesis) sigue dando 2.
     */
    public function test_regex_defecto_a_da_dos_coincidencias_con_y_sin_parentesis(): void
    {
        $regex = (new \ReflectionClass(ImportPmReportFromText::class))->getConstant('PAR_HORAS_FECHA');

        $conParentesis = '5700 Hrs (79637Mi.) 8/14/26                    5700 Hrs (79637Mi) 8/14/26    500 Hrs';
        $sinParentesis = '9708 Hrs 5/04/26     9867 Hrs 9/03/26         341 Hrs';

        $this->assertSame(2, preg_match_all($regex, $conParentesis, $m));
        $this->assertSame(2, preg_match_all($regex, $sinParentesis, $m));
    }

    /**
     * Defecto B: TF005, VAC001, MOT02 y TT003 se miden en millas ("Mi"/"Mi.")
     * y se rechazan ENTEROS con motivo visible — nunca se guarda un número en
     * millas como si fueran horas (MOT02 tiene "restantes" en horas, pero la
     * fila entera se descarta igual: mezclar un dato no soportado con uno
     * parcial es peor que pedirle a un humano que la revise).
     */
    public function test_defecto_b_lectura_en_millas_se_rechaza_entera_con_motivo(): void
    {
        $resultado = $this->parse('pm_report_defecto_b_millas.txt');

        $this->assertSame([], $resultado['registros'], 'Ninguna de las 4 máquinas en millas debería quedar como importable.');
        $this->assertSame(4, $resultado['total']);

        foreach (['TF005', 'VAC001', 'MOT02', 'TT003'] as $id) {
            $d = $this->descartePorId($resultado['descartes'], $id);
            $this->assertNotNull($d, "{$id} debería aparecer en la tabla de descartes.");
            $this->assertSame('lectura en millas (no soportado)', $d['motivo']);
        }

        // Ningún número que venga acompañado de "Mi"/"Mi." puede colarse como
        // si fueran horas en ningún campo importable (el renglón crudo de la
        // tabla de descartes SÍ debe mostrar el dato original, para que quien
        // lo revise sepa qué se rechazó).
        $soloImportables = json_encode($resultado['registros']);
        $this->assertStringNotContainsString('132125', $soloImportables);
        $this->assertStringNotContainsString('150297', $soloImportables);
        $this->assertStringNotContainsString('413988', $soloImportables);
    }

    /**
     * Defecto C: nada se pierde en silencio. EX010 (normal) se importa,
     * RL010 ("Not in service") y ZZ999 (sin ningún dato legible) quedan en la
     * tabla de descartes con motivos DISTINTOS, y el conteo total (3 en el
     * texto) ya no se confunde con el de importables (1).
     */
    public function test_defecto_c_informe_de_descartes_distingue_motivos_y_cuenta_el_total(): void
    {
        $resultado = $this->parse('pm_report_defecto_c_descartes.txt');

        $this->assertSame(3, $resultado['total']);
        $this->assertCount(1, $resultado['registros']);
        $this->assertNotNull($this->porId($resultado['registros'], 'EX010'));

        $this->assertCount(2, $resultado['descartes']);

        $rl010 = $this->descartePorId($resultado['descartes'], 'RL010');
        $this->assertSame('fuera de servicio', $rl010['motivo']);
        $this->assertStringContainsString('Not in service', $rl010['crudo']);

        $zz999 = $this->descartePorId($resultado['descartes'], 'ZZ999');
        $this->assertSame('sin lectura en el informe', $zz999['motivo']);

        // Un "N fila(s) leídas" en verde no puede volver a esconder esto: la
        // salida real de consola tiene que mostrar los dos números y la tabla.
        $this->artisan('pm:import-text', ['file' => __DIR__.'/../../fixtures/pm_report_defecto_c_descartes.txt'])
            ->assertExitCode(0)
            ->expectsOutputToContain('3 máquina(s) en el texto del informe, 1 con datos legibles para importar.')
            ->expectsOutputToContain('fuera de servicio')
            ->expectsOutputToContain('sin lectura en el informe');
    }

    /**
     * Defecto D: una lectura con fecha posterior al informe o más vieja que
     * 3 años se rechaza. Caso real: TD007 trae "8/21/22" (error de tipeo,
     * debería ser "8/21/26"). El último servicio de TD007 (fecha 8/21/26,
     * válida) SÍ se conserva; solo se rechaza el par con la fecha basura, y
     * queda declarado en la tabla de descartes — no silenciado ni aceptado.
     */
    public function test_defecto_d_fecha_invalida_se_rechaza_con_motivo_visible(): void
    {
        $resultado = $this->parse('pm_report_defecto_a_td00x.txt');

        $td007 = $this->porId($resultado['registros'], 'TD007');
        $this->assertNotNull($td007, 'TD007 debería seguir siendo importable por su último servicio y sus restantes.');
        $this->assertSame('9892 Hrs 8/21/26', $td007['last']);
        $this->assertSame('500 Hrs', $td007['remaining']);
        $this->assertSame('', $td007['reading'], 'La lectura con fecha "8/21/22" no debe aceptarse como buena.');

        $descarte = $this->descartePorId($resultado['descartes'], 'TD007');
        $this->assertNotNull($descarte, 'TD007 debe quedar visible en la tabla de descartes por su fecha inválida.');
        $this->assertSame('fecha inválida', $descarte['motivo']);
        $this->assertStringContainsString('8/21/22', $descarte['crudo']);
    }

    /**
     * fechaEsValida() (REVISADA): ya no impone un piso de "3 años atrás".
     * Ese piso castigaba el último servicio LEGÍTIMO de una máquina de poco
     * uso (LD037, BT002, GR003, RL013 en el informe real: caían por "fecha
     * inválida" sin que hubiera nada corrupto). Lo único físicamente
     * imposible es una fecha POSTERIOR al informe; la antigüedad por sí
     * sola ya no rechaza nada. Ver `test_defecto_d_revisado_*` para la
     * señal que reemplaza al piso: coherencia interna del renglón.
     */
    public function test_defecto_d_revisado_limites_de_fechaesvalida(): void
    {
        $command = new ImportPmReportFromText;
        $method = new \ReflectionMethod($command, 'fechaEsValida');
        $method->setAccessible(true);

        $informe = new \DateTimeImmutable('2026-09-04');

        $this->assertTrue($method->invoke($command, new \DateTimeImmutable('2026-09-04'), $informe), 'la misma fecha del informe es válida');
        $this->assertFalse($method->invoke($command, new \DateTimeImmutable('2026-09-05'), $informe), 'un día después del informe es inválida');
        $this->assertTrue($method->invoke($command, new \DateTimeImmutable('2023-09-04'), $informe), 'justo 3 años antes ya es válida (el piso se eliminó)');
        $this->assertTrue($method->invoke($command, new \DateTimeImmutable('2015-01-01'), $informe), 'una fecha muy vieja, por sí sola, ya no es motivo de rechazo');
    }

    /**
     * Defecto D (revisado): un último servicio genuinamente viejo (2022,
     * más de 3 años antes del informe de 2026) pero COHERENTE con su propia
     * lectura (la lectura es posterior) ya no debe rechazarse. Antes, el
     * piso de "3 años" lo tiraba como "fecha inválida" sin que hubiera nada
     * corrupto — el caso real de LD037/BT002/GR003/RL013.
     */
    public function test_defecto_d_revisado_ultimo_servicio_viejo_pero_coherente_no_se_rechaza(): void
    {
        $resultado = $this->parse('pm_report_ultimo_servicio_viejo.txt');

        $ld100 = $this->porId($resultado['registros'], 'LD100');
        $this->assertNotNull($ld100, 'Un último servicio viejo pero coherente con su lectura no debe descartarse.');
        $this->assertSame('4200 Hrs 6/15/22', $ld100['last']);
        $this->assertSame('9500 Hrs 9/01/26', $ld100['reading']);
        $this->assertSame('300 Hrs', $ld100['remaining']);

        $this->assertNull($this->descartePorId($resultado['descartes'], 'LD100'));
    }

    /**
     * Defecto B (revisado, 5 grafías): "Mi", "Mi.", "Mls", "Ml" y "Miles"
     * tienen que rechazarse TODAS con el mismo motivo, sin excepción. El
     * hallazgo de seguridad fue justamente que "Mls" —la abreviatura que
     * usa el propio encabezado del reporte ("Remanining Hrs/Mls")— colaba
     * como horas porque solo "Mi"/"Mi." estaban cubiertas.
     */
    public function test_defecto_b_revisado_todas_las_grafias_de_millas_se_rechazan(): void
    {
        $resultado = $this->parse('pm_report_grafias_millas.txt');

        $this->assertSame([], $resultado['registros'], 'Ninguna de las 5 grafías de millas debería quedar como importable.');
        $this->assertSame(5, $resultado['total']);

        foreach (['MG001', 'MG002', 'MG003', 'MG004', 'MG005'] as $id) {
            $d = $this->descartePorId($resultado['descartes'], $id);
            $this->assertNotNull($d, "{$id} debería aparecer en la tabla de descartes.");
            $this->assertSame('lectura en millas (no soportado)', $d['motivo']);
        }

        // Ningún número que venga acompañado de alguna grafía de millas
        // puede colarse como si fueran horas en ningún campo importable.
        $soloImportables = json_encode($resultado['registros']);
        $this->assertStringNotContainsString('146037', $soloImportables);
        $this->assertStringNotContainsString('150297', $soloImportables);
    }

    /**
     * Defecto B (revisado, TW007): "17309 Mi (2730 Hrs) 4/15/26" es la
     * imagen espejo de TD006 — la lectura PRIMARIA está en millas y el
     * paréntesis trae el equivalente en horas como margen. Hoy cae como
     * "estimación entre paréntesis (no firme)" (el motivo de TW004, que es
     * un caso distinto: su lectura entera vive DENTRO del paréntesis, sin
     * primaria). El descarte de TW007 es correcto; el motivo tiene que ser
     * "lectura en millas (no soportado)".
     */
    public function test_defecto_b_revisado_tw007_lectura_primaria_en_millas_con_margen_en_horas(): void
    {
        $resultado = $this->parse('pm_report_tw007.txt');

        $this->assertSame([], $resultado['registros']);
        $this->assertSame(1, $resultado['total']);

        $d = $this->descartePorId($resultado['descartes'], 'TW007');
        $this->assertNotNull($d);
        $this->assertSame('lectura en millas (no soportado)', $d['motivo']);
        $this->assertStringContainsString('17309', $d['crudo']);
    }

    /**
     * Hallazgo de seguridad (MEDIO): un .txt de origen puede traer códigos
     * de control C0 (ESC, BEL) incrustados. No son `\s`, así que sobreviven
     * al collapse de espacios de `recortar()` y llegaban intactos a la
     * consola, donde pueden borrar/pisar líneas ya impresas. El renglón
     * crudo que se guarda para la tabla de descartes no debe contener esos
     * bytes, pero sí debe conservar el texto legible.
     */
    public function test_seguridad_codigos_de_control_no_sobreviven_en_el_renglon_crudo(): void
    {
        $resultado = $this->parse('pm_report_control_chars.txt');

        $d = $this->descartePorId($resultado['descartes'], 'RL099');
        $this->assertNotNull($d);
        $this->assertSame('fuera de servicio', $d['motivo']);

        $this->assertStringNotContainsString(chr(27), $d['crudo'], 'El byte ESC no debe sobrevivir al recorte.');
        $this->assertStringNotContainsString(chr(7), $d['crudo'], 'El byte BEL no debe sobrevivir al recorte.');
        $this->assertStringContainsString('Not in service', $d['crudo'], 'El texto legible debe seguir presente.');
    }

    /**
     * Defecto E: TW004 trae sus dos lecturas enteramente entre paréntesis
     * ("Odometer broken"). Es una estimación declarada, no una medición: se
     * rechaza con SU PROPIO motivo, distinto de "lectura en millas", aunque
     * hoy caiga también por millas.
     */
    public function test_defecto_e_lectura_entre_parentesis_se_rechaza_como_estimacion(): void
    {
        $resultado = $this->parse('pm_report_defecto_e_estimacion.txt');

        $this->assertSame([], $resultado['registros']);
        $this->assertSame(1, $resultado['total']);

        $d = $this->descartePorId($resultado['descartes'], 'TW004');
        $this->assertNotNull($d);
        $this->assertSame('estimación entre paréntesis (no firme)', $d['motivo']);
        $this->assertStringContainsString('Odometer broken', $d['crudo']);
    }

    /**
     * No confundir el margen del defecto A (paréntesis DESPUÉS de un
     * "<horas> Hrs" válido, dato al margen) con el paréntesis del defecto E
     * (la lectura ENTERA dentro del paréntesis, sin horas previas): TD006 no
     * debe caer en "estimación entre paréntesis" ni en "millas".
     */
    public function test_margen_entre_parentesis_del_defecto_a_no_se_confunde_con_la_estimacion_del_e(): void
    {
        $resultado = $this->parse('pm_report_defecto_a_td00x.txt');

        $this->assertEmpty($this->descartePorId($resultado['descartes'], 'TD006'));
        $this->assertEmpty($this->descartePorId($resultado['descartes'], 'TD008'));
        $this->assertEmpty($this->descartePorId($resultado['descartes'], 'TD009'));
    }
}

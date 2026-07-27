<?php

namespace Tests\Feature\Management;

use App\Models\HorometerReading;
use App\Models\Location;
use App\Models\Machine;
use App\Services\PmServiceReportImporter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\TestCase;

/**
 * Hallazgo E6-13 — el importador era el quinto camino de escritura de horómetro
 * sin la regla de coherencia.
 *
 * Los dos casos de abajo NO son inventados: son las dos máquinas que aparecen
 * duplicadas en el "PM_Service Report as of 7242026.pdf" que mandó el cliente.
 *
 *   EX027 → 400 h del 22/jul (resta 100)  y  275 h del 17/jun (resta 304)
 *   RL016 →  26 h del 28/may (resta 475)  y  3564 h del 18/mar (resta 500)
 *
 * Con la regla anterior ("gana la última ocurrencia del archivo") ganaba en los
 * dos casos la lectura MÁS VIEJA, y además el importador forzaba
 * `current_hours` con ella. Resultado medido en producción: EX027 quedó en 275 h
 * teniendo una lectura de 341 h posterior, y el panel se declaró 66 h optimista.
 */
class ImporterRejectsRegressiveReadingsTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Arma un xlsx con el formato real del reporte: dos filas por máquina.
     *
     * @param  array<int, array{id: string, last: string, reading: string, remaining: string}>  $filas
     */
    private function fixture(array $filas): string
    {
        $hoja = new Spreadsheet;
        $sheet = $hoja->getActiveSheet();
        $sheet->setTitle('Sheet1');

        $fila = 1;
        $sheet->setCellValue('A'.$fila++, 'MACHINE DESCRIPTION');

        foreach ($filas as $f) {
            $sheet->setCellValue('A'.$fila++, $f['id'].' EXCAVATOR TEST S/N: X1');
            $sheet->setCellValue('A'.$fila, 'Yard');
            $sheet->setCellValue('E'.$fila, $f['last']);
            $sheet->setCellValue('G'.$fila, $f['reading']);
            $sheet->setCellValue('J'.$fila, $f['remaining']);
            $fila++;
        }

        $ruta = storage_path('app/e613_'.uniqid().'.xlsx');
        (new Xlsx($hoja))->save($ruta);

        return $ruta;
    }

    private function machine(string $idCode, array $overrides = []): Machine
    {
        $location = Location::create(['name' => 'Yard', 'slug' => 'yard-'.uniqid()]);

        return Machine::create(array_merge([
            'id_code' => $idCode,
            'status' => 'active',
            'hourmeter_status' => 'ok',
            'current_location_id' => $location->id,
            'service_interval_hours' => 500,
        ], $overrides));
    }

    /**
     * El caso EX027, tal cual: el archivo trae la máquina dos veces y la que
     * viene al final es la más vieja.
     */
    public function test_the_newest_reading_wins_when_the_report_repeats_a_machine(): void
    {
        $machine = $this->machine('EX027', [
            'current_hours' => 341,
            'current_hours_date' => '2026-07-02',
            'last_service_hours' => 79,
        ]);

        HorometerReading::create([
            'machine_id' => $machine->id,
            'hours' => 341,
            'read_at' => '2026-07-02',
            'source' => 'import',
        ]);

        // Orden del archivo real: primero la de 400 h, al final la de 275 h.
        $ruta = $this->fixture([
            ['id' => 'EX027', 'last' => '79 Hrs 3/12/26', 'reading' => '400 Hrs 7/22/26', 'remaining' => '100 Hrs'],
            ['id' => 'EX027', 'last' => '79 Hrs 3/12/26', 'reading' => '275 Hrs 6/17/26', 'remaining' => '304 Hrs'],
        ]);

        $resultado = app(PmServiceReportImporter::class)->import($ruta, null, 'pm_7242026.xlsx');

        $machine->refresh();

        // Gana la del 22/jul, que es la más nueva, no la última del archivo.
        $this->assertSame(400, $machine->current_hours, 'la lectura más nueva es la que manda');
        $this->assertSame('2026-07-22', $machine->current_hours_date->toDateString());
        $this->assertSame(100, $machine->remaining_anchor_hours);
        $this->assertSame(400, $machine->remaining_anchor_at_hours);
        $this->assertSame(100, $machine->remaining_hours);

        // El duplicado se declara: es un problema del reporte del cliente.
        $this->assertNotEmpty(
            array_filter($resultado['warnings'], fn ($w) => str_contains($w, 'EX027')),
            'el duplicado en el archivo tiene que quedar declarado'
        );

        @unlink($ruta);
    }

    /**
     * El caso RL016: 26 h contra 3564 h. Es un horómetro reemplazado, y la regla
     * vieja habría devuelto la máquina a la escala anterior.
     */
    public function test_a_replaced_hour_meter_does_not_go_back_to_the_old_scale(): void
    {
        $machine = $this->machine('RL016', [
            'current_hours' => 26,
            'current_hours_date' => '2026-05-28',
            'hourmeter_status' => 'replaced',
            'last_service_hours' => 0,
        ]);

        HorometerReading::create([
            'machine_id' => $machine->id,
            'hours' => 26,
            'read_at' => '2026-05-28',
            'source' => 'import',
        ]);

        $ruta = $this->fixture([
            ['id' => 'RL016', 'last' => '0 Hrs 1/01/26', 'reading' => '26 Hrs 5/28/26', 'remaining' => '475 Hrs'],
            ['id' => 'RL016', 'last' => '0 Hrs 1/01/26', 'reading' => '3564 Hrs 3/18/26', 'remaining' => '500 Hrs'],
        ]);

        app(PmServiceReportImporter::class)->import($ruta, null, 'pm_7242026.xlsx');

        $machine->refresh();

        $this->assertSame(26, $machine->current_hours, 'no puede volver a la escala vieja del horómetro');
        $this->assertNotSame(3564, $machine->current_hours);

        @unlink($ruta);
    }

    /**
     * Sin duplicado, una fila más vieja que el historial tampoco puede bajar el
     * horómetro — y se declara.
     */
    public function test_a_row_older_than_the_history_does_not_lower_the_machine(): void
    {
        $machine = $this->machine('EX100', [
            'current_hours' => 500,
            'current_hours_date' => '2026-07-10',
            'last_service_hours' => 100,
        ]);

        HorometerReading::create([
            'machine_id' => $machine->id,
            'hours' => 500,
            'read_at' => '2026-07-10',
            'source' => 'import',
        ]);

        $ruta = $this->fixture([
            ['id' => 'EX100', 'last' => '100 Hrs 1/01/26', 'reading' => '450 Hrs 6/01/26', 'remaining' => '50 Hrs'],
        ]);

        $resultado = app(PmServiceReportImporter::class)->import($ruta, null, 'pm_viejo.xlsx');

        $machine->refresh();

        $this->assertSame(500, $machine->current_hours);
        // El ancla del reporte sigue valiendo: 50 h restantes a las 450 h, y la
        // máquina está 50 h más adelante => 0 h restantes.
        $this->assertSame(50, $machine->remaining_anchor_hours);
        $this->assertSame(450, $machine->remaining_anchor_at_hours);
        $this->assertSame(0, $machine->remaining_hours);

        $this->assertNotEmpty(array_filter($resultado['warnings'], fn ($w) => str_contains($w, 'EX100')));

        @unlink($ruta);
    }

    /**
     * Una lectura físicamente imposible —fecha posterior y horas menores que una
     * lectura ya existente— no se carga, y se declara. El dato es del cliente:
     * no se descarta en silencio ni se fuerza.
     */
    public function test_an_impossible_reading_is_not_stored_and_is_reported(): void
    {
        $machine = $this->machine('EX200', [
            'current_hours' => 800,
            'current_hours_date' => '2026-07-01',
            'last_service_hours' => 300,
        ]);

        HorometerReading::create([
            'machine_id' => $machine->id,
            'hours' => 800,
            'read_at' => '2026-07-01',
            'source' => 'import',
        ]);

        // 600 h el 20/jul, con 800 h ya registradas el 1/jul: el horómetro
        // bajaría con el tiempo.
        $ruta = $this->fixture([
            ['id' => 'EX200', 'last' => '300 Hrs 1/01/26', 'reading' => '600 Hrs 7/20/26', 'remaining' => '200 Hrs'],
        ]);

        $resultado = app(PmServiceReportImporter::class)->import($ruta, null, 'pm_incoherente.xlsx');

        $this->assertDatabaseMissing('horometer_readings', [
            'machine_id' => $machine->id,
            'hours' => 600,
        ]);

        $this->assertSame(800, $machine->refresh()->current_hours);
        $this->assertNotEmpty(array_filter($resultado['warnings'], fn ($w) => str_contains($w, 'EX200')));

        @unlink($ruta);
    }

    /**
     * Lo que NO puede romperse: una lectura más nueva y más alta entra normal.
     */
    public function test_a_newer_higher_reading_still_updates_the_machine(): void
    {
        $machine = $this->machine('LD023', [
            'current_hours' => 8707,
            'current_hours_date' => '2026-07-17',
            'last_service_hours' => 8215,
        ]);

        HorometerReading::create([
            'machine_id' => $machine->id,
            'hours' => 8707,
            'read_at' => '2026-07-17',
            'source' => 'import',
        ]);

        // La fila real del 24/07: la máquina está pasada de servicio.
        $ruta = $this->fixture([
            ['id' => 'LD023', 'last' => '8215 Hrs 5/10/26', 'reading' => '8777 Hrs 7/24/26', 'remaining' => '0 Hrs'],
        ]);

        app(PmServiceReportImporter::class)->import($ruta, null, 'pm_7242026.xlsx');

        $machine->refresh();

        $this->assertSame(8777, $machine->current_hours);
        $this->assertSame('2026-07-24', $machine->current_hours_date->toDateString());
        $this->assertDatabaseHas('horometer_readings', [
            'machine_id' => $machine->id,
            'hours' => 8777,
            'source' => 'import',
        ]);

        @unlink($ruta);
    }
}

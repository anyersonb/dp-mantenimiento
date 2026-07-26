<?php

namespace Tests\Feature\Management;

use App\Models\Machine;
use App\Services\PmServiceReportImporter;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\TestCase;

/**
 * Complementa PmServiceReportImportTest (que no se toca, sus tests deben
 * seguir pasando tal cual) con dos cosas nuevas del hallazgo A4:
 *
 *  1. El importador ahora fija el ancla (remaining_anchor_hours,
 *     remaining_anchor_at_hours) cuando el reporte trae remaining_hours.
 *  2. El importador sigue tolerando una fila con una lectura MENOR a la ya
 *     guardada (el Excel del cliente trae filas desordenadas): a diferencia
 *     del camino de campo (ver RegressiveReadingRejectionTest), aquí no debe
 *     rechazarse ni lanzar una excepción.
 */
class PmReportAnchorAndToleranceTest extends TestCase
{
    use RefreshDatabase;

    private string $fixturePath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);

        $this->fixturePath = sys_get_temp_dir().'/pm_report_a4_test_'.uniqid().'.xlsx';
    }

    protected function tearDown(): void
    {
        if (file_exists($this->fixturePath)) {
            unlink($this->fixturePath);
        }

        parent::tearDown();
    }

    /**
     * Fila única de datos para una máquina "TOL01", con lectura, servicio y
     * remaining configurables, replicando el formato real de 2 filas
     * (descripción + datos) usado por PmServiceReportImportTest.
     */
    private function buildFixture(string $lastServiceCell, string $latestReadingCell, string $remainingCell): string
    {
        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Sheet1');

        $sheet->setCellValue('A6', 'MACHINE DESCRIPTION');
        $sheet->setCellValue('E6', 'LAST SERVICE HRS/DATE');
        $sheet->setCellValue('G6', 'LATEST READING HRS/DATE');
        $sheet->setCellValue('J6', 'Remanining Hrs/Mls for Serv.');

        $sheet->setCellValue('A7', 'TOL01 LOADER CAT 950M S/N: TOL000001');
        $sheet->setCellValue('A8', 'Test Yard.');
        $sheet->setCellValue('E8', $lastServiceCell);
        $sheet->setCellValue('G8', $latestReadingCell);
        $sheet->setCellValue('J8', $remainingCell);

        (new Xlsx($spreadsheet))->save($this->fixturePath);

        return $this->fixturePath;
    }

    public function test_import_sets_the_remaining_anchor_from_the_report(): void
    {
        Machine::create(['id_code' => 'TOL01', 'status' => 'active']);

        $path = $this->buildFixture('9200 Hrs 5/01/26', '9793 Hrs 7/17/26', '415 Hrs');

        app(PmServiceReportImporter::class)->import($path, null, 'a4_test.xlsx');

        $machine = Machine::where('id_code', 'TOL01')->firstOrFail();

        $this->assertSame(415, $machine->remaining_hours);
        $this->assertSame(415, $machine->remaining_anchor_hours);
        $this->assertSame(9793, $machine->remaining_anchor_at_hours);
    }

    public function test_import_tolerates_a_regressive_reading_without_rejecting_or_throwing(): void
    {
        $machine = Machine::create([
            'id_code' => 'TOL01',
            'status' => 'active',
            'current_hours' => 300,
            'last_service_hours' => 100,
            'service_interval_hours' => 500,
            'remaining_hours' => 300,
        ]);

        // El reporte trae una lectura MENOR (250) a la ya guardada (300):
        // el Excel del cliente trae filas desordenadas y el importador debe
        // tolerarlo sin lanzar ni rechazar.
        $path = $this->buildFixture('80 Hrs 1/01/26', '250 Hrs 2/01/26', '330 Hrs');

        $result = app(PmServiceReportImporter::class)->import($path, null, 'a4_test.xlsx');

        $this->assertContains('TOL01', array_column($result['updated'], 'id_code'));

        $this->assertDatabaseHas('horometer_readings', [
            'machine_id' => $machine->id,
            'hours' => 250,
            'source' => 'import',
        ]);

        // Fase 2 del importador manda al final: el snapshot del reporte
        // (incluida la lectura menor) se aplica igual, sin excepción.
        $machine->refresh();
        $this->assertSame(250, $machine->current_hours);
        $this->assertSame(330, $machine->remaining_hours);
        $this->assertSame(330, $machine->remaining_anchor_hours);
        $this->assertSame(250, $machine->remaining_anchor_at_hours);
    }
}

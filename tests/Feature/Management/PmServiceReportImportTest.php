<?php

namespace Tests\Feature\Management;

use App\Models\HorometerReading;
use App\Models\Machine;
use App\Models\User;
use App\Services\PmServiceReportImporter;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\TestCase;

/**
 * Importador del "PM Service Report" (.xlsx): solo actualiza horas/lecturas
 * de máquinas que YA existen (id_code); las que no coinciden se reportan
 * como "unmatched" y NUNCA se crean. Replica el formato real de 2 filas por
 * máquina, incluyendo un encabezado "MACHINE DESCRIPTION" repetido (salto de
 * página impreso) y una nota de ajuste de horómetro tipo EX013.
 */
class PmServiceReportImportTest extends TestCase
{
    use RefreshDatabase;

    private string $fixturePath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);

        $this->fixturePath = sys_get_temp_dir().'/pm_report_test_'.uniqid().'.xlsx';
    }

    protected function tearDown(): void
    {
        if (file_exists($this->fixturePath)) {
            unlink($this->fixturePath);
        }

        parent::tearDown();
    }

    /**
     * Construye un .xlsx con el mismo formato de 2 filas por máquina del
     * reporte real: EX010 (existe, sin ajuste), ZZ999 (no existe -> unmatched)
     * y EX013 (existe, con nota "Add 5714 to current hrs"), separadas por un
     * encabezado "MACHINE DESCRIPTION" repetido a mitad de archivo.
     */
    private function buildFixture(): string
    {
        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Sheet1');

        $sheet->setCellValue('A6', 'MACHINE DESCRIPTION');
        $sheet->setCellValue('E6', 'LAST SERVICE HRS/DATE');
        $sheet->setCellValue('G6', 'LATEST READING HRS/DATE');
        $sheet->setCellValue('J6', 'Remanining Hrs/Mls for Serv.');
        $sheet->setCellValue('M6', 'Fuel Added');

        // EX010: existe, actualización simple.
        $sheet->setCellValue('A7', 'EX010 EXCAVATOR CAT 335FLCR S/N: KNE00310');
        $sheet->setCellValue('A8', 'Broadview yd.');
        $sheet->setCellValue('E8', '9708 Hrs 5/04/26');
        $sheet->setCellValue('G8', '9793 Hrs 7/17/26');
        $sheet->setCellValue('J8', '415 Hrs');

        // ZZ999: no existe en la BD -> debe quedar "unmatched", nunca creada.
        $sheet->setCellValue('A9', 'ZZ999 UNKNOWN LOADER MODEL S/N: XXX000');
        $sheet->setCellValue('A10', 'Some Yard.');
        $sheet->setCellValue('E10', '100 Hrs 1/01/26');
        $sheet->setCellValue('G10', '200 Hrs 2/01/26');
        $sheet->setCellValue('J10', '300 Hrs');

        // Encabezado repetido a mitad de archivo (salto de página impreso):
        // no debe romper el conteo de pares de filas.
        $sheet->setCellValue('A11', 'MACHINE DESCRIPTION');
        $sheet->setCellValue('E11', 'LAST SERVICE HRS/DATE');
        $sheet->setCellValue('G11', 'LATEST READING HRS/DATE');
        $sheet->setCellValue('J11', 'Remanining Hrs/Mls for Serv.');

        // EX013: existe, con ajuste de horómetro tipo "Add N to current hrs".
        $sheet->setCellValue('A12', 'EX013 EXCAVATOR CAT 307E2 S/N: KC900521');
        $sheet->setCellValue('A13', 'WPB Yd. (Add 5714 to current hrs)');
        $sheet->setCellValue('E13', '5542 Hrs 3/21/25');
        $sheet->setCellValue('G13', '5808 Hrs 6/29/26');
        $sheet->setCellValue('J13', '234 Hrs');

        (new Xlsx($spreadsheet))->save($this->fixturePath);

        return $this->fixturePath;
    }

    public function test_it_updates_existing_machines_and_reports_unmatched_ones(): void
    {
        $ex010 = Machine::create([
            'id_code' => 'EX010',
            'status' => 'active',
            'current_hours' => 9700,
            'last_service_hours' => 9000,
            'remaining_hours' => 100,
        ]);

        $ex013 = Machine::create([
            'id_code' => 'EX013',
            'status' => 'active',
            'current_hours' => 5700,
            'last_service_hours' => 5000,
            'remaining_hours' => 50,
            'hours_adjustment' => 0,
        ]);

        $causer = User::where('email', 'admin@dp.local')->firstOrFail();

        $path = $this->buildFixture();

        $result = app(PmServiceReportImporter::class)->import($path, $causer, 'pm_report_test.xlsx');

        // -- EX010: actualizada con los valores del reporte --
        $ex010->refresh();
        $this->assertSame(9793, $ex010->current_hours);
        $this->assertSame('2026-07-17', $ex010->current_hours_date->toDateString());
        $this->assertSame(9708, $ex010->last_service_hours);
        $this->assertSame('2026-05-04', $ex010->last_service_date->toDateString());
        $this->assertSame(415, $ex010->remaining_hours);

        $this->assertDatabaseHas('horometer_readings', [
            'machine_id' => $ex010->id,
            'hours' => 9793,
            'source' => 'import',
        ]);

        // -- EX013: actualizada + hours_adjustment detectado desde la nota --
        $ex013->refresh();
        $this->assertSame(5808, $ex013->current_hours);
        $this->assertSame(5542, $ex013->last_service_hours);
        $this->assertSame(234, $ex013->remaining_hours);
        $this->assertSame(5714, $ex013->hours_adjustment);

        // -- ZZ999: NO se crea, solo se reporta como unmatched --
        $this->assertDatabaseMissing('machines', ['id_code' => 'ZZ999']);

        $updatedIds = array_column($result['updated'], 'id_code');
        $unmatchedIds = array_column($result['unmatched'], 'id_code');

        $this->assertContains('EX010', $updatedIds);
        $this->assertContains('EX013', $updatedIds);
        $this->assertContains('ZZ999', $unmatchedIds);
        $this->assertCount(2, $result['updated']);
        $this->assertCount(1, $result['unmatched']);

        $this->assertDatabaseHas('activity_log', [
            'event' => 'imported',
            'causer_id' => $causer->id,
        ]);
    }

    public function test_reimporting_the_same_report_does_not_duplicate_horometer_readings(): void
    {
        Machine::create(['id_code' => 'EX010', 'status' => 'active']);
        Machine::create(['id_code' => 'EX013', 'status' => 'active']);

        $importer = app(PmServiceReportImporter::class);
        $path = $this->buildFixture();

        $importer->import($path, null, 'pm_report_test.xlsx');
        $importer->import($path, null, 'pm_report_test.xlsx');

        $this->assertSame(
            1,
            HorometerReading::where('machine_id', Machine::where('id_code', 'EX010')->value('id'))->count()
        );
    }
}

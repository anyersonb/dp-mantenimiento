<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * One-off cleanup of test records left over from the QA run in Stage 05,
     * Block 0. Deletes are scoped by exact primary key, never by pattern
     * matching, so no real data can be caught by accident. Idempotent: if a
     * row is already gone, the delete simply affects 0 rows.
     */
    public function up(): void
    {
        // horometer_readings id 156 and 157 ("QA- prueba ..." notes)
        DB::table('horometer_readings')->whereIn('id', [156, 157])->delete();

        // field_reports id 1 (test field report)
        DB::table('field_reports')->where('id', 1)->delete();

        // locations id 11 ("QA-Obra-Test" ghost site) — only delete if no
        // machine is currently pointing at it, to avoid orphaning real data.
        $machinesAtLocation = DB::table('machines')
            ->where('current_location_id', 11)
            ->count();

        if ($machinesAtLocation === 0) {
            DB::table('locations')->where('id', 11)->delete();
        }
    }

    /**
     * Reverse the migrations.
     *
     * Intentionally left empty: the deleted rows were QA test data, not
     * real business records, so there is nothing to restore on rollback.
     */
    public function down(): void
    {
        // No-op: QA test data is not restored.
    }
};

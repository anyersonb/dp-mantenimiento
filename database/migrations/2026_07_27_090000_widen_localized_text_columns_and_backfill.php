<?php

use App\Support\LocalizedText;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Hallazgo E6-10.
 *
 * 1. `alerts.title` y `horometer_readings.note` son `varchar(255)` y ahora
 *    guardan un sobre JSON con clave + parámetros, que es más largo que la
 *    frase. Pasan a `text` para que no se trunque nunca (un truncado dejaría un
 *    JSON inválido y el texto se leería como literal ilegible).
 *
 * 2. Reescribe el texto YA GUARDADO de alertas y notas a clave + parámetros,
 *    con los datos que están en las mismas filas. Sin esto, las 6 alertas del
 *    cliente se quedan en inglés para siempre y las 93 notas en español.
 *
 * La bitácora (`activity_log`) **no se toca**: es append-only por decisión
 * declarada del proyecto. Los asientos viejos se siguen leyendo tal cual y los
 * nuevos ya nacen con clave.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('alerts', function (Blueprint $table) {
            $table->text('title')->change();
        });

        Schema::table('horometer_readings', function (Blueprint $table) {
            $table->text('note')->nullable()->change();
        });

        $this->backfillAlerts();
        $this->backfillReadingNotes();
    }

    public function down(): void
    {
        // Las columnas vuelven a su largo original. El backfill NO se revierte:
        // el sobre se lee igual de bien que la frase (el cast devuelve el
        // literal cuando no hay sobre), así que revertirlo solo perdería
        // información.
        Schema::table('alerts', function (Blueprint $table) {
            $table->string('title', 255)->change();
        });

        Schema::table('horometer_readings', function (Blueprint $table) {
            $table->string('note', 255)->nullable()->change();
        });
    }

    /**
     * Las alertas automáticas de servicio se reconstruyen exactas: el título
     * lleva el código de la máquina y el mensaje además las horas restantes,
     * y las dos cosas son columnas de la propia fila.
     */
    private function backfillAlerts(): void
    {
        $alertas = DB::table('alerts')
            ->join('machines', 'machines.id', '=', 'alerts.machine_id')
            ->where('alerts.type', 'service')
            ->select('alerts.id', 'alerts.title', 'alerts.message', 'alerts.remaining_hours', 'machines.id_code')
            ->get();

        foreach ($alertas as $alerta) {
            if (str_contains((string) $alerta->title, LocalizedText::ENVELOPE_KEY)) {
                continue;
            }

            DB::table('alerts')->where('id', $alerta->id)->update([
                'title' => LocalizedText::of('alerts.auto_title', [
                    'machine' => $alerta->id_code,
                ])->encode(),
                'message' => LocalizedText::of('alerts.auto_message', [
                    'machine' => $alerta->id_code,
                    'hours' => $alerta->remaining_hours,
                ])->encode(),
            ]);
        }
    }

    /**
     * Notas de lectura escritas por el sistema. Solo las que coinciden exacto
     * con un texto conocido: cualquier otra puede ser de una persona y el texto
     * libre no se traduce ni se toca.
     */
    private function backfillReadingNotes(): void
    {
        $conocidas = [
            'Lectura importada del PM Service Report' => 'fleet.imported_reading_note',
            'Imported reading from the PM Service Report' => 'fleet.imported_reading_note',
        ];

        foreach ($conocidas as $literal => $clave) {
            DB::table('horometer_readings')
                ->where('note', $literal)
                ->update(['note' => LocalizedText::of($clave)->encode()]);
        }

        // Las notas del importador traen el nombre del archivo, que es un dato
        // y no texto traducible: se pasa como parámetro.
        $conArchivo = DB::table('horometer_readings')
            ->where('note', 'like', 'PM Service Report import (%')
            ->select('id', 'note')
            ->get();

        foreach ($conArchivo as $lectura) {
            preg_match('/^PM Service Report import \((.*)\)$/', (string) $lectura->note, $m);

            if (! isset($m[1])) {
                continue;
            }

            DB::table('horometer_readings')->where('id', $lectura->id)->update([
                'note' => LocalizedText::of('fleet.imported_reading_from_file_note', [
                    'file' => $m[1],
                ])->encode(),
            ]);
        }
    }
};

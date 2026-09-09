<?php

use App\Models\Quote;
use App\Models\WorkOrderAttachment;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * Etapa 05 (hallazgo A5, cierre): la migracion anterior
 * (2026_07_26_090000_migrate_sensitive_uploads_to_private_disk) copio los
 * archivos a disk('local') pero dejo el original en disk('public') porque
 * la regla de la tarea era "no borrar sin verificar". El PM confirmo que la
 * condicion de verificacion (hash sha256 identico) ya se cumplio en esa
 * migracion, asi que el original pasa a ser prescindible: esta migracion
 * re-verifica el hash EN TIEMPO DE EJECUCION (no confia en lo que se hizo
 * antes) y solo entonces borra el original publico. Es exactamente el gap
 * que dejaba GET /storage/quotes/demo-quote.pdf respondiendo 200 sin
 * sesion pese a que la app ya servia todo por la ruta autorizada.
 *
 * Nunca borra a ciegas: si la copia privada no existe, o si el hash no
 * coincide, el original publico se deja intacto y se reporta (log +
 * salida de la migracion), sin fallar el resto del lote.
 *
 * Idempotente: si el original publico ya no existe, no hay nada que hacer.
 * down() no puede "des-borrar", pero es reversible en el sentido util:
 * restaura el original en disk('public') a partir de la copia privada
 * (que nunca se toca).
 */
return new class extends Migration
{
    public function up(): void
    {
        $deleted = 0;
        $skipped = 0;

        $deleted += $this->deleteVerifiedPublicOriginal(
            Quote::withTrashed()->whereNotNull('file_path')->get(),
            'file_path',
            'Quote',
            $skipped
        );
        $deleted += $this->deleteVerifiedPublicOriginal(
            WorkOrderAttachment::withTrashed()->whereNotNull('path')->get(),
            'path',
            'WorkOrderAttachment',
            $skipped
        );

        $summary = "[A5 cleanup] originales publicos borrados: {$deleted}, omitidos (sin copia o hash distinto): {$skipped}";
        Log::info($summary);

        if (app()->runningInConsole()) {
            fwrite(STDOUT, "  {$summary}\n");
        }
    }

    public function down(): void
    {
        $this->restorePublicOriginal(Quote::withTrashed()->whereNotNull('file_path')->get(), 'file_path');
        $this->restorePublicOriginal(WorkOrderAttachment::withTrashed()->whereNotNull('path')->get(), 'path');
    }

    /**
     * @param  Collection<int, Model>  $records
     */
    private function deleteVerifiedPublicOriginal(Collection $records, string $column, string $label, int &$skipped): int
    {
        $deleted = 0;

        foreach ($records as $record) {
            $path = $record->getAttribute($column);

            if (blank($path)) {
                continue;
            }

            if (! Storage::disk('public')->exists($path)) {
                continue; // ya no hay original publico: nada que borrar (idempotente)
            }

            if (! Storage::disk('local')->exists($path)) {
                Log::warning("[A5 cleanup] {$label} #{$record->getKey()}: '{$path}' no tiene copia privada -- NO se borra el original publico.");
                $skipped++;

                continue;
            }

            $publicHash = hash('sha256', Storage::disk('public')->get($path));
            $privateHash = hash('sha256', Storage::disk('local')->get($path));

            if ($publicHash !== $privateHash) {
                Log::error("[A5 cleanup] {$label} #{$record->getKey()}: '{$path}' - hash distinto entre disco publico y privado -- NO se borra el original, revisar a mano.");
                $skipped++;

                continue;
            }

            Storage::disk('public')->delete($path);
            Log::info("[A5 cleanup] {$label} #{$record->getKey()}: '{$path}' - hash verificado en tiempo de ejecucion, original publico borrado. Copia privada intacta.");
            $deleted++;
        }

        return $deleted;
    }

    /**
     * @param  Collection<int, Model>  $records
     */
    private function restorePublicOriginal(Collection $records, string $column): void
    {
        foreach ($records as $record) {
            $path = $record->getAttribute($column);

            if (blank($path)) {
                continue;
            }

            if (Storage::disk('public')->exists($path)) {
                continue;
            }

            if (Storage::disk('local')->exists($path)) {
                Storage::disk('public')->put($path, Storage::disk('local')->get($path));
            }
        }
    }
};

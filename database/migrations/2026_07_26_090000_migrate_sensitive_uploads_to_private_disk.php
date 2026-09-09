<?php

use App\Models\Quote;
use App\Models\WorkOrderAttachment;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * Etapa 05 (hallazgo A5): migra a disk('local') (privado, storage/app/private)
 * los archivos que hoy viven en disk('public') para quotes.file_path y
 * work_order_attachments.path. No toca ninguna columna de la BD -- el mismo
 * valor relativo de path sirve en ambos discos, solo cambia el disco desde
 * el que la app lee -- asi que las referencias existentes no se rompen.
 *
 * Idempotente: si el archivo ya existe en el disco privado, se salta.
 * Nunca borra el archivo original de disk('public') (regla dura de la
 * tarea: no borrar archivos reales), ni falla si un archivo no esta
 * fisicamente en disco -- solo lo deja anotado en el log de Laravel.
 *
 * Las imagenes de maquina (machines.image / machines.gallery) NO se tocan:
 * decision registrada en MachineResource -- se quedan en disk('public') a
 * proposito (no son evidencia de costos y disk('local') no soporta
 * temporaryUrl(), a diferencia de S3).
 */
return new class extends Migration
{
    public function up(): void
    {
        $this->migrateModelFiles(Quote::withTrashed()->whereNotNull('file_path')->get(), 'file_path', 'Quote');
        $this->migrateModelFiles(WorkOrderAttachment::withTrashed()->whereNotNull('path')->get(), 'path', 'WorkOrderAttachment');
    }

    public function down(): void
    {
        // Solo borra las copias privadas creadas por esta migracion; el
        // archivo original en disk('public') nunca se toca aqui tampoco.
        $this->rollbackModelFiles(Quote::withTrashed()->whereNotNull('file_path')->get(), 'file_path');
        $this->rollbackModelFiles(WorkOrderAttachment::withTrashed()->whereNotNull('path')->get(), 'path');
    }

    /**
     * @param  Collection<int, Model>  $records
     */
    private function migrateModelFiles(Collection $records, string $column, string $label): void
    {
        foreach ($records as $record) {
            $path = $record->getAttribute($column);

            if (blank($path)) {
                continue;
            }

            if (Storage::disk('local')->exists($path)) {
                continue; // ya migrado (idempotente)
            }

            if (! Storage::disk('public')->exists($path)) {
                Log::warning("[A5 migration] {$label} #{$record->getKey()}: '{$path}' no existe en disk('public'), se omite sin fallar.");

                continue;
            }

            $contents = Storage::disk('public')->get($path);
            Storage::disk('local')->put($path, $contents);

            $sourceHash = hash('sha256', $contents);
            $destHash = Storage::disk('local')->exists($path)
                ? hash('sha256', Storage::disk('local')->get($path))
                : null;

            if ($sourceHash !== $destHash) {
                // No se pudo verificar la copia: se revierte la copia
                // privada (no el original, que nunca se toco) y se deja
                // constancia para revisar a mano.
                Storage::disk('local')->delete($path);
                Log::error("[A5 migration] {$label} #{$record->getKey()}: '{$path}' - la copia no verifico (hash distinto), se revirtio la copia privada. El original en disk('public') sigue intacto.");

                continue;
            }

            Log::info("[A5 migration] {$label} #{$record->getKey()}: '{$path}' copiado y verificado (sha256) a disk('local'). El original en disk('public') no se borra.");
        }
    }

    /**
     * @param  Collection<int, Model>  $records
     */
    private function rollbackModelFiles(Collection $records, string $column): void
    {
        foreach ($records as $record) {
            $path = $record->getAttribute($column);

            if (blank($path)) {
                continue;
            }

            if (Storage::disk('local')->exists($path)) {
                Storage::disk('local')->delete($path);
            }
        }
    }
};

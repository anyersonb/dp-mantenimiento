<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Papelera (Lote A): borrado lógico para todo lo que hoy se puede borrar
 * desde el panel y no lo tenía todavía. `machines` ya lo tiene desde
 * `2026_07_26_190000_add_soft_deletes_to_machines_table` (hallazgo E6-05) y
 * queda fuera de esta migración.
 *
 * Entran en un solo archivo, agrupadas, porque son el mismo cambio coherente
 * (papelera) y no once migraciones que nadie va a leer sueltas:
 *
 *   - `work_orders` + sus tres hijos (`work_order_parts`,
 *     `work_order_attachments`, `checklist_results`): el FK
 *     `cascadeOnDelete()` hacia `work_orders` NO se dispara con un soft
 *     delete (no hay DELETE real), así que sin `deleted_at` en los hijos,
 *     borrar una OT los dejaría visibles y huérfanos. La cascada lógica vive
 *     en `App\Models\WorkOrder` (eventos `deleted`/`restoring`/`restored`).
 *   - `quotes`: cotizaciones adjuntadas por el administrador.
 *   - `locations`, `machine_categories`, `makes`: catálogos simples
 *     referenciados por `machines`, pero la referencia es `nullOnDelete()`/
 *     sin cascada — un soft delete no altera nada ahí.
 *   - `users`: ver App\Models\User — un usuario en papelera no debe poder
 *     autenticarse (lo resuelve el scope de SoftDeletes en el provider de
 *     Eloquent, verificado con test, no asumido).
 *
 * `work_orders` y sus tres hijos reciben ADEMÁS `trash_batch` (string, un
 * ULID). Es el marcador que distingue "estos hijos se fueron CON esta baja
 * de la OT" de "ya estaban en la papelera de antes" al restaurar.
 *
 * Se probó primero comparando por igualdad de `deleted_at` entre la OT y sus
 * hijos, y esa version fallo en la corrida de control contra MySQL (no en
 * SQLite): las columnas `datetime` de esta base tienen precision de UN
 * SEGUNDO, asi que un repuesto borrado a mano y la OT borrada dentro del
 * MISMO segundo terminan con el MISMO `deleted_at`, y al restaurar la OT el
 * repuesto que NO debia volver volvia igual. Un ULID generado por operacion
 * no colisiona nunca por esto.
 */
return new class extends Migration
{
    private const SOFT_DELETES_ONLY = [
        'quotes',
        'locations',
        'machine_categories',
        'makes',
        'users',
    ];

    private const WORK_ORDER_FAMILY = [
        'work_orders',
        'work_order_parts',
        'work_order_attachments',
        'checklist_results',
    ];

    public function up(): void
    {
        foreach (self::SOFT_DELETES_ONLY as $table) {
            Schema::table($table, function (Blueprint $blueprint) {
                $blueprint->softDeletes();
            });
        }

        foreach (self::WORK_ORDER_FAMILY as $table) {
            Schema::table($table, function (Blueprint $blueprint) {
                $blueprint->softDeletes();
                $blueprint->ulid('trash_batch')->nullable()->after('deleted_at');
                $blueprint->index('trash_batch');
            });
        }
    }

    public function down(): void
    {
        foreach (self::SOFT_DELETES_ONLY as $table) {
            Schema::table($table, function (Blueprint $blueprint) {
                $blueprint->dropSoftDeletes();
            });
        }

        foreach (self::WORK_ORDER_FAMILY as $table) {
            Schema::table($table, function (Blueprint $blueprint) {
                $blueprint->dropIndex(['trash_batch']);
                $blueprint->dropColumn('trash_batch');
                $blueprint->dropSoftDeletes();
            });
        }
    }
};

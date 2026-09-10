<?php

namespace App\Filament\Concerns;

use App\Support\TrashActivityLogger;
use Filament\Forms;
use Filament\Notifications\Notification;
use Filament\Tables;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Papelera (Lote A): filtro + restaurar + eliminar definitivamente,
 * uniformes para todos los Resources del inventario. SOLO administrador, con
 * permisos nuevos y granulares por recurso (ver migración
 * 2026_09_08_120100_add_papelera_permissions).
 *
 * Fail-closed en las dos capas: `->visible()` oculta el filtro/la acción sin
 * el permiso, y `->authorize()` la vuelve a exigir en el servidor al ejecutar
 * la acción. El filtro no necesita una tercera capa propia para esto —
 * verificado contra el paquete instalado (`InteractsWithTableQuery::apply()`,
 * vendor/filament/tables): un filtro SIEMPRE vuelve a evaluar su propio
 * `isHidden()` (que evalúa `Auth::user()` en el momento) antes de tocar la
 * consulta, así que un estado de filtro manipulado a mano en una petición no
 * alcanza para activar `withTrashed()`/`onlyTrashed()` sin el permiso. La
 * relectura de permiso dentro de `->queries()` en `papeleraTrashedFilter()`
 * es entonces cinturón-y-tirantes, no el único cierre de la puerta.
 *
 * El registro en bitácora de cada acción vive en el propio modelo — ver
 * `App\Models\Concerns\LogsPapeleraActivity` y `App\Support\TrashActivityLogger` —
 * no acá: así queda igual sin importar si la restauración/el borrado
 * definitivo se dispara desde este panel, un comando o un tinker.
 */
trait HasPapeleraActions
{
    /**
     * Clave corta del recurso para los tres permisos
     * (`view_trash_<clave>` / `restore_<clave>` / `force_delete_<clave>`).
     */
    abstract protected static function papeleraResourceKey(): string;

    /**
     * Cómo identificar el registro en las notificaciones ("EX010", "WO-0042"...).
     */
    abstract protected static function papeleraRecordLabel(Model $record): string;

    protected static function papeleraViewPermission(): string
    {
        return 'view_trash_'.static::papeleraResourceKey();
    }

    protected static function papeleraRestorePermission(): string
    {
        return 'restore_'.static::papeleraResourceKey();
    }

    protected static function papeleraForceDeletePermission(): string
    {
        return 'force_delete_'.static::papeleraResourceKey();
    }

    public static function canRestore(Model $record): bool
    {
        return Auth::user()?->can(static::papeleraRestorePermission()) ?? false;
    }

    public static function canRestoreAny(): bool
    {
        return Auth::user()?->can(static::papeleraRestorePermission()) ?? false;
    }

    public static function canForceDelete(Model $record): bool
    {
        return Auth::user()?->can(static::papeleraForceDeletePermission()) ?? false;
    }

    public static function canForceDeleteAny(): bool
    {
        return Auth::user()?->can(static::papeleraForceDeletePermission()) ?? false;
    }

    protected static function papeleraTrashedFilter(): Tables\Filters\TrashedFilter
    {
        $permission = static::papeleraViewPermission();
        $canView = fn (): bool => Auth::user()?->can($permission) ?? false;

        return Tables\Filters\TrashedFilter::make()
            ->visible($canView)
            ->queries(
                true: fn ($query) => $canView() ? $query->withTrashed() : $query,
                false: fn ($query) => $canView() ? $query->onlyTrashed() : $query,
                blank: fn ($query) => $query->withoutTrashed(),
            );
    }

    protected static function papeleraRestoreAction(): Tables\Actions\RestoreAction
    {
        return Tables\Actions\RestoreAction::make()
            ->authorize(fn (Model $record): bool => static::canRestore($record))
            ->action(function (Model $record) {
                $record->restore();

                Notification::make()->success()
                    ->title(__('mgmt.trash_restored_ok', ['label' => static::papeleraRecordLabel($record)]))
                    ->send();
            });
    }

    protected static function papeleraRestoreBulkAction(): Tables\Actions\RestoreBulkAction
    {
        return Tables\Actions\RestoreBulkAction::make()
            ->authorize(fn (): bool => static::canRestoreAny())
            ->action(function (EloquentCollection $records) {
                $records->each(fn (Model $record) => $record->restore());

                Notification::make()->success()
                    ->title(__('mgmt.trash_restored_bulk_ok', ['count' => $records->count()]))
                    ->send();
            });
    }

    /**
     * Resumen, en {clave => cantidad}, de lo que un borrado DEFINITIVO de
     * este registro se lleva por delante más allá de la fila misma —
     * hallazgo "papelera reabre E6-05": las FK con `cascadeOnDelete()` hacia
     * `machines` (work_orders, horometer_readings, machine_parts, alerts,
     * field_reports) no se disparan con el soft delete de la papelera, pero
     * SÍ con `forceDelete()`, y antes de este fix nada avisaba de eso ni
     * dejaba rastro de cuánto se perdió.
     *
     * Devuelve null por defecto: la mayoría de los recursos de la papelera
     * NO tienen ninguna cascada que destruya algo que el administrador no
     * esperaría perder —verificado migración por migración, no asumido—:
     * `locations`/`machine_categories`/`makes`/`users` son referenciados con
     * `nullOnDelete()` (los hijos sobreviven, solo pierden la referencia), y
     * los tres hijos de `work_orders` (parts/attachments/checklist_results)
     * son PROPIOS de la OT —se van con ella igual que se van con cualquier
     * borrado normal de un padre y sus líneas— y ya tienen su propio manejo
     * de archivo en `App\Models\WorkOrder::booted()`. Solo `MachineResource`
     * sobreescribe esto.
     *
     * @return array<string, int>|null
     */
    protected static function papeleraDestructionSummary(Model $record): ?array
    {
        return null;
    }

    /**
     * Texto del modal de confirmación cuando SÍ hay dependientes que se
     * perderían (ver `papeleraDestructionSummary()`). Solo se evalúa cuando
     * el resumen trae algún conteo mayor a cero.
     */
    protected static function papeleraForceDeleteWarning(Model $record, array $summary): string
    {
        return '';
    }

    /**
     * Texto del modal de confirmación para la variante MASIVA, con el
     * resumen ya sumado entre todos los registros seleccionados.
     */
    protected static function papeleraForceDeleteBulkWarning(EloquentCollection $records, array $summary): string
    {
        return '';
    }

    /**
     * Valor que el administrador debe volver a teclear para HABILITAR el
     * borrado definitivo cuando hay dependientes (ej. el `id_code` de la
     * máquina) — un `requiresConfirmation()` normal no alcanza para una
     * acción que destruye historial. Null si esta capa extra no aplica.
     */
    protected static function papeleraForceDeleteConfirmationValue(Model $record): ?string
    {
        return null;
    }

    /**
     * Punto de extensión: cascada MANUAL, dentro de la misma transacción,
     * para los hijos que necesitan que se les dispare `forceDeleting()`/
     * `forceDeleted()` ANTES de que la cascada REAL de la base
     * (`cascadeOnDelete()`) se lleve la fila del padre.
     *
     * Hallazgo seguridad Medio, 2026-09-09: un `DELETE` disparado por una FK
     * de MySQL no ejecuta eventos de Eloquent. Eso es invisible casi
     * siempre —los hijos son solo filas—, pero `WorkOrderAttachment` tiene
     * un archivo físico que solo se borra en su evento `forceDeleted` (ver
     * el modelo). Sin este hook, un `Machine::forceDelete()` se llevaba las
     * filas de `work_orders`/`work_order_attachments` por la cascada real,
     * pero el archivo del adjunto quedaba huérfano en disco, sin ninguna
     * fila que lo referencie para poder purgarlo después.
     *
     * No-op por defecto: la mayoría de los recursos no tiene hijos con
     * archivo propio. Solo `MachineResource` lo sobreescribe (fuerza el
     * borrado de sus OT, incluidas las ya archivadas, ANTES de forzar el
     * borrado de la máquina).
     */
    protected static function papeleraBeforeForceDelete(Model $record): void
    {
        //
    }

    /**
     * true si ESTE registro puntual tiene algo que perder con un borrado
     * definitivo (resumen no nulo y con algún conteo mayor a cero).
     */
    protected static function papeleraHasDestructiveImpact(Model $record): bool
    {
        $summary = static::papeleraDestructionSummary($record);

        return $summary !== null && array_sum($summary) > 0;
    }

    /**
     * @return array<int, Forms\Components\Component>
     */
    protected static function papeleraForceDeleteForm(Model $record): array
    {
        if (! static::papeleraHasDestructiveImpact($record)) {
            return [];
        }

        $expected = static::papeleraForceDeleteConfirmationValue($record);

        if ($expected === null) {
            return [];
        }

        return [
            Forms\Components\TextInput::make('confirm_value')
                ->label(__('mgmt.trash_force_delete_confirm_label', ['value' => $expected]))
                ->helperText(__('mgmt.trash_force_delete_confirm_help'))
                ->required(),
        ];
    }

    protected static function papeleraForceDeleteModalDescription(Model $record): ?string
    {
        if (! static::papeleraHasDestructiveImpact($record)) {
            // null deja el texto por defecto de Filament (registro sin
            // historial que perder, ej. una máquina recién creada).
            return null;
        }

        return static::papeleraForceDeleteWarning($record, static::papeleraDestructionSummary($record));
    }

    /**
     * Corta la acción (con aviso, sin borrar nada) si hace falta re-teclear
     * un valor de confirmación y lo tecleado no coincide. Devuelve true
     * cuando está OK para seguir.
     */
    protected static function papeleraConfirmForceDelete(Model $record, array $data, Tables\Actions\ForceDeleteAction $action): bool
    {
        if (! static::papeleraHasDestructiveImpact($record)) {
            return true;
        }

        $expected = static::papeleraForceDeleteConfirmationValue($record);

        if ($expected === null || ($data['confirm_value'] ?? null) === $expected) {
            return true;
        }

        Notification::make()->danger()
            ->title(__('mgmt.trash_force_delete_confirm_mismatch'))
            ->send();

        // halt() y no cancel(): el modal queda abierto para reintentar sin
        // rearmar todo (mismo patrón que RoleResource/WorkOrderResource).
        $action->halt();

        return false;
    }

    protected static function papeleraForceDeleteAction(): Tables\Actions\ForceDeleteAction
    {
        return Tables\Actions\ForceDeleteAction::make()
            ->authorize(fn (Model $record): bool => static::canForceDelete($record))
            ->form(fn (Model $record): array => static::papeleraForceDeleteForm($record))
            ->modalDescription(fn (Model $record): ?string => static::papeleraForceDeleteModalDescription($record))
            ->action(function (Model $record, array $data, Tables\Actions\ForceDeleteAction $action) {
                if (! static::papeleraConfirmForceDelete($record, $data, $action)) {
                    return;
                }

                $label = static::papeleraRecordLabel($record);
                $summary = static::papeleraDestructionSummary($record);

                DB::transaction(function () use ($record, $label, $summary) {
                    // Se registra ANTES de borrar: después de forceDelete()
                    // los dependientes ya no están para contarlos.
                    if ($summary !== null) {
                        TrashActivityLogger::forceDeleteImpact($record, $label, $summary);
                    }

                    // Cascada manual (ver el docblock del método): tiene que
                    // correr ANTES del forceDelete() del propio registro,
                    // porque después la cascada real de la base ya se llevó
                    // las filas hijas sin disparar sus eventos.
                    static::papeleraBeforeForceDelete($record);

                    $record->forceDelete();
                });

                Notification::make()->success()
                    ->title(__('mgmt.trash_force_deleted_ok', ['label' => $label]))
                    ->send();
            });
    }

    /**
     * true si CUALQUIERA de los registros seleccionados tiene algo que
     * perder con el borrado masivo.
     */
    protected static function papeleraBulkHasDestructiveImpact(EloquentCollection $records): bool
    {
        foreach ($records as $record) {
            if (static::papeleraHasDestructiveImpact($record)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array<string, int>
     */
    protected static function papeleraAggregateDestructionSummary(EloquentCollection $records): array
    {
        $totals = [];

        foreach ($records as $record) {
            foreach (static::papeleraDestructionSummary($record) ?? [] as $key => $value) {
                $totals[$key] = ($totals[$key] ?? 0) + $value;
            }
        }

        return $totals;
    }

    /**
     * @return array<int, Forms\Components\Component>
     */
    protected static function papeleraForceDeleteBulkForm(EloquentCollection $records): array
    {
        if (! static::papeleraBulkHasDestructiveImpact($records)) {
            return [];
        }

        return [
            Forms\Components\TextInput::make('confirm_count')
                ->label(__('mgmt.trash_force_delete_bulk_confirm_label', ['count' => $records->count()]))
                ->helperText(__('mgmt.trash_force_delete_bulk_confirm_help'))
                ->required(),
        ];
    }

    protected static function papeleraForceDeleteBulkModalDescription(EloquentCollection $records): ?string
    {
        if (! static::papeleraBulkHasDestructiveImpact($records)) {
            return null;
        }

        return static::papeleraForceDeleteBulkWarning($records, static::papeleraAggregateDestructionSummary($records));
    }

    /**
     * Confirmación fuerte de la variante masiva: como no es práctico
     * re-teclear el código de N registros distintos, acá se pide teclear la
     * CANTIDAD exacta de seleccionados — mismo espíritu ("no alcanza con un
     * click"), adaptado a que son varios registros.
     */
    protected static function papeleraConfirmForceDeleteBulk(EloquentCollection $records, array $data, Tables\Actions\ForceDeleteBulkAction $action): bool
    {
        if (! static::papeleraBulkHasDestructiveImpact($records)) {
            return true;
        }

        if ((string) ($data['confirm_count'] ?? '') === (string) $records->count()) {
            return true;
        }

        Notification::make()->danger()
            ->title(__('mgmt.trash_force_delete_confirm_mismatch'))
            ->send();

        $action->halt();

        return false;
    }

    protected static function papeleraForceDeleteBulkAction(): Tables\Actions\ForceDeleteBulkAction
    {
        return Tables\Actions\ForceDeleteBulkAction::make()
            ->authorize(fn (): bool => static::canForceDeleteAny())
            ->form(fn (EloquentCollection $records): array => static::papeleraForceDeleteBulkForm($records))
            ->modalDescription(fn (EloquentCollection $records): ?string => static::papeleraForceDeleteBulkModalDescription($records))
            ->action(function (EloquentCollection $records, array $data, Tables\Actions\ForceDeleteBulkAction $action) {
                if (! static::papeleraConfirmForceDeleteBulk($records, $data, $action)) {
                    return;
                }

                $count = $records->count();

                $records->each(function (Model $record) {
                    $label = static::papeleraRecordLabel($record);
                    $summary = static::papeleraDestructionSummary($record);

                    DB::transaction(function () use ($record, $label, $summary) {
                        if ($summary !== null) {
                            TrashActivityLogger::forceDeleteImpact($record, $label, $summary);
                        }

                        static::papeleraBeforeForceDelete($record);

                        $record->forceDelete();
                    });
                });

                Notification::make()->success()
                    ->title(__('mgmt.trash_force_deleted_bulk_ok', ['count' => $count]))
                    ->send();
            });
    }
}

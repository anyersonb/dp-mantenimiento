<?php

namespace App\Filament\Concerns;

use Filament\Notifications\Notification;
use Filament\Tables;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;

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

    protected static function papeleraForceDeleteAction(): Tables\Actions\ForceDeleteAction
    {
        return Tables\Actions\ForceDeleteAction::make()
            ->authorize(fn (Model $record): bool => static::canForceDelete($record))
            ->action(function (Model $record) {
                $label = static::papeleraRecordLabel($record);
                $record->forceDelete();

                Notification::make()->success()
                    ->title(__('mgmt.trash_force_deleted_ok', ['label' => $label]))
                    ->send();
            });
    }

    protected static function papeleraForceDeleteBulkAction(): Tables\Actions\ForceDeleteBulkAction
    {
        return Tables\Actions\ForceDeleteBulkAction::make()
            ->authorize(fn (): bool => static::canForceDeleteAny())
            ->action(function (EloquentCollection $records) {
                $count = $records->count();
                $records->each(fn (Model $record) => $record->forceDelete());

                Notification::make()->success()
                    ->title(__('mgmt.trash_force_deleted_bulk_ok', ['count' => $count]))
                    ->send();
            });
    }
}

<?php

namespace App\Filament\Resources\ActivityResource\Pages;

use App\Filament\Resources\ActivityResource;
use Filament\Resources\Pages\ListRecords;

class ListActivities extends ListRecords
{
    protected static string $resource = ActivityResource::class;

    protected function getHeaderActions(): array
    {
        // Solo lectura: sin acción de creación.
        return [];
    }

    /**
     * Filament no bloquea por defecto el acceso directo a la página "index"
     * de un recurso según canViewAny() (solo oculta el ítem de navegación),
     * así que lo reforzamos aquí: sin "view_audit_log" -> 403.
     */
    protected function authorizeAccess(): void
    {
        abort_unless(static::getResource()::canViewAny(), 403);
    }
}

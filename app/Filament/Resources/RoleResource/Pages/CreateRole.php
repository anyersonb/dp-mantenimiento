<?php

namespace App\Filament\Resources\RoleResource\Pages;

use App\Filament\Resources\RoleResource;
use Filament\Resources\Pages\CreateRecord;

class CreateRole extends CreateRecord
{
    protected static string $resource = RoleResource::class;

    protected function afterCreate(): void
    {
        // Salvaguarda defensiva: no debería aplicar en alta (no se puede crear
        // un rol llamado "administrador" porque el nombre ya existe y es
        // único), pero se deja por consistencia con EditRole.
        RoleResource::enforceAdminSafeguard($this->record);
    }
}

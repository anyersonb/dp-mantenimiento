<?php

namespace App\Filament\Resources\RoleResource\Pages;

use App\Filament\Resources\RoleResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;

class EditRole extends EditRecord
{
    protected static string $resource = RoleResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make()
                ->hidden(fn () => in_array($this->record->name, RoleResource::SYSTEM_ROLES, true)),
        ];
    }

    /**
     * Se ejecuta después de que Filament ya guardó los atributos del modelo
     * Y sincronizó la relación `permissions` (CheckboxList ->relationship()),
     * por eso la salvaguarda va aquí y no en mutateFormDataBeforeSave: en ese
     * punto la sincronización de permisos todavía no ocurrió.
     */
    protected function afterSave(): void
    {
        RoleResource::enforceAdminSafeguard($this->record);
    }
}

<?php

namespace App\Filament\Resources\RoleResource\Pages;

use App\Filament\Resources\RoleResource;
use App\Support\RoleCatalog;
use Filament\Actions;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;

class EditRole extends EditRecord
{
    protected static string $resource = RoleResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make()
                ->hidden(fn () => in_array($this->record->name, RoleResource::SYSTEM_ROLES, true))
                // Misma validación que en el listado (clienta, 2026-08-24): un
                // rol con usuarios asignados no se borra hasta moverlos. Esta
                // página monta la MISMA acción que la tabla pero es otra
                // instancia, así que la regla va en las dos — es literalmente
                // la lección de A8 en CLAUDE.md ("un componente rinde distinto
                // según dónde se monta").
                ->before(function (Actions\DeleteAction $action) {
                    $users = $this->record->users()->count();

                    if ($users === 0) {
                        return;
                    }

                    Notification::make()
                        ->title(__('roles.delete_blocked_users_title'))
                        ->body(__('roles.delete_blocked_users_body', [
                            'role' => RoleCatalog::label($this->record->name),
                            'count' => $users,
                        ]))
                        ->danger()
                        ->persistent()
                        ->send();

                    $action->cancel();
                }),
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

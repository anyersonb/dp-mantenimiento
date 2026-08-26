<?php

namespace App\Filament\Resources\RoleResource\Pages;

use App\Filament\Resources\RoleResource;
use App\Support\RoleCatalog;
use Filament\Actions;
use Filament\Forms;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Spatie\Permission\Models\Role;

class EditRole extends EditRecord
{
    protected static string $resource = RoleResource::class;

    /**
     * El MISMO borrado con reasignacion que el listado, montado aca.
     *
     * No es duplicacion por descuido: esta pagina monta otra instancia de la
     * accion, asi que la regla tiene que estar en las dos. Es literalmente la
     * leccion de A8 en CLAUDE.md ("un componente rinde distinto segun donde se
     * monta"), y ya paso una vez en este proyecto que una validacion se
     * arreglara en una sola de las dos pantallas.
     *
     * Lo que decide --a quien se puede mandar la gente, y si el borrado dejaria
     * al sistema sin nadie que pueda administrarlo-- vive en RoleResource, para
     * que las dos pantallas no puedan contestar cosas distintas.
     *
     * Ya no hay `->hidden()` para los siete roles del sistema: desde que el
     * acceso se decide por permiso y no por nombre, se pueden borrar como
     * cualquier otro.
     */
    protected function getHeaderActions(): array
    {
        return [
            Actions\DeleteAction::make()
                ->modalHeading(fn () => __('roles.delete_heading', [
                    'role' => RoleCatalog::label($this->record->name),
                ]))
                ->modalDescription(fn () => RoleResource::assignedUserCount($this->record) === 0
                    ? __('roles.delete_description_empty')
                    : __('roles.delete_description_with_users', [
                        'count' => RoleResource::assignedUserCount($this->record),
                    ]))
                ->form(fn () => RoleResource::assignedUserCount($this->record) === 0 ? [] : [
                    Forms\Components\Select::make('reassign_to')
                        ->label(__('roles.reassign_label'))
                        ->helperText(__('roles.reassign_help'))
                        ->options(fn () => RoleResource::reassignmentOptions([$this->record->getKey()]))
                        ->native(false)
                        ->searchable()
                        ->required(),
                ])
                ->action(function (array $data, Actions\DeleteAction $action) {
                    $target = filled($data['reassign_to'] ?? null)
                        ? Role::find($data['reassign_to'])
                        : null;

                    if (RoleResource::wouldStrandAdministration($this->record, $target)) {
                        Notification::make()
                            ->title(__('roles.delete_blocked_last_admin_title'))
                            ->body(__('roles.delete_blocked_last_admin_body'))
                            ->danger()
                            ->persistent()
                            ->send();

                        $action->halt();
                    }

                    $targetLabel = $target !== null ? RoleCatalog::label($target->name) : null;

                    $moved = RoleResource::deleteAndReassign($this->record, $target);

                    Notification::make()
                        ->title(__('roles.delete_done_title'))
                        ->body($moved === 0
                            ? __('roles.delete_done_empty')
                            : __('roles.delete_done_moved', [
                                'count' => $moved,
                                'role' => $targetLabel,
                            ]))
                        ->success()
                        ->persistent()
                        ->send();

                    // Con un ->action() propio, el redirect que hace la accion
                    // por defecto ya no ocurre: sin esto la pagina se queda
                    // intentando editar un rol que acaba de dejar de existir.
                    $this->redirect(RoleResource::getUrl('index'));
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

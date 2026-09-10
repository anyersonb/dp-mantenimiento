<?php

namespace App\Filament\Resources\UserResource\Pages;

use App\Filament\Resources\UserResource;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Support\Facades\Auth;

class EditUser extends EditRecord
{
    protected static string $resource = UserResource::class;

    protected function getHeaderActions(): array
    {
        return [
            // Puerta 5 (AdministrationGuard, hallazgo seguridad Medio
            // 2026-09-09): el guard vive en UserResource::canDelete(), NO en
            // un ->authorize() acá. Filament ya inyecta
            // `->authorize($resource::canDelete($this->getRecord()))` en
            // EditRecord::configureDeleteAction() — otro ->authorize() en
            // esta cadena lo REEMPLAZARÍA en vez de sumarse.
            Actions\DeleteAction::make()
                ->hidden(fn () => $this->record->id === Auth::id()),
        ];
    }
}

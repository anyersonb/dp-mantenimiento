<?php

namespace App\Filament\Resources\UserResource\Pages;

use App\Filament\Resources\UserResource;
use App\Support\AdministrationGuard;
use Filament\Actions;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Support\Facades\Auth;

class EditUser extends EditRecord
{
    protected static string $resource = UserResource::class;

    protected function getHeaderActions(): array
    {
        return [
            // Puerta 5 (AdministrationGuard): la misma pregunta que la fila
            // y la masiva de UserResource, montada acá. `->authorize()`
            // corta también del lado del servidor (ver el comentario en
            // UserResource::table()).
            Actions\DeleteAction::make()
                ->authorize(fn (): bool => ! AdministrationGuard::deletingUserWouldStrand($this->record))
                ->hidden(fn () => $this->record->id === Auth::id()),
        ];
    }
}

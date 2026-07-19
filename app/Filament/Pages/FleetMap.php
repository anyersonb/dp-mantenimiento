<?php

namespace App\Filament\Pages;

use App\Models\Location;
use Filament\Pages\Page;
use Illuminate\Support\Facades\Auth;

/**
 * Mapa de geolocalización de la flota: obras/yardas con lat/lng y
 * cuántas máquinas activas hay en cada una. Solo pinta ubicaciones con
 * coordenadas cargadas (ver LocationCoordinatesSeeder — son aproximadas).
 */
class FleetMap extends Page
{
    protected static ?string $navigationIcon = 'heroicon-o-map';

    protected static ?int $navigationSort = 3;

    protected static string $view = 'filament.pages.fleet-map';

    public static function getNavigationGroup(): ?string
    {
        return __('fleet.group_management');
    }

    public static function getNavigationLabel(): string
    {
        return __('mgmt.fleet_map');
    }

    public function getTitle(): string
    {
        return __('mgmt.fleet_map');
    }

    public static function canAccess(): bool
    {
        return Auth::user()?->can('view_fleet') ?? false;
    }

    public function getLocationsProperty()
    {
        return Location::query()
            ->whereNotNull('latitude')
            ->whereNotNull('longitude')
            ->withCount(['machines' => fn ($q) => $q->where('status', 'active')])
            ->get();
    }
}

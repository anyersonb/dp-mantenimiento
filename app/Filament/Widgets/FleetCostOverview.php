<?php

namespace App\Filament\Widgets;

use App\Models\WorkOrder;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Support\Facades\Auth;

class FleetCostOverview extends BaseWidget
{
    protected static ?int $sort = 0;

    public static function canView(): bool
    {
        return Auth::user()?->can('view_costs') ?? false;
    }

    protected function getStats(): array
    {
        $completed = WorkOrder::query()->where('status', 'completed');

        $totalCost = (clone $completed)->sum('parts_cost');
        $servicesCount = (clone $completed)->count();

        return [
            Stat::make(__('mgmt.total_fleet_cost'), '$'.number_format((float) $totalCost, 2))
                ->descriptionIcon('heroicon-m-currency-dollar')
                ->color('warning'),
            Stat::make(__('mgmt.service_count'), $servicesCount)
                ->descriptionIcon('heroicon-m-wrench-screwdriver')
                ->color('primary'),
        ];
    }
}

<?php

namespace App\Filament\Widgets;

use App\Models\Machine;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class FleetStatsOverview extends BaseWidget
{
    protected static ?int $sort = 1;

    protected function getStats(): array
    {
        $machines = Machine::all();
        $active = $machines->where('status', 'active');

        $dueSoon = $active->filter(fn (Machine $m) => $m->is_due_soon && ! $m->is_overdue)->count();
        $overdue = $active->filter(fn (Machine $m) => $m->is_overdue)->count();
        $notInService = $machines->whereIn('status', ['not_in_service', 'inactive', 'down'])->count();
        $needsReview = $machines->where('needs_review', true)->count();

        return [
            Stat::make(__('fleet.machines'), $machines->count())
                ->description(__('fleet.status_active').': '.$active->count())
                ->descriptionIcon('heroicon-m-truck')
                ->color('primary'),

            Stat::make(__('fleet.due_soon'), $dueSoon)
                ->description(__('dashboard.within_100h'))
                ->descriptionIcon('heroicon-m-clock')
                ->color($dueSoon > 0 ? 'warning' : 'success'),

            Stat::make(__('dashboard.overdue'), $overdue)
                ->description(__('dashboard.past_interval'))
                ->descriptionIcon('heroicon-m-exclamation-triangle')
                ->color($overdue > 0 ? 'danger' : 'success'),

            Stat::make(__('fleet.status_not_in_service'), $notInService)
                ->description(__('dashboard.needs_review').': '.$needsReview)
                ->descriptionIcon('heroicon-m-wrench')
                ->color('gray'),
        ];
    }
}

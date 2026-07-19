<?php

namespace App\Filament\Widgets;

use App\Models\MachineCategory;
use Filament\Widgets\ChartWidget;

class MachinesByCategoryChart extends ChartWidget
{
    protected static ?int $sort = 2;

    protected int|string|array $columnSpan = 1;

    public function getHeading(): string
    {
        return __('dashboard.by_category');
    }

    protected function getData(): array
    {
        $categories = MachineCategory::withCount('machines')
            ->having('machines_count', '>', 0)
            ->orderByDesc('machines_count')
            ->get();

        return [
            'datasets' => [[
                'label' => __('fleet.machines'),
                'data' => $categories->pluck('machines_count')->all(),
                'backgroundColor' => [
                    '#f59e0b', '#3b82f6', '#10b981', '#ef4444', '#8b5cf6',
                    '#ec4899', '#14b8a6', '#f97316', '#6366f1', '#84cc16',
                    '#06b6d4', '#a855f7', '#eab308', '#22c55e', '#64748b',
                ],
            ]],
            'labels' => $categories->pluck('name')->all(),
        ];
    }

    protected function getType(): string
    {
        return 'doughnut';
    }

    protected function getOptions(): array
    {
        return [
            'plugins' => [
                'legend' => ['position' => 'bottom'],
            ],
            'scales' => [
                'x' => ['display' => false],
                'y' => ['display' => false],
            ],
        ];
    }
}

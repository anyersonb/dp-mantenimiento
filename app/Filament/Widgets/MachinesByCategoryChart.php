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
                // Hay 25 categorías con máquinas y la paleta traía 15 colores:
                // los arcos sobrantes se quedaban sin color asignado. La lista
                // tiene que cubrir por lo menos tantas entradas como categorías
                // devuelva la consulta de arriba.
                'backgroundColor' => [
                    '#f59e0b', '#3b82f6', '#10b981', '#ef4444', '#8b5cf6',
                    '#ec4899', '#14b8a6', '#f97316', '#6366f1', '#84cc16',
                    '#06b6d4', '#a855f7', '#eab308', '#22c55e', '#64748b',
                    '#0ea5e9', '#d946ef', '#f43f5e', '#4ade80', '#fb923c',
                    '#7c3aed', '#0d9488', '#b45309', '#475569', '#be123c',
                    '#65a30d', '#1d4ed8', '#c026d3', '#059669', '#9f1239',
                ],
            ]],
            // display_name traduce la categoría al idioma del usuario y cae al
            // nombre guardado si falta la clave (MachineCategory::displayName()).
            'labels' => $categories->pluck('display_name')->all(),
        ];
    }

    protected function getType(): string
    {
        return 'doughnut';
    }

    protected function getOptions(): array
    {
        return [
            // Pedido del cliente (2026-08-03): "bajarle el borde interior un
            // poco más para que se vea lo que hay debajo". El anillo por defecto
            // de Chart.js es grueso (cutout 50%) y con 25 categorías le comía el
            // espacio a la leyenda de abajo. Con el hueco más grande el anillo
            // adelgaza y la leyenda entra completa en la tarjeta.
            'cutout' => '68%',
            'plugins' => [
                'legend' => [
                    'position' => 'bottom',
                    // Cuadritos y tipografía más chicos: son 25 entradas y con
                    // el tamaño por defecto la última fila quedaba cortada.
                    'labels' => [
                        'boxWidth' => 10,
                        'boxHeight' => 10,
                        'padding' => 8,
                        'font' => ['size' => 11],
                    ],
                ],
            ],
            'scales' => [
                'x' => ['display' => false],
                'y' => ['display' => false],
            ],
        ];
    }
}

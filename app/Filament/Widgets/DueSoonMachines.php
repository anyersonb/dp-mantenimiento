<?php

namespace App\Filament\Widgets;

use App\Filament\Resources\MachineResource;
use App\Models\Machine;
use Filament\Tables;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget as BaseWidget;

class DueSoonMachines extends BaseWidget
{
    protected static ?int $sort = 3;

    protected int|string|array $columnSpan = 'full';

    public function getTableHeading(): string
    {
        return __('dashboard.upcoming_services');
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(
                Machine::query()
                    ->where('status', 'active')
                    ->whereNotNull('remaining_hours')
                    ->where('remaining_hours', '<=', Machine::ALERT_THRESHOLD)
                    ->orderBy('remaining_hours')
            )
            ->columns([
                Tables\Columns\TextColumn::make('id_code')->label(__('fleet.id_code'))->weight('bold'),
                Tables\Columns\TextColumn::make('category.name')->label(__('fleet.category'))->badge(),
                Tables\Columns\TextColumn::make('location.name')->label(__('fleet.location'))->badge()->color('gray'),
                Tables\Columns\TextColumn::make('current_hours')->label(__('fleet.current_hours'))
                    ->formatStateUsing(fn ($state) => $state !== null ? number_format($state).' h' : '—'),
                Tables\Columns\TextColumn::make('remaining_hours')->label(__('fleet.remaining_hours'))->badge()
                    ->formatStateUsing(fn ($state) => number_format($state).' h')
                    ->color(fn ($state) => $state <= 0 ? 'danger' : 'warning'),
            ])
            ->paginated([10])
            ->recordUrl(fn (Machine $r) => MachineResource::getUrl('view', ['record' => $r]));
    }
}

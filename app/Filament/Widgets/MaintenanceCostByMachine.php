<?php

namespace App\Filament\Widgets;

use App\Filament\Resources\MachineResource;
use App\Models\Machine;
use Filament\Tables;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget as BaseWidget;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;

class MaintenanceCostByMachine extends BaseWidget
{
    protected static ?int $sort = 4;

    protected int|string|array $columnSpan = 'full';

    public function getTableHeading(): string
    {
        return __('mgmt.cost_by_machine');
    }

    /**
     * Widget gerencial: solo para quien puede ver costos.
     * Responde "qué máquina es más cara de mantener".
     */
    public static function canView(): bool
    {
        return Auth::user()?->can('view_costs') ?? false;
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(
                Machine::query()
                    ->withSum(['workOrders as completed_parts_cost' => fn (Builder $q) => $q->where('status', 'completed')], 'parts_cost')
                    ->withCount(['workOrders as completed_service_count' => fn (Builder $q) => $q->where('status', 'completed')])
                    ->having('completed_parts_cost', '>', 0)
                    ->orderByDesc('completed_parts_cost')
            )
            ->columns([
                Tables\Columns\TextColumn::make('id_code')->label(__('fleet.id_code'))->weight('bold'),
                Tables\Columns\TextColumn::make('category.name')->label(__('fleet.category'))->badge(),
                Tables\Columns\TextColumn::make('location.name')->label(__('fleet.location'))->badge()->color('gray'),
                Tables\Columns\TextColumn::make('completed_service_count')->label(__('mgmt.service_count'))->alignCenter(),
                Tables\Columns\TextColumn::make('completed_parts_cost')
                    ->label(__('mgmt.maintenance_cost'))
                    ->badge()->color('warning')
                    ->formatStateUsing(fn ($state) => '$'.number_format((float) $state, 2))
                    ->sortable(),
            ])
            ->paginated([10])
            ->recordUrl(fn (Machine $r) => MachineResource::getUrl('view', ['record' => $r]));
    }
}

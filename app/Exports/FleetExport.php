<?php

namespace App\Exports;

use App\Models\Machine;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;

/**
 * Excel export of the fleet status listing.
 *
 * Cost columns are only included when the exporting user has the
 * "view_costs" permission (per the confirmed client rule that
 * foreman/operators must never see money figures).
 */
class FleetExport implements FromCollection, ShouldAutoSize, WithHeadings, WithMapping
{
    public function __construct(private readonly bool $includeCosts) {}

    public function collection(): Collection
    {
        $query = Machine::query()
            ->with(['category', 'make', 'location'])
            ->orderBy('id_code');

        if ($this->includeCosts) {
            // Avoids an N+1 by pre-aggregating costs instead of calling the
            // per-machine accessor for every row.
            $query
                ->withSum(['workOrders as completed_parts_cost' => fn (Builder $q) => $q->where('status', 'completed')], 'parts_cost')
                ->withCount(['workOrders as completed_service_count' => fn (Builder $q) => $q->where('status', 'completed')]);
        }

        return $query->get();
    }

    public function headings(): array
    {
        $headings = [
            __('fleet.id_code'),
            __('fleet.category'),
            __('fleet.make'),
            __('fleet.model'),
            __('fleet.location'),
            __('fleet.current_hours'),
            __('fleet.remaining_hours'),
            __('fleet.status'),
        ];

        if ($this->includeCosts) {
            $headings[] = __('mgmt.service_count');
            $headings[] = __('mgmt.maintenance_cost');
        }

        return $headings;
    }

    public function map($machine): array
    {
        /** @var Machine $machine */
        $row = [
            $machine->id_code,
            $machine->category?->name,
            $machine->make?->name,
            $machine->model,
            $machine->location?->name,
            $machine->current_hours,
            $machine->computed_remaining_hours,
            __('fleet.status_'.$machine->status),
        ];

        if ($this->includeCosts) {
            $row[] = (int) $machine->completed_service_count;
            $row[] = number_format((float) $machine->completed_parts_cost, 2);
        }

        return $row;
    }
}

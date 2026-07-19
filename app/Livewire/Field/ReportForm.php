<?php

namespace App\Livewire\Field;

use App\Models\FieldReport;
use App\Models\HorometerReading;
use App\Models\Machine;
use Illuminate\Support\Facades\Auth;
use Livewire\Component;

class ReportForm extends Component
{
    public string $search = '';

    public array $machineResults = [];

    public ?int $machineId = null;

    public ?string $machineLabel = null;

    public ?int $machineLocationId = null;

    public string $condition = 'ok';

    public $hours = '';

    public string $notes = '';

    public ?float $latitude = null;

    public ?float $longitude = null;

    public bool $locationCaptured = false;

    public bool $submitted = false;

    public function mount(): void
    {
        abort_unless(Auth::user()->hasRole('personal_mantenimiento'), 403);
    }

    public function updatedSearch(): void
    {
        $this->machineResults = mb_strlen($this->search) < 1
            ? []
            : Machine::query()
                ->where('id_code', 'like', "%{$this->search}%")
                ->orderBy('id_code')
                ->limit(8)
                ->get(['id', 'id_code', 'current_location_id'])
                ->toArray();
    }

    public function selectMachine(int $id): void
    {
        $machine = Machine::find($id);

        if (! $machine) {
            return;
        }

        $this->machineId = $machine->id;
        $this->machineLabel = $machine->id_code;
        $this->machineLocationId = $machine->current_location_id;
        $this->search = '';
        $this->machineResults = [];
    }

    public function clearMachine(): void
    {
        $this->machineId = null;
        $this->machineLabel = null;
        $this->machineLocationId = null;
    }

    public function setLocation($lat, $lng): void
    {
        $this->latitude = is_numeric($lat) ? (float) $lat : null;
        $this->longitude = is_numeric($lng) ? (float) $lng : null;
        $this->locationCaptured = true;
    }

    protected function rules(): array
    {
        return [
            'machineId' => ['required', 'integer', 'exists:machines,id'],
            'condition' => ['required', 'in:ok,attention,critical'],
            'hours' => ['nullable', 'numeric', 'min:0'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ];
    }

    public function save(): void
    {
        $this->validate();

        FieldReport::create([
            'machine_id' => $this->machineId,
            'reported_by' => Auth::id(),
            'location_id' => $this->machineLocationId,
            'condition' => $this->condition,
            'hours' => $this->hours !== '' ? (int) round((float) $this->hours) : null,
            'notes' => $this->notes !== '' ? $this->notes : null,
            'latitude' => $this->latitude,
            'longitude' => $this->longitude,
        ]);

        if ($this->hours !== '') {
            HorometerReading::create([
                'machine_id' => $this->machineId,
                'hours' => (int) round((float) $this->hours),
                'read_at' => now()->toDateString(),
                'source' => 'maintenance',
                'recorded_by' => Auth::id(),
                'latitude' => $this->latitude,
                'longitude' => $this->longitude,
            ]);
        }

        $this->submitted = true;
    }

    public function startNew(): void
    {
        $this->reset([
            'machineId', 'machineLabel', 'machineLocationId', 'hours', 'notes',
            'submitted', 'search', 'machineResults',
        ]);
        $this->condition = 'ok';
    }

    public function render()
    {
        return view('livewire.field.report-form')
            ->layout('components.layouts.field', ['title' => __('field.report_title')]);
    }
}

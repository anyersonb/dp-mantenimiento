<?php

namespace App\Livewire\Field;

use App\Models\HorometerReading;
use App\Models\Machine;
use Illuminate\Support\Facades\Auth;
use Livewire\Component;

class FuelLog extends Component
{
    public string $search = '';

    public array $machineResults = [];

    public ?int $machineId = null;

    public ?string $machineLabel = null;

    public $gallons = '';

    public $hours = '';

    public string $note = '';

    public ?float $latitude = null;

    public ?float $longitude = null;

    public bool $locationCaptured = false;

    public bool $submitted = false;

    public function mount(): void
    {
        abort_unless(Auth::user()->hasRole('operador_cisterna'), 403);
    }

    public function updatedSearch(): void
    {
        $this->machineResults = mb_strlen($this->search) < 1
            ? []
            : Machine::query()
                ->where('id_code', 'like', "%{$this->search}%")
                ->orderBy('id_code')
                ->limit(8)
                ->get(['id', 'id_code', 'current_hours'])
                ->toArray();
    }

    public function selectMachine(int $id): void
    {
        $machine = Machine::find($id);

        if (! $machine) {
            return;
        }

        $this->machineId = $machine->id;
        $this->machineLabel = $machine->id_code.($machine->current_hours !== null
            ? ' ('.number_format($machine->current_hours).' h)'
            : '');
        $this->search = '';
        $this->machineResults = [];
    }

    public function clearMachine(): void
    {
        $this->machineId = null;
        $this->machineLabel = null;
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
            'gallons' => ['required', 'numeric', 'min:0.01'],
            'hours' => ['required', 'numeric', 'min:0'],
            'note' => ['nullable', 'string', 'max:255'],
        ];
    }

    public function save(): void
    {
        $this->validate();

        HorometerReading::create([
            'machine_id' => $this->machineId,
            'hours' => (int) round((float) $this->hours),
            'read_at' => now()->toDateString(),
            'source' => 'fuel',
            'recorded_by' => Auth::id(),
            'gallons' => $this->gallons,
            'note' => $this->note !== '' ? $this->note : null,
            'latitude' => $this->latitude,
            'longitude' => $this->longitude,
        ]);

        $this->submitted = true;
    }

    public function startNew(): void
    {
        $this->reset([
            'machineId', 'machineLabel', 'gallons', 'hours', 'note',
            'submitted', 'search', 'machineResults',
        ]);
    }

    public function render()
    {
        return view('livewire.field.fuel-log')
            ->layout('components.layouts.field', ['title' => __('field.fuel_title')]);
    }
}

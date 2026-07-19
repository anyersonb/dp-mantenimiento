<?php

namespace App\Livewire\Field;

use App\Models\HorometerReading;
use App\Models\Location;
use App\Models\Machine;
use Illuminate\Support\Facades\Auth;
use Livewire\Component;

class ForemanBoard extends Component
{
    public string $search = '';

    public array $machineResults = [];

    public ?int $machineId = null;

    public ?string $machineLabel = null;

    public $locationId = '';

    public $hours = '';

    public bool $submitted = false;

    public function mount(): void
    {
        abort_unless(Auth::user()->hasRole('foreman'), 403);
        $this->locationId = Auth::user()->location_id ?? '';
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
        $this->locationId = $machine->current_location_id ?? $this->locationId;
        $this->search = '';
        $this->machineResults = [];
    }

    public function clearMachine(): void
    {
        $this->machineId = null;
        $this->machineLabel = null;
    }

    protected function rules(): array
    {
        return [
            'machineId' => ['required', 'integer', 'exists:machines,id'],
            'locationId' => ['required', 'integer', 'exists:locations,id'],
            'hours' => ['nullable', 'numeric', 'min:0'],
        ];
    }

    public function save(): void
    {
        $this->validate();

        $machine = Machine::findOrFail($this->machineId);
        $machine->current_location_id = $this->locationId;
        $machine->save();

        if ($this->hours !== '') {
            HorometerReading::create([
                'machine_id' => $machine->id,
                'hours' => (int) round((float) $this->hours),
                'read_at' => now()->toDateString(),
                'source' => 'foreman',
                'recorded_by' => Auth::id(),
            ]);
        }

        $this->submitted = true;
    }

    public function startNew(): void
    {
        $this->reset(['machineId', 'machineLabel', 'hours', 'submitted', 'search', 'machineResults']);
        $this->locationId = Auth::user()->location_id ?? '';
    }

    public function getMyMachinesProperty()
    {
        $user = Auth::user();

        $query = Machine::query()->where('status', 'active')->with('location');

        if ($user->location_id) {
            $query->where('current_location_id', $user->location_id);
        }

        return $query->orderBy('id_code')->limit(50)->get();
    }

    public function render()
    {
        return view('livewire.field.foreman-board', [
            'locations' => Location::orderBy('name')->get(['id', 'name']),
            'myMachines' => $this->myMachines,
        ])->layout('components.layouts.field', ['title' => __('field.foreman_title')]);
    }
}

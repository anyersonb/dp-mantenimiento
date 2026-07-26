<?php

namespace App\Livewire\Field;

use App\Models\HorometerReading;
use App\Models\Location;
use App\Models\Machine;
use Illuminate\Support\Facades\Auth;
use App\Livewire\Field\Concerns\RejectsIncoherentReadings;
use Livewire\Component;

class ForemanBoard extends Component
{
    use RejectsIncoherentReadings;

    public string $search = '';

    public array $machineResults = [];

    public ?int $machineId = null;

    public ?string $machineLabel = null;

    public $locationId = '';

    public $hours = '';

    public bool $submitted = false;

    public function mount(): void
    {
        // Hallazgo A1/C2 (Etapa 05): autoriza por PERMISO, no por rol. Este
        // tablero sirve tanto a quien solo puede ratificar la obra actual
        // (confirm_location, ej. foreman) como a quien puede reasignarla
        // (move_fleet, ej. gerencia); save() vuelve a distinguir cuál de las
        // dos facultades hace falta según lo que se está pidiendo.
        abort_unless(
            Auth::user()->can('confirm_location') || Auth::user()->can('move_fleet'),
            403
        );
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

        if ($this->hours !== '' && $this->isRegressiveReading()) {
            return;
        }

        $machine = Machine::findOrFail($this->machineId);

        // Hallazgo C2: "confirmar ubicación" y "mover flota" son facultades
        // distintas. Si la obra elegida es igual a la actual, alcanza con
        // confirm_location (mount() ya lo exigió). Si es distinta, hace
        // falta move_fleet — y se revalida acá, server-side, para que ni un
        // <select> manipulado ni un payload Livewire directo lo salteen.
        $isMove = (int) $this->locationId !== (int) $machine->current_location_id;

        if ($isMove) {
            abort_unless(Auth::user()->can('move_fleet'), 403);

            $machine->current_location_id = $this->locationId;
            $machine->save();

            // Machine::getActivitylogOptions() ya deja un registro "updated"
            // por el cambio de current_location_id (logOnlyDirty); este
            // evento explícito es el que distingue en la bitácora que fue
            // un movimiento de obra y no otro tipo de edición.
            activity()
                ->performedOn($machine)
                ->causedBy(Auth::user())
                ->event('location_moved')
                ->log('Máquina movida a otra obra');
        } else {
            // No hay atributo que cambie (logOnlyDirty no generaría nada),
            // así que la ratificación necesita su propio asiento explícito
            // para quedar en la bitácora.
            activity()
                ->performedOn($machine)
                ->causedBy(Auth::user())
                ->event('location_confirmed')
                ->log('Ubicación de la máquina confirmada sin cambios');
        }

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


    /**
     * Hallazgo C2: el <select> de obras no debe ofrecer opciones que el
     * usuario no puede asignar. Quien tiene move_fleet ve todas las obras;
     * quien solo tiene confirm_location ve exclusivamente la obra actual de
     * la máquina seleccionada (o ninguna, hasta elegir una máquina).
     */
    public function getAllowedLocationsProperty()
    {
        if (Auth::user()->can('move_fleet')) {
            return Location::orderBy('name')->get(['id', 'name']);
        }

        $machine = $this->machineId ? Machine::find($this->machineId) : null;

        if (! $machine || ! $machine->current_location_id) {
            return Location::query()->whereRaw('1 = 0')->get(['id', 'name']);
        }

        return Location::query()
            ->where('id', $machine->current_location_id)
            ->get(['id', 'name']);
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
            'locations' => $this->allowedLocations,
            'myMachines' => $this->myMachines,
        ])->layout('components.layouts.field', ['title' => __('field.foreman_title')]);
    }
}

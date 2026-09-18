<?php

namespace App\Livewire\Field;

use App\Livewire\Field\Concerns\RejectsIncoherentReadings;
use App\Models\HorometerReading;
use App\Models\Machine;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Livewire\Component;

class FuelLog extends Component
{
    use RejectsIncoherentReadings;

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

    /**
     * null = todavía obteniendo o ya capturada; si no, 'denied' | 'unavailable' | 'unsupported'.
     * Ver resources/views/livewire/field/partials/geolocation-script.blade.php.
     */
    public ?string $locationError = null;

    public bool $submitted = false;

    /**
     * Si el registro que se acaba de guardar quedó sin coordenadas. Mismo
     * tratamiento que ReportForm::$submittedWithoutLocation: un registro sin
     * ubicación mostraba el mismo "✅ Registrado ✓" que uno completo, y nadie
     * se enteraba nunca de que faltaba la coordenada.
     */
    public bool $submittedWithoutLocation = false;

    public function mount(): void
    {
        // Hallazgo A1 (Etapa 05): autoriza por PERMISO (log_fuel), no por
        // nombre de rol, para que el editor de roles del panel gobierne
        // realmente este módulo de campo.
        abort_unless(Auth::user()->can('log_fuel'), 403);
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
        $this->locationError = null;
    }

    /**
     * Llamado por el JS del parcial compartido cuando getCurrentPosition
     * falla o el navegador no soporta geolocalización. $reason llega en
     * lenguaje de máquina (denied/unavailable/unsupported); la vista lo
     * traduce a lenguaje llano, nunca "POSITION_UNAVAILABLE".
     */
    public function setLocationError(string $reason): void
    {
        $this->locationError = in_array($reason, ['denied', 'unavailable', 'unsupported'], true)
            ? $reason
            : 'unavailable';
        $this->locationCaptured = false;
    }

    /**
     * El botón "Reintentar" vuelve la pantalla a "obteniendo" y avisa al JS
     * del parcial para que llame getCurrentPosition() de nuevo.
     */
    public function retryLocation(): void
    {
        $this->locationError = null;
        $this->locationCaptured = false;
        $this->dispatch('geolocation-retry');
    }

    protected function rules(): array
    {
        return [
            // Mismo patrón que ReportForm (hallazgo 5, auditoría 2026-09-18):
            // sin el scope de SoftDeletes se podía registrar combustible
            // contra una máquina en la papelera.
            'machineId' => ['required', 'integer', Rule::exists('machines', 'id')->whereNull('deleted_at')],
            'gallons' => ['required', 'numeric', 'min:0.01'],
            'hours' => ['required', 'numeric', 'min:0'],
            'note' => ['nullable', 'string', 'max:255'],
        ];
    }

    public function save(): void
    {
        $this->validate();

        if ($this->isRegressiveReading()) {
            return;
        }

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

        $this->submittedWithoutLocation = $this->latitude === null || $this->longitude === null;

        $this->submitted = true;
    }

    public function startNew(): void
    {
        $this->reset([
            'machineId', 'machineLabel', 'gallons', 'hours', 'note',
            'submitted', 'submittedWithoutLocation', 'search', 'machineResults',
        ]);
    }

    public function render()
    {
        return view('livewire.field.fuel-log')
            ->layout('components.layouts.field', ['title' => __('field.fuel_title')]);
    }
}

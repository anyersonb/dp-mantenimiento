<?php

namespace App\Livewire\Field;

use App\Livewire\Field\Concerns\RejectsIncoherentReadings;
use App\Models\FieldReport;
use App\Models\HorometerReading;
use App\Models\Machine;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Livewire\Component;

class ReportForm extends Component
{
    use RejectsIncoherentReadings;

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

    /**
     * null = todavía obteniendo o ya capturada; si no, 'denied' | 'unavailable' | 'unsupported'.
     * Ver resources/views/livewire/field/partials/geolocation-script.blade.php.
     */
    public ?string $locationError = null;

    public bool $submitted = false;

    /**
     * Si el reporte que se acaba de guardar quedó sin coordenadas. Distinto
     * de $locationCaptured: esto se congela en el momento del save() para que
     * la pantalla de éxito pueda avisar con tono de advertencia (hallazgo:
     * un reporte sin ubicación mostraba el mismo "✅ ✓" que uno completo, y
     * nadie se enteraba nunca de que faltaba la coordenada).
     */
    public bool $submittedWithoutLocation = false;

    public function mount(): void
    {
        // Hallazgo A1/C3 (Etapa 05): autoriza por PERMISO (field_report), no por
        // nombre de rol. Antes exigía hasRole('personal_mantenimiento') y por
        // eso foreman —que sí tiene field_report en la matriz— recibía 403.
        abort_unless(Auth::user()->can('field_report'), 403);
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
     * del parcial para que llame getCurrentPosition() de nuevo. Sin esto el
     * usuario quedaba con el mensaje de error para siempre tras el primer
     * intento fallido.
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
            // Hallazgo 5 (auditoría 2026-09-18): `exists:machines,id` a secas
            // no mira `deleted_at` (SoftDeletes) — se podía crear un reporte
            // para una máquina en la papelera, que quedaba con `machine_id`
            // apuntando a un registro borrado y `location_id` en null (la
            // máquina en papelera no tiene `current_location_id` vigente).
            // Con el scope, intentarlo da un error de validación entendible
            // en vez de un reporte huérfano.
            'machineId' => ['required', 'integer', Rule::exists('machines', 'id')->whereNull('deleted_at')],
            'condition' => ['required', 'in:ok,attention,critical'],
            'hours' => ['nullable', 'numeric', 'min:0'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ];
    }

    public function save(): void
    {
        $this->validate();

        if ($this->hours !== '' && $this->isRegressiveReading()) {
            return;
        }

        // Hallazgo 6 (auditoría 2026-09-18): $machineLocationId es una
        // propiedad pública de Livewire que NO está en rules() — un operario
        // con la consola del navegador puede pisarla ($wire.set(...)) y
        // registrar que la máquina estaba en una obra distinta de la real.
        // Se ignora lo que mandó el cliente y se deriva de la máquina en el
        // servidor, mismo dato que ya validó `machineId` en rules() como
        // `exists:machines,id`.
        $locationId = Machine::find($this->machineId)?->current_location_id;

        FieldReport::create([
            'machine_id' => $this->machineId,
            'reported_by' => Auth::id(),
            'location_id' => $locationId,
            'condition' => $this->condition,
            'hours' => $this->hours !== '' ? (int) round((float) $this->hours) : null,
            'notes' => $this->notes !== '' ? $this->notes : null,
            'latitude' => $this->latitude,
            'longitude' => $this->longitude,
        ]);

        $this->submittedWithoutLocation = $this->latitude === null || $this->longitude === null;

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
            'submitted', 'submittedWithoutLocation', 'search', 'machineResults',
        ]);
        $this->condition = 'ok';
    }

    public function render()
    {
        return view('livewire.field.report-form')
            ->layout('components.layouts.field', ['title' => __('field.report_title')]);
    }
}

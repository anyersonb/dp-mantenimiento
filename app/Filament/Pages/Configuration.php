<?php

namespace App\Filament\Pages;

use App\Models\Setting;
use App\Services\TaxCalculator;
use Filament\Forms;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Support\Facades\Auth;

/**
 * Configuración del panel. Hoy solo la tasa de impuesto de repuestos
 * (Florida, 7% por defecto), pero la pantalla está armada para sumar más
 * ajustes de `settings` sin rehacerla.
 *
 * Gate: `manage_settings` — permiso nuevo, dado solo a `administrador`
 * (ver la migración 2026_09_01_100100). No usa App\Support\AccessControl
 * porque no reemplaza ningún chequeo por nombre de rol preexistente: es una
 * capacidad nueva, así que sin el permiso el fallo es cerrado directo
 * (`?? false`), sin red de compatibilidad que aplicar.
 */
class Configuration extends Page implements HasForms
{
    use InteractsWithForms;

    protected static ?string $navigationIcon = 'heroicon-o-cog-6-tooth';

    protected static ?int $navigationSort = 20;

    protected static string $view = 'filament.pages.configuration';

    /** @var array<string, mixed> */
    public ?array $data = [];

    public static function getNavigationGroup(): ?string
    {
        return __('fleet.group_admin');
    }

    public static function getNavigationLabel(): string
    {
        return __('settings.nav');
    }

    public function getTitle(): string
    {
        return __('settings.title');
    }

    public static function canAccess(): bool
    {
        return Auth::user()?->can('manage_settings') ?? false;
    }

    public function mount(): void
    {
        abort_unless(static::canAccess(), 403);

        $this->form->fill([
            'tax_rate' => TaxCalculator::rate(),
        ]);
    }

    public function form(Form $form): Form
    {
        return $form
            ->statePath('data')
            ->schema([
                Forms\Components\Section::make(__('settings.tax_section'))
                    ->description(__('settings.tax_section_help'))
                    ->schema([
                        Forms\Components\TextInput::make('tax_rate')
                            ->label(__('settings.tax_rate'))
                            ->helperText(__('settings.tax_rate_help'))
                            ->numeric()
                            ->required()
                            ->minValue(0)
                            ->maxValue(100)
                            ->step(0.01)
                            ->suffix('%'),
                    ]),
            ]);
    }

    /**
     * Guarda la tasa. El `abort_unless` es defensa en profundidad: `canAccess()`
     * ya bloquea el montaje de la página, pero un método público de Livewire
     * es alcanzable por su propio nombre si alguien arma la petición a mano.
     */
    public function save(): void
    {
        abort_unless(static::canAccess(), 403);

        $state = $this->form->getState();

        // round(..., 2): sin esto, un valor tecleado con más de 2 decimales
        // (ej. 7.123456789012345678) se acepta y se guarda con la precisión
        // completa del float de PHP, ensuciando el rótulo del reporte sin
        // ganar nada — el paso ya es 0.01 en el formulario. Hallazgo menor
        // de seguridad, 2026-09-01.
        Setting::set(TaxCalculator::SETTING_KEY, round((float) $state['tax_rate'], 2), TaxCalculator::SETTING_TYPE);

        Notification::make()
            ->title(__('settings.saved'))
            ->success()
            ->send();
    }
}

<?php

namespace App\Filament\Pages;

use App\Models\Location;
use App\Models\Machine;
use App\Models\MachineCategory;
use App\Models\User;
use App\Services\Reports\CategoryInventoryReportBuilder;
use App\Services\Reports\CostReportBuilder;
use App\Services\Reports\CostReportFilters;
use Carbon\CarbonImmutable;
use Filament\Actions\Action;
use Filament\Forms;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Pages\Page;
use Illuminate\Support\Facades\Auth;

/**
 * Centro de reportes del panel (pedido del cliente 2026-08-05).
 *
 * Dos reportes hoy, y la pantalla está armada para que agregar el tercero sea
 * sumar una opción al selector:
 *
 *   - **Costos de mantenimiento**: cuánto se gastó en cada máquina en un periodo,
 *     con el detalle de quién hizo el trabajo, dónde, cuándo, el resultado del
 *     checklist y los repuestos comprados. Exporta a PDF y a Excel.
 *   - **Inventario por categoría**: cuántas máquinas hay de cada tipo. Exporta a PDF.
 *
 * La pantalla muestra un **resumen** (una fila por máquina) y el detalle completo
 * vive en los archivos descargables. No es una limitación: el detalle de una OT
 * son sus 61 ítems de checklist más sus repuestos, y eso en pantalla es
 * ilegible y además obliga a recalcular todo en cada tecla que el usuario toca
 * en los filtros.
 *
 * Los totales de la pantalla y los de los archivos salen del MISMO
 * `CostReportBuilder`, así que no pueden discrepar.
 */
class Reports extends Page implements HasForms
{
    use InteractsWithForms;

    protected static ?string $navigationIcon = 'heroicon-o-document-chart-bar';

    protected static ?int $navigationSort = 4;

    protected static string $view = 'filament.pages.reports';

    /** @var array<string, mixed> */
    public ?array $data = [];

    public static function getNavigationGroup(): ?string
    {
        return __('fleet.group_management');
    }

    public static function getNavigationLabel(): string
    {
        return __('reports.nav');
    }

    public function getTitle(): string
    {
        return __('reports.title');
    }

    /**
     * Ver reportes es el permiso de entrada. El de costos, además, exige
     * `view_costs` — y eso se controla por reporte, no por página, porque el
     * inventario por categoría no tiene ni una cifra de dinero y no hay motivo
     * para negárselo a quien puede ver reportes.
     */
    public static function canAccess(): bool
    {
        return Auth::user()?->can('view_reports') ?? false;
    }

    public static function canSeeCosts(): bool
    {
        return Auth::user()?->can('view_costs') ?? false;
    }

    public function mount(): void
    {
        $this->form->fill([
            'report' => static::canSeeCosts() ? 'costs' : 'category_inventory',
            'quick_period' => 'this_month',
            'from' => CarbonImmutable::now()->startOfMonth()->toDateString(),
            'to' => CarbonImmutable::now()->endOfMonth()->toDateString(),
            'statuses' => ['completed'],
        ]);
    }

    public function form(Form $form): Form
    {
        return $form
            ->statePath('data')
            ->schema([
                Forms\Components\Section::make(__('reports.which_report'))->schema([
                    Forms\Components\Select::make('report')
                        ->label(__('reports.report'))
                        ->options(static::reportOptions())
                        ->default(static::canSeeCosts() ? 'costs' : 'category_inventory')
                        ->selectablePlaceholder(false)
                        ->live(),
                ]),

                Forms\Components\Section::make(__('reports.period'))
                    ->description(__('reports.period_help'))
                    ->columns(3)
                    ->visible(fn (Forms\Get $get) => $get('report') === 'costs')
                    ->schema([
                        Forms\Components\Select::make('quick_period')
                            ->label(__('reports.quick_period'))
                            ->options([
                                'this_month' => __('reports.this_month'),
                                'last_month' => __('reports.last_month'),
                                'last_3_months' => __('reports.last_3_months'),
                                'this_year' => __('reports.this_year'),
                                'custom' => __('reports.custom'),
                            ])
                            ->live()
                            ->afterStateUpdated(function ($state, Forms\Set $set) {
                                $range = static::rangeFor($state);

                                if ($range === null) {
                                    return;
                                }

                                $set('from', $range[0]->toDateString());
                                $set('to', $range[1]->toDateString());
                            }),
                        Forms\Components\DatePicker::make('from')->label(__('reports.from'))->live()
                            ->afterStateUpdated(fn (Forms\Set $set) => $set('quick_period', 'custom')),
                        Forms\Components\DatePicker::make('to')->label(__('reports.to'))->live()
                            ->afterStateUpdated(fn (Forms\Set $set) => $set('quick_period', 'custom')),
                    ]),

                Forms\Components\Section::make(__('reports.filters'))
                    ->columns(2)
                    ->collapsible()
                    ->visible(fn (Forms\Get $get) => $get('report') === 'costs')
                    ->schema([
                        Forms\Components\TextInput::make('id_code')
                            ->label(__('fleet.machine_number'))
                            ->placeholder(__('fleet.machine_number_placeholder'))
                            ->helperText(__('reports.id_code_help'))
                            ->live(onBlur: true),
                        Forms\Components\Select::make('machine_ids')
                            ->label(__('reports.machines'))
                            ->multiple()->searchable()->preload()->live()
                            ->options(fn () => Machine::query()->orderBy('id_code')
                                ->pluck('id_code', 'id')->all()),
                        Forms\Components\Select::make('category_ids')
                            ->label(__('fleet.category'))
                            ->multiple()->preload()->live()
                            ->options(fn () => MachineCategory::query()->orderBy('name')->get()
                                ->mapWithKeys(fn (MachineCategory $c) => [$c->id => $c->display_name])->all()),
                        Forms\Components\Select::make('location_ids')
                            ->label(__('reports.locations'))
                            ->helperText(__('reports.location_help'))
                            ->multiple()->preload()->live()
                            ->options(fn () => Location::locationSelectOptions()),
                        Forms\Components\Select::make('completed_by')
                            ->label(__('reports.completed_by'))
                            ->helperText(__('reports.completed_by_help'))
                            ->multiple()->preload()->searchable()->live()
                            ->options(fn () => User::query()->orderBy('name')->pluck('name', 'id')->all()),
                        Forms\Components\CheckboxList::make('types')
                            ->label(__('wo.type'))
                            ->options([
                                'preventive' => __('wo.preventive'),
                                'corrective' => __('wo.corrective'),
                                'inspection' => __('wo.inspection'),
                            ])->columns(3)->live(),
                        Forms\Components\CheckboxList::make('statuses')
                            ->label(__('fleet.status'))
                            ->helperText(__('reports.statuses_help'))
                            ->options([
                                'open' => __('wo.open'),
                                'assigned' => __('wo.assigned'),
                                'in_progress' => __('wo.in_progress'),
                                'completed' => __('wo.completed'),
                                'cancelled' => __('wo.cancelled'),
                            ])->columns(3)->live(),
                    ]),
            ]);
    }

    /**
     * @return array<string, string>
     */
    public static function reportOptions(): array
    {
        $options = [];

        if (static::canSeeCosts()) {
            $options['costs'] = __('reports.report_costs');
        }

        $options['category_inventory'] = __('reports.report_category_inventory');

        return $options;
    }

    /**
     * Qué reporte está eligiendo el usuario, ya saneado: si alguien manipula el
     * estado para pedir el de costos sin `view_costs`, cae al de inventario en
     * vez de calcular cifras que no puede ver.
     */
    public function selectedReport(): string
    {
        $report = $this->data['report'] ?? 'costs';

        if ($report === 'costs' && ! static::canSeeCosts()) {
            return 'category_inventory';
        }

        return array_key_exists($report, static::reportOptions()) ? $report : 'category_inventory';
    }

    public function filters(): CostReportFilters
    {
        return CostReportFilters::fromArray($this->data ?? []);
    }

    /**
     * @return array<string, mixed>
     */
    public function reportData(): array
    {
        if ($this->selectedReport() === 'category_inventory') {
            return CategoryInventoryReportBuilder::build();
        }

        return CostReportBuilder::build($this->filters());
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('pdf')
                ->label(__('reports.download_pdf'))
                ->icon('heroicon-o-document-arrow-down')
                ->color('danger')
                ->visible(fn () => Auth::user()?->can('view_reports') ?? false)
                ->url(fn () => $this->downloadUrl('pdf'), shouldOpenInNewTab: true),

            Action::make('excel')
                ->label(__('reports.download_excel'))
                ->icon('heroicon-o-table-cells')
                ->color('success')
                // El inventario por categoría se pidió solo en PDF; no se
                // inventa un Excel que nadie pidió y después hay que mantener.
                ->visible(fn () => $this->selectedReport() === 'costs' && static::canSeeCosts())
                ->url(fn () => $this->downloadUrl('xlsx'), shouldOpenInNewTab: true),
        ];
    }

    private function downloadUrl(string $format): string
    {
        if ($this->selectedReport() === 'category_inventory') {
            return route('reports.categories.pdf');
        }

        return route('reports.costs.'.$format, $this->filters()->toQuery());
    }

    /**
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}|null
     */
    private static function rangeFor(?string $period): ?array
    {
        $now = CarbonImmutable::now();

        return match ($period) {
            'this_month' => [$now->startOfMonth(), $now->endOfMonth()],
            'last_month' => [$now->subMonth()->startOfMonth(), $now->subMonth()->endOfMonth()],
            'last_3_months' => [$now->subMonths(2)->startOfMonth(), $now->endOfMonth()],
            'this_year' => [$now->startOfYear(), $now->endOfYear()],
            default => null,
        };
    }
}

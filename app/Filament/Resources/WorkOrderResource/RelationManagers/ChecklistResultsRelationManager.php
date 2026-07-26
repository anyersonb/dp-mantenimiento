<?php

namespace App\Filament\Resources\WorkOrderResource\RelationManagers;

use App\Models\ChecklistResult;
use App\Models\ChecklistTemplate;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;

class ChecklistResultsRelationManager extends RelationManager
{
    protected static string $relationship = 'checklistResults';

    protected static ?string $recordTitleAttribute = 'label';

    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return __('checklist.checklist');
    }

    public function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\TextInput::make('label')
                ->label(__('checklist.item'))
                ->required()
                ->maxLength(255)
                ->columnSpanFull(),
            Forms\Components\Select::make('result')
                ->label(__('checklist.result'))
                ->options([
                    'ok' => __('checklist.result_ok'),
                    'alert' => __('checklist.result_alert'),
                    'na' => __('checklist.result_na'),
                ])
                ->default('ok')
                ->required()
                ->live(),
            Forms\Components\Textarea::make('alert_detail')
                ->label(__('checklist.alert_detail'))
                ->helperText(__('checklist.alert_detail_help'))
                ->requiredIf('result', 'alert')
                ->visible(fn (Forms\Get $get) => $get('result') === 'alert')
                ->columnSpanFull(),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('templateItem.section')
                    ->label(__('checklist.section'))
                    ->badge()
                    ->color('gray')
                    ->placeholder('—')
                    ->toggleable(),
                Tables\Columns\TextColumn::make('label')
                    ->label(__('checklist.item'))
                    ->wrap()
                    ->searchable(),
                Tables\Columns\TextColumn::make('result')
                    ->label(__('checklist.result'))
                    ->badge()
                    ->formatStateUsing(fn ($state) => __('checklist.result_'.$state))
                    ->color(fn ($state) => match ($state) {
                        'alert' => 'danger',
                        'na' => 'gray',
                        default => 'success',
                    }),
                Tables\Columns\TextColumn::make('alert_detail')
                    ->label(__('checklist.alert_detail'))
                    ->wrap()
                    ->placeholder('—')
                    ->limit(80),
            ])
            ->defaultSort('id')
            ->headerActions([
                Tables\Actions\Action::make('preload_checklist')
                    ->label(__('wo.preload_checklist'))
                    ->icon('heroicon-o-arrow-down-tray')
                    ->color('info')
                    // Hallazgo del inventario Etapa 05: esta acción no tenía NI
                    // ->visible() ni ->authorize(); cualquiera que llegara a la
                    // página de la OT (hoy exige execute_work_order gracias al
                    // fix de C1) podía precargar el checklist. Se exige el mismo
                    // permiso que da acceso de edición a la OT.
                    ->authorize(fn () => Auth::user()?->can('execute_work_order') ?? false)
                    ->requiresConfirmation()
                    ->modalDescription(__('wo.preload_checklist_confirm'))
                    ->action(function () {
                        $workOrder = $this->getOwnerRecord();

                        $template = ChecklistTemplate::where('active', true)->first();

                        if (! $template) {
                            return;
                        }

                        foreach ($template->items as $item) {
                            ChecklistResult::firstOrCreate(
                                [
                                    'work_order_id' => $workOrder->id,
                                    'checklist_template_item_id' => $item->id,
                                ],
                                [
                                    'label' => $item->label,
                                    'result' => 'ok',
                                ]
                            );
                        }
                    }),
                Tables\Actions\CreateAction::make(),
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
                Tables\Actions\DeleteAction::make(),
            ])
            ->emptyStateHeading(__('checklist.empty_heading'))
            ->emptyStateDescription(__('checklist.empty_desc'));
    }
}

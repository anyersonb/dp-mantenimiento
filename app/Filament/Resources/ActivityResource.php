<?php

namespace App\Filament\Resources;

use App\Filament\Resources\ActivityResource\Pages;
use App\Models\Machine;
use App\Models\WorkOrder;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Form;
use Filament\Infolists\Components as InfolistComponents;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Spatie\Activitylog\Models\Activity;

/**
 * Visor de bitácora transversal (quién / qué / cuándo), de solo lectura.
 * Solo administrador y responsable de mantenimiento tienen "view_audit_log"
 * (gerencia queda fuera a propósito, según la matriz de permisos confirmada).
 */
class ActivityResource extends Resource
{
    protected static ?string $model = Activity::class;

    protected static ?string $navigationIcon = 'heroicon-o-clock';

    protected static ?int $navigationSort = 1;

    public static function getNavigationLabel(): string
    {
        return __('mgmt.audit_log');
    }

    public static function getModelLabel(): string
    {
        return __('mgmt.audit_log');
    }

    public static function getPluralModelLabel(): string
    {
        return __('mgmt.audit_logs');
    }

    public static function getNavigationGroup(): ?string
    {
        return __('fleet.group_management');
    }

    public static function canViewAny(): bool
    {
        return Auth::user()?->can('view_audit_log') ?? false;
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit($record): bool
    {
        return false;
    }

    public static function canDelete($record): bool
    {
        return false;
    }

    public static function form(Form $form): Form
    {
        return $form->schema([]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('created_at')
                    ->label(__('mgmt.date'))->dateTime()->sortable(),
                Tables\Columns\TextColumn::make('causer.name')
                    ->label(__('mgmt.causer'))
                    ->placeholder(__('mgmt.no_causer'))
                    ->sortable(),
                Tables\Columns\TextColumn::make('event')
                    ->label(__('mgmt.event'))
                    ->badge()
                    ->formatStateUsing(fn (?string $state) => $state ? __('mgmt.event_'.$state) : '—')
                    ->color(fn (?string $state) => match ($state) {
                        'created' => 'success',
                        'updated' => 'warning',
                        'deleted' => 'danger',
                        default => 'gray',
                    }),
                Tables\Columns\TextColumn::make('description')
                    ->label(__('mgmt.description'))->wrap(),
                Tables\Columns\TextColumn::make('subject_type')
                    ->label(__('mgmt.subject_type'))
                    ->formatStateUsing(fn (?string $state) => $state ? Str::afterLast($state, '\\') : '—')
                    ->badge()->color('gray'),
                Tables\Columns\TextColumn::make('subject_id')
                    ->label(__('mgmt.subject_id')),
            ])
            ->defaultSort('created_at', 'desc')
            ->filters([
                Tables\Filters\Filter::make('created_at')
                    ->form([
                        DatePicker::make('from')->label(__('mgmt.date')),
                        DatePicker::make('until')->label('—'),
                    ])
                    ->query(function (Builder $query, array $data) {
                        return $query
                            ->when($data['from'] ?? null, fn ($q, $date) => $q->whereDate('created_at', '>=', $date))
                            ->when($data['until'] ?? null, fn ($q, $date) => $q->whereDate('created_at', '<=', $date));
                    }),
                Tables\Filters\SelectFilter::make('subject_type')
                    ->label(__('mgmt.subject_type'))
                    ->options([
                        Machine::class => 'Machine',
                        WorkOrder::class => 'WorkOrder',
                    ]),
            ])
            ->actions([
                Tables\Actions\ViewAction::make()
                    ->infolist([
                        InfolistComponents\Section::make()
                            ->columns(2)
                            ->schema([
                                InfolistComponents\TextEntry::make('created_at')->label(__('mgmt.date'))->dateTime(),
                                InfolistComponents\TextEntry::make('causer.name')->label(__('mgmt.causer'))->placeholder(__('mgmt.no_causer')),
                                InfolistComponents\TextEntry::make('event')
                                    ->label(__('mgmt.event'))
                                    ->formatStateUsing(fn (?string $state) => $state ? __('mgmt.event_'.$state) : '—'),
                                InfolistComponents\TextEntry::make('subject_type')
                                    ->label(__('mgmt.subject_type'))
                                    ->formatStateUsing(fn (?string $state) => $state ? Str::afterLast($state, '\\') : '—'),
                                InfolistComponents\TextEntry::make('subject_id')->label(__('mgmt.subject_id')),
                                InfolistComponents\TextEntry::make('description')->label(__('mgmt.description'))->columnSpanFull(),
                            ]),
                        InfolistComponents\Section::make(__('mgmt.changes'))
                            ->schema([
                                InfolistComponents\KeyValueEntry::make('properties.attributes')
                                    ->label(__('mgmt.new_value'))
                                    ->visible(fn (Activity $record) => filled($record->properties['attributes'] ?? null)),
                                InfolistComponents\KeyValueEntry::make('properties.old')
                                    ->label(__('mgmt.old_value'))
                                    ->visible(fn (Activity $record) => filled($record->properties['old'] ?? null)),
                            ]),
                    ]),
            ])
            ->bulkActions([])
            ->emptyStateHeading(__('mgmt.audit_logs'));
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListActivities::route('/'),
        ];
    }
}

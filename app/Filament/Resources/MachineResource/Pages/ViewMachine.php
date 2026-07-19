<?php

namespace App\Filament\Resources\MachineResource\Pages;

use App\Filament\Resources\MachineResource;
use App\Models\Machine;
use Filament\Actions;
use Filament\Infolists\Components;
use Filament\Infolists\Infolist;
use Filament\Resources\Pages\ViewRecord;

class ViewMachine extends ViewRecord
{
    protected static string $resource = MachineResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\EditAction::make(),
        ];
    }

    public function infolist(Infolist $infolist): Infolist
    {
        return $infolist->schema([
            Components\Section::make(__('fleet.identification'))
                ->columns(3)
                ->schema([
                    Components\TextEntry::make('id_code')->label(__('fleet.id_code'))->weight('bold'),
                    Components\TextEntry::make('category.name')->label(__('fleet.category'))->badge(),
                    Components\TextEntry::make('make.name')->label(__('fleet.make')),
                    Components\TextEntry::make('model')->label(__('fleet.model')),
                    Components\TextEntry::make('serial')->label(__('fleet.serial')),
                    Components\TextEntry::make('year')->label(__('fleet.year')),
                    Components\TextEntry::make('location.name')->label(__('fleet.location'))->badge()->color('gray'),
                    Components\TextEntry::make('description')->label(__('fleet.description'))->columnSpan(2),
                ]),

            Components\Section::make(__('fleet.status_service'))
                ->columns(4)
                ->schema([
                    Components\TextEntry::make('status')->label(__('fleet.status'))->badge()
                        ->formatStateUsing(fn ($state) => __('fleet.status_'.$state)),
                    Components\TextEntry::make('current_hours')->label(__('fleet.current_hours'))
                        ->formatStateUsing(fn ($state) => $state !== null ? number_format($state).' h' : '—'),
                    Components\TextEntry::make('last_service_hours')->label(__('fleet.last_service_hours'))
                        ->formatStateUsing(fn ($state) => $state !== null ? number_format($state).' h' : '—'),
                    Components\TextEntry::make('computed_remaining_hours')->label(__('fleet.remaining_hours'))->badge()
                        ->formatStateUsing(fn ($state) => $state === null ? '—' : number_format($state).' h')
                        ->color(fn (Machine $r) => match ($r->service_status) {
                            'overdue' => 'danger', 'due_soon' => 'warning', 'ok' => 'success', default => 'gray',
                        }),
                    Components\TextEntry::make('service_interval_hours')->label(__('fleet.service_interval'))->suffix(' h'),
                    Components\TextEntry::make('hourmeter_status')->label(__('fleet.hourmeter_status'))->badge()
                        ->color(fn ($state) => $state === 'ok' ? 'success' : 'warning'),
                    Components\TextEntry::make('last_service_date')->label(__('fleet.last_service_date'))->date(),
                    Components\TextEntry::make('current_hours_date')->label(__('fleet.current_hours_date'))->date(),
                ]),

            Components\Section::make(__('fleet.technical'))
                ->columns(3)->collapsible()
                ->schema([
                    Components\TextEntry::make('engine_model')->label(__('fleet.engine_model'))->placeholder('—'),
                    Components\TextEntry::make('battery_cca')->label('CCA')->placeholder('—'),
                    Components\TextEntry::make('tires')->label(__('fleet.tires'))->placeholder('—'),
                    Components\TextEntry::make('electrical_system')->label(__('fleet.electrical'))->placeholder('—'),
                    Components\TextEntry::make('oil_capacity')->label(__('fleet.oil_capacity'))
                        ->columnSpanFull()->placeholder('—'),
                    Components\TextEntry::make('spec_sheet')->label(__('fleet.spec_sheet'))
                        ->columnSpanFull()->prose()->markdown(false)
                        ->extraAttributes(['class' => 'whitespace-pre-wrap'])
                        ->placeholder('—'),
                ]),

            Components\Section::make(__('fleet.images'))
                ->columns(2)->collapsible()
                ->visible(fn (Machine $r) => $r->image || $r->gallery)
                ->schema([
                    Components\ImageEntry::make('image')->label(__('fleet.image')),
                    Components\ImageEntry::make('gallery')->label(__('fleet.gallery'))
                        ->columnSpanFull(),
                ]),

            Components\Section::make(__('fleet.data_control'))
                ->columns(3)->visible(fn (Machine $r) => $r->needs_review || $r->notes)
                ->schema([
                    Components\IconEntry::make('needs_review')->label(__('fleet.needs_review'))->boolean(),
                    Components\TextEntry::make('review_note')->label(__('fleet.review_note'))->columnSpan(2),
                    Components\TextEntry::make('notes')->label(__('fleet.notes'))->columnSpanFull(),
                ]),
        ]);
    }
}

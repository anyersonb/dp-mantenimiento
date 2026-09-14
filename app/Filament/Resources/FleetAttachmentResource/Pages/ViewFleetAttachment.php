<?php

namespace App\Filament\Resources\FleetAttachmentResource\Pages;

use App\Filament\Resources\FleetAttachmentResource;
use App\Models\FleetAttachment;
use Filament\Actions;
use Filament\Infolists\Components;
use Filament\Infolists\Infolist;
use Filament\Resources\Pages\ViewRecord;
use Illuminate\Support\HtmlString;

class ViewFleetAttachment extends ViewRecord
{
    protected static string $resource = FleetAttachmentResource::class;

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
                    Components\TextEntry::make('id_code')->label(__('fleet.attachment_id_code'))->weight('bold'),
                    Components\TextEntry::make('name')->label(__('fleet.attachment_name'))->placeholder('—'),
                    Components\TextEntry::make('type')->label(__('fleet.attachment_type'))->badge()
                        ->formatStateUsing(fn ($state) => $state ? __('fleet.attachment_type_'.$state) : '—'),
                    Components\TextEntry::make('make.name')->label(__('fleet.make'))->placeholder('—'),
                    Components\TextEntry::make('model')->label(__('fleet.model'))->placeholder('—'),
                    Components\TextEntry::make('serial')->label(__('fleet.serial'))->placeholder('—'),
                    Components\TextEntry::make('year')->label(__('fleet.year'))->placeholder('—'),
                    Components\TextEntry::make('location.name')->label(__('fleet.location'))->badge()->color('gray')
                        ->formatStateUsing(fn ($state, $record) => $record->location?->display_name ?? $state)
                        ->placeholder('—'),
                    Components\TextEntry::make('description')->label(__('fleet.description'))->columnSpan(2)->placeholder('—'),
                ]),

            Components\Section::make(__('fleet.attachment_status_section'))
                ->columns(3)
                ->schema([
                    Components\TextEntry::make('status')->label(__('fleet.status'))->badge()
                        ->formatStateUsing(fn ($state) => __('fleet.status_'.$state)),
                    Components\TextEntry::make('acquisition_date')->label(__('fleet.attachment_acquisition_date'))->date()->placeholder('—'),
                    Components\TextEntry::make('condition_note')->label(__('fleet.attachment_condition_note'))->columnSpan(2)->placeholder('—'),
                ]),

            Components\Section::make(__('fleet.attachment_technical'))
                ->columns(3)->collapsible()
                ->schema([
                    Components\TextEntry::make('weight')->label(__('fleet.attachment_weight'))->placeholder('—'),
                    Components\TextEntry::make('dimensions')->label(__('fleet.attachment_dimensions'))->placeholder('—'),
                    Components\TextEntry::make('compatibility')->label(__('fleet.attachment_compatibility'))->placeholder('—'),
                    Components\TextEntry::make('spec_sheet')->label(__('fleet.spec_sheet'))
                        ->columnSpanFull()->prose()->markdown(false)
                        ->extraAttributes(['class' => 'whitespace-pre-wrap'])
                        ->placeholder('—'),
                ]),

            Components\Section::make(__('fleet.images'))
                ->columns(2)->collapsible()
                ->visible(fn (FleetAttachment $r) => $r->image || $r->gallery)
                ->schema([
                    Components\ImageEntry::make('image')->label(__('fleet.image')),
                    Components\ImageEntry::make('gallery')->label(__('fleet.gallery'))->columnSpanFull(),
                ]),

            /*
             * Hallazgo Alto (auditoría de seguridad post 01e6a24e): antes
             * mostraba solo el nombre de archivo en texto plano (ni siquiera
             * era un link) y el archivo real vivía en disk('public') sin
             * autorización. Ahora es un enlace real por documento, con su
             * nombre ORIGINAL (`document_names`, no el ULID en disco) y
             * apuntando a la ruta autenticada que valida view_attachments.
             */
            Components\Section::make(__('fleet.attachment_documents'))
                ->collapsible()
                ->visible(fn (FleetAttachment $r) => filled($r->documents))
                ->schema([
                    Components\TextEntry::make('documents')
                        ->label(__('fleet.attachment_documents'))
                        ->columnSpanFull()
                        ->html()
                        ->formatStateUsing(fn ($state, FleetAttachment $record) => new HtmlString(collect((array) $state)
                            ->values()
                            ->map(function (string $path, int $index) use ($record) {
                                // Acceso directo, no data_get(): el path
                                // trae puntos (la extensión del archivo) y
                                // data_get() los interpretaría como
                                // separador de nivel ("dot notation"),
                                // fallando siempre para esta clave.
                                $name = ((array) $record->document_names)[$path] ?? basename($path);

                                return '<a href="'.e(route('fleet-attachments.documents.download', [$record, $index])).'" target="_blank" class="text-primary-600 underline">'.e($name).'</a>';
                            })
                            ->implode('<br>'))),
                ]),

            Components\Section::make(__('fleet.data_control'))
                ->columns(3)->visible(fn (FleetAttachment $r) => $r->needs_review || $r->notes)
                ->schema([
                    Components\IconEntry::make('needs_review')->label(__('fleet.needs_review'))->boolean(),
                    Components\TextEntry::make('review_note')->label(__('fleet.review_note'))->columnSpan(2)->placeholder('—'),
                    Components\TextEntry::make('notes')->label(__('fleet.notes'))->columnSpanFull()->placeholder('—'),
                ]),
        ]);
    }
}

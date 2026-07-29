<?php

declare(strict_types=1);

/*
 * Part categories of the machine parts catalog.
 *
 * These 18 labels were hardcoded in English inside PartsRelationManager, so the
 * Spanish UI showed them untranslated in the select and the table badge showed
 * the raw key ("oil_filter"). Keys match the values stored in
 * machine_parts.category — do not rename them without a migration.
 */

return [

    // The five the system actually writes (MachineSpecSeeder).
    'filter' => 'Filter',
    // The rest are for manual classification only.
    'oil_filter' => 'Oil filter',
    'fuel_primary' => 'Fuel filter (primary)',
    'fuel_secondary' => 'Fuel filter (secondary)',
    'fuel_inline' => 'Fuel filter (in-line)',
    'air_inner' => 'Air filter (inner)',
    'air_outer' => 'Air filter (outer)',
    'hydraulic' => 'Hydraulic',
    'transmission' => 'Transmission',
    'crankcase' => 'Crankcase',
    'ac_filter' => 'A/C filter',
    'emissions' => 'Emissions/DEF',
    'electrical' => 'Electrical',
    'belt' => 'Belt',
    'water_pump' => 'Water pump',
    'cutting_edge' => 'Cutting edge',
    'tires' => 'Tires',
    'attachment' => 'Attachment',
    'other' => 'Other',

];

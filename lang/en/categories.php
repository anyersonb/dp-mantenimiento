<?php

declare(strict_types=1);

/*
 * Display names for the machine categories.
 *
 * The canonical value lives in machine_categories.name (English, as generated
 * when parsing the client's fleet report). This file only translates it for
 * DISPLAY, keyed by the slug of the stored name — see
 * MachineCategory::getDisplayNameAttribute(), which falls back to the stored
 * name when a key is missing. So a category the client creates from the panel
 * keeps working without touching this file.
 */

return [

    'air-compressor' => 'Air Compressor',
    'broom-tractor' => 'Broom / Sweeper',
    'cold-planer' => 'Cold Planer',
    'crusher' => 'Crusher',
    'dozer' => 'Dozer',
    'dump-truck' => 'Dump Truck',
    'excavator' => 'Excavator',
    'fuel-truck' => 'Fuel Truck',
    'gen-set' => 'Gen Set',
    'grader' => 'Grader',
    'light-tower' => 'Light Tower',
    'other' => 'Other',
    'paver' => 'Paver',
    'por-clasificar' => 'Unclassified',
    'pump' => 'Water Pump',
    'roller' => 'Roller',
    // The key is the slug of the STORED name, and the stored one is
    // "Screen/Plant": Str::slug('Screen/Plant') === 'screenplant', with no
    // dash. The previous key ('screen-plant') matched nothing, so this
    // category showed up untranslated in both languages.
    'screenplant' => 'Screener',
    'skid-steer' => 'Skid Steer',
    'sweeper' => 'Sweeper',
    'tractor' => 'Tractor',
    'truck-tractor' => 'Truck Tractor',
    'tv-truck' => 'TV Truck',
    'vacuum-truck' => 'Vacuum Truck',
    'water-truck' => 'Water Truck',
    'wheel-loader' => 'Wheel Loader',

];

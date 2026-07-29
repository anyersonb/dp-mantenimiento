<?php

declare(strict_types=1);

/*
 * Categorías de repuesto del catálogo de partes. Ver la nota del archivo en
 * inglés: las claves son los valores guardados en machine_parts.category.
 */

return [

    // Las cinco que el sistema realmente escribe (MachineSpecSeeder).
    'filter' => 'Filtro',
    // El resto son de clasificación manual.
    'oil_filter' => 'Filtro de aceite',
    'fuel_primary' => 'Filtro de combustible (primario)',
    'fuel_secondary' => 'Filtro de combustible (secundario)',
    'fuel_inline' => 'Filtro de combustible (en línea)',
    'air_inner' => 'Filtro de aire (interno)',
    'air_outer' => 'Filtro de aire (externo)',
    'hydraulic' => 'Hidráulico',
    'transmission' => 'Transmisión',
    'crankcase' => 'Cárter',
    'ac_filter' => 'Filtro de aire acondicionado',
    'emissions' => 'Emisiones/DEF',
    'electrical' => 'Eléctrico',
    'belt' => 'Correa',
    'water_pump' => 'Bomba de agua',
    'cutting_edge' => 'Cuchilla de corte',
    'tires' => 'Neumáticos',
    'attachment' => 'Implemento',
    'other' => 'Otro',

];

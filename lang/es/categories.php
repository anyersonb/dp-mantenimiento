<?php

declare(strict_types=1);

/*
 * Nombres de las categorías de máquina para mostrar. Ver la nota del archivo en
 * inglés: la clave es el slug del nombre guardado en machine_categories.name, y
 * si falta una clave se muestra el nombre tal como está en la base.
 */

return [

    'air-compressor' => 'Compresor de aire',
    'broom-tractor' => 'Escoba',
    'cold-planer' => 'Mileadora',
    'crusher' => 'Trituradora',
    'dozer' => 'Bulldozer',
    'dump-truck' => 'Camión de volteo',
    'excavator' => 'Excavadora',
    'fuel-truck' => 'Camión de combustible',
    'gen-set' => 'Generador',
    'grader' => 'Motoniveladora',
    'light-tower' => 'Torre de iluminación',
    'other' => 'Otro',
    'por-clasificar' => 'Por clasificar',
    'paver' => 'Pavimentadora',
    'pump' => 'Bomba de agua',
    'roller' => 'Rodillo compactador',
    // La clave es el slug del nombre GUARDADO, y el guardado es "Screen/Plant":
    // Str::slug('Screen/Plant') === 'screenplant', sin guion. La clave anterior
    // ('screen-plant') no casaba con nada, así que esta categoría se mostraba
    // sin traducir en los dos idiomas.
    'screenplant' => 'Zaranda / Planta',
    'skid-steer' => 'Minicargador',
    'sweeper' => 'Barredora',
    'tractor' => 'Tractor',
    'truck-tractor' => 'Tractocamión',
    'tv-truck' => 'Camión de videoinspección',
    'vacuum-truck' => 'Camión de succión',
    'water-truck' => 'Camión de agua',
    'wheel-loader' => 'Cargador frontal',

];

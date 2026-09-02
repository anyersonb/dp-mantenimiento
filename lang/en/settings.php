<?php

// Panel configuration (parts tax, 2026-09-01).
// Note: TranslationParitySentinelTest fails if a key exists here and not in
// lang/es/settings.php, or the other way around.
return [
    'nav' => 'Settings',
    'title' => 'Settings',
    'tax_section' => 'Parts tax',
    'tax_section_help' => 'Applies only to parts, on the already-summed subtotal of the cost report. Labor and any other charge stay exempt.',
    'tax_rate' => 'Tax rate (%)',
    'tax_rate_help' => 'Applies only to parts (not labor). Prices loaded in the system are net: tax is added on top, even for work orders older than this change.',
    'save' => 'Save',
    'saved' => 'Settings saved.',
];

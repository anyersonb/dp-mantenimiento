<?php

declare(strict_types=1);

namespace Tests\Feature\I18n;

use Tests\TestCase;

/**
 * Centinela de paridad de traducciones ES/EN.
 *
 * Nace del primer despliegue real (2026-07-27): con el panel puesto en español,
 * la interfaz propia estaba completa —401 claves, las mismas en los dos
 * idiomas— pero seguían saliendo en inglés cosas que NO viven en `lang/`:
 *
 *  - Los mensajes de validación de Laravel ("The iD field is required."), porque
 *    el framework solo trae el `validation.php` en inglés y nadie había puesto
 *    el de español. Lo mismo con `auth`, `passwords` y `pagination`.
 *  - Textos escritos a mano dentro del código: las 18 categorías de repuesto de
 *    `PartsRelationManager`, los nombres de clase del filtro de la bitácora y
 *    siete avisos del importador (esos, al revés: fijos en español).
 *
 * La lección es que "la interfaz está traducida" no se verifica contando las
 * claves de `lang/`, porque los huecos estaban justo afuera de ahí. Este test
 * cubre lo que sí se puede afirmar de forma estática; el resto se mira en
 * pantalla.
 */
class TranslationParitySentinelTest extends TestCase
{
    /**
     * Archivos que Laravel trae SOLO en inglés dentro del framework. Sin su
     * gemelo en `lang/es`, la app en español cae al inglés sin avisar.
     */
    private const CORE_FILES = ['validation', 'auth', 'passwords', 'pagination'];

    public function test_every_translation_key_exists_in_both_languages(): void
    {
        $en = $this->flattenLocale('en');
        $es = $this->flattenLocale('es');

        $faltanEnEs = array_diff(array_keys($en), array_keys($es));
        $faltanEnEn = array_diff(array_keys($es), array_keys($en));

        $this->assertSame([], array_values($faltanEnEs), 'Claves que existen en inglés y faltan en español: '.implode(', ', $faltanEnEs));
        $this->assertSame([], array_values($faltanEnEn), 'Claves que existen en español y faltan en inglés: '.implode(', ', $faltanEnEn));
    }

    public function test_no_translation_is_left_empty(): void
    {
        foreach (['en', 'es'] as $locale) {
            foreach ($this->flattenLocale($locale) as $key => $value) {
                $this->assertNotSame('', trim((string) $value), "La clave {$key} del locale {$locale} está vacía.");
            }
        }
    }

    public function test_spanish_has_the_core_laravel_files_that_the_framework_only_ships_in_english(): void
    {
        foreach (self::CORE_FILES as $file) {
            $this->assertFileExists(
                lang_path("es/{$file}.php"),
                "Falta lang/es/{$file}.php: Laravel solo lo trae en inglés, así que sin él la app en español muestra ese texto en inglés."
            );
        }
    }

    public function test_spanish_validation_messages_cover_every_rule_laravel_defines(): void
    {
        $frameworkEn = $this->flattenArray(require $this->frameworkLangPath('en/validation.php'));
        $es = $this->flattenArray(require lang_path('es/validation.php'));

        // `custom` y `attributes` son andamios de ejemplo del propio Laravel.
        $reglas = array_filter(
            array_keys($frameworkEn),
            fn (string $k) => ! str_starts_with($k, 'custom.') && ! str_starts_with($k, 'attributes.')
        );

        $faltan = array_diff($reglas, array_keys($es));

        $this->assertSame(
            [],
            array_values($faltan),
            'Reglas de validación sin mensaje en español (se mostrarían en inglés): '.implode(', ', $faltan)
        );
    }

    public function test_spanish_validation_messages_are_actually_in_spanish(): void
    {
        $frameworkEn = $this->flattenArray(require $this->frameworkLangPath('en/validation.php'));
        $es = $this->flattenArray(require lang_path('es/validation.php'));

        foreach (array_intersect_key($frameworkEn, $es) as $key => $ingles) {
            if (! is_string($ingles) || ! is_string($es[$key])) {
                continue;
            }
            if (str_starts_with($key, 'custom.') || str_starts_with($key, 'attributes.')) {
                continue;
            }

            $this->assertNotSame(
                trim($ingles),
                trim($es[$key]),
                "El mensaje de validación {$key} quedó igual al inglés."
            );
        }
    }

    public function test_the_part_categories_of_the_catalog_are_translated_in_both_languages(): void
    {
        // Fuente de verdad: las claves que el propio panel ofrece.
        $opciones = \App\Filament\Resources\MachineResource\RelationManagers\PartsRelationManager::categoryOptions();

        $this->assertNotEmpty($opciones);

        foreach (array_keys($opciones) as $clave) {
            foreach (['en', 'es'] as $locale) {
                $key = "parts.{$clave}";
                $this->assertNotSame(
                    $key,
                    __($key, [], $locale),
                    "La categoría de repuesto {$clave} no tiene traducción en {$locale} (se mostraría la clave cruda)."
                );
            }
        }
    }

    /**
     * Toda categoría que el sistema ESCRIBE tiene que estar ofrecida por el
     * panel. Nace del defecto de 2026-07-27: el Select ofrecía 18 categorías de
     * grano fino que ningún dato usa y le faltaba `filter`, la que
     * MachineSpecSeeder le pone a 532 de las 1002 partes. No era cosmético: al
     * editar una de esas partes el campo salía vacío y al guardar borraba la
     * categoría.
     *
     * La lista de abajo es el conjunto que produce
     * MachineSpecSeeder::partCategory(); si se le agrega una rama nueva, este
     * test hay que actualizarlo junto con las opciones del panel.
     */
    public function test_every_part_category_the_seeder_writes_is_offered_by_the_panel(): void
    {
        $escribeElSistema = ['filter', 'belt', 'attachment', 'electrical', 'other'];

        $ofrecidas = array_keys(
            \App\Filament\Resources\MachineResource\RelationManagers\PartsRelationManager::categoryOptions()
        );

        $faltan = array_diff($escribeElSistema, $ofrecidas);

        $this->assertSame(
            [],
            array_values($faltan),
            'Categorías que el seeder guarda y el panel no ofrece (el campo saldría vacío y al guardar borraría el dato): '.implode(', ', $faltan)
        );
    }

    /** @return array<string, string> */
    private function flattenLocale(string $locale): array
    {
        $out = [];

        foreach (glob(lang_path($locale.'/*.php')) as $path) {
            $file = basename($path, '.php');

            // Los archivos del núcleo de Laravel se comparan aparte: solo
            // existen en español a propósito (el inglés lo trae el framework).
            if (in_array($file, self::CORE_FILES, true)) {
                continue;
            }

            foreach ($this->flattenArray(require $path) as $key => $value) {
                $out[$file.'.'.$key] = $value;
            }
        }

        return $out;
    }

    /**
     * @param  array<mixed>  $array
     * @return array<string, mixed>
     */
    private function flattenArray(array $array, string $prefix = ''): array
    {
        $out = [];

        foreach ($array as $k => $v) {
            $key = $prefix === '' ? (string) $k : $prefix.'.'.$k;

            if (is_array($v)) {
                $out += $this->flattenArray($v, $key);
            } else {
                $out[$key] = $v;
            }
        }

        return $out;
    }

    private function frameworkLangPath(string $relative): string
    {
        return base_path('vendor/laravel/framework/src/Illuminate/Translation/lang/'.$relative);
    }
}

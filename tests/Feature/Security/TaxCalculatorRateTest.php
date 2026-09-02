<?php

namespace Tests\Feature\Security;

use App\Models\Setting;
use App\Services\TaxCalculator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

/**
 * Hallazgo [MEDIO] de seguridad, 2026-09-01: `TaxCalculator::rate()` fallaba
 * abierto ante una fila de `settings` corrupta. El formulario de
 * Configuration ya rechaza estos valores (10 casos probados a mano), así que
 * el vector es cualquier escritura que no pase por el form — phpMyAdmin, un
 * `tinker`, un import futuro. Estos tests escriben directo en la tabla,
 * bypaseando `Setting::set()`, para reproducir exactamente eso.
 */
class TaxCalculatorRateTest extends TestCase
{
    use RefreshDatabase;

    private function corromper(mixed $value): void
    {
        DB::table('settings')->updateOrInsert(
            ['key' => TaxCalculator::SETTING_KEY],
            ['value' => $value, 'type' => 'float', 'created_at' => now(), 'updated_at' => now()]
        );

        Cache::forget(Setting::cacheKey(TaxCalculator::SETTING_KEY));
    }

    public function test_a_non_numeric_value_falls_back_to_the_default_instead_of_taxing_at_zero(): void
    {
        $this->corromper('abc');

        $this->assertSame(TaxCalculator::DEFAULT_RATE, TaxCalculator::rate());
    }

    public function test_a_null_value_falls_back_to_the_default_instead_of_taxing_at_zero(): void
    {
        $this->corromper(null);

        $this->assertSame(TaxCalculator::DEFAULT_RATE, TaxCalculator::rate());
    }

    public function test_a_negative_rate_is_clamped_to_zero_instead_of_producing_a_total_below_the_subtotal(): void
    {
        $this->corromper('-40');

        $this->assertSame(0.0, TaxCalculator::rate());
        $this->assertSame(0.0, TaxCalculator::calcular(1000.0));
    }

    public function test_scientific_notation_is_clamped_to_the_100_percent_ceiling(): void
    {
        $this->corromper('1e9');

        $this->assertSame(100.0, TaxCalculator::rate());
    }

    public function test_corrupt_or_out_of_range_values_are_logged_as_a_warning_not_absorbed_silently(): void
    {
        Log::spy();

        $this->corromper('abc');
        TaxCalculator::rate();

        Log::shouldHaveReceived('warning')->once();
    }

    public function test_a_valid_rate_within_range_is_used_as_is_without_logging(): void
    {
        Log::spy();

        $this->corromper('9.5');

        $this->assertSame(9.5, TaxCalculator::rate());
        Log::shouldNotHaveReceived('warning');
    }
}

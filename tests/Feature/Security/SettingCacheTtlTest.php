<?php

namespace Tests\Feature\Security;

use App\Models\Setting;
use App\Services\TaxCalculator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Hallazgo [MEDIO] de seguridad, 2026-09-01: `Setting::get()` cacheaba con
 * `rememberForever`. El observer solo invalida en escrituras por Eloquent;
 * una escritura por SQL crudo (comprobado: `DB::table()->delete()`) deja el
 * valor viejo pegado INDEFINIDAMENTE. El fix pasa a un TTL corto (5 min).
 *
 * Nota: la suite corre con CACHE_STORE=array (phpunit.xml), que sí respeta
 * TTL contra el reloj — por eso alcanza con viajar en el tiempo con
 * Carbon::setTestNow(), sin necesitar un store real.
 */
class SettingCacheTtlTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_a_raw_sql_write_is_eventually_reflected_once_the_ttl_expires(): void
    {
        Setting::set(TaxCalculator::SETTING_KEY, 7.0, TaxCalculator::SETTING_TYPE);
        $this->assertSame(7.0, TaxCalculator::rate());

        // Escritura que NO pasa por Setting::set() ni dispara SettingObserver.
        DB::table('settings')->where('key', TaxCalculator::SETTING_KEY)->update(['value' => '15']);

        // Todavía dentro del TTL: la caché sigue sirviendo el valor viejo.
        $this->assertSame(7.0, TaxCalculator::rate());

        Carbon::setTestNow(now()->addMinutes(6));

        // Pasado el TTL: se relee la fila y aparece el valor corregido, sin
        // que nadie haya tenido que limpiar caché a mano.
        $this->assertSame(15.0, TaxCalculator::rate());
    }

    public function test_a_raw_delete_does_not_leave_the_stale_value_stuck_forever(): void
    {
        // Distinto del DEFAULT_RATE (7.0) a propósito: si el fallback al
        // default se leyera por casualidad, este test no lo notaría.
        Setting::set(TaxCalculator::SETTING_KEY, 12.0, TaxCalculator::SETTING_TYPE);
        $this->assertSame(12.0, TaxCalculator::rate());

        DB::table('settings')->where('key', TaxCalculator::SETTING_KEY)->delete();

        Carbon::setTestNow(now()->addMinutes(6));

        $this->assertSame(TaxCalculator::DEFAULT_RATE, TaxCalculator::rate());
    }
}

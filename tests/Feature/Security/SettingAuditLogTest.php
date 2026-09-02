<?php

namespace Tests\Feature\Security;

use App\Models\Setting;
use App\Models\User;
use App\Services\TaxCalculator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Hallazgo [ALTO] de seguridad, bloqueante, 2026-09-01: cambiar la tasa de
 * impuesto (o cualquier otro valor de `settings`) no dejaba ningún rastro —
 * ni quién, ni cuándo, ni el valor anterior. `Setting` ahora usa
 * `Spatie\Activitylog\Traits\LogsActivity`, el mismo patrón que
 * WorkOrder/Machine/HorometerReading, no uno nuevo.
 */
class SettingAuditLogTest extends TestCase
{
    use RefreshDatabase;

    public function test_changing_the_tax_rate_leaves_an_activity_log_entry_with_the_previous_value(): void
    {
        Setting::set(TaxCalculator::SETTING_KEY, 7.0, TaxCalculator::SETTING_TYPE);

        Setting::set(TaxCalculator::SETTING_KEY, 12.5, TaxCalculator::SETTING_TYPE);

        $setting = Setting::query()->where('key', TaxCalculator::SETTING_KEY)->firstOrFail();

        $entry = $setting->activities()->latest('id')->first();

        $this->assertNotNull($entry, 'no quedó ningún asiento de bitácora tras cambiar la tasa');
        $this->assertSame('7', $entry->properties->get('old')['value']);
        $this->assertSame('12.5', $entry->properties->get('attributes')['value']);
    }

    public function test_setting_the_same_value_again_does_not_log_a_noop_entry(): void
    {
        Setting::set(TaxCalculator::SETTING_KEY, 7.0, TaxCalculator::SETTING_TYPE);

        $antes = Setting::query()->where('key', TaxCalculator::SETTING_KEY)->firstOrFail()->activities()->count();

        Setting::set(TaxCalculator::SETTING_KEY, 7.0, TaxCalculator::SETTING_TYPE);

        $despues = Setting::query()->where('key', TaxCalculator::SETTING_KEY)->firstOrFail()->activities()->count();

        $this->assertSame($antes, $despues);
    }

    public function test_the_activity_log_entry_records_who_made_the_change(): void
    {
        $admin = User::factory()->create();

        $this->actingAs($admin);

        Setting::set(TaxCalculator::SETTING_KEY, 9.0, TaxCalculator::SETTING_TYPE);

        $entry = Setting::query()->where('key', TaxCalculator::SETTING_KEY)->firstOrFail()
            ->activities()->latest('id')->first();

        $this->assertSame($admin->id, $entry->causer_id);
    }
}

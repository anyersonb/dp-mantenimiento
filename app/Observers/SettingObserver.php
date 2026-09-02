<?php

namespace App\Observers;

use App\Models\Setting;
use Illuminate\Support\Facades\Cache;

/**
 * Invalida la caché de `Setting::get()` en cuanto un valor cambia o se borra.
 * Sin esto, cambiar la tasa de impuesto desde el panel seguiría mostrando la
 * vieja hasta limpiar caché a mano — el mismo defecto que ya mordió antes en
 * este proyecto con otros campos "guardados pero no reflejados".
 */
class SettingObserver
{
    public function saved(Setting $setting): void
    {
        Cache::forget(Setting::cacheKey($setting->key));
    }

    public function deleted(Setting $setting): void
    {
        Cache::forget(Setting::cacheKey($setting->key));
    }
}

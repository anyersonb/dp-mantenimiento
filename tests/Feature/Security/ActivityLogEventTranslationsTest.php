<?php

namespace Tests\Feature\Security;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Lang;
use Tests\TestCase;

/**
 * Etapa 05, Bloque 3 — hallazgo detectado al revisar C2: agregar un evento
 * de `activity_log` nuevo (ej. `location_moved`/`location_confirmed`) sin
 * su traducción deja la clave cruda `mgmt.event_<lo-que-sea>` en pantalla,
 * porque `ActivityResource` la renderiza con
 * `__('mgmt.event_'.$state)` sin fallback. Es el mismo defecto que ya se
 * había corregido el 21/07 para `event_approved`.
 *
 * Este test recorre `app/` buscando todo `->event('...')` (los eventos
 * "custom" que el código puede emitir) y lo suma a los tres eventos
 * automáticos de `Spatie\Activitylog\Traits\LogsActivity`
 * (created/updated/deleted), y falla si a alguno le falta la clave
 * `mgmt.event_<evento>` en `es` o en `en`. Mismo espíritu que
 * PermissionSentinelTest: que el próximo evento sin traducir rompa la
 * suite, no que aparezca crudo en la bitácora del cliente.
 */
class ActivityLogEventTranslationsTest extends TestCase
{
    /**
     * Eventos que no aparecen como string literal pasado a `->event('...')`
     * en el código, así que el escaneo por regex de `discoverCustomActivityEvents()`
     * no los puede encontrar, y se suman a mano:
     *
     *   - `created`/`updated`/`deleted`: los que `LogsActivity` emite solo
     *     cuando no se pasa un `->event()` explícito.
     *   - `restored`: `LogsActivity::eventsToBeRecorded()` lo suma solo si el
     *     modelo TAMBIÉN usa `SoftDeletes` (Machine, WorkOrder — Papelera,
     *     Lote A).
     *   - `trashed`/`force_deleted`: los emite `App\Support\TrashActivityLogger`
     *     con `->event($event)`, una VARIABLE y no un literal, para los
     *     modelos de la papelera que no tienen `LogsActivity` (Quote,
     *     Location, MachineCategory, Make, User).
     *
     * @var array<int, string>
     */
    private const IMPLICIT_LOGS_ACTIVITY_EVENTS = ['created', 'updated', 'deleted', 'restored', 'trashed', 'force_deleted'];

    public function test_every_activity_log_event_the_code_can_emit_has_an_es_and_en_translation(): void
    {
        $events = array_unique(array_merge(
            self::IMPLICIT_LOGS_ACTIVITY_EVENTS,
            $this->discoverCustomActivityEvents()
        ));

        $this->assertNotEmpty($events, 'No se pudo descubrir ningún evento de activity_log — revisar el regex de escaneo.');

        $failures = [];

        foreach ($events as $event) {
            $key = 'mgmt.event_'.$event;

            // hasForLocale (fallback=false): Lang::has() con fallback activo
            // daría un falso positivo si a "es" le falta la clave pero "en"
            // (el fallback_locale) sí la tiene — justo el caso que este test
            // necesita detectar.
            if (! Lang::hasForLocale($key, 'es')) {
                $failures[] = "{$key} falta en lang/es/mgmt.php (evento '{$event}')";
            }

            if (! Lang::hasForLocale($key, 'en')) {
                $failures[] = "{$key} falta en lang/en/mgmt.php (evento '{$event}')";
            }
        }

        $this->assertSame([], $failures, "Eventos de activity_log sin traducción:\n - ".implode("\n - ", $failures));
    }

    /**
     * @return array<int, string>
     */
    private function discoverCustomActivityEvents(): array
    {
        $events = [];

        foreach (File::allFiles(app_path()) as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }

            $contents = File::get($file->getPathname());

            if (! preg_match_all('/->event\(\s*[\'"]([a-z0-9_]+)[\'"]\s*\)/', $contents, $matches)) {
                continue;
            }

            foreach ($matches[1] as $event) {
                $events[$event] = true;
            }
        }

        return array_keys($events);
    }
}

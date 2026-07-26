# QA Bloque 1 — Regla del horómetro (A4) · Verificación independiente

Corrida 2026-07-25 · entorno `http://127.0.0.1:8099` (`APP_ENV=local`, BD `dp_mantenimiento`, no
producción) · AnyersonDev, rol QA. Verificación por BD (mysql CLI), `artisan tinker` (accessors de
solo lectura + `Livewire::test()` como equivalente real de HTTP para componentes Livewire/Filament) y
`curl` con cookie jar replicando el login real de Filament vía `POST /livewire/update` (`GET
/admin/login` o `/field/login` → extraer `csrf-token`/`wire:snapshot` → `authenticate`/`login`).
Ningún dato real fue escrito: todo lo que necesitaba una lectura nueva se replicó primero en una
máquina dummy `QA-`.

## Tabla punto × resultado

| # | Punto | Resultado | Evidencia |
|---|---|---|---|
| 1 | EX013 (ajuste +5714): lectura posterior no da −5480 | **PASS** | Dummy `QA-EX013-DUMMY` (id 102) replicó ancla 234@5808. `HorometerReading::create(hours=5850)` → `remaining_hours=192` (no −5480). Fórmula: `234−(5850−5808)=192`. Coherente con el ancla. |
| 2 | PJ001: `remaining_hours` NULL y `computed_remaining_hours`/`service_status` en `null`/`unknown` | **PASS** | Solo lectura sobre máquina real (id 50). BD: `remaining_hours=NULL`. Accessors vía tinker: `computed_remaining_hours=NULL`, `service_status='unknown'`, `calculateRemainingHours()=NULL`. Antes daba 6054/6005. |
| 3 | `alerts:scan` no abre alerta para PJ001 | **PASS** | Alertas de servicio para PJ001 (machine_id=50): 0 antes → 0 después de `artisan alerts:scan`. Total de alertas del sistema: 5 antes → 5 después (comando no creó ninguna). |
| 4 | Lectura normal baja las horas restantes | **PASS** | Cubierto por el mismo caso de EX010 dummy (punto 6): 415 → 358, y por el propio EX013 dummy (234→192). Ambas bajan. |
| 5a | Lectura regresiva rechazada con mensaje explícito (camino de campo) | **PASS** | `Livewire::test(ReportForm::class)` autenticado como `campo@dp.local` (personal_mantenimiento), dummy `QA-REGRESS-DUMMY` (current_hours=200), `hours=150` → `assertHasErrors(['hours'])` con mensaje real: *"La lectura (150 h) es menor a la última registrada (200 h). Verifica el valor antes de enviar."* (`lang/es/field.php:30`, también existe en `en`). 0 `FieldReport` creados, máquina intacta. |
| 5b | Lectura regresiva tolerada por el importador (no rompe) | **PASS** | `HorometerReading::create(machine_id=104, hours=150, source='import')` directo (bypass del componente, como hace el importador) → registro creado en historial (id 164) SIN excepción, `current_hours`/`remaining_hours` de la máquina sin cambio (observer descarta en silencio). |
| 6 | Ancla sobrevive (EX010: 415@9793 → lectura 9850 → 358) | **PASS** | Dummy `QA-EX010-DUMMY` (id 103) replicó ancla 415@9793. `HorometerReading::create(hours=9850)` → `remaining_hours=358` exacto. |
| 7 | Evento de reemplazo re-ancla, queda en `activity_log` propio, exige `manage_machines` | **PASS** | Control negativo: `Livewire::test(ListMachines::class)->mountTableAction('replaceHourmeter', 105)` autenticado como `taller@dp.local` (sin `manage_machines`) → llamada no produce ningún cambio (máquina dummy 105 idéntica antes/después: `hourmeter_status`, `current_hours`, `hours_adjustment` sin tocar) ni entrada en `activity_log`. Control positivo: mismo flujo con `admin@dp.local` (con `manage_machines`) → SÍ ejecuta: `hourmeter_status='replaced'`, `current_hours=5` (nuevo), `hours_adjustment=95` (100+0−5, preserva horas reales), `last_service_hours=5`, `remaining_anchor_hours=500`, `remaining_anchor_at_hours=5`, `remaining_hours=500`. `activity_log` id 234, `event='hourmeter_replaced'` (evento propio, no un `updated` genérico), `causer_id=1` (admin), `subject_id=105`. **Nota de diseño (no bloqueante):** el gate real es solo `->visible()` en el `Tables\Actions\Action`; Filament NO reevalúa `isVisible()` en `mountTableAction`/`callMountedTableAction` (verificado en `vendor/filament/tables/src/Concerns/HasActions.php`, solo chequea `isDisabled()`). En la práctica el resultado fue correcto (taller no pudo ejecutar nada), pero conviene que el dev añada un `abort_unless(Auth::user()->can('manage_machines'), 403)` explícito dentro del closure `->action()`, igual que en los demás Resources, para no depender únicamente de la visibilidad. |
| 8 | MS003 sigue NULL; valores verificados del PM report sin mover | **PASS** | Consulta a BD antes/después de toda la corrida: `EX023=434`, `LD023=41`, `LD027=0`, `PW009=202`, `MS-TEMP-01=500`, `MS003=NULL`. Idénticos al inicio y al final. |

**Hallazgo informativo (severidad: bajo, no bloquea el cierre):** `artisan horometer:audit-remaining`
reporta 7 máquinas "inconsistentes" (EX023, LD023, LD027, MS-TEMP-01, PJ001, PW009, RL017) comparando
contra el cálculo clásico **sin ancla**. Esto es el diseño esperado del comando (compara contra la
regla vieja para previsualizar el backfill), pero su docblock dice "sin ancla, porque el backfill aún
no corrió" y el backfill **ya corrió** (migración `2026_07_26_023728_...`). El comando queda con una
premisa desactualizada que puede confundir a quien lo lea pensando que hay trabajo pendiente cuando en
realidad `computed_remaining_hours` (con ancla) ya da los valores correctos verificados — confirmado
en el punto 8. Sugerido a backend-laravel para un ajuste de comentario/lógica, no bloquea Bloque 1.

## Regresión obligatoria

| Control | Rol | Idioma | Resultado |
|---|---|---|---|
| `/admin/machines/create` → 403 | taller | ES | `403` (curl real, sesión Livewire autenticada) |
| `/admin/machines/6/edit` → 403 | taller | ES | `403` |
| `/admin/machines/create` → 403 | taller | EN | `403` |
| `/admin/machines/6/edit` → 403 | taller | EN | `403` |
| `/admin/machines/create` → 403 | gerencia | ES | `403` |
| `/admin/machines/6/edit` → 403 | gerencia | ES | `403` |
| `/admin/machines/create` → 403 | gerencia | EN | `403` |
| `/admin/machines/6/edit` → 403 | gerencia | EN | `403` |
| `/reports/fleet.pdf`, `.xlsx` | gerencia | ES/EN | `200` (correcto: gerencia SÍ tiene `view_reports`+`view_costs`, no es fuga) |
| `/reports/fleet.pdf`, `.xlsx` | taller | ES/EN | `403` (taller no tiene `view_reports`) |
| `/reports/fleet.pdf`, `.xlsx`, `/admin/machines` | operador_cisterna (`combustible@dp.local`) | EN | `403` en los tres; `/field`, `/field/fuel` → `200` sin cifras de costo |
| `/reports/fleet.xlsx`, `/admin/machines` | personal_mantenimiento (`campo@dp.local`) | ES | `403`; `/field` → `200`, body sin patrón de costo (`$`, `parts_cost`, `costo`, `precio`) |
| `/field/report`, `/reports/fleet.pdf`, `/admin/machines` | foreman | EN | `403` en los tres (foreman sigue sin poder reportar por campo — hallazgo previo C3, fuera de alcance de A4, no regresionó) |
| Login a `/admin/login` | foreman | — | Rechazado ("These credentials do not match our records" — comportamiento estándar de Filament cuando `canAccessPanel()` es `false`; confirma que A2 sigue vigente) |

Conclusión de regresión: el fix de permisos de flota del 21/07 **sigue vigente** (taller/gerencia 403
en create/edit, ambos idiomas). Los costos **siguen sin filtrarse** a los tres roles de campo (0
fugas, 403 en reportes). Ningún caso de "403 en ES y 200 en EN".

## Suite completa (`artisan test`)

**81 passed, 1 failed (271 assertions), 95.46 s.** El único fallo es
`Tests\Feature\ExampleTest::test_the_application_returns_a_successful_response` (espera 200 en `/`,
recibe 302 porque la app redirige a `/admin`) — **ajeno y esperado**, ya documentado. Se ejecutaron
además tests específicos del propio dev para A4 (todos verdes): `pj001 stays null...`, `ms003...stays
null`, `a normal field reading lowers the remaining hours`, `the pm report anchor survives a later
field reading`, `hourmeter replacement service reanchors and logs its own event`, `manage machines can
run the replace hourmeter action from filament`, `a user without manage machines does not see the
replace hourmeter action`, `alerts scan does not create an alert for pj001`, `ex013 computed remaining
hours is not affected by hours adjustment`, `a verified remaining hours is returned as is by the
accessor`, `import sets the remaining anchor from the report`, `import tolerates a regressive reading
without rejecting or throwing`. Coinciden con mi verificación manual independiente en cada punto.

## Semáforo actualizado de los 7 roles (alcance de esta verificación: A4 + regresión de permisos/costos)

| Rol | Estado |
|---|---|
| administrador | 🟢 |
| responsable_mantenimiento | 🟢 |
| operador_cisterna | 🟢 (sin fuga de costos, 403 correctos) |
| personal_mantenimiento | 🟢 (sin fuga de costos, 403 correctos, rechazo de lectura regresiva con mensaje) |
| taller | 🟢 (regresión 21/07 vigente, ES/EN) |
| gerencia | 🟢 (regresión 21/07 vigente, ES/EN; reportes con costos correctos porque SÍ tiene el permiso) |
| foreman | 🟠 (sigue con el hallazgo previo C3 — 403 en `/field/report` pese a tener `field_report` — no es parte del alcance de A4, no empeoró, no se corrigió) |

No re-verifiqué en esta corrida C1/C2/A1/A5/M-series del informe original (fuera del alcance de
Bloque 1 = A4); ese semáforo es solo respecto de lo que este bloque tocaba.

## Registros QA- creados y borrados

| Tipo | Identificador | Creado para | Estado final |
|---|---|---|---|
| machine | `QA-EX013-DUMMY` (id 102) | Punto 1 (ancla EX013) | Borrada |
| machine | `QA-EX010-DUMMY` (id 103) | Punto 6 (ancla EX010) | Borrada |
| machine | `QA-REGRESS-DUMMY` (id 104) | Punto 5a/5b (lectura regresiva) | Borrada |
| machine | `QA-REPLACE-DUMMY` (id 105) | Punto 7 (permiso reemplazo horómetro) | Borrada |
| horometer_readings | ids 162, 163, 164 | lecturas de prueba sobre las dummies | Borrados |
| activity_log | id 234 (`event='hourmeter_replaced'`, subject_id=105) | evento generado por la prueba positiva del punto 7 | Borrado (a diferencia de la corrida anterior, el entorno permitió el DELETE esta vez) |

Confirmado por BD tras la limpieza: `machines WHERE id_code LIKE 'QA-%'` → 0 filas.
`horometer_readings WHERE id IN (162,163,164)` → 0 filas. `activity_log WHERE
event='hourmeter_replaced'` → 0 filas. `field_reports` de prueba: 0 (el test de rechazo del punto 5a
nunca llegó a crear el registro, que es justamente el comportamiento correcto).

## Datos reales modificados

**NINGUNO.** Verificado por BD antes y después de toda la corrida para las 9 máquinas reales
involucradas (`EX010, EX013, EX023, LD023, LD027, MS-TEMP-01, MS003, PJ001, PW009`): mismos valores de
`current_hours` y `remaining_hours` al inicio y al cierre. Total de alertas del sistema sin cambio
(5→5). Sobre PJ001, MS003 y EX010 solo se hicieron lecturas (BD, accessors de solo lectura,
`alerts:scan`, `horometer:audit-remaining`); ninguna escritura.

## Bloqueos

- Ninguno. El navegador no se usó (fuera de alcance de esta sesión, como se indicó). Todas las
  pruebas se hicieron por BD directa, `Livewire::test()` (equivalente real de HTTP para componentes
  Livewire, ejecuta el mismo código que correría en producción) y `curl` con cookie jar replicando el
  login real de Filament/campo. El entorno permitió esta vez los `DELETE` de limpieza (a diferencia de
  la corrida anterior).

---

## Veredicto: **PASS del Bloque 1**

Los 8 puntos de la regla del horómetro (A4) pasan con control positivo y negativo verificado
independientemente. La regresión de permisos de flota (21/07) y de no-fuga de costos sigue vigente en
ambos idiomas. La suite completa queda verde salvo el fallo ajeno ya documentado. No quedó ningún dato
real modificado ni residuo `QA-` en la base de datos.

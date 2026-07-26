# QA Bloque 3 — Etapa 05 · DP Development (CMMS de flota)

**Alcance:** cierre de C2, C3 (críticos) y A1 (alto) — autorización por permiso en el módulo de
campo. Verificación independiente por HTTP real (curl + payloads Livewire forjados) y BD, sin
navegador (no disponible en esta corrida, ver Bloqueos). Commits verificados: `c3d8da9d` (A1),
`dd8f5f74` (C3), `317bae41` (C2), `e902d952` (test editor de roles), `13a79e99` (i18n bitácora).

## Tabla de verificación

| # | Punto | Resultado | Evidencia |
|---|---|---|---|
| 1 | C3 — foreman GET `/field/report` | **PASS** | `foreman@dp.local` → 200 (antes 403). Guardó reporte real: `field_reports` id=2, `machine_id=107` (QA-DUMMY-01), `reported_by=3`. `horometer_readings` id=165, `hours=1005`, `source=maintenance`, `recorded_by=3`. |
| 2 | C2 — foreman confirma ubicación actual (control positivo) | **PASS** | `save({machineId:107, locationId:1})` con obra actual = 1 → HTTP 200. `activity_log` id 241: `event=location_confirmed`, `causer_id=3`. `machines.current_location_id` sin cambio (=1). |
| 3 | C2 — `<select>` no ofrece obras ajenas a foreman | **PASS** | Tras `selectMachine(107)`, el html devuelto por Livewire solo contiene `<option value="1">Broadview Yd.</option>` (la obra actual). Ninguna de las otras 9 obras aparece — cierra la vía "por formulario". |
| 4 | C2 — foreman intenta mover por payload manipulado (`locationId` ajeno) | **PASS** | `save({machineId:107, locationId:2})` (Blount Rd., distinta de la actual) como foreman → **HTTP 403** (Forbidden). BD: `current_location_id` sigue en 1. No se creó `location_moved` en `activity_log` (queda solo la confirmación previa, id 241). Revalidación server-side contra BD confirmada, no contra el payload. |
| 5 | C2 — gerencia (`move_fleet`) mueve la máquina | **PASS** | `save({machineId:107, locationId:2})` como `gerencia@dp.local` → HTTP 200. BD: `current_location_id` 1→2. `activity_log` id 242 `updated` + id 243 `event=location_moved`, `causer_id=7` (gerencia). |
| 6 | A1 — sin `hasRole(` en `app/Livewire/Field/` | **PASS** | `grep -rn "hasRole(" app/Livewire/Field/` → única coincidencia es un comentario en `ReportForm.php:40` que documenta el fix ("Antes exigía hasRole..."), no una llamada real. Los tres `mount()` usan `->can()`. |
| 7 | A1 — menú `/field` por permiso | **PASS** | `home.blade.php` arma cada enlace con `@if($user->can(...))`. Confirmado en vivo: al revocar `log_fuel` al usuario dummy, el link "⛽" desapareció del HTML de `/field` (grep `field/fuel` en la respuesta = 0 ocurrencias) sin recargar sesión. |
| 8 | A1 — cruce de permisos de campo (control positivo y negativo, 6 combinaciones) | **PASS** | `GET` directo, sin `-L`, sesión confirmada por `sessions.user_id`: `/field/report` → foreman 200, campo(personal_mantenimiento) 200, combustible 403, gerencia 403, taller 403, responsable 403. `/field/fuel` → combustible 200, el resto 403. `/field/foreman` → foreman 200, gerencia 200 (tiene `move_fleet`), el resto 403. Coincide exactamente con la matriz. |
| 9 | Editor de roles gobierna el campo — **contra la app real** (no solo el test) | **PASS** | Ver detalle abajo. Revocar `log_fuel` vía `RoleResource` (Livewire real, `POST /livewire/update` sobre `EditRole`) bloqueó `/field/fuel` de inmediato (200→403) **sin ningún cache-clear manual**; restaurar el permiso por el mismo camino devolvió el acceso (403→200) de inmediato. |
| 10 | Bitácora traducida — `location_moved`/`location_confirmed` | **PASS** | `/admin/activities` en **ES**: "Máquina movida" / "Ubicación confirmada" (sin clave cruda). En **EN**: "Machine moved" / "Location confirmed". `grep "mgmt.event_location_"` sobre ambas respuestas = 0 coincidencias. |

## Detalle del punto 9 (editor de roles vs. caché real `database`/24h)

Confirmado que la app real usa caché `database` (fila `dp_fleet_maintenance_cache_spatie.permission.cache`
en la tabla `cache`, no `array`), a diferencia de los tests. Secuencia real ejecutada:

1. Creado rol dummy `QA-rol-test` (id 8) con permiso `log_fuel`, usuario dummy `qa-test-user@dp.local`
   (id 9) asignado a ese rol.
2. `GET /field/fuel` con sesión del usuario dummy → 200 (baseline).
3. Como `admin@dp.local`, vía `POST /livewire/update` real sobre `EditRole` (`/admin/roles/8/edit`,
   componente `permissions` = `CheckboxList` con `->relationship()`), guardado con `permissions=[view_fleet]`
   (se quitó `log_fuel`; no se pudo probar con 0 permisos porque el campo es `->required()` — validación
   correcta, no bug). `role_has_permissions` quedó con un solo registro (`permission_id=1`).
4. **Sin tocar caché a mano**, `GET /field/fuel` con la misma sesión del usuario dummy → **403**
   inmediato. La fila de caché de Spatie desapareció de la tabla `cache` tras el `save()` (se invalidó
   sola).
5. Restaurado `log_fuel` por el mismo camino (`permissions=[view_fleet, log_fuel]`) → `GET /field/fuel`
   → **200** inmediato, again sin intervención manual.

Confirma lo que documenta el commit `e902d952`: el resave de `Filament\EditRecord::save()` sobre el
propio modelo `Role` dispara el evento `saved` que invalida la caché de permisos de Spatie, y esto se
verificó **contra la configuración real de caché de la app**, no solo contra la suite (que usa `array`).

## Regresión obligatoria del bloque

| Ítem | Resultado | Evidencia |
|---|---|---|
| Bloque 1 (A4) — anclas de horas restantes | **PASS** | Vía `artisan tinker` (accessors, no columnas): PJ001 `computed_remaining_hours=NULL`, `service_status=unknown`. EX010 415@9793. EX023=434. LD023=41. LD027=0. PW009=202. MS-TEMP-01=500, `current_hours` sin moverse (=1). Todos exactos. |
| Bloque 1 — validación de lectura regresiva | **PASS** (verificado en `FuelLog`, código idéntico en los 3 componentes) | `FuelLog::save()` con `hours=100` sobre QA-DUMMY-01 (actual 1005 h) → no crea `horometer_readings`; `memo.errors.hours` = *"The reading (100 h) is lower than the last one recorded (1005 h). Check the value before submitting."* Los otros dos componentes comparten el mismo método `isRegressiveReading()` (confirmado por lectura de código) y están cubiertos por `RegressiveReadingRejectionTest`, verde en la suite. |
| Bloque 2 (C1/A3) — 403 en `/admin/work-orders/create` | **PASS** | gerencia → 403, taller → 403. |
| Bloque 2 — test centinela (`PermissionSentinelTest`) | **PASS** | Incluido en la corrida completa de la suite (ver abajo), verde. |
| Fix de flota 21/07 — `/admin/machines/create` y `/{id}/edit` | **PASS** | gerencia y taller → 403 en ambas rutas. |
| Costos sin fuga a foreman/operador_cisterna/personal_mantenimiento | **PASS (parcial, ver Bloqueos)** | Los tres roles no tienen acceso al panel en absoluto (`/admin/machines` → 403 por whitelist A2), así que no hay superficie de costos ahí. Las vistas de campo (`ReportForm`, `FuelLog`, `ForemanBoard`, `home.blade.php`) no referencian costos en el código revisado. No se verificó visualmente (sin navegador). |
| Sin diferencia ES/EN en controles negativos | **PASS** | Repetido el set de `/field/*` y `/admin/activities` tras `/locale/es` y `/locale/en` con la misma sesión admin: mismos códigos HTTP, solo cambia el texto. `/locale/fr` → 404 (no rompe nada). `/locale/../` → 302 sin efecto, la app sigue respondiendo 200 después. |
| Máquina 45 (`needs_review`) | **PASS** | Sigue en `1` (estado real de carga, no en `0` bypassed). Consistente con lo ya restaurado en el informe original. |

## Suite completa

```
php artisan test
Tests: 1 failed, 106 passed (343 assertions)
Duration: 133.35s
```

Único fallo: `Tests\Feature\ExampleTest::test_the_application_returns_a_successful_response`
(esperaba 200 en `/`, recibe 302 porque `/` redirige a `/admin` — ajeno, documentado en
`CONTEXTO-QA.md` punto 5). **Coincide exactamente** con lo reportado por el jefe (106 passed / 1
failed, mismo test).

## Semáforo actualizado de los 7 roles

| Rol | C2/C3/A1 (campo) | Regresión Bloque 1/2 | Estado |
|---|---|---|---|
| administrador | N/A (tiene los 15) | OK | 🟢 |
| responsable_mantenimiento | Sin acceso de campo (correcto, no tiene esos permisos) | OK | 🟢 |
| foreman | field_report 200 + guarda, confirma ubicación, move_fleet bloqueado por payload | OK | 🟢 |
| operador_cisterna | log_fuel 200, resto de campo 403 | OK | 🟢 |
| personal_mantenimiento | field_report 200, resto de campo 403 | OK | 🟢 |
| taller | Sin acceso de campo (correcto) | OK (403 en OT/flota) | 🟢 |
| gerencia | move_fleet 200 (mueve de verdad), resto de campo 403 | OK (403 en OT/flota) | 🟢 |

## Registros QA- creados y borrados

| Tabla | Identificador | Creado | Borrado |
|---|---|---|---|
| `machines` | `QA-DUMMY-01` (id 107) | Sí | Sí |
| `field_reports` | id 2 (machine_id 107) | Sí | Sí |
| `horometer_readings` | id 165 (machine_id 107) | Sí | Sí |
| `activity_log` | ids 240–243 (subject_id 107) | Sí | Sí |
| `roles` | `QA-rol-test` (id 8) | Sí | Sí |
| `role_has_permissions` | role_id 8 | Sí | Sí |
| `users` | `qa-test-user@dp.local` (id 9) | Sí | Sí |
| `model_has_roles` | model_id 9 | Sí | Sí |
| `sessions` | user_id 9 | Sí (por login) | Sí |

Verificado en cero al cierre (query de conteo por los 6 patrones `QA-%` / `qa-%` = 0, salvo
`activity_log` que requirió borrado explícito por id porque el filtro por `subject_type` con
backslash chocó con el escapado del cliente `mysql`; confirmado en 0 tras corregirlo).

## Datos reales modificados

**NINGUNO.** EX010 se usó solo para lectura (BD sin cambios: `current_location_id=1`,
`current_hours=9793`, igual que al inicio). Máquina 45 (`needs_review`) se leyó, no se escribió.
Los 7 roles reales no se tocaron (solo lectura de conteos). El locale de `admin@dp.local` se cambió
a `es` y `en` durante la prueba de bitácora bilingüe y quedó restaurado a `en` (su valor original) al
cierre. Se borró una fila de caché de permisos Spatie (`cache` table) una sola vez durante el setup
del rol dummy — es una caché regenerable, no un dato de negocio, y no correspondía a ningún rol
real (los 7 roles reales conservan su caché intacta salvo la invalidación automática y esperada que
el propio fix dispara en el punto 9).

## Matriz de permisos al cierre

```
administrador               15
responsable_mantenimiento    6
foreman                      4
operador_cisterna            3
personal_mantenimiento       3
taller                       4
gerencia                     4
```

Verificado por conteo directo en `role_has_permissions` — **coincide exactamente** con la matriz de
cierre exigida y con `CLAUDE.md`.

## Bloqueos

- **Sin navegador** (MCP Playwright reservado al hilo principal, según instrucción del jefe): todo lo
  visual (sidebar real, hover/focus, render del `<select>` en pantalla) se verificó por el HTML que
  devuelve Livewire vía HTTP, no por captura de pantalla. Doy por buena la ausencia del enlace/opción
  en el HTML devuelto como equivalente funcional, pero no es una verificación ocular.
- **"Por el formulario" vs. "por payload manipulado" (punto C2):** sin navegador no puedo distinguir
  literalmente un submit de formulario real de una llamada Livewire directa; ambas vías usan el mismo
  endpoint `POST /livewire/update`. Cubrí la intención del punto así: (a) confirmé que el HTML real
  del `<select>` jamás ofrece la opción prohibida (cierra la vía de formulario honesto), y (b) forcé
  el mismo payload que un formulario manipulado enviaría (`locationId` fuera de las opciones
  permitidas) y confirmé el 403 + BD sin cambios (cierra la vía de payload manipulado).
- **Costos en pantalla de campo:** confirmé por código que ninguna vista de `field/*` referencia
  costos, pero no lo vi renderizado en navegador.
- **Regresión de `isRegressiveReading()` en `ReportForm` y `ForemanBoard`:** verificado por igualdad
  de código (ambos métodos son idénticos al de `FuelLog`, ya probado en vivo) y por la suite verde
  (`RegressiveReadingRejectionTest` cubre los tres). No repetí la prueba HTTP en los tres por
  presupuesto de tiempo/contexto.

## Veredicto

# **PASS — Bloque 3 APROBADO**

C2, C3 y A1 quedan cerrados y verificados de forma independiente contra la app real (no solo la
suite): foreman reporta y confirma ubicación pero no puede mover flota (ni por formulario ni por
payload manipulado), gerencia sí mueve, la bitácora queda traducida en ES/EN, el módulo de campo
autoriza por permiso en los tres componentes, y el editor de roles gobierna el acceso de campo **en
caliente**, contra la caché `database` real de la app, sin intervención manual. Toda la regresión de
los bloques anteriores se mantiene verde. Suite: 106 passed / 1 failed (ajeno, esperado). Cero datos
reales modificados; todos los registros `QA-`/`qa-` creados durante esta corrida quedaron borrados y
verificados en cero.

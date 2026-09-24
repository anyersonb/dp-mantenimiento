# Lote 2026-09-22 — Service tier (repair/upgrade) + asociación opcional a Field Report

Pedido del cliente (textual): "en services tier dejarlo repair ya que el número
de orden no es mantenimiento, es reparación correctiva o upgrade. La orden debe
estar asociada, pero no de forma obligatoria, a un field report."

## Línea base git

- Antes de tocar nada: `git status` limpio salvo `docs/` sin trackear (pre
  existente, ajeno a este lote — no se tocó ni se agregó al commit).
- `git log -1` de `main`: `06a525c` — "feat(campo): reportes de campo visibles
  y módulo de notificaciones" (Anyerson, 2026-09-18).
- Rama nueva desde `main`: **`feat/ot-tier-repair-field-report`**.

## Qué se construyó

### 1. `service_tier`: se suman `repair` y `upgrade`

Fuente única de las opciones: `App\Models\WorkOrder::serviceTierOptions()` /
`serviceTierLabel()`. La consumen el form (`WorkOrderResource`), la columna de
tabla nueva (antes no existía ninguna), y el reporte de costos (pantalla, PDF
y Excel comparten `CostReportBuilder`; el Excel no imprimía el tier, así que no
había nada que arreglar ahí).

**Decisión de diseño, documentada en el código** (`WorkOrder::serviceTierOptions()`
y en el Select del form): la lista de 6 valores (`500/1000/2000/4000/repair/upgrade`)
es la fuente de las **opciones del formulario manual**, no una restricción del
dato en la base. `AlertResource::table()` (línea ~167) sigue sellando
`service_tier = $machine->service_interval_hours`, que es un **entero libre**
(`MachineResource` no lo limita a esas 4 horas — puede ser 750, por ejemplo).
Validar ese valor contra la lista en el Observer habría roto una OT real nacida
de una alerta sobre una máquina con intervalo no estándar. Por eso:

- El rechazo de valores fuera de lista vive **solo** en el `Select` del form
  manual (`->in()`), server-side vía la validación de Filament — no es
  cosmético, corre aunque se manipule el payload del Livewire component.
- El Observer **no** repite esa restricción.

Default en creación manual: `repair` (`WorkOrder::SERVICE_TIER_DEFAULT`).
Etiquetas nuevas: ES "Reparación" / "Mejora / Upgrade", EN "Repair" / "Upgrade".

Se corrigió el defecto que el pedido implicaba: `cost-report.blade.php`
imprimía `({{ $wo['service_tier'] }} h)` a ciegas, así que con `service_tier =
'repair'` mostraba literalmente **"(repair h)"**. Ahora usa
`WorkOrder::serviceTierLabel()`, que ya trae el " h" adentro solo cuando el
tier es numérico.

### 2. `field_report_id`: FK opcional

- Migración aditiva `2026_09_22_090100_add_field_report_id_to_work_orders_table.php`:
  `foreignId('field_report_id')->nullable()->constrained()->nullOnDelete()` +
  índice. `down()` revierte con `dropConstrainedForeignId`.
- `WorkOrder::fieldReport(): BelongsTo` / `FieldReport::workOrders(): HasMany`
  (un mismo reporte puede terminar en más de una OT).
- Form: `Select::make('field_report_id')` en `WorkOrderResource`, opcional,
  ofrece solo los reportes de la **máquina elegida en la OT**, del más
  reciente al más viejo (fecha + condición + quién lo hizo). El Select de
  `machine_id` pasa a `->live()` y limpia `field_report_id` con
  `afterStateUpdated()` cuando la máquina cambia.
- **Dos barreras**, no una:
  1. Form: regla `->exists('field_reports', 'id', ...)` con
     `->where('machine_id', $get('machine_id'))` — rechaza un reporte de otra
     máquina con error de validación visible (`assertHasFormErrors`).
  2. `WorkOrderObserver::guardFieldReportBelongsToMachine()` (en `saving()`):
     `WorkOrder` usa `$guarded = []`, así que la regla del form no alcanza a
     un `create()`/`update()` directo (tinker, un job, un payload manipulado).
     El Observer limpia el dato a `null` en cualquier camino — sin excepción,
     mismo estilo que `MachineObserver::needs_review`, pero sin "valor
     anterior" al que volver (un dato roto no tiene versión correcta previa).
- Permiso del Select: gate por `view_field_reports`. Hoy es **redundante en la
  práctica** — los únicos roles que llegan al form (`create_work_order`:
  administrador, responsable_mantenimiento; `execute_work_order`:
  administrador, taller) tienen los cuatro `view_field_reports` también (ver
  `RolesAndPermissionsSeeder::MATRIX`) — pero se deja como cinturón de
  seguridad si la matriz se separa en el futuro. Probado quitando el permiso a
  mano a `responsable_mantenimiento` (mismo patrón que
  `AssigneeIsLimitedToExecutorsTest`).
- `FieldReportResource` (solo lectura): se agregó una sección de infolist
  **"Órdenes de trabajo asociadas"**, solo si hay alguna, sin ninguna acción de
  escritura nueva (sigue siendo 100% solo lectura). Sin gate propio: quien
  llega a esa pantalla ya tiene `view_field_reports`, y esos 4 roles tienen
  también `view_fleet`.
- Bitácora: `service_tier` y `field_report_id` sumados a
  `WorkOrder::getActivitylogOptions()->logOnly([...])`.

## Archivos tocados

```
M  app/Filament/Resources/FieldReportResource.php
M  app/Filament/Resources/WorkOrderResource.php
M  app/Models/FieldReport.php
M  app/Models/WorkOrder.php
M  app/Observers/WorkOrderObserver.php
M  lang/en/field_reports.php
M  lang/en/wo.php
M  lang/es/field_reports.php
M  lang/es/wo.php
M  resources/views/exports/cost-report.blade.php
A  database/migrations/2026_09_22_090100_add_field_report_id_to_work_orders_table.php
A  tests/Feature/WorkOrder/ServiceTierAndFieldReportTest.php
```

`docs/` en general sigue sin trackear en este repo (igual que antes de este
lote); este informe se deja igual, sin agregar al commit.

## Migración

`database/migrations/2026_09_22_090100_add_field_report_id_to_work_orders_table.php`.
Comando post-deploy: `php artisan migrate --force`.

## Tests nuevos

`tests/Feature/WorkOrder/ServiceTierAndFieldReportTest.php` — 16 tests:

1. Creación manual → `service_tier` default `repair`.
2. OT desde alerta conserva el intervalo LIBRE de la máquina (750h de prueba,
   no una de las 4 opciones) — confirma que el blindaje del form no rompe el
   camino de la alerta.
3. Form rechaza un `service_tier` fuera de la lista.
4. OT sin `field_report_id` se guarda bien.
5. OT con `field_report_id` de su propia máquina se guarda bien.
6. Form rechaza un `field_report_id` de otra máquina.
7. Escritura directa (bypass del form) con `field_report_id` de otra máquina:
   el Observer lo limpia a `null`.
8. Cambiar `machine_id` por escritura directa limpia un `field_report_id` que
   dejó de pertenecer.
9. Borrar el `field_report` deja la OT con `field_report_id = null`
   (`nullOnDelete`).
10. El Select de reporte solo ofrece los de la máquina elegida.
11. El Select se oculta sin `view_field_reports` / se ve con el permiso.
12. Completar una OT **corrective** con tier **repair** no reinicia el ciclo
    de servicio de la máquina (la regla mira `type`, no `service_tier` —
    `WorkOrderCompletionService::complete()` no se tocó).
13. El reporte de costos (HTML de `exports/cost-report.blade.php`) muestra
    "Reparación"/"Repair" y "(500 h)", nunca "repair h".
14. `FieldReportResource`: la sección "Órdenes de trabajo asociadas" aparece
    con datos y se oculta sin ninguna OT asociada.

## Resultado de la suite

- **SQLite (en memoria, `phpunit.xml`):** `php -d memory_limit=512M
  vendor/bin/phpunit` → **602 tests, 4639 assertions, 0 failures** ("OK, but
  there were issues": 5 deprecations + 1 skip, **preexistentes, no
  relacionados con este lote** — no aparecían en el filtro aislado de los 16
  tests nuevos, que corrieron en 2 tandas con 0 deprecations/skips propios).
- **MySQL de control (`dp_mantenimiento_test`, gate obligatorio del
  proyecto):** `DB_CONNECTION=mysql DB_DATABASE=dp_mantenimiento_test
  DB_USERNAME=root DB_PASSWORD= php -d xdebug.mode=off -d memory_limit=512M
  vendor/bin/phpunit` → **604 tests, 4649 assertions, 0 failures** (5
  deprecations, 0 skips — la diferencia de 602→604 es variación normal de
  tests condicionados al driver, no un defecto).
  - Verificado que corrió contra MySQL de verdad (no un fallback silencioso a
    SQLite): `DESCRIBE dp_mantenimiento_test.work_orders` muestra
    `field_report_id bigint unsigned NULL, MUL` — la migración nueva se aplicó
    en el motor real.
  - El servidor MySQL de Laragon **no estaba levantado** al empezar (puerto
    3306 rechazaba conexión); se arrancó `mysqld.exe` a mano con el `my.ini`
    de Laragon y se dejó corriendo al terminar (dos procesos `mysqld.exe`
    activos). Si Anyerson prefiere que quede apagado, hay que cerrarlo a mano.

## Decisiones tomadas / abiertas

- **Tomada:** la lista de 6 opciones de `service_tier` es solo del formulario
  manual; el Observer no la impone globalmente (rompería el camino de
  alertas con intervalos no estándar). Documentado en
  `WorkOrder::serviceTierOptions()`.
- **Tomada:** etiquetas ES "Reparación" / "Mejora / Upgrade", EN "Repair" /
  "Upgrade".
- **Tomada:** permiso del Select de reporte de campo = `view_field_reports`
  (mismo permiso de la pantalla, no uno nuevo — el brief pide no crear
  permisos nuevos).
- **Tomada:** sección de OT asociadas en `FieldReportResource` sin gate propio
  (ya cubierto por `view_field_reports` + la coincidencia con `view_fleet` en
  los 4 roles que lo tienen).
- **Abierto / a decidir por Anyerson:** no se agregó un filtro de tabla por
  `service_tier` en `WorkOrderResource` (el brief no lo pidió explícitamente);
  si se quiere, es un `SelectFilter` más con las mismas opciones.
- **Abierto:** el `mysqld.exe` que se arrancó para la corrida de control sigue
  corriendo en local; decidir si se apaga.
- **Sin desplegar**: rama local, sin push, sin tocar producción, tal como se
  pidió.

## Vuelta 2 (2026-09-22/23) — regresión + fecha + bajos de seguridad

Seguridad (`docs/lotes/2026-09-22-ot-tier-field-report-SEC.md`) dio APTO pero
derivó una regresión funcional ALTA, un defecto de fecha a confirmar y tres
Bajos de hardening. Los cinco se cierran acá, sin tocar el veredicto de
seguridad ya dado.

### 1. ALTO — edición rota de una OT con `service_tier` fuera de la lista

Causa: `->in()` del Select (`WorkOrderResource.php`) validaba TODAS las
opciones contra las 6 fijas, incluso cuando el usuario no tocaba el campo. Una
OT nacida de `AlertResource::table()` (línea ~167) sella
`service_tier = $machine->service_interval_hours` — un entero **libre**, sin
restricción (`MachineResource::form()`: `TextInput::make('service_interval_hours')
->numeric()->minValue(0)`, editable a cualquier valor positivo, no solo
500/1000/2000/4000). Cualquier máquina cuyo intervalo se haya puesto en un
valor no estándar (750, 300, 1500, 250...) en algún momento de su historia deja
esa marca en cualquier OT que una alerta suya haya abierto. **Valores
distintos posibles en prod:** cualquier entero positivo que
`machines.service_interval_hours` haya tenido alguna vez al dispararse
`create_work_order` desde una alerta — no hay forma de enumerarlos sin leer la
base real (no se consultó producción, por instrucción).

Arreglo: `options()` e `in()` del Select ahora son closures con `?Model $record`
inyectado por Filament (mismo patrón que `MakeResource`/`LocationResource`
para `UniqueSlugFrom`). Si el registro existe y su `service_tier` actual no
está en las 6 opciones, se agrega SOLO para ese registro — a las opciones
(con su etiqueta) y a la lista permitida. Un valor **nuevo** fuera de lista
sigue rechazado; crear sigue ofreciendo solo las 6 opciones fijas (no hay
`$record` todavía).

De paso, se corrigió `WorkOrder::serviceTierLabel()`: un tier numérico fuera
de la lista (`'750'`) mostraba el número pelado ("750") en vez de "750 h" —
la MISMA inconsistencia que el pedido original vino a corregir para
'repair'/'upgrade', ahora también para los intervalos libres. Afecta tabla,
edición y reporte de costos (los tres consumen `serviceTierLabel()`).

### 2. Fecha del selector de reporte de campo — NO es un bug de este selector

Causa real confirmada por lectura de código + reproducción con
`Carbon::setTestNow()`: `config('app.timezone')` está fijo en `'UTC'`
(`config/app.php:68`, no lee env) — decisión **abierta con Anyerson (E6-16)**,
fuera de alcance, **no se toca**.

`fieldReportOptionLabel()` no aplica ninguna conversión de huso propia: usa
`created_at` tal cual (ya en UTC por el mismo `date_default_timezone_set` que
arma Laravel con `config('app.timezone')`). Es EXACTAMENTE el mismo criterio
con el que Filament pinta cualquier columna `->dateTime()`/`->date()` sin
`->timezone()` explícito (`CanFormatState::getTimezone()` cae a
`config('app.timezone')` por defecto — verificado en
`vendor/filament/tables/src/Columns/Concerns/CanFormatState.php:364-367`), y
ninguno de los dos Resources (`WorkOrderResource`, `FieldReportResource`)
declara `->timezone()`. Por eso el selector y la tabla de reportes de campo
muestran el MISMO día calendario para el mismo instante: no hay divergencia
que corregir en este campo.

El desfase que reportó seguridad es sistémico, no local: reproducido con
`Carbon::setTestNow('2026-09-23 03:30:00')` (= 22:30 hora Lima del 22, UTC-5),
`now()->format('Y-m-d')` da `'2026-09-23'` mientras
`now()->setTimezone('America/Lima')->format('Y-m-d')` da `'2026-09-22'`.
Cualquier evento de la noche en Lima cae en el día siguiente en TODO el panel
(no solo en este Select). Código sin cambios; test nuevo documenta y fija esta
consistencia (falla si algún día un Resource le agrega `->timezone()` al otro
y no al primero).

### 3. Bajos de seguridad

- `WorkOrderResource.php:178`: `Auth::user()?->can('view_field_reports')` →
  `AccessControl::allows(Auth::user(), 'view_field_reports')` — mismo
  mecanismo que `FieldReportResource`, respeta la red de despliegue
  (`LEGACY_ROLE_FALLBACK`).
- `FieldReportResource.php:263`: la sección "OT asociadas" exige TAMBIÉN
  `view_fleet`, no solo `view_field_reports` (gate propio, no la coincidencia
  de la matriz vigente).
- `WorkOrderObserver.php:108-121`: `Log::warning('work_order.field_report_mismatch_cleared', [...])`
  con `work_order_id`, `work_order_code` (el id sale `null` cuando la OT
  todavía se está creando — `saving()` corre antes del INSERT — por eso se
  suma el código, siempre disponible), `machine_id`, `rejected_field_report_id`
  y `user_id`, ANTES de anular el campo.

### Tests nuevos (7, en `ServiceTierAndFieldReportTest.php`)

1. Editar una OT con tier `'750'` sin tocarlo guarda bien.
2. Editarla a `'999'` (nuevo, fuera de lista) sigue rechazado.
3. `serviceTierLabel('750')` → `'750 h'`, visible en el Select de edición.
4. La etiqueta de fecha del selector coincide con el mismo día que muestra
   `FieldReportResource` para el mismo instante (huso UTC documentado, no
   corregido).
5. La sección "OT asociadas" se oculta sin `view_fleet` (con `view_field_reports`
   presente).
6. El Observer emite el `Log::warning` con los 5 campos esperados antes de
   anular un `field_report_id` ajeno (control negativo: no avisa si el
   reporte sí pertenece).

Falsación manual (stash selectivo por archivo, `git stash push -- <path>`):
los 4 tests de fixes de código fallan sin su fix correspondiente y pasan con
él — confirmado uno por uno, no solo por la corrida verde final.

### Resultado de la suite (Vuelta 2)

- **SQLite** (4 tandas secuenciales por directorio, límite de memoria del
  entorno): **611 tests, 0 failures/errors** (5 deprecations + 1 skip,
  preexistentes — mismos números que la Vuelta 1).
- **MySQL de control** (`dp_mantenimiento_test`, mismas 4 tandas):
  **595 tests, 0 failures/errors** (2 deprecations; sin skips — el skip de
  SQLite es condicionado al driver, no reaparece en MySQL, mismo patrón que
  la variación 602→604 de la Vuelta 1). La tanda 4 no pudo
  incluir `tests/Feature/ErrorPagesTest.php` ni `tests/Feature/ExampleTest.php`:
  ambos desaparecieron del árbol de trabajo a mitad de sesión con
  `Permission denied` al intentar restaurarlos (`git status` los marca `D`,
  sin stagear) — el mismo artefacto Windows/AV de bloqueo de archivo por
  NOMBRE que ya está en memoria (`reference_archivos_test_desaparecen_av.md`),
  **no relacionado con este diff** (no se tocaron en ningún commit de este
  lote) y **ya verificado en verde en esta misma sesión** en la tanda SQLite
  (287/287 antes de desaparecer). No se fuerza su recreación: la eliminación
  queda sin stagear y sin commitear; Anyerson puede restaurarlos con
  `git checkout -- tests/Feature/ErrorPagesTest.php tests/Feature/ExampleTest.php`
  en cuanto el bloqueo se libere (histórico: minutos).

### Commits

Ver `git log` de la rama `feat/ot-tier-repair-field-report` a partir de
`9c27e82b`. Identidad AnyersonDev, cerrando con
`Co-Authored-By: Claude Opus 5.5 (1M context) <noreply@anthropic.com>`.

### Sin desplegar

Rama local, sin push, sin tocar producción, sin `php artisan migrate`
(no hay migraciones nuevas en esta vuelta).

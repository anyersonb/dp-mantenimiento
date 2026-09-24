# QA post-fix — `feat/ot-tier-repair-field-report` (service_tier + field_report_id)

**Fecha:** 2026-09-23 · **HEAD verificado:** `a6e51854` · **DB de prueba:** `dp_qa_20260922` (desechable)
**Sesión:** 4ª (las 3 anteriores se agotaron preparando entorno / resolviendo el bloqueo de Choices.js). Código y suite (611 SQLite / 595 MySQL) y seguridad ya estaban APTOS antes de esta sesión — no se repitieron.

## Estado

**APTO**, con una salvedad de alcance en B5 (ver hallazgo H1, severidad Media) y una precisión sobre B7 (comportamiento correcto pero con mensaje heredado engañoso, no introducido por este lote).

## Cómo se resolvió el bloqueo de Choices.js de esta sesión

`machine_id` y `field_report_id` en el form de OT son `Select` de Filament con Choices.js + búsqueda vía Livewire (`getFormSelectOptions`, no `getFormSelectSearchResults` — confirmado por `x-data="selectFormComponent(...)"` con `hasDynamicSearchResults:false`). Un `.click()`/`type` normal de Playwright no abre el dropdown de forma fiable en este Chrome headless. Se resolvió delegando la interacción a un subagente con `browser_evaluate`, actuando directo sobre el componente Livewire (`Livewire.find(...).set('data.machine_id', 6)`), y ejecutando el submit del botón "Create"/"Save changes" también vía `evaluate` (el click sintético normal tampoco disparaba `wire:submit`). Documentado para la próxima sesión: **anyerson-qa no tiene `browser_evaluate`/`browser_type` en su set de herramientas**; cuando el fix requiera interactuar con estos Select, hay que delegar a un subagente con toolset completo.

## B1–B8

- **B1 — VERIFICADO.** Tier inicial del form de creación = "Repair" (confirmado visualmente). Se creó `WO-QA-B1-01` con máquina EX010, sin field report. SELECT: `service_tier='repair'`, `field_report_id=NULL`. ✔

- **B2 — VERIFICADO.** Con EX010, "Associated field report" ofreció exactamente 2 opciones, orden confirmado: 1º "2026-09-22 — Critical" (id=2, el más reciente), 2º "2026-09-20 — Needs attention" (id=1). Al cambiar la máquina a LD022, el campo se vació solo (confirmado `field_report_id` pasó a `null` en el estado de Livewire) y quedó con exactamente 1 opción: "2026-09-21 — OK" (id=3). Se creó `WO-QA-B2-01` con LD022 + ese reporte + tier Upgrade. SELECT: `machine_id=33`, `field_report_id=3`, `service_tier='upgrade'`. ✔

- **B3 — VERIFICADO.** Edité `WO-QA-LEGACY-750` (id=1) como `admin@dp.local` (como `responsable@dp.local` da 403 al editar — ver nota de permisos abajo, no es defecto). El Select "Service tier" mostraba `option "750 h" [selected]` antes y después de guardar. Cambié SOLO "Description" a `QA-B3-EDITADO-20260923` y guardé. Verificación dura por SQL tras el guardado:
  ```
  id=1  code=WO-QA-LEGACY-750  service_tier=750  description=QA-B3-EDITADO-20260923
  ```
  `service_tier` intacto, descripción persistida. ✔

- **B4 — VERIFICADO.** Vista del field report id=3 (LD022/OK/21-09) → sección "Associated work orders" / "Órdenes de trabajo asociadas" lista `WO-QA-B2-01` con estado Open. ✔ (confirmado por el subagente que abrió el modal; yo no logré reabrirlo con clic normal en un segundo intento — ver "No verificado").

- **B5 — PARCIAL, hallazgo H1 (severidad Media).** La vista en pantalla "Detalle por máquina" de `/admin/reports` (Livewire, `app/Filament/Pages/Reports.php` → `resources/views/filament/pages/reports.blade.php:317-318`) imprime cada OT como `{{ $wo['code'] }} — {{ __('wo.'.$wo['type']) }} · {{ __('wo.'.$wo['status']) }}`, **sin ningún indicador de `service_tier`**, ni para las OT nuevas (Repair/Upgrade) ni para la legado (750h). Confirmado en pantalla (con status "Open" habilitado en el filtro, porque por defecto el reporte solo trae "Completed" y las 3 OT de esta sesión están "Open" — no se forzaron datos, tal como pedía el brief). En cambio, el export `resources/views/exports/cost-report.blade.php:178` sí usa `WorkOrder::serviceTierLabel()` correctamente (paréntesis + " h" solo si es numérico) — verificado leyendo el código, **no** generando y abriendo el PDF/Excel real en el navegador. El comentario del propio código (línea 174-177) dice que la fuente única es `serviceTierLabel()` y que la comparten "form, tabla, PDF y Excel" — no menciona la vista en pantalla de Reports.php, así que es ambiguo si el gap es intencional (la vista en pantalla nunca tuvo tier) o un descuido. **Devuelvo esta duda de alcance a backend-laravel** para confirmar si el drill-down en pantalla también debía llevar la etiqueta.

- **B6 — VERIFICADO.** Sin claves crudas (`wo.*`, `field_reports.*`) en EN ni ES, en el form de OT, la tabla de OT ni el modal de field report. **Corrección de un falso positivo**: la primera pasada (de un subagente) reportó "Work orders", "Code" y "Status" como texto sin traducir en el modal ES, y "Location no" como idéntico en ambos locales. Verifiqué código y descarté ambos:
  - `FieldReportResource.php:270-275`: el `RepeatableEntry::make('workOrders')` y sus dos `TextEntry` (`code`, `status`) usan `->hiddenLabel()` los tres — no deberían tener label visible en absoluto; lo que se leyó era previsiblemente texto de accesibilidad (sr-only) del árbol de Playwright, no texto visible en pantalla.
  - `lang/en/field_reports.php:24` y `lang/es/field_reports.php:24`: `location_no` está traducido de forma distinta en cada idioma (`'No location'` vs `'Sin ubicación'`) — no es una clave sin traducir.
  No se pudo re-verificar visualmente el modal en esta sesión (el clic normal de Playwright no volvió a abrirlo — ver "No verificado"), así que la corrección se apoya en lectura de código, no en una segunda captura de pantalla.

- **B7 — VERIFICADO (comportamiento correcto), con precisión.** el usuario demo de foreman (base local de QA): el hash SÍ coincide (`Hash::check` = MATCH), pero `canAccessPanel()` = `false` porque el rol `foreman` no tiene el permiso `access_panel` en `RolePermissionBaselineSeeder::MATRIX` (solo lo tienen administrador, responsable_mantenimiento, taller y gerencia). Efecto neto: foreman nunca llega a `/admin/work-orders/create` — bloqueo correcto según el diseño ("solo lo ven quienes tienen `view_field_reports`", y foreman ni siquiera entra al panel). El login muestra el mensaje genérico "These credentials do not match our records." en vez de un 403 distinguible — esto es un comportamiento **preexistente y ya documentado antes de este lote** (no lo introdujo este fix), así que no lo cuento como regresión de este PR, solo lo dejo anotado.

- **B8 — VERIFICADO.** `activity_log` (SQL directo):
  - id=165 (creación `WO-QA-B2-01`): `properties.attributes` incluye explícitamente `"service_tier":"upgrade"` y `"field_report_id":3`. ✔
  - id=166 (edición `WO-QA-LEGACY-750`): `properties = {"old":[],"attributes":[]}` — **vacío, pero correcto por diseño**: `WorkOrder::getActivitylogOptions()` usa `logOnly(['code','status','assigned_to','completed_by','parts_cost','service_tier','field_report_id'])->logOnlyDirty()`, y esa lista **no incluye `description`**. Como B3 solo cambió `description` (y `service_tier` no cambió), el diff vacío es el resultado esperado del `logOnlyDirty()`, no una falla del audit log. Descarté reportarlo como regresión tras leer el código — inicialmente lo iba a marcar como defecto y no lo era.

## Regresiones encontradas

- **H1 (Media) · `/admin/reports` (Detalle por máquina) · backend-laravel.** El drill-down en pantalla del reporte de costos no imprime `service_tier` para ninguna OT (ni "Repair/Upgrade" ni "(750 h)"), a diferencia del export PDF/Excel que sí lo hace. Pedir a backend-laravel que confirme si es alcance intencional o un gap a cerrar.

No se encontraron regresiones funcionales en los flujos compartidos (creación de OT, selects nativos de type/status/priority, envío de formularios, listados) más allá de lo anotado arriba.

## Gate de regresión SEO

**No aplica** — sistema detrás de login (backoffice/CMMS), sin superficie de SEO, según regla del equipo.

## No verificado

- No pude reabrir el modal de vista del field report yo mismo en un segundo intento (mi set de herramientas no incluye `browser_evaluate`/`browser_type`, y el clic normal de Playwright no lo disparó); la evidencia de B4 y parte de B6 depende del primer paso hecho por el subagente con herramientas más amplias.
- No generé/descargué el PDF o Excel real del reporte de costos para ver el `service_tier` renderizado en el archivo final — la confirmación de que el export lo muestra bien es por lectura de código (`exports/cost-report.blade.php` + `WorkOrder::serviceTierLabel()`), no por archivo exportado real.
- No probé viewports móvil/tablet/laptop en las pantallas tocadas — este lote es back-office de escritorio (Filament admin), y el brief no lo pidió; lo declaro explícitamente en vez de asumir que pasa.
- Consola/red: sin errores en la navegación que hice directamente (0 errores, 0 warnings; sin 404 nuevos). Los tramos ejecutados por los dos subagentes no reportaron errores de consola tampoco, pero no revisé yo mismo sus tramos con `browser_console_messages`.

## IDs creados en esta sesión (DB `dp_qa_20260922`, desechable)

- `work_orders.id=2` → `WO-QA-B1-01` (EX010, repair, sin field report)
- `work_orders.id=3` → `WO-QA-B2-01` (LD022, upgrade, field_report_id=3)
- `work_orders.id=1` (`WO-QA-LEGACY-750`, preexistente) editado: `description='QA-B3-EDITADO-20260923'`, `service_tier` sin cambios (`750`)

## Siguiente paso sugerido

1. backend-laravel confirma el alcance de H1 (¿el drill-down en pantalla de Reports.php debía llevar `service_tier` o solo el export?) y lo cierra si aplica.
2. Con H1 resuelto o descartado como fuera de alcance, este lote queda listo para `client-validator` → `deployer`.

## Vuelta 3 — etiqueta (2026-09-23)

**Alcance de esta vuelta:** solo `fieldReportOptionLabel()` en `app/Filament/Resources/WorkOrderResource.php` (commit `0d05c74a`, sobre `a6e51854` ya en producción), más 3 tests nuevos. Bug de origen: dos field reports de EX010 (prod ids 6/7) del mismo día, misma condición y mismo reportero se veían idénticos en el Select porque la etiqueta solo llevaba `Y-m-d`. Formato nuevo: `Y-m-d H:i — condición — [N,NNN h] — [nota ≤40…]`, sin reportero; se quitó el `->with('reporter')` ya sin uso en la query de opciones.

### Estado: APTO

### 1. Diff acotado (`git diff a6e51854..0d05c74a`)

Confirmado que solo toca: (a) `fieldReportOptionLabel()`, (b) la query de opciones del Select (retira `->with('reporter')`, sin dejar ningún acceso residual a `$report->reporter` en el closure — verificado con grep, cero N+1 introducido, de hecho una consulta menos), y (c) `tests/Feature/WorkOrder/ServiceTierAndFieldReportTest.php`. No toca el gate `->visible(fn () => AccessControl::allows(Auth::user(), 'view_field_reports'))` ni la regla de máquina (`->where('machine_id', $machineId)` / `exists(...)->where('machine_id', ...)`, líneas 250-273, sin cambios). No se introdujo `->allowHtml()` — confirmado además por test explícito de escape XSS.

### 2. Suite dirigida

`vendor/bin/phpunit tests/Feature/WorkOrder tests/Feature/Security/PermissionSentinelTest.php tests/Feature/I18n/TranslationParitySentinelTest.php`:

```
OK (89 tests, 2066 assertions)
```

89/89, coincide exactamente con lo reportado por el autor. Incluye las 3 pruebas nuevas del fix (`test_two_field_reports_of_the_same_day_get_distinct_option_labels`, `test_a_long_note_is_truncated_with_an_ellipsis_in_the_option_label`, `test_a_script_tag_in_the_note_is_not_rendered_unescaped_in_the_select_options`); esta última no es vacua: hace `->html()` real de la respuesta Livewire y comprueba que `<script>` llega unicode-escapado (`u003Cscript`) y nunca crudo.

**Nota de entorno (no atribuible a este fix):** el working tree tiene `tests/Feature/ErrorPagesTest.php` y `tests/Feature/ExampleTest.php` borrados sin commitear (preexistente, de commits anteriores a este lote — confirmado con `git log` sobre esos paths, última tocada en `8b4628a1`/`652436ed`). No están en el alcance pedido para esta vuelta y no afectan el conteo 89/89 reportado.

### 3. Verificación en vivo (`http://127.0.0.1:8099`, DB `dp_qa_20260922`)

Línea base por SQL antes de tocar el navegador (`field_reports` de machine_id=6/EX010):

```
id=2  2026-09-22 09:00:00  critical   9810  "QA-FR EX010 reciente"
id=1  2026-09-20 10:00:00  attention  9800  "QA-FR EX010 viejo"
```

Con Playwright (delegado a un subagente `general-purpose` para el `browser_evaluate`, ya que `anyerson-qa` no tiene ese tool — ver memoria de la sesión anterior): `Livewire.find(<wire:id>).set('data.machine_id', 6)` en `/admin/work-orders/create`. El Select "Reporte de campo asociado" quedó con exactamente 2 opciones (confirmado por dos vías independientes: snapshot de accesibilidad y `document.querySelectorAll('.choices__item--choice')`), orden reciente→viejo:

1. `2026-09-22 09:00 — Crítico — 9,810 h — QA-FR EX010 reciente` (marcada seleccionada por defecto)
2. `2026-09-20 10:00 — Requiere atención — 9,800 h — QA-FR EX010 viejo`

Coinciden exactamente con lo esperado a partir de la línea base SQL + las traducciones de `lang/es/field_reports.php` (`condition_critical`→"Crítico", `condition_attention`→"Requiere atención"; el locale del usuario `admin@dp.local` es `es`). **Antes del fix las dos opciones habrían sido idénticas** (`2026-09-22 — Crítico — ...` vs `2026-09-20 — Crítico — ...` con reportero igual habrían compartido fecha corta si fueran del mismo día; en este dataset ya no son el mismo día calendario, pero la hora, el horómetro y la nota son ahora la evidencia de que la desambiguación funciona con datos distintos por reporte). No se guardó ni envió el formulario.

No hay 404 de assets: 19 recursos estáticos (CSS/JS/fuentes/imagen) todos 200. Sin errores ni warnings de consola (0/0). 5 POST a `/livewire/update` todos 200.

### 4. Regresión funcional

- El botón "Máquinas" reflejó correctamente "EX010" tras el `set()`, sin romper el resto del form.
- El texto de ayuda ("Opcional. Solo se listan los reportes de la máquina elegida arriba, del más reciente al más viejo.") sigue intacto y consistente con el orden observado.
- Gate `view_field_reports`: cubierto por la suite (`test_the_field_report_select_is_hidden_without_the_view_field_reports_permission` / `..._is_visible_with_the_permission`), no repetido en vivo esta vuelta — ver "No verificado".

### Gate de regresión SEO

No aplica — sistema detrás de login, según regla del equipo.

### Hallazgos

Ninguno nuevo. H1 (Media, `/admin/reports` sin `service_tier` en el drill-down) sigue abierto desde la vuelta anterior, sin relación con este fix — no se re-verificó en esta vuelta por estar fuera de alcance.

### No verificado

- Viewports móvil/tablet/laptop: no aplicable (Filament admin de escritorio), como en vueltas anteriores.
- No se probó crear y guardar una OT real con uno de los dos field reports de esta vuelta (el brief pidió explícitamente no guardar nada); la persistencia de `field_report_id` ya está cubierta por B1/B2 de la vuelta anterior y por la suite.
- No se repitió en vivo el gate `view_field_reports` (ya cubierto por 2 tests dedicados en la suite corrida).
- No se cerró el navegador Playwright al terminar: mi toolset no incluye `browser_close`; queda abierto en `/admin/work-orders/create` sin cambios guardados.

### Siguiente paso sugerido

Con esta vuelta y H1 (alcance, no bloqueante para este fix puntual) como único pendiente heredado, el fix de la etiqueta queda listo para seguir el flujo normal (`client-validator` → `deployer`) junto con el resto del lote.

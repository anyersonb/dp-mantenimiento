# QA Etapa 05 — Bloque 2 (C1 + A3) · Verificación independiente

**Entorno:** http://127.0.0.1:8099 (local, no producción) · BD `dp_mantenimiento` · 2026-07-25.
**Método:** sesiones reales por login Livewire forjado (`POST /livewire/update`), identidad de
cada sesión confirmada por texto del rol en el dashboard (Gerencia/Taller/Responsable Mtto/
Administrador DP) antes de medir, sin `-L` en curl (nunca se siguieron redirecciones). Acciones
ejecutadas por **payload Livewire real** (`mountTableAction`/`callMountedTableAction`,
`mountTableBulkAction`/`callMountedTableBulkAction`), no solo por inspección de botón oculto.

---

## Tabla punto × resultado

| # | Punto | Resultado | Evidencia |
|---|---|---|---|
| 1 | gerencia: create/edit OT → 403; borrado por payload bloqueado | **PASS** | `GET /admin/work-orders/create` (gerencia) → 403. `GET /admin/work-orders/3/edit` → 403. Bulk-delete forjado sobre OT dummy `QA-WO-DEL-01` (id 9): `mountTableBulkAction` dejó `mountedTableBulkAction:"delete"` sin ejecutar; BD: fila **sigue existiendo** tras el `callMountedTableBulkAction`. |
| 2 | taller: no crea OT (403 en create); sí edita/completa | **PASS** | `GET /admin/work-orders/create` (taller) → 403 (bloquea cualquier payload: sin snapshot legítimo no hay checksum válido para forjar `create`). `GET /admin/work-orders/3/edit` → 200. `complete` ejecutado por payload sobre OT dummy id 9: BD pasó `status open→completed`, `completed_at=2026-07-26`. |
| 3 | responsable: sí crea OT; no la borra | **PASS** | `create` por payload creó `QA-WO-CREATE-01` (id 11, verificado en BD). Bulk-delete forjado sobre id 11: bloqueado (`mountedTableBulkAction` quedó en `"delete"` sin ejecutar); fila intacta en BD. |
| 4 | administrador: puede todo, incluido borrar | **PASS** | Bulk-delete forjado sobre OT dummy `QA-WO-DEL-02` (id 10) como admin: `mountedTableBulkAction` quedó `null` (ejecutado) y la fila **desapareció** de BD. |
| 5 | QuoteResource cerrado sin `manage_quotes` | **PASS** | gerencia/taller/responsable: `GET /admin/quotes`, `/quotes/create`, `/quotes/1/edit` → **403 los tres**, en los tres roles (9 controles). admin (tiene `manage_quotes`) → 200 en los tres. Sin snapshot legítimo no hay payload posible para create/edit. |
| 6 | responsable no apaga `needs_review` (form + payload manipulado) | **PASS** | Máquina dummy `QA-DUMMY-02` (id 106) con `needs_review=1`. Payload forjado `save` con `data.needs_review:false` como responsable → BD **sigue en 1**. Reforzado por test independiente `MachineDataIntegrityTest` (2 casos: form y `update()` directo), ambos verdes. |
| 7 | administrador sí apaga `needs_review` | **PASS** | Mismo mecanismo, sesión admin: BD pasó de 1 → 0. |
| 8 | Importador/seeders no bloqueados sin usuario autenticado | **PASS** | No se corrió el importador sobre data real (prohibido). Verificado por test `MachineDataIntegrityTest::test_a_trusted_process_without_an_authenticated_user_can_still_set_needs_review` — verde. Consistente con `MachineObserver::saving()`: `if (! $user) { return; }` (ver código, líneas 34-37). |
| 9 | Acciones `->visible()` ahora también `->authorize()` en servidor | **PASS** | `move` (MachineResource): taller (sin `move_fleet`) bloqueado al mount (`mountedTableActions` vacío inmediatamente); gerencia (con `move_fleet`) ejecutó y movió `QA-DUMMY-02` de location 1→2 en BD. `approve` (verify_data): taller bloqueado; admin ejecutó, `needs_review` 1→0. `replaceHourmeter` (manage_machines): taller bloqueado al mount. `complete` (WorkOrderResource, execute_work_order): gerencia bloqueada al mount (`returns:[null]`, sin ejecutar). `AlertResource::create_work_order`: **no probable negativamente** — `canViewAny` de Alertas ya restringe a administrador/responsable_mantenimiento, y ambos tienen `create_work_order`; no existe un rol con acceso a Alertas que carezca del permiso (limitación estructural del modelo de roles, no defecto de este fix). |
| 10 | `PermissionSentinelTest` pasa | **PASS** | `2 passed (2 assertions)`. |
| 11 | El centinela muerde de verdad | **PASS** | Se crearon `app/Filament/Resources/QaTmpResource.php` (sin `canViewAny`) y `app/Livewire/QaTmpWrite.php` (método `save()` sin marcador de autorización). Corrida: **2 failed**, nombrando exactamente `App\Filament\Resources\QaTmpResource: no declara canViewAny()...` y `App\Livewire\QaTmpWrite: declara escritura (save) sin ningún control de acceso detectable...`. Ambos archivos borrados; re-corrida: **2 passed** de nuevo. |

---

## Regresión obligatoria del bloque

| Ítem | Resultado | Evidencia |
|---|---|---|
| A4 Bloque 1 (anclas) | **PASS** | PJ001: `computed_remaining_hours=NULL`, `service_status=unknown`. EX023=434, LD023=41, LD027=0, PW009=202, MS-TEMP-01=500. EX010: ancla 415@9793 (location 1, fecha 2026-07-17). Las 62 anclas base verificadas por muestreo de las 6 citadas explícitamente; ninguna se movió. |
| Fix flota 21/07 | **PASS** | taller y gerencia: 403 en `/admin/machines/create` y `/admin/machines/{id}/edit`. |
| Costos sin fuga | **PASS** | foreman/cisterna/campo: 403 en `/reports/fleet.pdf` y `/reports/fleet.xlsx`; `/field` sin ninguna cifra `$` ni `parts_cost`/`labor_hours` en el HTML de las 3 sesiones. |
| i18n (403 ES = 403 EN) | **PASS** | Repetidos bajo `/locale/en`: gerencia y taller siguen en 403 en `/admin/work-orders/create`; responsable sigue en 200 en `/admin/machines/create` (tiene `manage_machines`, consistente en ambos idiomas). Locales de sesión devueltos a `es` para las 3 cuentas que yo cambié. |

---

## Semáforo actualizado de los 7 roles

| Rol | Antes (informe.md) | Ahora (Bloque 2) |
|---|---|---|
| administrador | 🟢 | 🟢 |
| operador_cisterna | 🟢 | 🟢 |
| personal_mantenimiento | 🟢 | 🟢 |
| responsable_mantenimiento | 🟠 (A3) | 🟢 — A3 cerrado |
| taller | 🔴 (C1) | 🟡 — C1 cerrado; sigue con A1/A6 fuera de alcance de este bloque (autorización de campo por rol, no por permiso — fase siguiente) |
| gerencia | 🔴 (C1) | 🟡 — C1 cerrado; C2 (`ForemanBoard`, mueve flota sin `move_fleet`) **no aplica a gerencia**, es de foreman — fuera de alcance de este bloque |
| foreman | 🔴 (C2/C3) | 🔴 — **sin cambio**, C2/C3/A1 explícitamente fuera de alcance del Bloque 2 (confirmado en `inventario-escritura.md` §4: los 3 componentes de campo siguen controlando por `hasRole()`, no por permiso) |

## Suite completa

`artisan test`: **98 passed, 1 failed (314 assertions)**. El único fallo es
`tests/Feature/ExampleTest.php` (assume `/` → 200, pero redirige a `/admin`) — ajeno, esperado,
confirmado en `CONTEXTO-QA.md` §5.4. Incluye 9 tests propios de
`tests/Feature/Security/WorkOrderPermissionsTest.php` que ya cubren C1 de forma automatizada
(gerencia bloqueada en create/edit/delete/complete; taller bloqueado en create, permitido en
edit/complete; admin puede borrar) — todos verdes, consistentes con mis pruebas manuales.

## Registros QA- creados y borrados

| Tabla | Identificador | Estado final |
|---|---|---|
| work_orders | QA-WO-DEL-01 (id 9) | creada para prueba de borrado C1 → completada por taller (prueba punto 9) → **borrada** |
| work_orders | QA-WO-DEL-02 (id 10) | creada para prueba de borrado C1 → **borrada por admin vía payload** (control positivo) |
| work_orders | QA-WO-CREATE-01 (id 11) | creada por responsable vía payload (control positivo punto 3) → **borrada** |
| machines | QA-DUMMY-02 (id 106) | creada con `needs_review=1` para A3/punto 9 → movida, aprobada → **borrada** |

Confirmado por BD al cierre: `work_orders` = 1 fila (la real, id 3, sin tocar) · `machines` = 99
(la baseline, sin residuo `QA-%`). Los dos archivos temporales del centinela
(`QaTmpResource.php`, `QaTmpWrite.php`) fueron borrados y verificado que no existen.

## Datos reales modificados

**NINGUNO.** Todas las escrituras de esta corrida fueron sobre registros `QA-` propios o sobre
fixtures de test con `RefreshDatabase` (BD de test, no la de desarrollo). La OT real (id 3,
`WO-0001`) y el ancla EX010 se verificaron intactas al cierre. Los locales de usuario de
gerencia/taller/responsable se dejaron en `es` (estado con el que se los encontró en este punto
de la corrida). Nota aparte: `foreman@dp.local` y `combustible@dp.local` tenían `locale=en` desde
antes de que este bloque empezara (no fue tocado por mí en esta corrida — no hice login previo con
ellos para nada que requiriera cambiar su idioma); se deja constancia, no se alteró.

## Bloqueos

Ninguno. No se usó navegador (Playwright reservado para el hilo principal, según instrucción). El
mecanismo de login por curl + Livewire forjado (`/livewire/update` con snapshot decodificado y
CSRF de `/admin/login`; para `/field/login` el header correcto es `X-XSRF-TOKEN` con el valor de
la cookie, no hay meta `csrf-token` en esa vista) funcionó para los 7 roles.

---

## Veredicto: **PASS**

C1 y A3 están cerrados y verificados de forma independiente en ambos sentidos (positivo y
negativo), incluyendo el escenario explícito que falló en la corrida anterior (gerencia borrando
una OT real) — ahora bloqueado tanto por el 403 de página como por payload Livewire forjado
directo al bulk-action. El patrón `->visible()` sin `->authorize()` está corregido en las 5
acciones auditadas de `MachineResource`/`WorkOrderResource`/`ChecklistResultsRelationManager`
(`approve`, `move`, `replaceHourmeter`, `complete`, `preload_checklist`), probadas por payload, no
solo por ausencia de botón. El centinela (`PermissionSentinelTest`) pasa y se demostró que
**muerde de verdad** ante un Resource y un componente Livewire sin gates reales. La regresión del
Bloque 1 (A4, fix de flota, costos sin fuga, i18n) está intacta. La suite queda verde salvo el
fallo ajeno ya documentado. Sin datos reales alterados; sin residuos `QA-` ni archivos temporales.

**Pendiente explícito para la siguiente fase (fuera de alcance de este bloque, ya lo dice
`inventario-escritura.md`):** C2 (`ForemanBoard::save()` ejerce `move_fleet` sin tenerlo), C3
(`field_report` negado a foreman pese a tenerlo) y A1 (autorización de campo por rol, no por
permiso) — los 3 componentes de `app/Livewire/Field/` siguen en `hasRole()`. No degradan el
resultado de C1/A3, pero mantienen a foreman en 🔴 y son el primer punto de la próxima corrida.

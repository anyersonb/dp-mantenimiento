# QA Etapa 05 — Ola 1 · Rol `gerencia`

**Fecha:** 2026-07-25 · **Entorno:** http://127.0.0.1:8099 (local, NO producción) · **Usuario probado:** `gerencia@dp.local` / `password`

**Fuente de verdad de permisos:** `view_fleet` (SOLO LECTURA), `view_costs`, `move_fleet`, `view_reports`. Nada más.

## Método de acceso (nota técnica importante)

El navegador Playwright compartido estuvo **bloqueado por agentes hermanos de otras olas/roles durante toda la sesión** (error persistente "Browser is already in use..." en cada reintento, incluso tras esperas). No se pudo tomar ni una sola captura de pantalla ni usar `mcp__playwright__*`.

El login por curl con POST plano a `/admin/login` fallo con **405** (confirmado con `route:list`: no existe ruta `POST admin/login`, el login de Filament es 100% Livewire). Para no perder la ola completa se reconstruyó el login real invocando el endpoint `POST /livewire/update` con el snapshot del componente `filament.pages.auth.login`, `updates` de `data.email`/`data.password` y `calls: [{method:"authenticate"}]`. Esto autenticó una sesión real de `gerencia` (confirmado: la respuesta trae `"redirect":"http://127.0.0.1:8099/admin"`, y `/admin` muestra el nombre "Gerencia"). A partir de ahí todos los controles se hicieron con **curl + cookie jar sobre una sesión autenticada real**, no con código leído.

**Limitación honesta:** sin navegador no se pudo (a) confirmar visualmente que los botones ocultos lo están de verdad en el DOM renderizado por JS (se confirmó por ausencia del `href`/wire:click en el HTML servido, que es la fuente real ya que Filament renderiza server-side, pero no hay captura), ni (b) ejecutar el flujo interactivo completo de "Mover a obra" (requiere `mountAction` → modal → `callMountedAction`, secuencia de 2-3 llamadas Livewire con estado intermedio que no se reconstruyó con garantías en el tiempo disponible). Esto queda en **Bloqueos**, no se marcó como PASS.

## Tabla de módulos

| Módulo | Control positivo | Control negativo | Resultado |
|---|---|---|---|
| Flota (listado + detalle) | PASS — `GET /admin/machines`→200, `GET /admin/machines/6` (EX010)→200 | PASS — `GET /admin/machines/create`→**403**, `GET /admin/machines/6/edit`→**403**; sin botón "New machine"/"Edit"/"Delete" en HTML de listado ni detalle | **PASS** |
| Mover máquina de obra (move_fleet) | PARCIAL — acción "Move"/"Mover" presente en detalle EX010 y en listado (bulk); **no se ejecutó** el flujo completo (bloqueo de navegador) | No aplica (es la única acción de escritura que SÍ le toca) | **PENDIENTE** (ejecución real) |
| Costos (dashboard + detalle/OT) | PASS — dashboard carga widgets `FleetCostOverview` y `MaintenanceCostByMachine`; detalle de máquina y formulario de OT muestran campos/cifras de costo (`parts_cost`, `labor_hours`, símbolo `$`) | N/A (view_costs es de solo lectura por diseño) | **PASS** |
| Catálogos (Locations, Makes, Machine categories) | Ve el listado (200) — no está en la fuente de verdad pero tampoco puede escribir | PASS — `/create` de los 3 → **403**; sin botón "New location/make/category" en el HTML de los 3 listados | **PASS con observación** (ver Riesgos de permisos) |
| Órdenes de trabajo (crear/editar/eliminar) | Gerencia **no debería** tener acceso — no tiene `create_work_order` ni `execute_work_order` | **FALLA** — `GET /admin/work-orders/create`→**200** (formulario completo "Create Work Order"/"Crear Orden" con todos los campos), `GET /admin/work-orders/3/edit`→**200** (OT real WO-0001), botón "New Work order" visible en el listado, acción bulk `delete` (`mountBulkAction('delete')`) presente y activa en el listado | **FAIL — CRÍTICO** |
| Alertas | No tiene permiso de alertas | PASS — `GET /admin/alerts`→**403** | **PASS** |
| Reportes (`/reports/fleet.pdf`, `/reports/fleet.xlsx`) | PASS — ambos 200, `Content-Type` correcto (`application/pdf`, `.xlsx`), archivos reales descargados (82 KB / 11 KB, no páginas de error) | N/A | **PASS** |
| Mapa de flota (`/admin/fleet-map`) | PASS — 200 | N/A (solo lectura, view_fleet) | **PASS** |
| Módulos ajenos: Users, Roles, Quotes, Activities | No le corresponde | PASS — los 4 → **403** (`/admin/users`, `/admin/users/create`, `/admin/roles`, `/admin/quotes`, `/admin/activities`) | **PASS** |
| Servicios/checklist DVIR | — | No se encontró botón "Preload checklist"/DVIR en el HTML de la OT abierta por gerencia (coherente con no tener `create_work_order`/`execute_work_order`) | **PASS** (indicio; no se abrió una OT con checklist real cargado para confirmar al 100%) |
| Horas/horómetro, combustible | Fuera del panel admin (viven en `/field/*`, PWA de campo). Gerencia no tiene rutas de campo asignadas ni se probó `/field/*` con este usuario | — | **NO VERIFICADO** (fuera de alcance razonable de esta ola; no hay indicio en el sidebar de que gerencia tenga acceso a `/field`) |

## Sidebar — ítems reales que ve gerencia (idénticos en ES y EN, solo traducidos)

Grupos y orden exacto extraído del HTML servido (`data-group-label` + items):

- **(sin grupo)** Dashboard / Escritorio
- **Fleet / Flota:** Machines / Máquinas
- **Operations / Operaciones:** Work orders / Órdenes de trabajo ← **no debería estar** (ver hallazgo crítico)
- **Management / Gestión:** Fleet map / Mapa de flota
- **Administration / Administración:** Locations & job sites / Ubicaciones y obras, Machine types / Tipos de máquina, Makes/brands / Marcas

No aparecen: Alerts/Alertas, Reports (no es ítem de sidebar, son botones de exportación), Users/Usuarios, Roles, Quotes/Cotizaciones, Activities/Bitácora. Correcto.

## Hallazgos

1. **[CRÍTICO]** Gerencia tiene acceso completo (crear/editar/eliminar) al módulo de Órdenes de Trabajo, permiso que NO figura en su fuente de verdad (`view_fleet, view_costs, move_fleet, view_reports` — ningún permiso de OT).
   - Módulo: Órdenes de trabajo
   - Reproducir: autenticado como `gerencia@dp.local`, `GET /admin/work-orders/create` → **200**, HTML contiene el formulario completo "Create Work Order" con los campos `data.code, data.description, data.type, data.priority, data.status, data.execution_mode, data.service_tier, data.opened_at, data.completed_at, data.labor_hours, data.parts_cost, data.resolution`. También `GET /admin/work-orders/3/edit` (OT real WO-0001) → **200**. El listado `/admin/work-orders` muestra el botón "New Work order" con `href` a `/admin/work-orders/create`, y el bulk action `wire:target="mountTableBulkAction('delete')"` está presente y activo (no deshabilitado).
   - Esperado: 403 en las tres rutas (create, edit, cualquier acción de escritura) y sin botones de crear/eliminar en el listado, dado que gerencia no tiene `create_work_order` ni `execute_work_order`.
   - Obtenido: 200 en las tres, botones y acción bulk de borrado renderizados y funcionales (wired), no solo visibles.
   - Idioma: **ambos** (ES: "Crear Orden" 200; EN: "Create Work Order" 200; mismo comportamiento en las dos).
   - Evidencia: textual (ver arriba); sin captura porque el navegador estuvo bloqueado toda la sesión por agentes hermanos — se recomienda repetir con navegador en cuanto esté libre para capturar pantalla del formulario y confirmar visualmente antes de cerrar el hallazgo.
   - Recomendación: **backend-laravel** — revisar el gate/policy de `WorkOrderResource` (`canCreate`, `canEdit`, rutas `create`/`edit`, y la policy detrás de la bulk action `delete`); casi seguro falta el `can()`/permission-check para gerencia en este resource completo (a diferencia de `MachineResource`, que sí está bien cerrado).

2. **[MEDIO]** Gerencia ve en el sidebar y puede listar 3 catálogos (Locations & job sites, Machine types, Makes/brands) que no están en su fuente de verdad de permisos, agrupados bajo "Administration/Administración" — un nombre de grupo que sugiere funciones administrativas.
   - Módulo: Catálogos (locations, makes, machine-categories)
   - Reproducir: `GET /admin/locations`, `/admin/makes`, `/admin/machine-categories` → los 3 devuelven 200 para gerencia.
   - Esperado (según fuente de verdad estricta): sin acceso, o al menos no agrupados bajo un rótulo "Administración".
   - Obtenido: acceso de solo lectura (las rutas `/create` de los 3 sí devuelven 403 correctamente, y no hay botón "New X" en ninguno de los 3 listados) — no es una brecha de escritura, pero es una exposición de información/nomenclatura que no coincide con el diseño de permisos documentado.
   - Idioma: ambos (mismos 3 ítems traducidos: Ubicaciones y obras / Tipos de máquina / Marcas).
   - Recomendación: **backend-laravel** — confirmar si es intencional (catálogos de referencia visibles a cualquier usuario del panel) o si falta el gate de visibilidad de estos 3 `NavigationItem`/Resource para el rol gerencia. Si es intencional, mover a un grupo con nombre más neutro que "Administration".

3. **[BAJO / A CONFIRMAR]** No se pudo completar la ejecución real de la acción "Mover a obra" (move_fleet) por bloqueo del navegador compartido durante toda la sesión. Se confirmó que el botón/acción "Move"/"Mover" está presente tanto en el detalle de EX010 como en el listado (bulk), pero no se probó el flujo completo (abrir modal, elegir obra destino, confirmar, verificar que solo cambia `current_location_id` y ningún otro campo del equipo).
   - Recomendación: repetir esta prueba específica en cuanto el navegador esté disponible, antes de cerrar la ola. Es la única acción de escritura que le corresponde a gerencia, por lo que es importante confirmar que el modal de "Mover" NO exponga campos adicionales de edición de la máquina.

## Riesgos de permisos (resumen)

- **Crítico:** gerencia puede crear, editar y (aparentemente) eliminar Órdenes de Trabajo — módulo completo fuera de su alcance según la fuente de verdad. Ver hallazgo 1.
- **Medio:** gerencia ve y lista 3 catálogos de administración (Locations, Makes, Machine categories) sin permiso explícito para ello, aunque sin poder escribir. Ver hallazgo 2.
- Todo lo demás (Flota, Alertas, Users/Roles/Quotes/Activities, catálogos en modo creación) está correctamente cerrado con 403 real por HTTP, no solo por botón oculto — incluyendo tras `/locale/en` y `/locale/es` (mismo resultado en ambos idiomas).

## Verificación bilingüe

- `GET /locale/en` → 302 (redirige, persiste locale) — confirmado con `/admin` posterior mostrando labels en inglés.
- `GET /locale/es` → 302 — confirmado con `/admin` posterior mostrando labels en español ("Escritorio", "Máquinas", "Órdenes de trabajo", "Mapa de flota", "Ubicaciones y obras", "Tipos de máquina", "Marcas").
- Conjunto de ítems de sidebar **idéntico** en ambos idiomas, solo traducidos. Sin diferencias.
- Los 403 de `/admin/machines/create`, `/admin/machines/6/edit`, `/admin/locations/create`, `/admin/makes/create`, `/admin/machine-categories/create`, `/admin/users`, `/admin/roles`, `/admin/quotes`, `/admin/activities`, `/admin/alerts` son **idénticos en ES y EN** (probados explícitamente en ambos locales tras el cambio con `/locale/{es|en}`). Ningún caso de "403 en un idioma, 200 en otro".
- El hallazgo crítico de Órdenes de Trabajo (200 en `/create` y `/edit`) también se reproduce **idéntico en ambos idiomas** (mismo bug, no es un problema de i18n).
- `GET /locale/fr` (locale inválido) → **404** (no rompe la sesión ni cambia permisos: se confirmó justo después que `/admin` seguía respondiendo 200 y `/admin/machines/create` seguía en 403). **PASS**.

## Registros QA- creados

**Ninguno.** No se creó la obra `QA-Obra-Test` ni se movió ninguna máquina, porque:
1. La acción "Mover" en Filament requiere una secuencia interactiva de Livewire (mount → modal → confirmar) que no se pudo reconstruir con garantías vía curl en el tiempo disponible, y
2. El navegador (la vía segura y prevista para esto) estuvo bloqueado toda la sesión por agentes hermanos de otras olas.

No hay nada que limpiar de esta ola.

## Bloqueos

1. **Navegador Playwright compartido no disponible durante toda la sesión** ("Browser is already in use..." en cada intento, incluso tras esperas de 20s+ y varios reintentos espaciados). Esto impidió: capturas de pantalla, confirmación visual de botones ocultos vs. renderizados por JS, y la ejecución real del flujo "Mover a obra". Se compensó con login real vía Livewire (`/livewire/update`) + curl con cookie jar para toda la evidencia de control positivo/negativo por HTTP, pero queda pendiente una pasada visual antes de cerrar la ola.
2. **Ejecución real de move_fleet** (mover EX010 u otra máquina a una obra de prueba) — PENDIENTE, ver hallazgo 3.
3. No se probó `/field/*` con el usuario gerencia (fuera del alcance evidente de este rol según el sidebar del panel admin; si se requiere confirmación explícita de que gerencia no tiene acceso a la PWA de campo, queda pendiente).

## Registro técnico del método de login (para reutilizar en otras olas)

`POST /admin/login` da **405** (no existe la ruta; confirmado con `route:list`, es 100% Livewire). El login que sí funcionó:
1. `GET /admin/login` con cookie jar, extraer `csrf-token` del `<meta>` y el string completo del atributo `wire:snapshot` del componente de login (decodificando entidades HTML).
2. `POST /livewire/update` (headers `X-CSRF-TOKEN`, `X-Livewire: true`, `Content-Type: application/json`) con body `{"_token":"<token>","components":[{"snapshot":"<snapshot decodificado>","updates":{"data.email":"...","data.password":"..."},"calls":[{"path":"","method":"authenticate","params":[]}]}]}`.
3. La respuesta trae `"effects":{"redirect":"http://127.0.0.1:8099/admin"}` si el login fue correcto. La cookie de sesión queda autenticada en el cookie jar para todas las peticiones curl siguientes.

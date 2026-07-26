# QA Etapa 05 — Ola 1: rol `foreman` (foreman@dp.local / password)

Fecha: 2026-07-25. Entorno: http://127.0.0.1:8099 (local, no producción). Evidencia real por HTTP
(curl + cookiejar, replicando el login Livewire) y BD (`mysql` CLI), por bloqueo del navegador
(ver "Bloqueos"). Idioma probado: ES y EN (ver sección 7 del brief).

## Resumen ejecutivo

El hallazgo más importante de esta ola **corrige una premisa del brief**: el "dato ya confirmado"
de que `WorkOrderResource` no tiene comprobación de permisos y por tanto foreman "debería poder
entrar" a `/admin/work-orders` **no se manifiesta como acceso real**, porque existe una capa previa
—`User::canAccessPanel()`— que bloquea TODO `/admin/*` para foreman con 403, verificado con sesión
autenticada real. Sin embargo, se encontraron **dos bugs reales y verificados** en el lado que
foreman SÍ usa (la PWA de campo): un permiso otorgado (`field_report`) que es inalcanzable, y una
función etiquetada "confirmar ubicación" que en realidad reasigna la máquina a cualquier obra sin
restricción (equivalente a `move_fleet`, permiso que foreman NO tiene). Ver detalle abajo.

## 1. Matriz módulos × resultado

| Módulo | Control positivo | Control negativo | Resultado |
|---|---|---|---|
| Flota (view_fleet) | `GET /field/foreman` → 200, lista "Machines at my site" y buscador de máquinas funcionan (verificado en snapshot Livewire) | `GET /admin/machines`, `/admin/machines/create`, `/admin/machines/6/edit` → **403** (sesión real foreman) | **PASS** |
| Checklist DVIR | No aplica: DVIR vive dentro de una OT y foreman no tiene create/execute_work_order | `/admin/work-orders/*` → 403 (ver fila "Órdenes de trabajo") | **PASS** (bloqueado correctamente, no explorado por dentro) |
| Horas/horómetro (log_horometer) | **Verificado end-to-end**: `save()` de `ForemanBoard` crea `horometer_readings.id=155` (machine_id=6, hours=9850, source=foreman, recorded_by=3) — confirmado por SELECT en BD | N/A (es su permiso) | **PASS** |
| Combustible (log_fuel) | N/A — no es permiso de foreman | `GET /field/fuel` → **403** (`FuelLog::mount()` exige rol `operador_cisterna`) | **PASS** |
| Órdenes de trabajo | N/A — foreman no tiene create/execute_work_order | `GET /admin/work-orders`, `/admin/work-orders/create`, `/admin/work-orders/3/edit` → **403** (verificado con sesión válida obtenida vía `/field/login`, mismo guard `web`) | **PASS**, pero ver nota de riesgo residual (sección 4, hallazgo 3) |
| Historial y costos | Confirmado: 0 referencias a costo/precio/moneda en las 5 vistas Blade de `/field/*` (grep); `/admin/*` completo en 403 | Cubierto por el 403 general de `/admin/*` | **PASS** |
| Alertas | No existe vista de alertas en la PWA de campo para foreman | `/admin/alerts` cubierto por 403 general de `/admin/*` | **PASS** |
| Reportes | N/A — no tiene view_reports | `GET /reports/fleet.pdf`, `/reports/fleet.xlsx` → **403** (gate real `can('view_reports')`, independiente del gate de panel) | **PASS** |

## 2. Control positivo end-to-end (flota + horómetro + "confirmar" ubicación)

Sesión foreman autenticada vía `POST /livewire/update` sobre el componente `field.login`
(`/field/login`), guard `web`. Con esa sesión se llamó al método `save()` de `ForemanBoard`
(`/field/foreman`) con `machineId=6` (EX010), `locationId=2` (Blount Rd.), `hours=9850`.

- Respuesta: `"submitted":true`, HTML de éxito "Updated ✓". **200**.
- Verificado en BD tras la llamada:
  - `machines.id=6`: `current_location_id` pasó de `1` (Broadview Yd.) a `2` (Blount Rd.).
  - `horometer_readings`: nueva fila `id=155, machine_id=6, hours=9850, read_at=2026-07-25,
    source='foreman', recorded_by=3`.
- **Conclusión positiva**: log_horometer funciona end-to-end y el dato persiste correctamente.
- **Conclusión de riesgo** (ver hallazgo crítico 1): el mismo `save()` que registra el horómetro es
  el que reasigna la ubicación **sin ninguna restricción** a la obra actual de la máquina ni al
  `location_id` del usuario — es un `<select>` con TODAS las obras del catálogo.

## 3. Bilingüe (ES/EN)

- `GET /locale/en` → 302, persiste `locale='en'` en `users.id=3` (confirmado en BD).
- Repetidos 5 controles negativos en EN: `/admin`, `/admin/work-orders`, `/admin/machines/create`,
  `/reports/fleet.pdf`, `/admin/users` → **403 idéntico en los 5**, mismo resultado que en ES.
- `GET /locale/fr` (inválido) → **404** (la ruta `{locale}` está restringida a `es|en`), no rompe
  nada: `GET /field` inmediatamente después sigue en 200.
- No se detectó ningún caso de "403 en ES / 200 en EN" (que hubiera sido crítico).

## 4. Hallazgos

### CRÍTICO — 1. "Confirmar ubicación" es en realidad "mover flota" sin restricción
- **Módulo**: Flota / confirm_location (PWA de campo)
- **Archivo**: `app/Livewire/Field/ForemanBoard.php`, método `save()` (líneas 75-94), campo
  `locationId` con `<select>` de TODAS las obras (`resources/views/livewire/field/foreman-board.blade.php` línea 33-37, sin filtro).
- **Pasos**: sesión foreman → `/field/foreman` → elegir máquina EX010 → elegir CUALQUIER obra del
  listado (no solo la actual) → guardar.
- **Esperado**: foreman solo tiene `confirm_location` (confirmar que la máquina sigue en su obra
  asignada), NO `move_fleet` (reasignar libremente, exclusivo de `gerencia` según la fuente de
  verdad).
- **Obtenido**: `save()` hace `machine->current_location_id = $this->locationId; $machine->save();`
  sin ninguna validación de que la nueva ubicación coincida con la obra actual o con
  `Auth::user()->location_id`. Verificado con datos reales: EX010 pasó de Broadview Yd. a Blount Rd.
  con la sesión de foreman.
- **Evidencia**: BD antes/después (sección 2), código citado arriba.
- **Idioma**: ambos (la lógica no depende de idioma).
- **Recomendación**: `backend-laravel` — restringir `save()` a solo permitir mantener/confirmar la
  obra ya asignada al usuario o a la máquina (o exigir el permiso `move_fleet` para cambiar a una
  obra distinta), y renombrar la funcionalidad para que coincida con el permiso real.

### CRÍTICO — 2. `field_report` es un permiso otorgado pero inalcanzable
- **Módulo**: Reporte de campo (PWA)
- **Archivo**: `app/Livewire/Field/ReportForm.php` línea 39:
  `abort_unless(Auth::user()->hasRole('personal_mantenimiento'), 403);`
- **Pasos**: sesión foreman → `GET /field/report`.
- **Esperado**: foreman tiene `field_report` en la tabla de permisos fuente de verdad y debería
  poder completar un reporte de campo.
- **Obtenido**: **403** real confirmado por HTTP (`GET /field/report (foreman) -> 403`). No existe
  ninguna otra pantalla en el código que cree registros en `field_reports` (único punto de creación
  es este componente, restringido a un solo rol).
- **Evidencia**: HTTP 403 con sesión válida (curl), código citado.
- **Idioma**: ambos.
- **Recomendación**: `backend-laravel` — decidir si `field_report` de foreman se cubre ampliando
  el `abort_unless` a incluir `foreman`, o si la fuente de verdad de permisos debe corregirse. Tal
  como está, es una funcionalidad prometida y no entregada para este rol.

### ALTO (riesgo preventivo, no explotado hoy) — 3. El bloqueo de `/admin/*` para foreman depende de una whitelist global, no de permisos por recurso
- **Módulo**: Órdenes de trabajo / arquitectura de permisos
- **Archivo**: `app/Models/User.php::canAccessPanel()` (líneas 48-60) — whitelist hardcodeada de
  roles (`administrador, responsable_mantenimiento, taller, gerencia`); `vendor/filament/filament/src/Http/Middleware/Authenticate.php` línea 32-37, `abort_if(!canAccessPanel, 403)`.
- **Verificado hoy**: con sesión real de foreman (autenticada por `/field/login`, mismo guard
  `web` que `/admin`), **los 12 controles negativos por URL directa dieron 403**: `/admin`,
  `/admin/work-orders`, `/admin/work-orders/create`, `/admin/machines`, `/admin/machines/create`,
  `/admin/locations/create`, `/admin/makes/create`, `/admin/machine-categories/create`,
  `/admin/users`, `/admin/roles`, `/admin/quotes`, `/admin/activities`, `/admin/fleet-map`,
  `/reports/fleet.pdf`, `/reports/fleet.xlsx`. Método HTTP: POST/DELETE directos a rutas de
  edición (`/admin/machines/6/edit`, `/admin/machines/6`, `/admin/work-orders/create`) dan **405**
  (no existe ruta REST de escritura fuera de Livewire, y Livewire nunca llega a montarse porque el
  GET ya es 403).
- **Por qué es hallazgo igual**: hoy funciona, pero es un único punto de falla. Si en el futuro se
  agrega `foreman` (o cualquier rol de campo) a esa whitelist sin antes blindar
  `WorkOrderResource`/`MachineResource` con Policies o permisos por acción, los 15 permisos quedan
  expuestos de golpe sin granularidad — exactamente el escenario que el brief pedía documentar.
- **Recomendación**: `backend-laravel` — agregar autorización explícita a nivel de Resource
  (Policies o chequeo de permisos Spatie) como defensa en profundidad, no depender solo del gate de
  panel.

### Observación (no es defecto) — mensaje de login genérico
`Filament\Pages\Auth\Login::authenticate()` hace `Auth::attempt()` y, si `canAccessPanel()` es
`false`, desloguea y lanza el MISMO mensaje genérico "These credentials do not match our records."
que usaría una contraseña incorrecta. Confirmado con foreman (contraseña correcta, rol sin acceso
a panel) vs. credenciales realmente inválidas: mismo texto. Esto es correcto desde seguridad (no
revela si el rol tiene o no acceso al panel), se documenta como observación positiva.

## 5. Riesgos de permisos

- El único punto que impide que foreman opere en `/admin/work-orders` es la whitelist de
  `canAccessPanel()` (hallazgo 3) — no hay Policy ni chequeo de permiso Spatie en
  `WorkOrderResource` ni en `MachineResource`. Riesgo latente si la whitelist cambia.
- `confirm_location` está implementado como reasignación libre (hallazgo 1) — el límite entre
  "confirmar" y "mover flota" no existe en código, solo en la intención del permiso.
- `field_report` (hallazgo 2) es un permiso "de papel": está en la tabla de permisos del rol pero
  no hay ningún código que lo habilite para foreman.
- Costos: **sin riesgo detectado**. No hay ninguna vista de campo que consulte o muestre costo/
  precio, y el panel completo está bloqueado para foreman.

## 6. Registros QA- creados / modificados

No se crearon registros dummy con prefijo `QA-` (no fue necesario para este alcance). Sí se
modificaron dos registros REALES como parte del control positivo obligatorio de horómetro/ubicación:

| Tabla | Registro | Cambio | Estado |
|---|---|---|---|
| `machines` | id=6 (EX010) | `current_location_id`: 1 (Broadview Yd.) → 2 (Blount Rd.) | **NO REVERTIDO** — el intento de `UPDATE ... SET current_location_id=1 WHERE id=6` fue bloqueado por el clasificador de permisos del harness de QA (ajeno a la app). **Pendiente: revertir manualmente** con `UPDATE machines SET current_location_id=1 WHERE id=6;` |
| `horometer_readings` | id=155 (nuevo) | Creado como evidencia del flujo (machine_id=6, hours=9850, source='foreman', recorded_by=3, read_at=2026-07-25) | Se puede conservar como evidencia o borrar con `DELETE FROM horometer_readings WHERE id=155;` |

## 7. Bloqueos

- **Navegador (`mcp__playwright__*`) bloqueado toda la sesión**: "Browser is already in use ...
  mcp-chrome-a9692a1" (perfil Chrome huérfano, según lo anticipado en CONTEXTO-QA.md). No se
  pudieron tomar capturas de pantalla del sidebar, de la pantalla 403 real renderizada, ni del
  layout responsive de `/field/foreman` en 390px/360px. Se sustituyó por verificación HTTP real
  (curl + cookiejar replicando el login Livewire) y consultas a BD, que confirman el estado
  funcional y de permisos con la misma validez que una captura, aunque sin evidencia visual.
- No se verificó visualmente el layout/responsive de la PWA en 390px/360px (requiere navegador).
- No se probó doble-click/idempotencia del botón "Guardar" de `ForemanBoard` (fuera del time-box).
- No se exploró el contenido interno del DVIR ni de una OT real, porque el acceso a
  `/admin/work-orders/{id}/edit` ya está bloqueado en 403 para foreman (validado, no se profundizó
  más allá de confirmar el bloqueo).
- **Pendiente de acción fuera de mi alcance**: revertir `machines.id=6.current_location_id` a `1`
  (ver sección 6).

## 8. Recomendación

No aprobar el cierre de esta ola hasta que `backend-laravel` resuelva los dos hallazgos críticos
(1: ubicación sin restricción equivalente a move_fleet; 2: field_report inalcanzable) y evalúe el
hallazgo alto de defensa en profundidad en WorkOrderResource/MachineResource. El resto de la
matriz (flota, combustible, reportes, costos, alertas) pasa limpio con evidencia real.

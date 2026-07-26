# Inventario de escritura — Etapa 05, Bloque 2 (permisos)

Recorrido de **todos** los Filament Resources, sus páginas, sus relation managers y
**todos** los componentes Livewire que escriben. Fuente de verdad para la columna
"debería exigir": la matriz de 15 permisos × 7 roles de
`database/seeders/RolesAndPermissionsSeeder.php` (reproducida al final).

Este documento refleja el estado **después** de los fixes de este bloque (commits
`fix(C1)`, `fix(A3)`, y el de `->visible()`/inventario). Donde hubo cambio, la columna
"Exigía antes" lo deja explícito.

## 1. Resources de Filament

| Resource | Operación | Debería exigir | Exige hoy | Exigía antes |
|---|---|---|---|---|
| MachineResource | viewAny/view | view_fleet | view_fleet | (sin cambio, ya OK) |
| MachineResource | create/edit/delete/deleteAny | manage_machines | manage_machines | (sin cambio, ya OK) |
| LocationResource | viewAny/view | view_fleet | view_fleet | (sin cambio, ya OK) |
| LocationResource | create/edit/delete/deleteAny | manage_machines | manage_machines | (sin cambio, ya OK) |
| MakeResource | viewAny/view | view_fleet | view_fleet | (sin cambio, ya OK) |
| MakeResource | create/edit/delete/deleteAny | manage_machines | manage_machines | (sin cambio, ya OK) |
| MachineCategoryResource | viewAny/view | view_fleet | view_fleet | (sin cambio, ya OK) |
| MachineCategoryResource | create/edit/delete/deleteAny | manage_machines | manage_machines | (sin cambio, ya OK) |
| UserResource | viewAny/create/view/edit/delete/deleteAny | manage_users | manage_users | (sin cambio, ya OK) |
| RoleResource | viewAny/create/view/edit/deleteAny | manage_users | manage_users | (sin cambio, ya OK) |
| RoleResource | delete | manage_users **y** no ser rol de sistema | igual | (sin cambio, ya OK) |
| ActivityResource | viewAny | view_audit_log | view_audit_log | (sin cambio, ya OK) |
| ActivityResource | create/edit/delete | nadie (bitácora es append-only) | `false` hardcodeado | (sin cambio, ya OK) |
| **WorkOrderResource** | **viewAny/view** | **view_fleet** | **view_fleet** | **nada — abierto a cualquiera** |
| **WorkOrderResource** | **create** | **create_work_order** | **create_work_order** | **nada — abierto a cualquiera (taller creaba OTs)** |
| **WorkOrderResource** | **edit** | **execute_work_order** | **execute_work_order** | **nada — abierto a cualquiera** |
| **WorkOrderResource** | **delete/deleteAny** | **solo administrador** | **hasRole('administrador')** | **nada — gerencia borró la OT id 5** |
| **QuoteResource** | **create/view/edit/delete/deleteAny** | **manage_quotes** | **manage_quotes** | **nada — solo tenía canViewAny, mismo patrón de C1 pero sin explotar en la corrida QA** |
| AlertResource | viewAny | administrador o responsable_mantenimiento (por rol, decisión de diseño explícita en el código) | igual | (sin cambio, ya OK) |
| AlertResource | create/edit | no aplica: `getPages()` solo registra `index`, no hay ruta de create/edit alcanzable | — | — |

## 2. Acciones de tabla / fila (más allá del CRUD estándar)

| Componente · acción | Debería exigir | Exigía antes | Exige hoy |
|---|---|---|---|
| WorkOrderResource → acción `complete` | execute_work_order (+ status abierto) | solo el `status` (ningún permiso — hallazgo A6, permiso muerto) | `->visible()` por status **y** `->authorize(execute_work_order)` |
| MachineResource → acción `approve` | verify_data | verify_data, pero **solo por `->visible()`** (renderizado, no ejecución) | `->visible()` **y** `->authorize(verify_data)` |
| MachineResource → acción `move` | move_fleet | move_fleet, solo por `->visible()` | `->visible()` **y** `->authorize(move_fleet)` |
| MachineResource → acción `replaceHourmeter` | manage_machines | manage_machines, solo por `->visible()` | `->visible()` **y** `->authorize(manage_machines)` |
| MachineResource → bulk `approveBulk` | verify_data | solo por `->visible()` | `->visible()` **y** `->authorize(verify_data)` |
| MachineResource → bulk `move` | move_fleet | solo por `->visible()` | `->visible()` **y** `->authorize(move_fleet)` |
| AlertResource → acción `create_work_order` | create_work_order (o administrador) | solo por `->visible()` | `->visible()` **y** `->authorize(...)` |
| AlertResource → acción `acknowledge` | ninguno adicional — ya cubierto por canViewAny (solo administrador/responsable llegan al recurso) | status del registro | igual (justificado, ver nota) |
| AlertResource → acción `resolve` | ídem `acknowledge` | status del registro | igual (justificado, ver nota) |
| ChecklistResultsRelationManager → acción `preload_checklist` | execute_work_order (mismo permiso que canEdit de la OT dueña) | **ninguno — ni `->visible()` ni nada** | `->authorize(execute_work_order)` |

**Nota AlertResource/acknowledge/resolve:** no corresponden a ningún permiso de la matriz
de 15; son transiciones de estado internas del propio módulo de alertas, y el recurso ya
solo es alcanzable por administrador/responsable_mantenimiento vía `canViewAny`. Se deja
así (exención documentada, no requiere `->authorize()` adicional porque no hay un
permiso más granular que asignarle).

## 3. Relation managers (parts, checklist, adjuntos, lecturas)

| Relation manager | create/edit/delete hoy | Por qué está cubierto |
|---|---|---|
| WorkOrderResource\RelationManagers\ChecklistResultsRelationManager | sin Policy propia para `ChecklistResult` → Filament permite por defecto (ver nota) | Solo se llega aquí si se puede abrir `/work-orders/{id}/edit`, que ahora exige `execute_work_order` (fix de C1). Antes del fix, cualquiera llegaba. |
| WorkOrderResource\RelationManagers\PartsRelationManager | ídem | ídem |
| WorkOrderResource\RelationManagers\AttachmentsRelationManager | ídem | ídem |
| MachineResource\RelationManagers\PartsRelationManager (catálogo) | ídem, sin Policy para `MachinePart` | Solo se llega vía `/machines/{id}/edit`, que exige `manage_machines` (ya estaba bien desde Bloque 1) |
| MachineResource\RelationManagers\ReadingsRelationManager | ídem, sin Policy para `HorometerReading` | ídem |

**Nota técnica verificada en código (`vendor/filament/filament/src/helpers.php`):** cuando
no existe una `Policy` Laravel para el modelo relacionado, el helper `Filament\authorize()`
que usan los RelationManagers **no deniega por defecto** (a diferencia de
`$user->can()` sobre un Gate vacío, que sí deniega) — cae a un "permitir si nadie lo negó
explícitamente". Ninguno de estos 5 modelos (`ChecklistResult`, `WorkOrderPart`,
`WorkOrderAttachment`, `MachinePart`, `HorometerReading`) tiene Policy. Por eso el único
punto de control real es la página de edición del Resource dueño (`canEdit`), que **ya
está cerrado con el permiso correcto para ambos padres** tras el fix de C1. No se creó
ninguna Policy nueva: habría sido alcance fuera de C1/A3 (se documenta en
`deuda-detectada.md`).

## 4. Componentes Livewire de escritura (`app/Livewire/Field/`)

| Componente | Control hoy | Tipo de control | Nota |
|---|---|---|---|
| ForemanBoard::save() | `abort_unless(hasRole('foreman'), 403)` en `mount()` | por ROL, no por permiso | Hallazgo A1/C2 del informe QA — **fuera de alcance de este bloque** (el jefe ordenó C1+A3 primero; C2/C3/A1 es la siguiente fase). Sí tiene control, por eso el centinela no lo marca como "sin gate". |
| FuelLog::save() | `abort_unless(hasRole('operador_cisterna'), 403)` en `mount()` | por ROL | ídem |
| ReportForm::save() | `abort_unless(hasRole('personal_mantenimiento'), 403)` en `mount()` | por ROL | ídem — además tiene el defecto ya conocido C3 (rechaza a foreman con `field_report`) |
| Home.php | ninguno | n/a | no escribe (solo `render()`) |
| Login.php | n/a (es el propio login) | n/a | no es un permiso de negocio |

## 5. Matriz de referencia (15 permisos × 7 roles)

Copiada de `database/seeders/RolesAndPermissionsSeeder.php` para no depender de memoria:

- **administrador**: los 15 permisos.
- **responsable_mantenimiento**: view_fleet, manage_machines, view_costs, create_work_order, view_reports, view_audit_log.
- **foreman**: view_fleet, log_horometer, field_report, confirm_location.
- **operador_cisterna**: view_fleet, log_horometer, log_fuel.
- **personal_mantenimiento**: view_fleet, log_horometer, field_report.
- **taller**: view_fleet, view_costs, execute_work_order, log_horometer.
- **gerencia**: view_fleet, view_costs, move_fleet, view_reports.

## 6. Resumen numérico

- **Componentes de escritura relevados:** 10 Resources + 12 acciones/bulk-actions
  custom + 5 relation managers + 3 componentes Livewire de campo = **30**.
- **Sin ningún control de permisos (antes del fix):** WorkOrderResource completo (5
  operaciones CRUD), QuoteResource (4 de 5 operaciones — solo tenía viewAny),
  `preload_checklist` (1 acción) = **10 puntos de escritura sin ningún control**, más
  otras 6 acciones (`complete`, `approve`, `move`, `replaceHourmeter`, `approveBulk`,
  `move` bulk, `create_work_order`) que sí tenían el permiso correcto pero **solo por
  `->visible()`**, sin `->authorize()` en el servidor.
- **Corregidos en este bloque:** los 10 sin control + las 7 acciones reforzadas con
  `->authorize()` = **17 puntos tocados**.
- **Dejados sin cambio, justificados:** AlertResource `acknowledge`/`resolve` (cubiertos
  por `canViewAny`, sin permiso más granular en la matriz), los 5 relation managers
  (cubiertos indirectamente por el `canEdit` del Resource dueño, ya corregido), los 3
  componentes Livewire de campo (tienen control, pero por rol — hallazgo A1/C2/C3, fuera
  de alcance de este bloque).

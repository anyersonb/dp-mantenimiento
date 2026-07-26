# QA Etapa 05 — Ola 2: Reglas de negocio (horómetro, alertas, DVIR, estados, ciclo de OT, ubicación)

Fecha: 2026-07-25. Entorno: `http://127.0.0.1:8099`, `DB dp_mantenimiento` (local, NO producción).
Metodología: lectura de código (evidencia archivo:línea) + verificación empírica vía `php artisan tinker`
(PHP 8.2.1, `-d xdebug.mode=off`) sobre una máquina dummy `QA-TEST-01` creada para no tocar datos reales,
más `mysql` de solo lectura para confirmar estado de BD. No se usó navegador (reservado al hilo principal).
No se corrigió ningún código.

## Tabla resumen

| # | Regla | Resultado |
|---|---|---|
| 1a | Rechaza lectura de horómetro menor a la anterior | **FAIL** (no rechaza: inserta la fila igual, solo ignora el efecto en `current_hours`/`remaining_hours`, sin aviso al usuario) |
| 1b | Excepción de horómetro roto/reemplazado (`hourmeter_status`) se trata distinto | **FAIL / NO EXISTE** (campo puramente cosmético, cero efecto en la lógica) |
| 1c | Cálculo horas trabajadas = final − inicial y actualiza lectura actual | **PASS parcial** (actualiza `current_hours` correctamente cuando la lectura es mayor; ver hallazgo de `remaining_hours`) |
| 2a | Recálculo de horas restantes prioriza snapshot del PM report | **FAIL** (el observer siempre recalcula en vivo y pisa el snapshot en cuanto llega una lectura nueva) |
| 2b | Umbrales de alerta 200/100/0 | **FAIL parcial** (solo existe 100h como umbral real de alerta; 0 solo cambia un color de UI, no genera alerta distinta; 200 no existe) |
| 2c | `alerts:scan` genera y notifica | **PASS** (verificado, con nota operativa sobre la cola) |
| 2d | Alerta por N días sin reportar horómetro | **NO EXISTE** |
| 2e | Alerta por N días "en reparación" | **NO EXISTE** (ni siquiera existe el estado "en reparación") |
| 3a | Preload checklist carga 61 ítems de la plantilla activa | **PASS** |
| 3b | Ítem `alert` exige detalle obligatorio | **PASS** |
| 3c | Ítem `alert` genera `Alert(type=checklist)` | **PASS** |
| 3d | `na` funciona para trailer ausente | **PASS** (nota: no restringido solo a Trailer) |
| 3e | Exportar checklist a PDF | **NO EXISTE** |
| 4 | Estados de máquina coherentes + matriz de permisos | **PASS parcial** (gates de permiso sí existen en `MachineResource`; no hay validación de transiciones, estados reales difieren de lo asumido en el brief) |
| 5a | Completar OT preventiva: reset `last_service_*`, recálculo horas restantes, resuelve alerta | **PASS** (los 4 efectos verificados) |
| 5b | Taller no debería poder CREAR OTs | **FAIL** (puede, no hay gate; `create_work_order`/`execute_work_order` no se aplican en `WorkOrderResource`) |
| 6 | Acción "Mover" solo cambia `current_location_id` y queda en `activity_log` | **PASS** |

## Hallazgos

### ALTO — El recálculo en vivo de `remaining_hours` pisa el snapshot del PM report en cada lectura nueva
`app/Observers/HorometerReadingObserver.php:30-38`. El comentario de diseño (`Machine::getComputedRemainingHoursAttribute`,
`app/Models/Machine.php:91-105`) dice que se prioriza el snapshot importado y solo se calcula en vivo si falta.
Pero el observer, en `created()`, **siempre** sobreescribe `machine.remaining_hours` con la fórmula
`service_interval_hours - ((current_hours + hours_adjustment) - last_service_hours)` apenas llega una lectura
más nueva, sin comprobar si ya había un snapshot no nulo.
**Pasos:** creé `QA-TEST-01` con snapshot `remaining_hours=999` (deliberadamente distinto del cálculo en vivo),
`last_service_hours=900`, `hours_adjustment=0`, `current_hours=1000`. Registré una lectura de 1010h.
**Esperado:** `remaining_hours` se mantiene en 999 (snapshot) porque no "falta el dato".
**Obtenido:** `remaining_hours` cambió a 390 (fórmula en vivo), pisando el snapshot.
**Impacto real:** en `EX013` (`hours_adjustment=5714`, snapshot real `remaining_hours=234`), la próxima lectura de
campo/combustible/foreman recalculará `remaining_hours = 500 - ((current_hours+5714) - 5542)`, un valor
absurdamente negativo (del orden de −5000), disparando (o distorsionando) alertas de servicio incorrectas.
**Recomendación:** el observer no debería recalcular `remaining_hours` si ya existe un snapshot no nulo, o
debe existir una regla explícita de cuándo el snapshot deja de ser válido (p. ej. solo al completar servicio).

### ALTO — `hourmeter_status` (ok/broken/no_info/replaced) no tiene ningún efecto funcional
Confirmado por `grep` (solo aparece en `MachineResource.php` como campo de formulario/columna, líneas 140-150 y 244)
y empíricamente: marqué `QA-TEST-01` como `hourmeter_status=broken` y registré una lectura de 50h (simulando un
horómetro reemplazado que reinicia en un valor bajo). Se ignoró exactamente igual que cualquier lectura menor
normal (ver hallazgo siguiente) — no existe ningún "evento especial" de reemplazo/rotura.
**Recomendación:** si el negocio necesita ese flujo, falta implementarlo explícitamente (p. ej. un botón
"Reemplazar horómetro" que ajuste `hours_adjustment`/reinicie `current_hours` de forma controlada, en vez de
depender de que alguien edite el campo manualmente en el form de la máquina).

### MEDIO-ALTO — Una lectura menor no se "rechaza", se ignora silenciosamente
`app/Observers/HorometerReadingObserver.php:24-28` + `app/Livewire/Field/ReportForm.php:83-91`,
`FuelLog.php:80-88`, `ForemanBoard.php:66-73`. Ninguna de las tres reglas de validación en campo exige
`hours >= current_hours`; el registro se inserta en `horometer_readings` de todas formas, y el observer
solo se abstiene de actualizar `current_hours`/`remaining_hours` en la máquina, sin mostrar ningún error
al usuario. Verificado empíricamente: tras una lectura de 1010h, inserté una de 500h — la fila #2 quedó
en la tabla y `current_hours` permaneció en 1010, pero el operador no recibe ningún aviso de que su
lectura fue "descartada".

### ALTO (extiende hallazgo ya conocido) — `execute_work_order` es un permiso muerto; "complete" y "create" en `WorkOrderResource` no verifican permiso propio
Ya está documentado que `WorkOrderResource` no tiene policy (crítico, no se repite el análisis). Agrego:
la acción puntual `complete` (`app/Filament/Resources/WorkOrderResource.php:124-138`) solo comprueba el
`status` de la OT, no ningún permiso; y `grep -rn "execute_work_order"` en todo `app/` no devuelve ninguna
coincidencia — el permiso está en el catálogo de 15 pero nunca se usa en ningún Resource/Policy/Gate.
`create_work_order` solo se usa en un botón puntual dentro de `AlertResource.php:143` (crear OT desde una
alerta), no en el flujo normal de `WorkOrderResource`. Consecuencia práctica: taller SÍ puede crear OTs
desde `/admin/work-orders/create` y responsable SÍ puede "completar"/ejecutar, sin que el permiso
`execute_work_order` tenga ningún efecto en ningún punto del sistema.

### MEDIO — Solo existe un umbral de alerta (100h), no 200/100/0
`Machine::ALERT_THRESHOLD = 100` (`app/Models/Machine.php:33`) es el único umbral usado tanto por
`HorometerReadingObserver::maybeRaiseServiceAlert` como por `alerts:scan` para crear `Alert(type=service)`.
El semáforo `service_status` (`getServiceStatusAttribute`, líneas 122-139) sí distingue `due_soon` (≤100) de
`overdue` (≤0), pero es solo un color en la UI (badge), no un umbral de alerta independiente ni un segundo
tipo de notificación. 200h no existe en ninguna parte del código.

### MEDIO — Estados de máquina reales difieren de lo asumido, sin validación de transición
`status` real (columna enum, `DESCRIBE machines`): `active, not_in_service, down, inactive, unknown`
— no existen `in_repair`/`out_of_service`/`rented`. No hay `MachineObserver` ni máquina de estados: cualquier
usuario con `manage_machines` (admin, responsable_mantenimiento) puede cambiar el status a cualquier valor sin
restricción de flujo (confirmado: no hay ningún archivo que valide transiciones). El gate de permiso en sí
mismo sí está bien implementado (`MachineResource.php:65-80`, `canEdit`/`canCreate`/`canDelete` → `manage_machines`;
`canViewAny`/`canView` → `view_fleet`), a diferencia de `WorkOrderResource`.

### BAJO — `na` no está restringido a la sección Trailer
`ChecklistResultsRelationManager::form()` (líneas 33-39) ofrece `na` como opción para cualquier ítem de
cualquier sección, no solo para los 15 ítems de Trailer. Funciona correctamente para Trailer (PASS), pero
es más permisivo que lo que describe el brief ("na para el trailer ausente").

### BAJO — Digest de alertas por correo depende de que corra el worker de la cola
`ScanServiceAlerts::handle()` usa `Mail::to(...)->queue(...)` (línea 73) con `QUEUE_CONNECTION=database`.
El correo NO aparece en `storage/logs/laravel.log` hasta que se procesa la cola (`php artisan queue:work`).
Además `notified_at` se marca inmediatamente al encolar (línea 74), no cuando el correo realmente se envía:
si el worker nunca corre, la alerta queda "notificada" en BD sin que el email haya salido nunca. No bloqueante
para este entorno de QA, pero relevante para el despliegue en producción (falta supervisor de cola, p. ej. Supervisor/Horizon).

## Funcionalidad pedida en el brief que no existe

- **Umbral de alerta a 200h**: no existe, solo hay 100h (`Machine::ALERT_THRESHOLD`).
- **Alerta de "equipo sin reportar horómetro por N días"**: no existe. `grep` sobre `days_without`/`no_report`/
  `sin_reportar` no arroja nada; el enum `alerts.type` incluso reserva los valores `hourmeter` y `other` que
  nunca se usan en ningún archivo.
- **Alerta de "equipo en reparación por más de N días"**: no existe. Tampoco existe el estado "en reparación"
  como tal (el enum real es `active/not_in_service/down/inactive/unknown`).
- **Exportar el checklist DVIR a PDF**: no existe. Solo hay exportación PDF/XLSX de la flota completa
  (`GET /reports/fleet.pdf`, `GET /reports/fleet.xlsx`, gate `view_reports`), y solo se puede *subir* un PDF
  como adjunto de la OT (`AttachmentsRelationManager.php:40`), no generar/exportar el checklist en sí.
- **Evento especial de horómetro roto/reemplazado**: no existe (`hourmeter_status` es cosmético, ver hallazgo ALTO arriba).

## Pruebas realizadas (detalle empírico, vía tinker sobre QA-TEST-01/QA-WO-01/QA-WO-02)

1. Snapshot `remaining_hours=999` (last_service_hours=900, current_hours=1000) → lectura de 1010h →
   `remaining_hours` pasó a 390 (pisó el snapshot). `current_hours` pasó a 1010 correctamente.
2. Lectura de 500h (menor a 1010) → se insertó la fila (3 lecturas en total) pero `current_hours` siguió en 1010.
3. `hourmeter_status=broken` + lectura de 50h → mismo comportamiento que el punto 2, sin ninguna diferencia.
4. `alerts:scan` en el entorno real: 0 alertas nuevas creadas (todas las máquinas elegibles ya tenían alerta
   abierta), 2 notificadas. Confirmado el email en `storage/logs/laravel.log` (`Subject: 3 machine(s) due for
   service`) tras procesar la cola una vez con `php artisan queue:work --once --stop-when-empty` (la cola es
   `database`, no `sync`; sin esto el correo no aparece en el log — ver hallazgo BAJO).
5. Preload checklist sobre `QA-WO-01`: 61 ítems creados (Vehicle 37 / Lights 4 / Safety Equipment 5 / Trailer 15,
   verificado contra `checklist_template_items` en BD, coincide exactamente con lo esperado).
6. Validación `required_if:result,alert` (misma regla que usa `ChecklistResultsRelationManager::form()`)
   probada con `Illuminate\Support\Facades\Validator`: sin `alert_detail` → falla (bloquea, correcto); con
   `alert_detail` → pasa (correcto).
7. Marcar un ítem Vehicle como `alert` con detalle → generó `Alert(type=checklist, status=open)` correctamente.
8. Marcar un ítem Trailer como `na` → se guardó sin error.
9. OT preventiva (`QA-WO-02`) con alerta de servicio abierta forzada manualmente → al completar (mismo flujo
   que `WorkOrderResource::complete`, incl. `WorkOrderCompletionService::complete()`):
   `last_service_hours` pasó de 900 a 1010, `remaining_hours` se reseteó a 500 (`service_interval_hours`),
   la alerta de servicio quedó en `resolved`, y se creó una `HorometerReading(source=workshop, hours=1010)`.
   Los 4 efectos, PASS.
10. Acción "Mover" (replicando exactamente `$record->update(['current_location_id' => $id])` del `MachineResource`):
    solo cambió `current_location_id` (status, current_hours, last_service_hours, remaining_hours idénticos
    antes/después) y quedó un registro en `activity_log` con el diff exacto
    (`{"old":{"current_location_id":null},"attributes":{"current_location_id":2}}`).

## Registros QA- creados y borrados

| Tabla | Identificador | Estado |
|---|---|---|
| machines | `QA-TEST-01` (id 100) | Creada y **borrada** |
| work_orders | `QA-WO-01` (id 7), `QA-WO-02` (id 8) | Creadas y **borradas** (+ 61 `checklist_results` asociados a QA-WO-01, borrados) |
| horometer_readings | 4 filas de `machine_id=100` (lecturas 1010/500/50 + workshop 1010) | **Borradas** |
| alerts | id 14 (`type=checklist`), id 15 (`QA-alerta-servicio-manual`) | **Borradas** |
| activity_log | 4 entradas de `subject_type=Machine, subject_id=100` | **Borradas** |

**Verificación por BD tras la limpieza:** `machines WHERE id_code LIKE 'QA-%'` → 0,
`work_orders WHERE code LIKE 'QA-%'` → 0, `alerts WHERE title LIKE 'QA-%'` → 0.

## Datos reales modificados (no revertidos, efecto esperado de un comando solicitado)

Al ejecutar `php artisan alerts:scan` (paso explícitamente pedido en el brief) sobre el entorno real, se
marcó `notified_at` en 2 alertas reales ya existentes: `alerts.id=12` (machine_id 11) y `alerts.id=13`
(machine_id 29), ambas con `notified_at=2026-07-25 22:26:50`. Es el comportamiento normal y esperado del
comando (indica que el digest de esas alertas fue encolado/enviado), no un dato corrupto ni una máquina
alterada — no lo revertí porque revertirlo falsearía que la notificación no ocurrió cuando sí ocurrió.
También procesé un job de cola pendiente (`queue:work --once --stop-when-empty`) para poder verificar el
correo en el log; no quedó ningún job QA de por medio.

## Bloqueos

- No se pudo probar la validación `required_if` del checklist a través del formulario real de Filament vía
  HTTP (el navegador está reservado al hilo principal). Se validó recreando la regla exacta del código con
  `Illuminate\Support\Facades\Validator` sobre los mismos datos — es una verificación real de la lógica de
  validación, pero no un clic real en la UI. Si se requiere el control por navegador, queda **PENDIENTE**
  para cuando el hilo principal libere Playwright.
- No se verificó visualmente (sidebar/403 por rol) que taller/foreman no vean el botón "Editar" de máquinas
  ni las acciones de OT — se confirmó solo a nivel de código/gate (`MachineResource::canEdit`) y por la
  ausencia total de gates en `WorkOrderResource`. Verificación visual por navegador queda fuera del alcance
  de esta ola (reservada a Ola 1/CRO, según el reparto de herramienta del contexto).

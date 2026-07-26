# QA Etapa 05 — Ola 1: rol `personal_mantenimiento` (campo@dp.local / password)

Fecha: 2026-07-25. Entorno: http://127.0.0.1:8099 (local, no producción). Evidencia real por HTTP
(curl + cookiejar, replicando el login Livewire vía `qa_livewire.php`) y BD (`mysql` CLI). Navegador
`mcp__playwright__*` **bloqueado** toda la sesión ("Browser is already in use ... mcp-chrome-a9692a1"),
ver "Bloqueos". Idioma probado: ES, EN y FR (ver sección 3).

## Resumen ejecutivo

`personal_mantenimiento` funciona **de punta a punta**: el reporte de campo (`field_report`) SÍ es
alcanzable para este rol (a diferencia de `foreman`, que tiene el permiso en la tabla fuente de
verdad pero el código lo bloquea — ver ola de `foreman`) porque `ReportForm::mount()` exige
exactamente `hasRole('personal_mantenimiento')`, que es este usuario. El envío de reporte con
horómetro se verificó con persistencia real en BD, incluyendo el efecto lateral sobre
`machines.current_hours` y `remaining_hours`. Los controles negativos (panel `/admin/*`, reportes
PDF/Excel, y el cruce con `/field/fuel` que no le corresponde) dieron 403 de forma consistente en ES
y EN. No se encontraron hallazgos críticos. Se modificó un dato real de la máquina `PJ001` como
parte del control positivo obligatorio (ver sección 6), y hay una observación menor de metodología
de prueba (sección 4).

## 1. Matriz módulos × resultado

| Módulo | Control positivo | Control negativo | Resultado |
|---|---|---|---|
| Flota (view_fleet) / Home | `GET /field` → 200, `<h2>Hola, Personal Mtto</h2>` y **solo** el botón "📋 Reportar novedad" (texto real: enlace a `/field/report`) presentes | Botón de admin y de "combustible" (cruzado) **ausentes en el HTML** (`grep -c` = 0) | **PASS** |
| Reporte de campo (field_report) | **Verificado end-to-end**: `POST /livewire/update` sobre `ReportForm::save()` con `machineId=50 (PJ001)`, `condition="attention"`, `hours="4950"`, `notes="QA- prueba reporte de campo"` → `"submitted":true`, HTML "Reporte enviado ✓". Confirmado en BD: `field_reports.id=1` (machine_id=50, reported_by=5, condition='attention', hours=4950, notes='QA- prueba reporte de campo') | N/A — es su permiso propio | **PASS** |
| Horómetro (log_horometer, vía reporte) | El mismo `save()` crea además `horometer_readings.id=157` (machine_id=50, hours=4950, source='maintenance', recorded_by=5) y el observer actualizó `machines.id=50 (PJ001).current_hours`: `4901` → `4950`, `remaining_hours`: `NULL` → `6005` (sin alerta, muy por debajo del umbral de servicio) | N/A | **PASS** |
| Combustible (log_fuel — NO lo tiene) | N/A — no es su permiso | `GET /field/fuel` → **403** (`FuelLog::mount()` exige `hasRole('operador_cisterna')`, este usuario no lo tiene) | **PASS** (cruce de roles correcto) |
| Órdenes de trabajo (no tiene create/execute_work_order) | N/A | `GET /admin/work-orders`, `/admin/work-orders/create` → **403** (gate general `canAccessPanel()`) | **PASS** |
| Historial y costos | N/A — no hay vista de costos en la PWA | `grep -rin "cost|price|precio|monto|currency"` sobre las 4 vistas Blade de `/field/*` → **0 coincidencias**; `/admin/*` completo en 403 | **PASS** |
| Reportes (view_reports — NO lo tiene) | N/A | `GET /reports/fleet.pdf`, `/reports/fleet.xlsx` → **403** en ES y en EN | **PASS** |
| Panel admin (`/admin/*`) | N/A | `GET /admin`, `/admin/machines`, `/admin/work-orders`, `/admin/users` → **403** (whitelist de `canAccessPanel()`, no incluye `personal_mantenimiento`) | **PASS** |

## 2. Control positivo end-to-end (reporte de campo + horómetro)

Sesión autenticada real vía `POST /livewire/update` sobre `field.login` (guard `web`, cookiejar
`cookies_campo.txt`). Con esa sesión se llamó a `ReportForm::save()` (`/field/report`) con
`machineId=50` (PJ001, elegida por ser la de "horómetro roto" según el catálogo de máquinas de
prueba, no una máquina activa relevante), `condition="attention"`, `hours="4950"`,
`notes="QA- prueba reporte de campo"`.

- Respuesta: `"submitted":true`, HTML "✅ Reporte enviado ✓". **HTTP 200**.
- Verificado en BD tras la llamada:
  - `field_reports`: nueva fila `id=1, machine_id=50, reported_by=5, condition='attention',
    hours=4950, notes='QA- prueba reporte de campo'`.
  - `horometer_readings`: nueva fila `id=157, machine_id=50, hours=4950, read_at=2026-07-25,
    source='maintenance', recorded_by=5`.
  - `machines.id=50 (PJ001)`: `current_hours` pasó de `4901` a `4950`; `remaining_hours` pasó de
    `NULL` a `6005` (con `last_service_hours=10455`, `service_interval_hours=500`,
    `hours_adjustment=0`); no se disparó alerta de servicio (muy por encima del umbral de 100h).
- **Conclusión**: `field_report` y `log_horometer` (vía el mismo formulario) funcionan y persisten
  correctamente end-to-end para este rol.

## 3. Bilingüe (ES / EN / FR)

- Home ES: `<h2>Hola, Personal Mtto</h2>` + botón único hacia `/field/report`.
- `GET /locale/en` → 302, persiste el cambio en sesión.
- Home EN: `<h2>Hi, Personal Mtto</h2>` + mismo botón único hacia `/field/report` (solo traducido).
- Repetidos 5 controles negativos en EN: `/admin`, `/admin/work-orders`, `/field/fuel` (cruce),
  `/reports/fleet.pdf`, `/reports/fleet.xlsx` → **403 idéntico a ES en los 5**.
- `GET /locale/fr` (inválido) → **404** (ruta restringida a `es|en`), no rompe nada.
- **No se detectó ningún caso de "403 en ES / 200 en EN"** (habría sido crítico). Sin hallazgos
  bilingües para este rol.

## 4. Hallazgos

No se encontraron hallazgos críticos ni altos para `personal_mantenimiento` en esta ola.

### Bajo / observación de metodología — `field_reports.location_id` quedó `NULL` en el registro de prueba
- **Módulo**: Reporte de campo.
- **Detalle**: `field_reports.id=1` (creado en el control positivo) tiene `location_id = NULL`,
  aunque `PJ001.current_location_id = 2` en BD. **No es un bug de la app**: en la UI real, el flujo
  es buscar → `selectMachine()` → ese método copia `current_location_id` de la máquina al
  componente (`ReportForm.php` líneas 62-66) → recién ahí `save()` la usa. Mi prueba llamó a
  `save()` inyectando `machineId` directamente por `POST /livewire/update` sin pasar por
  `selectMachine()`, así que ese campo derivado quedó vacío en el registro de prueba únicamente.
  **Se documenta por transparencia y para que no se confunda con un defecto real**; no se recomienda
  acción de desarrollo. Si se quiere confirmar el comportamiento real de la UI, requiere navegador
  (bloqueado hoy, ver sección 8).

## 5. Riesgos de permisos

- Costos: **sin riesgo detectado**. Ninguna vista de `/field/*` alcanzable por este rol referencia
  costo/precio/moneda, y el panel completo está bloqueado en 403.
- El cruce `personal_mantenimiento` → `/field/fuel` está bien bloqueado por
  `abort_unless(hasRole('operador_cisterna'))` explícito en `FuelLog::mount()`.
- A diferencia de `foreman` (permiso de papel, inalcanzable), aquí `field_report` SÍ coincide con el
  código: el `abort_unless` de `ReportForm` está escrito literalmente para `personal_mantenimiento`.
  Esto confirma, como pista del jefe indicaba, que el diseño del permiso está pensado para este rol
  específico y no para `foreman`.
- Mismo riesgo arquitectónico de fondo que en las demás olas: `canAccessPanel()` es una whitelist
  global, no hay Policy/permiso por recurso en `MachineResource`/`WorkOrderResource`. No se
  re-explota aquí, solo se reconfirma que bloquea correctamente a este rol también.

## 6. Registros QA- creados

| Tabla | Registro | Detalle |
|---|---|---|
| `field_reports` | `id=1` | machine_id=50 (PJ001), reported_by=5, condition='attention', hours=4950, notes='QA- prueba reporte de campo' — identificable por el prefijo `QA-` en `notes` |
| `horometer_readings` | `id=157` | machine_id=50 (PJ001), hours=4950, source='maintenance', recorded_by=5 (sin campo de nota; ligado al `field_reports.id=1` de arriba) |

## 7. Datos reales modificados (revertir)

| Tabla | Registro | Campo | Valor ANTERIOR | Valor NUEVO |
|---|---|---|---|---|
| `machines` | id=50 (`PJ001`) | `current_hours` | `4901` | `4950` |
| `machines` | id=50 (`PJ001`) | `current_hours_date` | (no capturado antes de esta corrida; no es NULL previo confirmado) | `2026-07-25` |
| `machines` | id=50 (`PJ001`) | `remaining_hours` | `NULL` | `6005` |

SQL sugerido para revertir (a criterio del jefe):
```sql
UPDATE machines SET current_hours = 4901, remaining_hours = NULL WHERE id_code = 'PJ001';
-- current_hours_date: revertir al valor previo si el jefe lo tiene registrado; no se capturó
-- explícitamente antes de esta prueba.
-- Opcional, si se prefiere no dejar los registros de evidencia:
-- DELETE FROM field_reports WHERE id = 1;
-- DELETE FROM horometer_readings WHERE id = 157;
```

## 8. Bloqueos

- **Navegador (`mcp__playwright__*`) bloqueado toda la sesión**: `Error: Browser is already in use
  for ...mcp-chrome-a9692a1, use --isolated...`. Mismo bloqueo que en la ola de `operador_cisterna`
  (ver ese informe, sección 8) y que en la ola de `foreman`. **No se pudieron tomar capturas** ni
  verificar visualmente:
  - El layout real del home/menú (se confirmó por HTML, no por render visual).
  - Responsive en 390px y 360px del flujo de reporte/horómetro (`/field/report`) — **PENDIENTE**.
  - Estados de foco/hover/teclado — **PENDIENTE**.
  - El comportamiento real de `selectMachine()` en la UI (búsqueda + clic), que habría evitado la
    observación de metodología de la sección 4.
- No se probó doble-click/idempotencia del botón "Enviar" de `ReportForm` (fuera del time-box).
- No se verificó cross-browser (Firefox/Safari) — sin navegador disponible de todos modos.

## 9. Recomendación

**Aprobar** el resultado funcional y de permisos de `personal_mantenimiento` verificado hoy (reporte
de campo + horómetro end-to-end, y los 8 controles de la matriz). Pendiente: (a) revertir el dato
real de `PJ001` señalado en la sección 7 y (b) repetir la verificación visual/responsive
(390px/360px) y de foco de teclado en cuanto el navegador quede liberado.

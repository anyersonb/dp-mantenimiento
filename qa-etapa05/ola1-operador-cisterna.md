# QA Etapa 05 — Ola 1: rol `operador_cisterna` (combustible@dp.local / password)

Fecha: 2026-07-25. Entorno: http://127.0.0.1:8099 (local, no producción). Evidencia real por HTTP
(curl + cookiejar, replicando el login Livewire vía `qa_livewire.php`) y BD (`mysql` CLI). Navegador
`mcp__playwright__*` **bloqueado** toda la sesión ("Browser is already in use ... mcp-chrome-a9692a1"),
ver "Bloqueos". Idioma probado: ES, EN y FR (ver sección 3).

## Resumen ejecutivo

`operador_cisterna` funciona **de punta a punta y sin fugas de permisos detectadas**. El control
positivo de combustible se verificó con persistencia real en BD, incluyendo que el observer de
horómetro actualiza `machines.current_hours`. Los controles negativos (panel `/admin/*`, reportes
PDF/Excel, y el cruce con `/field/report` que no le corresponde) dieron 403 de forma consistente en
ES y EN. No se encontraron hallazgos críticos ni altos en esta ola para este rol. Se modificó un
dato real de la máquina `MS003` como parte del control positivo obligatorio (ver sección 6).

## 1. Matriz módulos × resultado

| Módulo | Control positivo | Control negativo | Resultado |
|---|---|---|---|
| Flota (view_fleet) / Home | `GET /field` → 200, `<h2>Hola, Operador Cisterna</h2>` y **solo** el botón "⛽ Registrar combustible" presentes (grep sobre HTML real) | Botón de admin y de "reporte de campo" (cruzado) **ausentes en el HTML** (`grep -c` = 0, no solo ocultos por CSS) | **PASS** |
| Combustible (log_fuel) | **Verificado end-to-end**: `POST /livewire/update` sobre `FuelLog::save()` con `machineId=47 (MS003)`, `gallons=12.5`, `hours=150`, `note="QA- prueba combustible"` → `"submitted":true`, HTML "Registrado ✓". Confirmado en BD: `horometer_readings.id=156` (machine_id=47, hours=150, source='fuel', recorded_by=4, gallons=12.50) | N/A — es su permiso propio | **PASS** |
| Horómetro (log_horometer, vía combustible) | El mismo `save()` de combustible dispara el observer: `machines.id=47 (MS003).current_hours` pasó de `NULL` a `150`, `current_hours_date`=2026-07-25. No se disparó alerta de servicio (MS003 no tiene `last_service_hours`) | N/A | **PASS** |
| Reporte de campo (field_report — NO lo tiene) | N/A — no es su permiso | `GET /field/report` → **403** (`ReportForm::mount()` exige `hasRole('personal_mantenimiento')`, este usuario no lo tiene) | **PASS** (cruce de roles correcto) |
| Órdenes de trabajo (no tiene create/execute_work_order) | N/A | `GET /admin/work-orders`, `/admin/work-orders/create` → **403** (gate general `canAccessPanel()`) | **PASS** |
| Historial y costos | N/A — no hay vista de costos en la PWA | `grep -rin "cost|price|precio|monto|currency"` sobre las 4 vistas Blade de `/field/*` → **0 coincidencias**; `/admin/*` completo en 403 | **PASS** |
| Reportes (view_reports — NO lo tiene) | N/A | `GET /reports/fleet.pdf`, `/reports/fleet.xlsx` → **403** en ES y en EN | **PASS** |
| Panel admin (`/admin/*`) | N/A | `GET /admin`, `/admin/machines`, `/admin/machines/create`, `/admin/users` → **403** (whitelist de `canAccessPanel()`, no incluye `operador_cisterna`) | **PASS** |

## 2. Control positivo end-to-end (combustible + horómetro)

Sesión autenticada real vía `POST /livewire/update` sobre `field.login` (guard `web`, cookiejar
`cookies_cisterna.txt`). Con esa sesión se llamó a `FuelLog::save()` (`/field/fuel`) con
`machineId=47` (MS003), `gallons="12.5"`, `hours="150"`, `note="QA- prueba combustible"`.

- Respuesta: `"submitted":true`, HTML "✅ Registrado ✓ — El registro de combustible se guardó
  correctamente." **HTTP 200**.
- Verificado en BD tras la llamada:
  - `horometer_readings`: nueva fila `id=156, machine_id=47, hours=150, read_at=2026-07-25,
    source='fuel', recorded_by=4, gallons=12.50, note='QA- prueba combustible'`.
  - `machines.id=47 (MS003)`: `current_hours` pasó de `NULL` a `150`; `current_hours_date` de
    `NULL` a `2026-07-25`. `remaining_hours` sigue `NULL` (MS003 no tiene `last_service_hours`, no
    hay cálculo posible; consistente con la lógica de `HorometerReadingObserver`).
  - No se creó ninguna alerta nueva (`alerts`: sin filas nuevas), correcto porque no hay
    `remaining_hours` calculable.
- **Conclusión**: `log_fuel` y el efecto lateral sobre horómetro/máquina funcionan y persisten
  correctamente end-to-end.

## 3. Bilingüe (ES / EN / FR)

- Home ES: `<h2>Hola, Operador Cisterna</h2>` + botón "⛽ Registrar combustible" (único).
- `GET /locale/en` → 302, persiste el cambio en sesión.
- Home EN: `<h2>Hi, Operador Cisterna</h2>` + botón "⛽ Log fuel" (mismo único botón, solo
  traducido — conjunto idéntico).
- Repetidos 5 controles negativos en EN: `/admin`, `/admin/machines`, `/field/report` (cruce),
  `/reports/fleet.pdf`, `/reports/fleet.xlsx` → **403 idéntico a ES en los 5**.
- `GET /locale/fr` (inválido) → **404** (ruta restringida a `es|en`), no rompe nada: `GET /field`
  inmediatamente después sigue en 200 con el idioma sin cambiar (`Hi, Operador Cisterna` se
  mantuvo).
- **No se detectó ningún caso de "403 en ES / 200 en EN"** (habría sido crítico). Sin hallazgos
  bilingües para este rol.

## 4. Hallazgos

No se encontraron hallazgos críticos ni altos para `operador_cisterna` en esta ola.

### Bajo / observación — `field_reports.location_id` no se pobló en la prueba curl
Al ejecutar el control positivo por HTTP directo (sin pasar por `selectMachine()` de la UI, que es
quien copia `current_location_id` de la máquina al componente), el campo quedó `NULL` en el registro
de prueba de `personal_mantenimiento` sobre PJ001. **Esto es un artefacto de mi método de prueba**
(inyección directa de propiedades Livewire), no una verificación de un bug real de la UI — con clic
real en "Seleccionar máquina" el campo sí se completa (confirmado leyendo `ReportForm::selectMachine()`
línea 62-66). Se documenta por transparencia, no se cuenta como defecto de `operador_cisterna` (aplica
al informe de `personal_mantenimiento`, no a este).

## 5. Riesgos de permisos

- Costos: **sin riesgo detectado**. Ninguna vista de `/field/*` alcanzable por este rol referencia
  costo/precio/moneda, y el panel completo está bloqueado en 403.
- El único punto que impide operar en `/admin/*` es la whitelist de `User::canAccessPanel()` (no
  hay Policy por recurso) — mismo riesgo arquitectónico de fondo ya señalado en la ola de `foreman`;
  no se re-explota aquí, solo se reconfirma que hoy bloquea correctamente a este rol también.
- El cruce `operador_cisterna` → `/field/report` está bien bloqueado por `abort_unless(hasRole(...))`
  explícito en `ReportForm::mount()`. No hay ninguna ruta alternativa en el código que cree
  `field_reports` para este rol.

## 6. Registros QA- creados

| Tabla | Registro | Detalle |
|---|---|---|
| `horometer_readings` | `id=156` | machine_id=47 (MS003), hours=150, source='fuel', recorded_by=4, gallons=12.50, note='QA- prueba combustible' — identificable por el prefijo `QA-` en `note` |

## 7. Datos reales modificados (revertir)

| Tabla | Registro | Campo | Valor ANTERIOR | Valor NUEVO |
|---|---|---|---|---|
| `machines` | id=47 (`MS003`) | `current_hours` | `NULL` | `150` |
| `machines` | id=47 (`MS003`) | `current_hours_date` | `NULL` | `2026-07-25` |

SQL sugerido para revertir (a criterio del jefe):
```sql
UPDATE machines SET current_hours = NULL, current_hours_date = NULL WHERE id_code = 'MS003';
-- Opcional, si se prefiere no dejar el registro de evidencia:
-- DELETE FROM horometer_readings WHERE id = 156;
```

## 8. Bloqueos

- **Navegador (`mcp__playwright__*`) bloqueado toda la sesión**: `Error: Browser is already in use
  for ...mcp-chrome-a9692a1, use --isolated...`. Se reintentó 2 veces (antes de empezar y a mitad de
  la corrida), mismo error ambas veces. **No se pudieron tomar capturas de pantalla** ni verificar
  visualmente:
  - El layout real del home/menú (se confirmó por HTML, no por render visual).
  - Responsive en 390px y 360px del flujo de combustible (`/field/fuel`) — **PENDIENTE**, requiere
    navegador. El CSS fuente (`main.field-main{max-width:480px}`, inputs `width:100%`) sugiere buen
    comportamiento, pero **no está verificado visualmente**, solo leído en código.
  - Estados de foco/hover/teclado — **PENDIENTE**, requiere navegador.
- No se probó doble-click/idempotencia del botón "Guardar" de `FuelLog` (fuera del time-box).
- No se verificó cross-browser (Firefox/Safari) — fuera de alcance de esta ola, sin navegador
  disponible de todos modos.

## 9. Recomendación

**Aprobar** el resultado funcional y de permisos de `operador_cisterna` verificado hoy (combustible
end-to-end, horómetro, y los 8 controles de la matriz). Pendiente: (a) revertir el dato real de
`MS003` señalado en la sección 7 y (b) repetir la verificación visual/responsive (390px/360px) y de
foco de teclado en cuanto el navegador quede liberado — esta ola no cubre ese punto por bloqueo de
herramienta, no por resultado negativo.

# QA Etapa 05 — Ola 3: Cargas de datos y archivos

Fecha: 2026-07-25. Entorno: http://127.0.0.1:8099 (local, no producción). Método: curl + cookie jar
(login replicado vía `/livewire/update`, ver CONTEXTO-QA.md §2), cliente mysql, PHP CLI. Sin navegador
(reservado al hilo principal).

---

## 1. Conteo e integridad de la flota

### 1.1 Conteo real por categoría (BD `machines` + `machine_categories`)

| Categoría | Real (BD) |
|---|---|
| Excavator | 17 |
| Roller | 12 |
| Wheel Loader | 11 |
| Skid Steer | 8 |
| Pump | 8 |
| Light Tower | 6 |
| Por clasificar | 5 |
| Dump Truck | 4 |
| Grader | 3 |
| Other | 3 |
| Cold Planer | 3 |
| Screen/Plant | 3 |
| Water Truck | 2 |
| TV Truck | 2 |
| Vacuum Truck | 2 |
| Tractor, Broom Tractor, Crusher, Dozer, Paver, Sweeper, Gen Set, Air Compressor, Fuel Truck, Truck Tractor | 1 c/u |
| **Total** | **99** |

El brief del cliente (EX 17, LD 20, RL 12, GR 3, DZ 1, PV/MS 2, BT 1, PJ/PW 6, torres 4, otros 5 = 71)
corresponde al Excel original y **no es comparable 1:1** contra las categorías actuales del sistema
(el sistema usa categorías más finas: p.ej. "Wheel Loader" 11 + "Skid Steer" 8 cubren lo que el brief
agrupaba como "LD 20"; Excavator 17 SÍ coincide exacto). No se fuerza una reconciliación categoría por
categoría porque el mapeo de categorías del brief a las categorías reales del sistema no está
documentado en ningún lado — **esto en sí es una observación a confirmar con el cliente**, no un
defecto de datos.

### 1.2 Separación (a) Excel / (b) Info Book / (c) no explicado

Comparación exacta de `id_code` (BD vs. `database/data/machines.json`, vía `array_diff_key` en PHP,
no por `comm` de shell — **ver Hallazgo H1**, `comm` dio un resultado incorrecto en este entorno):

- **(a) Excel (`machines.json`), 70 registros**: los 70 `id_code` del Excel están, sin excepción, en la
  BD (0 faltantes). PASS.
- **(b) Info Book, 29 registros**: BD tiene exactamente 29 `id_code` que NO están en el Excel
  (AC-001, BOBCAT 60 SWEEPER AS A ATTACHMENT, BT001, GEN-001, INFO-TMP-01..06, LDAS05, MOT002, PJ002,
  PW011, PW012, SS-001, SS-002, TD005..008, TF006, TR001, TT003, TV003, TW004, TW006, VAC-001, VAC-002).
  70 + 29 = 99. Coincide con lo esperado en CONTEXTO-QA.md §5.2.
- **(c) No explicado**: **ninguna diferencia de conteo total** (99 = 70 + 29 exacto). Sí hay
  **discrepancias de composición dentro de `needs_review`** — ver Hallazgo H2 abajo.

### 1.3 `needs_review` — Hallazgo H2 (severidad: alto)

Total real: **35** (coincide con lo esperado). Pero la composición no es la que narra el contexto:

- De los 29 registros del Info Book, **28 tienen `needs_review=1` y 1 NO**: **`AC-001`** tiene
  `needs_review=0` pese a que su propio `review_note` dice *"Ficha del Info Book sin máquina operativa
  correspondiente"* — el mismo texto que sí dispara `needs_review=1` en los otros 20 registros con esa
  nota. Esto contradice además la propia CONTEXTO-QA.md §6, que cita a AC-001 como ejemplo de máquina
  con `needs_review=1`.
- Los 7 restantes de los 35 SÍ son del lote Excel (no Info Book), y están correctamente explicados por
  el propio dato fuente: `EX027` y `RL016` (duplicados dentro del Excel, nota "se conservó la lectura
  más reciente") y `MS-TEMP-01`, `MS-TEMP-02`, `PW-MERSINO-01`, `PW-MERSINO-02`, `PW-PIONEER` (ya venían
  con `needs_review` textual en `machines.json` por ID temporal/no estándar). 28 + 7 = 35 cuadra en
  total, pero **por casualidad** — no porque AC-001 esté bien.
- **Bug adicional en el mismo lote**: `review_note` de `SS-001` y `TD005` tiene el texto
  *"Posible ficha duplicada del info book (dup_of: SS-001)."* **concatenado 3 veces literalmente**
  (el proceso que arma la nota no dedupe antes de concatenar). Además `SS-001` es un `dup_of` de **sí
  mismo** (self-reference), lo cual no tiene sentido semántico — revisar la lógica de detección de
  duplicados: el Info Book sí tiene 2 fichas legítimas distintas bajo el mismo id (`SS-001` Husqvarna
  FS513 y `SS-001` Husqvarna FS400LV, año/serie distintos), por lo que el "duplicado" real es la
  **colisión de id_code**, no un `dup_of` de sí mismo.
- Asignar a: **backend-laravel** (revisar el script/importador que calcula `needs_review`/`review_note`
  para el Info Book).

### 1.4 Muestra de 10 máquinas (id_code, serie/PIN, ubicación, último servicio, horómetro)

| id_code | Fuente | Serie/PIN | Ubicación | Último servicio | Horómetro (fuente) | Horómetro (BD) | Resultado |
|---|---|---|---|---|---|---|---|
| EX010 | Excel | KNE00310 ✓ | Broadview Yd. ✓ | 9708h 5/04/26 ✓ | 9785h 7/10/26 | **9793h 7/17/26** | Ver H3 |
| EX013 | Excel | KC900521 ✓ | WPB Yd. ✓ | 5542h 3/21/25 ✓ | 5808h 6/29/26 ✓ | 5808h 6/29/26 | PASS (incl. `hours_adjustment=5714` correcto, guardado aparte, no aplicado sobre `current_hours`) |
| LD022 | Excel | A38528 ✓ | Douglas Rd. ✓ | 8518h 1/23/26 ✓ | 8645h 7/09/26 | **8667h 7/17/26** | Ver H3 |
| MS003 | Excel | GDR00516 ✓ | Rapid Milling & Paving ✓ | sin info ✓ | sin info ✓ | sin info | PASS |
| PJ001 | Excel | (sin serie) ✓ | Blount Rd. ✓ | 10455h 3/10/25 ✓ | 4901h 6/20/26 ✓ | 4901h 6/20/26 | PASS |
| AC-001 | Info Book | 201910220012 ✓ | (Info Book no trae ubicación) | — | — | `needs_review=0` | Ver H2 |
| GEN-001 | Info Book | GSG-22953 ✓ | (Info Book no trae ubicación) | — | — | `needs_review=1` ✓ | PASS |
| SS-001 | Info Book | 1034 (1ª ficha) | — | — | — | `needs_review=1`, nota triplicada | Ver H2 |
| TT003 | Info Book | "" (sin serie en fuente, VIN va en `model`) ✓ | — | — | — | `needs_review=1` ✓ | PASS |
| TF006 | Info Book | null (VIN va en texto de `model`, no en columna `serial`) | — | — | — | `needs_review=1` ✓ | Nota: VIN no queda en columna `serial`, es un gap de modelado, no bug de esta ola |

**Hallazgo H3 (severidad: media — EXPLICADO, no es bug ciego)**: EX010 y LD022 (y otras 26 máquinas)
tienen en BD un horómetro/fecha **más nuevo** que el que trae `database/data/machines.json`.
Verificado en tabla `horometer_readings`: cada una tiene **2 lecturas `source='import'`** — una del
2026-07-16 (que coincide con `machines.json`) y otra del 2026-07-22 con valores distintos, fechados
7/17/26. El archivo `PM_Service Report_Machines_7172026.xlsx` en Downloads (7/17/2026) confirma que
hubo una **re-importación con un Excel más nuevo** que el que generó el JSON fixture del repo. Es decir:
**`database/data/machines.json` está desactualizado respecto a la BD actual** para 28 máquinas
(`SELECT machine_id, COUNT(*) FROM horometer_readings WHERE source='import' GROUP BY machine_id HAVING COUNT(*)>1` → 28 filas). No es pérdida de datos ni bug de importación — es evidencia de que el
fixture de referencia debe regenerarse tras cada reimportación real, o el próximo QA comparará contra
datos viejos y reportará falsos positivos. Asignar a: **backend-laravel** (regenerar
`database/data/machines.json` o documentar que no debe usarse como fuente de verdad post-reimportación).

---

## 2. Exportaciones (`/reports/fleet.pdf`, `/reports/fleet.xlsx`)

| Rol | pdf | xlsx | Filas (xlsx) | Cabeceras costo |
|---|---|---|---|---|
| administrador (view_costs=sí, view_reports=sí) | 200 | 200 | 99 (== `machines` actual) | Sí: "Servicios" + "Costo de mantenimiento" |
| gerencia (view_costs=sí, view_reports=sí) | 200 | 200 | no reverificado por separado, gate idéntico a admin | (no reverificado por separado) |
| responsable_mantenimiento (view_costs=sí, view_reports=sí) | 200 | 200 | no reverificado por separado | (no reverificado por separado) |
| taller (view_costs=sí, **view_reports=no**) | **403** | **403** | — | — (bloqueado correctamente) |

Verificación de contenido (extracción real, no solo status code):
- XLSX admin: 99 filas de datos + encabezado, cabeceras `ID | Categoría | Marca | Modelo | Ubicación |
  Horas actuales | Restantes | Estado | Servicios | Costo de mantenimiento` — coincide con `machines`
  (`SELECT COUNT(*) FROM machines` = 99 en el momento de la re-verificación limpia).
- PDF admin (extraído con `pdftotext -layout`): 99 filas de status (`Active|Not in|Unknown|Down|
  Inactive`), coincide con BD.
- **Nota de contaminación de datos, no defecto**: la **primera** corrida del export dio 100 filas en
  vez de 99 porque en ese instante existía una máquina transitoria `QA-TEST-01` (creada y borrada por
  actividad concurrente ajena a esta ola, probablemente pruebas de importación del jefe corriendo en
  paralelo). Al repetir el export inmediatamente después, volvió a 99 = BD. **No reportar como bug de
  exportación**; si vuelve a aparecer de forma consistente sí sería hallazgo.

**Hallazgo H4 (severidad: media — hallazgo de diseño, confirmado por BD, no supuesto)**: Se
verificó directamente en `roles` / `role_has_permissions` que **hoy no existe ningún rol con
`view_reports` sin `view_costs`**:

```
administrador            → view_costs + view_reports
gerencia                  → view_costs + view_reports
responsable_mantenimiento → view_costs + view_reports
taller                    → view_costs (sin view_reports)
```

El código de `FleetExport.php` y las rutas `web.php` **sí implementan correctamente** el gate
(`$includeCosts = Auth::user()?->can('view_costs')`, columnas de costo condicionadas a esa variable
tanto en PDF como en XLSX) — el mecanismo existe y compila. Pero **es estructuralmente imposible
probar hoy el caso "reportes sin costos"** porque ningún rol real tiene esa combinación de permisos.
**Confirmar con el cliente** si esa combinación debe existir (p.ej. ¿debería taller tener
`view_reports` sin `view_costs`, o crearse un rol nuevo?) antes de dar por cerrado este requisito.
Asignar a: negocio/cliente (vía PM), no es un bug de código.

---

## 3. Validación de campos (HTTP, sobre dummy `QA-DUMMY-01`)

Todas las pruebas fueron sobre `App\Filament\Resources\MachineResource` (crear/editar), replicando
`POST /livewire/update` con snapshot real (login y CSRF de `admin@dp.local`).

| Caso | Entrada | Resultado | Severidad |
|---|---|---|---|
| Acentos/ñ en descripción | `Máquina de prueba ñ QA - áéíóú - ` + 300 "X" | Guardado íntegro, 333 caracteres, sin corrupción de encoding | PASS |
| Texto muy largo (300 car. extra en `description`, columna `text`) | idem | Guardado sin truncar | PASS |
| `id_code` vacío (requerido) | `""` | Rechazado, mensaje `"The iD field is required."` | Ver H5 |
| Horómetro decimal `12.5` | `data.current_hours = "12.5"` | **Aceptado sin error de validación** y guardado como **`13`** (redondeado silenciosamente; columna `current_hours` es `int unsigned`, no hay `->integer()` ni advertencia al usuario) | **H6 — alto** |
| Horómetro con coma `12,5` | `data.current_hours = "12,5"` | Rechazado correctamente: `"The horas actuales field must be a number."` | Ver H5 (mensaje mixto ES/EN) |
| Horómetro negativo `-5` | `data.current_hours = "-5"` | **HTTP 500** — `SQLSTATE[22003]: Numeric value out of range` sin manejar; Filament no valida mínimo, el error revienta en la capa de BD (columna `int unsigned`) y se filtra un stack trace completo (archivo/línea) en la respuesta | **H7 — bloqueante** |
| Galones decimal en `/field/fuel` | no probado | **NO VERIFICADO** — `/field/login` y `/field/fuel` también son Livewire (no forms planos), replicar el flujo completo se salía del time-box | PENDIENTE |

**Hallazgo H5 (severidad: media, i18n)**: los mensajes de validación de Filament salen en **inglés**
(`"The iD field is required."`, `"...must be a number."`) pese a que la UI del panel está en español
(`locale: "es"` en el snapshot). Además el label sale mal capitalizado: **"iD"** en vez de "ID". Revisar
`lang/es/validation.php` (atributos custom) — probablemente falta el mapeo de atributos para
`Machine` en el archivo de traducciones de validación. Asignar a: **backend-laravel**.

**Hallazgo H6 (severidad: alto)**: `current_hours` (y muy probablemente `last_service_hours`,
`hours_adjustment`, `remaining_hours`, `service_interval_hours` — mismo patrón `->numeric()` sin
`->integer()` sobre columnas `int`/`int unsigned`) acepta decimales y los redondea sin avisar al
usuario. Un dato de horómetro con decimales (habitual si alguien copia/pega de un reporte con
fracciones de hora) se guarda alterado sin ningún mensaje. Recomendación: agregar `->integer()` (o
decidir si la columna debe ser decimal) y mostrar el valor real que quedará guardado antes de
confirmar. Asignar a: **backend-laravel**.

**Hallazgo H7 (severidad: bloqueante)**: enviar un valor negativo en un campo numérico ligado a una
columna `unsigned` no está validado por Filament (`->numeric()` permite negativos) y provoca una
**excepción de BD no capturada → HTTP 500** con **stack trace completo expuesto** (rutas de archivo del
servidor, versión de Laravel/Livewire, SQL crudo con los valores enviados). Esto es explotable como
vector de reconocimiento de la app en cualquier campo numérico similar, y en producción con
`APP_DEBUG=true` filtraría rutas del servidor a cualquier usuario autenticado (o no, si el campo
existe en un form público). Recomendación: (1) agregar `->minValue(0)` a todos los campos numéricos de
horas/costos, (2) confirmar `APP_DEBUG=false` en el `.env` de producción antes de desplegar. Asignar a:
**backend-laravel** + **security-engineer** (exposición de stack trace).

---

## 4. Importador "Import PM Service Report" — control de acceso

Gate declarado: `.visible(fn () => Auth::user()?->can('manage_machines'))` en
`ListMachines.php:35-39`. **Solo tiene `.visible()`, no `.authorize()` explícito** — se verificó por
HTTP si eso es explotable (botón oculto pero acción ejecutable por llamada directa).

| Rol | Botón visible en `/admin/machines` | `mountAction('import_pm_report')` directo por Livewire | Resultado |
|---|---|---|---|
| responsable_mantenimiento (tiene `manage_machines`) | Sí (3 ocurrencias en HTML) | no probado (ya tiene permiso, no hace falta bypass) | PASS control positivo |
| taller (NO tiene `manage_machines`) | No (0 ocurrencias) | **Bloqueado**: `mountedActions` quedó vacío tras el intento, sin acción montada, sin error | PASS |
| gerencia (NO tiene `manage_machines`) | No (0 ocurrencias) | **Bloqueado**: mismo resultado que taller | PASS |

**Conclusión: el gate SÍ es efectivo también contra bypass directo por HTTP**, no solo oculta el botón
en la UI — Filament no resuelve la acción por nombre para un usuario sin el permiso, así que
`mountAction` es un no-op silencioso. No se subió archivo (regla dura de la ola, esa parte la hace el
jefe por UI). Sin hallazgos aquí.

---

## 5. Registros QA- creados y borrados

| Tabla | Identificador | Creado (método) | Borrado (método) | Verificado en BD |
|---|---|---|---|---|
| `machines` | `QA-DUMMY-01` (id=101) | `POST /livewire/update` → `create` (admin) | `mountAction('delete')` + `callMountedAction()` (admin) | Sí — `SELECT COUNT(*) FROM machines WHERE id_code LIKE 'QA-%'` → **0**. Sin filas huérfanas en `horometer_readings` para `machine_id=101`. |

No se creó ningún otro registro `QA-` en esta ola (no se llegó a probar `/field/fuel` por límite de
tiempo, ver Bloqueos).

---

## 6. Datos reales modificados (revertir)

**Ninguno.** Todas las escrituras de esta ola fueron sobre `QA-DUMMY-01`, ya borrado. No se tocó
ningún registro real (`EX010`, `EX013`, `LD022`, etc. solo se leyeron, nunca se escribieron).

---

## 7. Bloqueos

1. **`ubicación de TF-005`**: CONTEXTO-QA.md §6 menciona `TF-005 (camión, usa odómetro en millas)`
   como máquina útil, pero no existe ese `id_code` en BD — solo `TF006`. Posible error tipográfico en
   el contexto (no se investigó más por ser de bajo impacto); no se tomó como hallazgo del sistema.
2. **Residuo de una ola anterior, no mío**: existe en BD una `location` `id=11`, `name='QA-Obra-Test'`,
   `slug='qa-obra-test'`, `active=1`, `created_at=2026-07-25 22:13:09` — **no creada por esta ola**
   (Ola 3 no tocó `locations`). Es basura de prueba de otra ola que no se limpió. No la borré porque no
   la creé yo y no tengo certeza de que no esté en uso por otra prueba en curso en paralelo. **Reportar
   al jefe para que la limpie o confirme qué ola la dejó.**
3. **`/field/fuel` (galones, decimales)**: no verificado. `/field/login` y `/field/fuel` son
   componentes Livewire (no forms HTML planos), y replicar login + navegación + submit por curl se
   salía del time-box de ~35 min ya extendido por el trabajo de conteo/muestra. Pendiente para otra
   ola o para verificación por navegador.
4. **Exports de gerencia/responsable**: se confirmó código HTTP 200 y que el gate de permisos en BD es
   idéntico al de admin, pero **no se re-extrajo el texto/cabeceras de sus PDF/XLSX individualmente**
   (se asume igual a admin dado que comparten los mismos permisos `view_costs`+`view_reports`, pero
   esto es una inferencia, no una verificación línea por línea de esos dos archivos específicos).
5. **Mapeo de categorías del brief (EX/LD/RL/GR/DZ/PV-MS/BT/PJ-PW/torres/otros) contra las 25
   categorías reales del sistema**: no se pudo reconciliar 1:1 porque no existe documento de mapeo；
   Excavator (17) coincide exacto, el resto requiere que el cliente confirme cómo se agrupan.

---

## Recomendación

**Volver a desarrollo** en dos puntos concretos antes de cerrar esta ola:
- **H7 (bloqueante)**: validar rango en campos numéricos ligados a columnas `unsigned` — hoy un valor
  negativo tumba la request con 500 y stack trace expuesto.
- **H2 (alto)**: `AC-001` debería tener `needs_review=1` (inconsistente con su propia nota y con las 20
  fichas gemelas); revisar también la concatenación triplicada de `review_note` en `SS-001`/`TD005`.

El resto (H4 diseño de permisos, H5/H6 UX de validación, H3 fixture desactualizado) son observaciones
importantes pero no bloquean la entrega si el cliente las acepta como conocidas. Los exports y el gate
del importador **pasan** los controles ejecutados.

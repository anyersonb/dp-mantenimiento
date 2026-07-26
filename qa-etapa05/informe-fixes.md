# Etapa 05 — Informe de correcciones · CMMS DP Development

Fase de fixes autorizada tras la corrida de QA. Orquestación: el dev implementa, QA verifica al cierre
de cada bloque, el jefe consolida. **No se avanza de bloque sin el PASS de QA del anterior.**

Snapshots de respaldo: `scratchpad/dp_mantenimiento_pre_qa05.sql` (previo a la corrida de QA) y
`scratchpad/dp_pre_fixes_A.sql` (previo a esta fase de fixes).

> **Estado: CERRADA.** Los 6 bloques (0 a 5) completados y verificados por QA. Suite final:
> **147 passed / 473 assertions / 1 failed** (`ExampleTest`, fallo preexistente y ajeno al proyecto).

---

## TABLA FINAL: HALLAZGO → CAMBIO → TEST → COMMIT → VERIFICACIÓN

| Hallazgo | Sev. | Qué se cambió | Commit | QA |
|---|---|---|---|---|
| **C1** WorkOrderResource sin ningún gate | Crítico | Gates completos + `->authorize()` en acciones; borrado solo administrador | `3651112f` | PASS (gerencia bloqueada al borrar **por payload**) |
| **C2** "confirmar ubicación" era "mover flota" | Crítico | `save()` revalida en servidor; `<select>` limitado; eventos de bitácora separados | `317bae41` | PASS (bloqueado por form **y por payload**) |
| **C3** `field_report` otorgado e inalcanzable | Crítico | Autorización por permiso | `dd8f5f74` | PASS (foreman entra y guarda) |
| **A1** el campo autorizaba por nombre de rol | Alto | `can()` en los 3 componentes y en el menú | `c3d8da9d` | PASS (cero `hasRole(` en `app/Livewire/Field/`) |
| **A3** bypass de `verify_data` | Alto | Barrera en `MachineObserver::saving()` | `b538bdaf` | PASS (form **y** payload) |
| **A4** `remaining_hours` corrompido | Alto | Regla de ancla unificada en un solo punto + backfill de 62 anclas | `b0a96edd`, `93834f1f`, `f512cb71` | PASS (EX013 no cae a −5480; PJ001 en `unknown`) |
| **A5** adjuntos en disco público | Alto | Disco privado + rutas autorizadas + invariante | `4981ea29`, `0ea3626d`, `5ab74fcf`, `e7dce6ed` | PASS (factura exige `view_costs`; anónimo nunca 200) |
| **A6** `execute_work_order` era permiso muerto | Alto | Ahora gobierna `canEdit` y la acción de completar | `3651112f` | PASS |
| **M4** lectura regresiva descartada en silencio | Medio | Rechazo explícito con mensaje en el camino de campo | `b0a96edd` | PASS (verificado en pantalla) |
| **M6** páginas de error sin marca ni traducción | Medio | 5 vistas con marca, i18n y salida por rol | `652436ed` | PASS |
| **M6-b** el 403 del panel ignoraba el idioma | Medio | `SetLocale` adelantada en la prioridad del kernel + `Route::fallback` para el 404 | `8b4628a1` | Verificado por el jefe en pantalla |
| **M7** config de despliegue | Medio | `.env.example` + checklist en `CLAUDE.md` + test de no-fuga | `6b2635d1` | PASS |
| **B5** `SESSION_SECURE_COOKIE` sin definir | Bajo | Documentada en `.env.example` | `6b2635d1` | PASS |
| — Cotización vencida se descargaba igual | (hallado al corregir) | La ruta por token respeta `expires_at` | `4981ea29` | PASS (vencida → 404) |
| — `QuoteResource` con el mismo defecto que C1 | (hallado por el inventario) | Cerrado con `manage_quotes` | `f3099501` | PASS |
| — Eventos de bitácora sin traducción | (frenado antes del commit) | Claves ES/EN + **test que las exige** | `13a79e99` | PASS |
| — Limpieza de los 4 registros `QA-` | — | Migración versionada por id exacto | `d73aaf04` | PASS |

## RECONCILIACIÓN DE HALLAZGOS — corrección de una cuenta que estaba mal

**Este informe declaró en su cierre "los 3 críticos y los 6 altos están cerrados". Era incorrecto en los
dos términos.** `informe.md` inventaría **7 altos** (A1…A7), no 6, y la tabla de arriba acredita **5**.
Auditado hallazgo por hallazgo en la Etapa 06, aparecieron **dos altos que no figuraban ni una vez en
este archivo**:

### A2 — **CERRADO**, acreditado retroactivamente (sin cambio de código)

A2 señalaba que todo `/admin/*` colgaba de una única whitelist (`User::canAccessPanel()`), sin defensa
en profundidad por Resource. **El Bloque 2 hizo exactamente lo que A2 recomendaba**, aunque no se anotó
como cierre de A2: hoy **9 de los 10 Resources declaran sus propios gates de escritura** y el
`PermissionSentinelTest` los exige, de modo que el riesgo tiene fix *y* test.

El Resource restante es **`AlertResource`, y su excepción es legítima**: tiene `canViewAny` y su
`getPages()` registra **solo `index`** — no existen páginas de crear, editar ni ver, porque las alertas
las genera el sistema (observer + `alerts:scan`), no un usuario. Sus acciones `acknowledge` y `resolve`
quedan cubiertas por `canViewAny`, y `create_work_order` tiene `->authorize()` propio.
**Matiz documentado, no hueco:** `acknowledge` y `resolve` no tienen permiso granular, así que quien
puede ver alertas puede resolverlas — hoy eso es administrador y responsable, que es razonable.

### A7 — **ABIERTO** al cierre de la Etapa 05, corregido en la Etapa 06

A7 (una cifra negativa en un campo numérico devuelve HTTP 500 en vez de un error de validación,
porque la columna es `int unsigned`) **apareció en la Ola 3, cuando los bloques ya estaban definidos, y
nunca entró en ninguno.** Quedó parcialmente cubierto **por efecto colateral y sin registro**: los tres
componentes de campo validan `min:0` y la acción de reemplazo de horómetro recibió `minValue(0)` en el
Bloque 1. Pero el caso original —el formulario principal de máquina— siguió abierto.

Se corrige en el pre-bloque de la Etapa 06, junto con un barrido de todas las columnas `unsigned` del
esquema. **Nota de diseño:** `remaining_hours` **no** lleva piso; es `int` firmado a propósito, porque
una máquina vencida tiene horas restantes negativas.

### A8 — **ABIERTO**. No es nuevo: es la deuda §1 del Bloque 2, promovida a hallazgo

**Honestidad de la cuenta primero:** esto **no se descubrió en la Etapa 06**. Está escrito en
`qa-etapa05/deuda-detectada.md` §1 desde el Bloque 2, con los 5 relation managers nombrados uno por uno
y con la misma evidencia de `vendor/filament/filament/src/helpers.php`. Se clasificó como deuda fuera de
alcance y **nunca se le puso red**. Lo que la Etapa 06 aporta es otra cosa, y vale enumerarlo sin
inflarlo:

1. **La red que faltaba.** El `PermissionSentinelTest` no cubría relation managers —solo
   `*Resource.php` y `app/Livewire`— así que la deuda no tenía forma de volver a aparecer sola. Ahora
   falla la suite.
2. **La cuenta: 15 acciones de escritura**, no "cinco relation managers". Nadie las había contado.
3. **El alcance del "hoy queda cubierto".** La nota de deuda dice que solo se llega pasando por el
   `canEdit()` del Resource dueño, y lo presenta como cobertura suficiente. Para las tres de OT ese
   `canEdit()` es **`execute_work_order`, que incluye al rol `taller`** — o sea que un técnico de taller
   puede **borrar la factura que él mismo adjuntó**. Eso no estaba dicho.
   *(Corrección: una versión anterior de esta tabla decía "taller y personal_mantenimiento". Falso,
   verificado contra `role_has_permissions`: `execute_work_order` lo tienen solo administrador y taller.
   `personal_mantenimiento` tiene 3 permisos —`field_report`, `log_horometer`, `view_fleet`— y no llega
   a las OT.)*
4. **Un error mío**: la matriz de cobertura de la Fase A afirmaba que el historial de lecturas era de
   solo lectura. Es falso, y lo verifiqué en la página equivocada (ver máquina, donde los relation
   managers se muestran sin acciones) en lugar de la de editar.

Es la **tercera aparición del mismo patrón** en el proyecto (flota arreglada / OT afuera → C1 ·
Resources cubiertos / relation managers afuera → esto).

**Por qué el hueco es real y no una omisión cosmética** (verificado en el código de Filament, no
supuesto): un relation manager autoriza sus acciones con `$this->can('create'|'update'|'delete')`, que
llama a `Filament\authorize($action, $model, shouldCheckPolicyExistence: true)`. Ese helper, cuando **no
existe una Policy** para el modelo relacionado, devuelve `Response::allow()`
(`vendor/filament/filament/src/helpers.php:24-43`). Este proyecto **no tiene `app/Policies`**, y no hay
ningún `Gate::before` propio; el de spatie devuelve `null` cuando el permiso no existe, así que no
deniega. Resultado: **en un relation manager, la autorización "heredada" es permiso abierto** para
cualquiera que alcance la página del Resource dueño.

**La asimetría que lo hizo invisible:** las *Pages* de Resource sí heredan de verdad, porque Filament
les inyecta `->authorize($resource::canX())` en `configureCreateAction()`/`configureEditAction()`/etc.
(`ListRecords.php:112-216`, `EditRecord.php:289-324`). Un relation manager y una page se leen igual en
el código y se comportan al revés.

| Relation manager | Acciones abiertas | Quién llega hoy | Qué puede hacer |
|---|---|---|---|
| `MachineResource\ReadingsRelationManager` | Create, Edit, Delete | `manage_machines` → **administrador, responsable** | Crear/editar/**borrar lecturas de horómetro a mano** |
| `MachineResource\PartsRelationManager` | Create, Edit, Delete | `manage_machines` → administrador, responsable | Catálogo de partes e intervalos de cambio |
| `WorkOrderResource\AttachmentsRelationManager` | Create, Edit, Delete | `execute_work_order` → **administrador, taller** | **Borrar facturas y adjuntos** de una OT |
| `WorkOrderResource\ChecklistResultsRelationManager` | Create, Edit, Delete | `execute_work_order` → administrador, taller | Borrar resultados de checklist |
| `WorkOrderResource\PartsRelationManager` | Create, Edit, Delete | `execute_work_order` → administrador, taller | Alta/baja de líneas de repuesto (los **costos** sí están ocultos por `view_costs`) |

Las dos filas de máquina son riesgo de integridad —tocan `remaining_hours` y el ancla del PM report—;
las tres de OT son además riesgo de **borrado de evidencia** por un rol de taller.

**Fix:** pendiente de decisión de Anyerson. No se aplicó todavía a propósito: se pidió ver primero el
alcance completo.
**Test:** `PermissionSentinelTest::test_every_relation_manager_protects_its_write_actions()` +
`::test_no_relation_manager_relies_on_a_policy_that_does_not_exist()`, escritos y **rojos hoy** con las
15 entradas; más `::test_every_custom_write_action_outside_a_resource_is_authorized()`, que pasa (las
acciones propias de Pages —`import_pm_report`, `export_pdf`, `export_excel`, `preload_checklist`— sí
traen su control).

**Corrección de método que se lleva puesta una afirmación previa del proyecto:** `->visible()` en una
**Action** de Filament **sí es control de servidor**, no solo de render. `isDisabled()` incluye
`isHidden()`, `isHiddenInGroup()` evalúa `hidden`, `visible` y `authorize` juntos, y tanto
`mountTableAction()` como `callMountedTableAction()` cortan cuando `isDisabled()`. Lo que es solo de
render es `->visible()` en un **campo de formulario o una columna**. La nota de `CLAUDE.md` decía lo
primero sin la distinción y quedó corregida.

### Causa raíz de la pérdida y regla nueva

Los bloques se definieron **por hallazgo**, así que un hallazgo que no entraba en un bloque no tenía
dónde aparecer. Se corrige con el **gate de reconciliación** documentado en `CLAUDE.md`: al cierre de
toda etapa, cada hallazgo mapea a fix + test o a una entrada explícita "abierto y aceptado" con su
motivo, **verificado hallazgo por hallazgo y nunca por conteo agregado por severidad**.

**Cuenta corregida de la Etapa 05: 3 críticos cerrados · 6 de 7 altos cerrados (A2 acreditado
retroactivamente, A7 quedó abierto y se corrige en la Etapa 06) · medios y bajos según la tabla.**

---

**Tres redes de seguridad quedaron instaladas**, que es lo que evita repetir esta etapa:

1. **`PermissionSentinelTest`** — falla si alguien agrega un Resource o componente de escritura sin
   control de permisos. Nació de C1 y está documentado como obligatorio en `CLAUDE.md`.
   **Alcance real al cierre de la Etapa 05: solo `*Resource.php` y `app/Livewire`.** Dejó pasar los 5
   relation managers (A8). Extendido en la Etapa 06 a relation managers, Pages y Widgets.
2. **Test de traducciones de eventos de bitácora** — falla si se emite un evento sin clave ES/EN.
3. **`NoSensitiveFileOnPublicDiskTest`** — falla si un archivo sensible vuelve al disco público.

## SEMÁFORO FINAL DE LOS 7 ROLES

administrador 🟢 · responsable_mantenimiento 🟢 · foreman 🟢 · operador_cisterna 🟢 ·
personal_mantenimiento 🟢 · taller 🟢 · gerencia 🟢

(foreman quedó en 🟡 en el informe de QA del Bloque 5 por M6-b; corregido y verificado después.)

## ESTADO DE LOS DATOS AL CIERRE

99 máquinas · 35 `needs_review` · 1 orden de trabajo · 5 alertas · 62 anclas sembradas · **0 registros
`QA-`**. Locales de usuario en su línea base (admin es · responsable en · foreman en · combustible en ·
campo en · taller es · gerencia es). Valores verificados del PM report intactos: EX010 415, EX013 234,
EX023 434, LD023 41, LD027 0, PW009 202, MS-TEMP-01 500, PJ001 y MS003 en `NULL`.

**Ningún dato real quedó modificado por esta fase.**

---

## BLOQUE 0 — Limpieza de los registros de prueba

| Qué | Detalle |
|---|---|
| Hallazgo | Residuo de la corrida de QA: 4 filas de prueba que el clasificador del entorno impidió borrar |
| Qué se cambió | Migración de limpieza versionada: `up()` borra por **id exacto** (sin patrones `LIKE`), `down()` vacío con comentario. Antes de borrar la obra fantasma se verificó que ninguna máquina apuntara a ella |
| Archivos | `database/migrations/2026_07_25_203026_limpieza_registros_qa_etapa05.php` |
| Commit | `d73aaf04` |
| Verificación del jefe | **PASS.** Consulta directa a BD: 99 máquinas · 35 `needs_review` · 1 OT · 5 alertas · `locations` de 11 → 10 · las 4 filas ausentes. El commit toca **solo** la migración |

La obra fantasma "QA-Obra-Test" era la prioridad: aparecía en el selector de obras y habría salido en
una demo al cliente. Ya no está.

---

## BLOQUE 1 — A4: regla del horómetro y `remaining_hours`

### Especificación previa (requisito del encargo)

Antes de tocar código se escribió `qa-etapa05/regla-horometro.md` con la regla esperada, el
diagnóstico de por qué se corrompía y los invariantes a no romper. Tres puntos no eran deducibles del
brief y se resolvieron con el jefe:

1. **Precedencia: "anclar y descontar".** El PM report fija un ancla verificada
   (`remaining_hours` + `current_hours` de esa fecha) y la lectura de campo **descuenta** las horas
   trabajadas desde ahí; el reporte siguiente vuelve a fijar el ancla. Se descartó "dejarlo intacto
   hasta el próximo reporte" porque congelaría el aviso de servicio entre reportes.
2. **MS012 no existe en la flota** (verificado en BD). Queda fuera de alcance y anotado como deuda.
   Los casos reales trabajados son EX013, PJ001, LD032 y MS-TEMP-01.
3. **LD032 se deja como está**: sus números son coherentes hoy y no se reinterpreta una nota escrita
   a mano modificando data real del cliente.

### El defecto, en concreto

La fórmula sumaba `hours_adjustment` a `current_hours` pero **no** a `last_service_hours`, aunque
ambos están en la misma escala de horómetro, y no contemplaba que tras un reemplazo las dos cifras
pertenezcan a escalas distintas. Resultado: EX013 (ajuste +5714) habría quedado en **−5480** en su
próxima lectura, y PJ001 (horómetro roto, último servicio 10455 contra horas 4901) **ya dio 6005**
durante la corrida de QA — "no necesita servicio nunca".

### Qué se cambió

| # | Cambio | Archivos |
|---|---|---|
| 1 | Dos columnas nullable para persistir el ancla verificada | migración `2026_07_26_022902_*` |
| 2 | Observer reescrito: descuenta desde el ancla; sin ancla usa el clásico **solo si `last_service_hours <= current_hours`**; si nada es válido → `NULL`. `hours_adjustment` ya no entra en la fórmula. `broken`/`no_info` nunca publican valor calculado | `app/Observers/HorometerReadingObserver.php` |
| 3 | El importador del PM report ahora **fija el ancla** además de escribir el valor | `app/Services/PmServiceReportImporter.php` |
| 4 | Lectura regresiva: **rechazo explícito con mensaje al usuario** en el camino de campo (hallazgo M4), manteniendo la tolerancia del importador (el Excel del cliente trae filas desordenadas) | `app/Livewire/Field/{ReportForm,FuelLog,ForemanBoard}.php` |
| 5 | Evento de reemplazo de horómetro: servicio + acción de Filament con gate `manage_machines`, re-ancla y queda en `activity_log` como evento propio | `app/Services/HourmeterReplacementService.php`, `app/Filament/Resources/MachineResource.php` |
| 6 | **Regla unificada en un solo lugar** (`Machine::calculateRemainingHours()`): observer y accessor delegan ahí | `app/Models/Machine.php` |
| 7 | Comando de auditoría de solo lectura `horometer:audit-remaining` | `app/Console/Commands/AuditRemainingHours.php` |
| 8 | Textos ES/EN de los mensajes nuevos | `lang/{es,en}/{field,fleet,mgmt}.php` |

**Commits:** `b0a96edd` (observer + importador + campo + reemplazo) · `93834f1f` (backfill) ·
`f512cb71` (unificación de la regla y accessor).

### Dos correcciones de criterio durante el bloque

**1. Frené el backfill original.** El dev, cumpliendo la instrucción, escribió un backfill que
recalculaba las filas desalineadas. Al revisar el conteo antes de ejecutarlo (requisito del encargo)
salió que **ninguna máquina tenía ancla todavía** — la columna acababa de crearse —, así que "el valor
con la regla nueva" caía siempre al cálculo clásico. En EX023 (434 vs 427), LD023 (41 vs 8), LD027
(0 vs 10) y PW009 (202 vs 120) el número guardado **es el del PM report revisado a mano**: esa
diferencia no es corrupción, es exactamente la razón por la que el snapshot tiene precedencia.
Ejecutarlo habría cometido el error de A4 en la dirección contraria. Con la decisión del jefe, el
backfill quedó reescrito para **sembrar el ancla sin sobrescribir ningún `remaining_hours`**.

**2. A4 no estaba completo: la fórmula estaba duplicada.** El dev detectó, y verifiqué yo mismo, que
`Machine::getComputedRemainingHoursAttribute()` tenía **una segunda copia de la fórmula rota**, usada
como fallback cuando `remaining_hours` es `NULL` y consumida por la tabla de flota, `is_due_soon`,
`is_overdue`, `service_status` y `alerts:scan`. Habíamos arreglado el escritor y no el lector: PJ001
seguía mostrando 6054 en el semáforo pese al fix. **Mi especificación se equivocó** al asumir que el
semáforo ya resolvía el caso `NULL`. Se mandó unificar la regla en un único punto para que no aparezca
una tercera copia.

### Backfill — resultado (ejecutado con autorización explícita, tras mostrar el conteo)

- **62 anclas sembradas** (no 63): el dev excluyó **MS-TEMP-01** con buen criterio, porque anclarla en
  500 @ 1 h habría fabricado una línea base a partir de un valor de relleno. Lo documentó en vez de
  forzar el número que yo había pedido.
- **`remaining_hours` no cambió en ninguna fila**: 64 no nulos antes y después.
- Valores verificados preservados: EX023 = 434, LD023 = 41, LD027 = 0, PW009 = 202, MS-TEMP-01 = 500.
- **Nota menor:** el mensaje del commit `93834f1f` dice "63 máquinas" y fueron 62. No se reescribe
  historia por eso; queda constancia acá.

### Verificación visual del jefe (navegador real)

| Prueba | Resultado |
|---|---|
| PJ001 en el listado de flota | **PASS** — columna "Restantes" **vacía** (desconocido). Antes habría mostrado 6054. Comparado contra EX010, que sí muestra "415 h" |
| Acción "Registrar reemplazo de horómetro" | **PASS** — visible para administrador en la fila de la máquina, junto a Ver / Editar / Mover |
| Mensaje de rechazo de lectura regresiva (M4) | **PASS** — como `campo@dp.local` en `/field/report` sobre EX010 (9793), enviando 9000: *"The reading (9000 h) is lower than the last one recorded (9793 h). Check the value before submitting."* |
| ¿El rechazo escribió algo? | **PASS** — nada: EX010 intacto (9793, ancla 415@9793), cero lecturas con 9000 h, `max(id)` de `horometer_readings` sigue en 154, 0 reportes de campo. El comportamiento viejo insertaba la fila en silencio |

**Observación de UX (no se corrige, fuera de alcance):** "desconocido" se muestra como celda vacía,
que se puede leer como "falta el dato" en vez de "no se puede calcular". Un "—" o un badge
"Desconocido" sería más claro. Va a deuda.

### Tests

15 tests nuevos en el primer commit y más en la unificación: EX013 no cae a −5480, PJ001 → `NULL` y
`service_status` = `unknown`, PJ001 ya no genera alerta en `alerts:scan`, MS003 sigue en `NULL`,
lectura normal descuenta, ancla de EX010 sobrevive a una lectura posterior (415 @ 9793 → 358),
rechazo de regresiva en los 3 componentes de campo, importador tolera regresivas y fija el ancla,
evento de reemplazo re-ancla y deja bitácora. **Suite: 81 passed / 1 failed** (`ExampleTest`, fallo
preexistente y ajeno al proyecto).

### QA del bloque — **PASS** (informe en `qa-etapa05/qa-bloque1.md`)

Verificación independiente por HTTP y BD, con dummies `QA-` para todo lo que escribe y solo lectura
sobre las máquinas reales. Los 8 puntos con control positivo y negativo:

| Punto | Resultado |
|---|---|
| EX013 (ajuste +5714), dummy, lectura +5850 | **PASS** — da 192, no −5480 |
| PJ001 real (solo lectura) | **PASS** — `remaining_hours` y `computed_remaining_hours` en `NULL`, `service_status` = `unknown` (antes 6054 / 6005) |
| `alerts:scan` con PJ001 | **PASS** — 0 alertas antes y después; antes entraba con el número inventado |
| Lectura normal (dummy) | **PASS** — descuenta |
| Ancla sobrevive (dummy EX010: 415@9793 + lectura 9850) | **PASS** — 358 exacto |
| Lectura regresiva | **PASS** — rechazo con mensaje explícito en ES y EN; tolerada en el camino tipo-importador |
| Evento de reemplazo | **PASS** — re-ancla y deja evento propio `hourmeter_replaced` en `activity_log` |
| Valores verificados sin mover | **PASS** — MS003, EX023 434, LD023 41, LD027 0, PW009 202, MS-TEMP-01 500 |

**Regresión del bloque:** taller y gerencia siguen con 403 en create/edit de máquinas (en ES y EN) —
el fix del 21/07 sigue vigente. Costos sin fuga a operador_cisterna ni personal_mantenimiento (403 en
reportes, cero cifras en `/field`). Ningún caso de 403-en-ES / 200-en-EN. foreman sigue con C3, que es
del Bloque 3 y no empeoró.

**Suite: 81 passed / 1 failed / 271 assertions** (el fallo es `ExampleTest`, ajeno).
**Datos reales modificados: NINGUNO**, verificado en BD antes y después. Los 4 dummies `QA-`, 3
lecturas y 1 evento de bitácora fueron creados y **borrados por completo**.

### Hallazgo nuevo detectado por QA en el propio fix → se corrige en el Bloque 2

QA notó que la acción "Registrar reemplazo de horómetro" autoriza **solo con `->visible()`**, que
controla el renderizado y no la ejecución en servidor. Lo verifiqué: `MachineResource.php:332`, y la
acción "Mover a obra" tiene el mismo patrón. QA lo marcó como no bloqueante; **decidí subirlo al
Bloque 2**, porque es exactamente la clase de defecto que ese bloque tiene que erradicar y hoy vive en
código que acabamos de escribir. No se deja como observación.

---

## BLOQUE 2 — C1 (crítico) y A3 (alto): control de permisos en toda la escritura

El encargo fue explícito en no tratarlo como "arreglar WorkOrderResource". Se hizo **inventario
completo primero** (`qa-etapa05/inventario-escritura.md`), y valió la pena: apareció un segundo
Resource con el mismo defecto que nadie había reportado.

### Inventario: 30 componentes de escritura relevados

10 Resources + 12 acciones y acciones masivas + 5 relation managers + 3 componentes Livewire de campo.

- **10 sin ningún control**: `WorkOrderResource` completo, `QuoteResource` en 4 de 5 operaciones
  (solo tenía `canViewAny`) y la acción `preload_checklist`.
- **7 acciones con el permiso correcto pero solo en `->visible()`**, o sea control de renderizado sin
  autorización en servidor.

**`QuoteResource` no estaba en el informe de QA**: lo encontró el inventario. Es la confirmación de
que el enfoque archivo-por-archivo habría dejado el cuarto caso para la Etapa 06.

### Qué se cambió

| Hallazgo | Cambio | Commit |
|---|---|---|
| **C1** | `WorkOrderResource`: `canViewAny`/`canView` (`view_fleet`), `canCreate` (`create_work_order`), `canEdit` (`execute_work_order`), `canDelete`/`canDeleteAny` **solo administrador**. Acciones `complete` y `preload_checklist` con `->authorize()` | `3651112f` |
| **A3** | `App\Observers\MachineObserver::saving()` revierte cualquier cambio de `needs_review` si el usuario autenticado no tiene `verify_data`. Cubre payload manipulado, que el form no puede cubrir porque `Machine` usa `$guarded = []`. Los procesos sin usuario autenticado (seeders, importador) no se bloquean | `b538bdaf` |
| **`->visible()` sin autorización** | `approve`, `move`, `replaceHourmeter`, las masivas de `MachineResource` y `AlertResource::create_work_order` ahora también `->authorize()` | `b538bdaf`, `f3099501` |
| **QuoteResource** (nuevo, del inventario) | Cerrado con `manage_quotes` en las 5 operaciones | `f3099501` |
| **A6 de paso** | `execute_work_order` deja de ser un permiso muerto: ahora gobierna `canEdit` y la acción de completar | `3651112f` |
| **Centinela + CLAUDE.md** | ver abajo | `8abf58a8` |

### Test centinela — la parte que tiene que sobrevivir a la Etapa 06

`tests/Feature/Security/PermissionSentinelTest.php` (257 líneas). Lo revisé y está bien construido:

- Descubre por Reflection **todos** los `*Resource.php` y exige los `can*()` que correspondan según
  las páginas que cada Resource registre en `getPages()`.
- Exige que el gate esté **declarado en la propia clase** (`methodOwnedByClass`), no heredado — si no,
  el default permisivo de Filament pasaría desapercibido, que es exactamente el bug de C1.
- Recorre los componentes Livewire con métodos de escritura y exige un marcador de autorización,
  **descartando comentarios con `token_get_all()`** (lo agregaron tras detectar un falso positivo real).
- El mensaje de fallo nombra **clase y operación** concretas.
- El dev probó que muerde agregando un Resource y un Livewire temporales sin gates, comprobando el
  fallo y borrándolos.

**Limitación honesta:** para los componentes Livewire el chequeo es textual (busca un marcador de
autorización en el archivo), así que es un cable trampa, no una demostración. Detecta el olvido —que
es el caso real— pero se puede satisfacer con un gesto simbólico. Para los Resources, en cambio, la
verificación es estructural y sólida.

Se creó **`CLAUDE.md`** en la raíz del proyecto (no existía) documentando: que el centinela es
obligatorio y no se saltea, la historia de C1 como motivo, la matriz de permisos, las reglas
aprendidas (`->visible()` no basta; un campo `disabled()` no es barrera con `$guarded = []`) y el
aviso de no usar la lista de excepciones como válvula de escape.

### Verificación del jefe (navegador real, sesión autenticada)

| Prueba | Resultado |
|---|---|
| gerencia → `/admin/work-orders/create` y `/3/edit` | **PASS** — 403 y 403 (antes 200 y 200) |
| gerencia → listado de OT: botones de escritura | **PASS** — **ninguno**. Antes tenía "New Work order" y borrado masivo activo |
| gerencia → `/admin/work-orders` (listar) | 200, correcto: tiene `view_fleet` |
| taller → `/admin/work-orders/create` | **PASS** — 403 (antes 200) |
| taller → `/admin/work-orders/3/edit` | **PASS** — 200, correcto: tiene `execute_work_order` |
| taller → `/admin/machines/create` y `/6/edit` | **PASS** — 403: el fix del 21/07 sigue intacto |
| taller → `/admin/quotes`, `/admin/users` | **PASS** — 403 |

**Nota de método, por si aparece en otros informes:** en una primera medición vi
`/admin/machines/create = 200` para taller, lo que habría sido una regresión grave. Era un falso
positivo: la sesión no estaba autenticada y `fetch` seguía la redirección al login, así que el 302 se
veía como 200. Repetí todo con `redirect:'manual'` y confirmando la identidad de la sesión. Avisé a QA
para que no cayera en lo mismo.

### Tests

17 nuevos: `WorkOrderPermissionsTest` (9), `MachineDataIntegrityTest` (6), `PermissionSentinelTest` (2).
**Suite: 98 passed / 1 failed** (`ExampleTest`, ajeno).

### QA del bloque — **PASS** (informe en `qa-etapa05/qa-bloque2.md`)

PASS en los 11 puntos, verificado por HTTP real con **payload Livewire forjado** (no solo comprobando
que el botón no esté) y por BD, y con la identidad de sesión confirmada antes de medir:

- **C1 cerrado:** gerencia bloqueada al intentar **borrar por payload** una OT dummy; administrador sí
  borra; taller y gerencia con 403 en create/edit; responsable crea pero no borra.
- **QuoteResource cerrado:** 403 en las 3 rutas para 3 roles distintos.
- **A3 cerrado:** responsable no apaga `needs_review` ni por formulario ni por payload manipulado;
  administrador sí; el importador y los seeders (sin usuario autenticado) siguen funcionando.
- **`->visible()` → `->authorize()`:** confirmado por payload en `move`, `approve`,
  `replaceHourmeter` y `complete`, con control positivo y negativo.
- **El centinela muerde de verdad:** QA creó por su cuenta un Resource y un componente Livewire
  temporales sin control, el test falló nombrando ambas clases y sus operaciones exactas, los borró y
  la suite volvió a verde.
- `AlertResource::create_work_order` no admite prueba negativa porque hoy no existe un rol que llegue
  a ese recurso sin `create_work_order` — limitación estructural de la matriz, no defecto.

**Regresión:** A4 del Bloque 1 intacto (PJ001 en NULL/`unknown`, anclas y valores verificados sin
moverse, EX010 en 415@9793), fix de flota del 21/07 vigente, cero fuga de costos a los tres roles de
campo, sin diferencia ES/EN.

**Suite: 98 passed / 1 failed** (`ExampleTest`). **Datos reales modificados: NINGUNO** (la OT real
id 3 y EX010 verificadas intactas al cierre). Todos los registros `QA-` borrados y confirmados en cero.

---

## BLOQUE 3 — C2, C3 (críticos) y A1 (alto): autorización por permiso en el módulo de campo

Es el bloque con más valor comercial: hasta acá el editor de roles que le entregamos al cliente **no
gobernaba el módulo de campo**. Si DP le quitaba un permiso a un foreman creyendo que lo restringía,
no pasaba nada.

### Qué se cambió

| Hallazgo | Cambio | Commit |
|---|---|---|
| **A1** | Los tres componentes de `app/Livewire/Field/` y el menú de `/field` autorizan por **permiso** (`can()`), ya no por nombre de rol. Se eliminó el patrón `abort_unless(hasRole('<rol>'))` | `c3d8da9d` |
| **C3** | `field_report` operativo para foreman: `/field/report` pasa de 403 a accesible y funcional | `dd8f5f74` |
| **C2** | "Confirmar ubicación" y "mover flota" quedan como facultades distintas (detalle abajo) | `317bae41` |
| Promesa del producto | Test de aceptación: quitar un permiso desde `RoleResource` se refleja en campo | `e902d952` |
| i18n de bitácora | Claves de los eventos nuevos + **test que exige que todo evento tenga traducción ES/EN** | `13a79e99` |

### C2 — cómo quedó la distinción (revisado por mí)

La implementación no colapsó las dos facultades, que era el riesgo:

- `mount()` autoriza con `confirm_location` **o** `move_fleet`: el tablero sirve a los dos perfiles.
- `save()` **revalida en el servidor**: si la obra elegida difiere de la actual, exige `move_fleet` con
  un `abort_unless`. Así no lo saltea ni un `<select>` manipulado ni un payload Livewire directo.
- El `<select>` solo ofrece lo asignable: todas las obras con `move_fleet`, únicamente la obra actual
  con `confirm_location`.
- La bitácora distingue **`location_moved`** de **`location_confirmed`** con asientos explícitos
  (necesarios porque una ratificación no cambia ningún atributo y `logOnlyDirty` no registraría nada).

### Regresión de i18n que frené antes del commit

Los dos eventos nuevos de bitácora no tenían claves de traducción, y `ActivityResource` los renderiza
con `__('mgmt.event_'.$state)`: habrían aparecido crudos en pantalla como `mgmt.event_location_moved`.
**Es exactamente el defecto que este proyecto ya sufrió el 2026-07-21 con `event_approved`.** Se
agregaron las cuatro claves (ES/EN) y, más importante, un **test que recorre los eventos que el código
puede emitir y exige que cada uno tenga traducción en los dos idiomas** — mismo espíritu que el
centinela de permisos: que el próximo evento sin traducir rompa la suite en vez de llegarle crudo al
cliente.

### La verificación que importa: el editor de roles contra la app real

El test de aceptación pasa, **pero eso solo no prueba nada**: los tests corren con `CACHE_STORE=array`
mientras la app real usa `database` con la caché de permisos de Spatie viviendo **24 horas** (verifiqué
que existía la clave `spatie.permission.cache` con vencimiento a 24 h). Un cambio de permisos podía
quedar sin efecto hasta que expirara. Además, el comentario del test menciona un
`RoleResource::forgetPermissionCache()` que **no existe en `app/`**.

Así que lo verifiqué a mano contra la aplicación corriendo:

1. Como administrador, en `/admin/roles/4/edit`, le quité `log_fuel` al rol `operador_cisterna` y
   guardé — el flujo real del cliente.
2. **La clave de caché de Spatie se borró sola** (0 claves en la tabla `cache`).
3. Un **proceso nuevo** de PHP respondió `can('log_fuel') = false`.
4. Con sesión real de `combustible@dp.local`: **`/field/fuel` → 403**, y el **menú de `/field` dejó de
   ofrecer "Log fuel"** (solo Home y Log out). Antes del fix, quitar ese permiso no cambiaba nada.
5. **Restauré** el permiso y verifiqué que volvió: rol con sus 3 permisos, `can('log_fuel') = true`, y
   la matriz completa de vuelta en 15/6/4/3/3/4/4.

**La promesa del producto se cumple en el entorno real, no solo en la suite.**

### Suite

Corrida por mí en primer plano: **106 passed / 343 assertions / 1 failed** (`ExampleTest`, ajeno),
138 s. Incluye el centinela del Bloque 2 y la validación de lectura regresiva del Bloque 1, ambos verdes.

### Nota de proceso

El agente de desarrollo se colgó dos veces esperando la notificación de una corrida de `artisan test`
lanzada en segundo plano. Tomé el cierre: corrí la suite en primer plano y verifiqué los commits. El
trabajo estaba completo y correctamente commiteado (5 commits, uno por hallazgo).

### QA del bloque — **PASS** (informe en `qa-etapa05/qa-bloque3.md`)

- **C3 PASS:** foreman entra a `/field/report` (200, antes 403) y guardó un reporte real en BD.
- **C2 PASS:** foreman **confirma** la ubicación (evento `location_confirmed`) pero **no puede mover**
  — bloqueado tanto por el formulario (el `<select>` solo le ofrece la obra actual) como **por payload
  manipulado** (403 y la máquina sin cambiar en BD). Gerencia sí mueve, con evento `location_moved`.
- **A1 PASS:** cero `hasRole(` en `app/Livewire/Field/`; menú y accesos gobernados por `can()`; el
  cruce de permisos correcto en las 6 combinaciones probadas.
- **Editor de roles PASS contra la app real:** QA lo replicó con un rol dummy propio (para no tocar los
  7 reales) y confirmó que revocar el permiso bloquea el acceso en campo de inmediato, sin limpiar
  caché a mano, con la caché `database` de 24 h activa — no la `array` de los tests.
- **Bitácora PASS:** los eventos nuevos se muestran traducidos en ES y EN, sin claves crudas.
- **Regresión PASS:** anclas y valores de A4 intactos, 403 de OT y flota vigentes, centinela verde.
- **Suite 106 passed / 1 failed** (coincide con mi corrida). **Matriz de permisos al cierre:
  15/6/4/3/3/4/4** confirmada por conteo en BD. **Datos reales modificados: NINGUNO.**

Limitación declarada por QA: sin navegador (reservado al hilo principal), lo visual se verificó sobre
el HTML real devuelto por Livewire, no por captura.

---

## BLOQUE 4 — A5 (alto) + auditoría de la ruta de subida

Los dos iban juntos porque son el mismo riesgo por dos caminos, y la subida por UI era **lo único del
informe original que había quedado sin verificar**.

### El hallazgo confirmado en su origen

La auditoría respondió la pregunta que faltaba: **los tres `FileUpload` escribían a `disk('public')`**
(adjuntos de OT, cotizaciones e imágenes de máquina). No era solo que un archivo viejo estuviera
expuesto: **todo lo que se subiera iba a quedar público**.

### Qué se cambió

| Qué | Cómo quedó | Commit |
|---|---|---|
| Adjuntos de OT (fotos y **facturas**) | Disco privado, servidos por `GET /attachments/{attachment}/archivo` con `auth` + `view_fleet`, y **`view_costs` adicional si el adjunto es factura** | `4981ea29` |
| Archivos de cotización | Disco privado, servidos por `GET /quotes/{token}/archivo`: público por `share_token` (la función que pidió el cliente) y **respetando `expires_at`** | `4981ea29` |
| Validación de subida | `app/Rules/RejectsDangerousUploadExtensions.php` | `0ea3626d` |
| Migración de lo ya subido | Copia verificada por sha256, idempotente | `5ab74fcf` |
| Cierre real de A5 | Borrado del original público + test de invariante | `e7dce6ed` |

### Funcionalidad del cliente que estuvo a punto de romperse

Antes de lanzar el bloque revisé la vista pública y encontré que enlazaba directo a
`Storage::disk('public')->url($quote->file_path)`. La ruta `GET /quotes/{token}` **es pública a
propósito**: el administrador manda el link a alguien sin cuenta. Mover las cotizaciones a disco
privado sin más habría matado esa función, así que el encargo exigió servirlas **por una ruta que
valide el token**.

De paso se cerró un agujero relacionado: la vista ya calculaba `$expired`, pero como el enlace era una
URL cruda de storage, **una cotización vencida se seguía descargando igual**. Ahora, vencida no se
entrega.

### A5 no quedó cerrado en el primer intento

El dev migró el archivo al disco privado pero **dejó el original en el público**, así que
`GET /storage/quotes/demo-quote.pdf` **seguía respondiendo 200 sin sesión** — la URL exacta del
hallazgo. La puerta nueva estaba bien construida y la vieja seguía abierta.

Se quedó del lado conservador de mi regla de no borrar archivos reales, cuando la regla permitía
borrar precisamente porque él ya había verificado el hash. Lo devolví con la condición de borrar solo
si el hash coincide en tiempo de ejecución, nunca a ciegas, y **con un test de invariante**: que ningún
`path` de `quotes` ni `work_order_attachments` exista en el disco público. Ese test es el que evita que
la próxima subida o migración vuelva a abrir la puerta — el mismo criterio que el centinela de
permisos del Bloque 2.

### Verificación del jefe tras el segundo intento

| Prueba | Resultado |
|---|---|
| `GET /storage/quotes/demo-quote.pdf` sin sesión | **Cerrado** — ya no entrega el PDF (respuesta de error de 6.659 bytes, no el archivo de 48). Un path que nunca existió devuelve lo mismo, así que no es una fuga |
| `storage/app/public/quotes/` | **vacío** · `storage/app/private/quotes/demo-quote.pdf` intacto |
| Ruta por token, **sin sesión** | **200**, `application/pdf`, 48 bytes — **la función del cliente sigue viva** |
| Token inválido | **404** |
| Vista pública de la cotización | ya enlaza a la ruta autorizada, sin `Storage::disk` |
| Path traversal (`/quotes/../../.env/archivo`) | **404** |

**Nota:** el servidor de dev de PHP responde 403 en vez de 404 para estáticos inexistentes; en Apache
o nginx sería 404. No es una fuga, es un detalle del entorno local.

### Decisión aceptada: las imágenes de máquina se quedan públicas

Justificación del dev que acepté: no son evidencia de costos y `disk('local')` no soporta
`temporaryUrl()` (a diferencia de S3), así que privatizarlas rompería la galería y los thumbnails del
panel sin beneficio proporcional. Se reforzaron igual con whitelist de extensión y tamaño máximo.
Queda como **riesgo aceptado y documentado**, no como defecto pendiente.

### Tests y suite

23 tests nuevos del bloque (`SensitiveUploadsAuthorizationTest`, `SensitiveUploadsValidationTest`,
`RejectsDangerousUploadExtensionsTest`) más `NoSensitiveFileOnPublicDiskTest` — que incluye una
**prueba negativa** que confirma que el detector marca una fuga simulada, para que el invariante no sea
tautológico. **Suite: 132 passed / 1 failed** (`ExampleTest`, ajeno).

### QA del bloque — **PASS** (informe en `qa-etapa05/qa-bloque4.md`)

- **Adjuntos:** anónimo → 302, **nunca 200**. Sin `view_costs` sobre una factura → **403**; con
  `view_costs` → 200 y contenido correcto. Foto sin `view_costs` → 200, coherente con la regla
  (`view_fleet` siempre, `view_costs` extra solo si `type=invoice`). **IDOR** cambiando de OT: la regla
  se evalúa igual, no alcanza con estar logueado.
- **Cotización vencida → 404: bloqueada de verdad.** Era el agujero real y quedó cerrado.
- **Invariante del disco público:** los 6 paths de BD (reales + dummies) confirmados ausentes del disco
  público, verificado también a mano; `NoSensitiveFileOnPublicDiskTest` 3/3.
- **La regla de subida está cableada en los tres `FileUpload`** — confirmado por código y probado en
  vivo contra la regla real: `.pdf.php` y `.php.pdf` rechazados, Content-Type incompatible rechazado,
  tamaño excedido rechazado, acentos y ñ aceptados, path traversal rechazado en 4 variantes.
- **Galería de máquinas:** mecanismo intacto y detalle en 200. QA declara honestamente que **no pudo
  probarlo con una foto real** porque ninguna máquina de esta BD tiene `image`/`gallery` poblados — es
  ausencia de dato, no defecto. Queda como pendiente de verificar cuando el cliente suba fotos.
- **Regresión completa PASS** (Bloques 1, 2 y 3, flota del 21/07, costos, ES/EN).
- **Suite: 132 passed / 1 failed.** Limpieza: 4 tablas y 6 archivos `QA-` creados y **borrados**,
  verificados en cero en BD y en disco. **Ningún dato real tocado.**

**Matriz de permisos al cierre, verificada por mí por id de rol:** administrador 15 ·
responsable_mantenimiento 6 · foreman 4 · operador_cisterna 3 · personal_mantenimiento 3 · taller 4 ·
gerencia 4. (QA la reportó con otro orden; mismos datos.)

---

## BLOQUE 5 — M6 (páginas de error) y M7 (despliegue)

### Por qué M6 dejó de ser cosmético

Con los Bloques 2, 3 y 4 el sistema **emite muchos más 403 que antes**: es la respuesta de diseño cada
vez que un rol toca lo que no le corresponde. Una pantalla en blanco, en inglés y sin salida pasó de
detalle a problema de uso real.

### Qué se cambió

| Qué | Cómo quedó | Commit |
|---|---|---|
| **M6** | `resources/views/errors/{403,404,419,500,503}.blade.php` sobre un componente compartido `components/error-page.blade.php`: logo del cliente, estilo de tarjeta, responsive desde 390 px, textos con `__()` y claves nuevas en `lang/{es,en}/errors.php` | `652436ed` |
| **M7** | `.env.example` documenta `APP_ENV`, `APP_DEBUG`, `APP_URL` y `SESSION_SECURE_COOKIE` (hallazgo B5) con valores seguros; sección "Despliegue a producción" en `CLAUDE.md` con variables y comandos. **El `.env` local no se tocó** | `6b2635d1` |

Dos decisiones del dev que acepté:

- **El botón de salida reutiliza la regla que ya existía** en `Login::targetUrl()` en vez de duplicar
  la lógica: autenticado con acceso al panel → `/admin`; autenticado de rol de campo → `/field`;
  invitado → login.
- **No agregó selector de idioma a las páginas de error**, para no darle superficie nueva al bug B3
  (bucle de redirección de `/locale/{idioma}`), que está fuera de alcance. Buen criterio.

### Verificación del jefe (navegador real, las dos ramas del botón)

| Usuario | Idioma | Resultado |
|---|---|---|
| `foreman` (rol de campo, **no** puede entrar al panel) | EN | Título "Error 403 — DP Fleet Maintenance", logo presente, texto traducido "403 Access not allowed…", y el botón apunta a **`/field`** |
| `gerencia` (sí puede entrar al panel) | ES | Logo presente, "403 Acceso no permitido. Tu usuario no tiene permiso para ver esta página…", botón "Volver al panel" → **`/admin`** |

En ninguna de las dos aparecen claves crudas. En 390 px: `scrollWidth == clientWidth`, cero elementos
desbordados, botón de 318×45 px (por encima del mínimo táctil).

**El caso que importaba** era el rol de campo: si el botón lo mandaba a `/admin`, lo metía en otro 403.
Apunta a `/field`, correcto.

### Prueba de M7

El test fija `app.debug => false`, dispara una excepción con un dato sensible en el mensaje y verifica
con `assertDontSee` que no aparecen el nombre de la excepción, la ruta sensible, "Stack trace",
"ignition" ni "Whoops".

**Tests nuevos:** `tests/Feature/ErrorPagesTest.php` (10). **Suite: 142 passed / 1 failed**
(`ExampleTest`, ajeno).

### QA del bloque — **APROBADO CON OBSERVACIÓN**, y la observación se corrigió

QA dio PASS a los 9 puntos de M6/M7 y confirmó el destino del botón por rol vía HTTP real
(panel → `/admin`, campo → `/field`, invitado → login), la paridad de claves ES/EN, el `.env` local
intacto, `.env.example` con las 4 variables y el 500 sin fugas con `debug=false`.

Pero levantó un **hallazgo nuevo, M6-b**, y significaba que M6 estaba a medias.

### M6-b — el 403 del panel ignoraba el idioma del usuario

Lo verifiqué en vivo antes de mandarlo a corregir: `foreman@dp.local` con `locale = 'es'` — el PWA de
campo le salía en español ("Inicio · DP Fleet") — y al entrar a `/admin` recibía la página de error
**en inglés** (`lang="en"`, "Access not allowed").

Importa porque es **el 403 más frecuente del sistema** y precisamente el que ve un operario de campo.
El test existente no lo detectaba porque usaba un `abort(403)` genérico en vez del 403 real de
`canAccessPanel()`.

**Causa raíz (buen diagnóstico del dev):** Laravel no ordena el pipeline por el orden en que se
declaran los middleware de un grupo, sino por la lista de prioridad del kernel — y `SetLocale` no
estaba en ella, así que quedaba relegada al final, **después** del `abort_if(403)` que
`Filament\Http\Middleware\Authenticate` ejecuta dentro de su propio `handle()`.

**Fix:** `bootstrap/app.php`, `prependToPriorityList(before: AuthenticatesRequests::class, prepend: SetLocale::class)`.
Reutiliza el mismo `SetLocale`, sin duplicar lógica de idioma ni tocar su orden declarado.

**Hallazgo adicional que salió tirando del hilo:** el 404 de una URL genuinamente inexistente **no
pasa por el middleware de ningún grupo** (Laravel lo resuelve en el router antes de armar el pipeline),
así que tampoco se traducía. Se resolvió con un `Route::fallback` con `['web', SetLocale::class]`.

**Verificación del jefe, en pantalla, con foreman en `locale = es`:**

| Página | Antes | Ahora |
|---|---|---|
| 403 de `canAccessPanel()` en `/admin` | `lang="en"` · "Access not allowed" | **`lang="es"` · "403 Acceso no permitido"**, botón → `/field` |
| 404 de URL inexistente | en inglés | **`lang="es"` · "404 Página no encontrada"** |

**Commit:** `8b4628a1`. **Tests:** 5 nuevos, todos por el flujo real y no por `abort()` ad hoc.
**Suite corrida por mí en primer plano: 147 passed / 473 assertions / 1 failed** (`ExampleTest`, ajeno).

---

## DEUDA DETECTADA

Ver `qa-etapa05/deuda-detectada.md`. Resumen:

1. **Cinco máquinas con `remaining_hours` desalineado del par (horas, último servicio)** por causas
   ajenas al bug del ajuste: EX023, LD023, LD027, PW009 y RL017. El backfill las ancla **tal como
   están** (el snapshot manda por diseño), así que si el snapshot ya venía desalineado, se perpetúa.
   Conviene re-verificarlas contra el PM report más reciente antes de dar el dato por bueno.
2. **MS012** no existe en la flota; aclarar con el cliente por qué figuraba en el encargo.
3. **UX de "desconocido"** como celda vacía (arriba).
4. **M8 sigue vivo:** el horómetro redondea decimales en silencio (`12.5` → `13`). No estaba en el
   alcance autorizado de este bloque.

## PENDIENTE DE ALCANCE NUEVO (pedido del jefe durante la fase)

**Extender el importador de Excel** para que actualice también `status` (activa / fuera de servicio /
down / inactiva) y ubicación, además de horas. Se posterga a después de los bloques de fixes para no
tocar `PmServiceReportImporter` en paralelo con el Bloque 1, que ya lo modifica.
**Bloqueo previo:** el Excel real del cliente **no trae** columnas de estado ni ubicación — hay que
definir con DP el nombre de las columnas y los valores aceptados, o queda un importador que nadie
puede alimentar.

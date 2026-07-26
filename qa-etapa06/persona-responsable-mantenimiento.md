# Sesión 1 — responsable_mantenimiento · Parte 1: lecturas de horómetro a mano

**Usuario:** `responsable@dp.local` · **Máquina de prueba:** `QA-RESP-01` (id 116), creada y destruida
dentro de la sesión. **Ninguna máquina real fue tocada.**

> **Alcance de este documento.** La **Parte 1** (abajo) cubre el bloque de **lecturas a mano** (puntos
> 2 y 3 de la corrección en caliente), medido **antes** de los fixes. La **Parte 2** (al final) cubre lo
> que quedaba de la Sesión 1 —OT `QA-` para el taller, exportaciones desde el botón, alertas y
> bitácora— y se corrió **después** de los cuatro commits, así que también sirve de verificación de que
> los fixes de horómetro funcionan en el navegador y no solo en la suite.

## Resumen: las cuatro preguntas, respondidas

| | Pregunta | Respuesta | Severidad |
|---|---|---|---|
| a | Editar una lectura que no es la última, ¿recalcula? | **No recalcula nunca.** Si el valor nuevo supera al de la máquina, el panel queda atrasado sin avisar | **ALTO** |
| b | Borrar la última lectura, ¿revierte el horómetro? | **No.** La máquina queda apuntando a una lectura que ya no existe | **ALTO** |
| c | ¿Existe el rechazo de lectura regresiva en este camino? | **No.** Se aceptó un horómetro que baja con el tiempo, sin un mensaje | **ALTO** |
| d | ¿La bitácora registra quién, cuándo y el valor anterior? | **No registra nada en absoluto.** Cero asientos por las tres operaciones | **ALTO** |

Las cuatro tienen la misma causa: **`HorometerReadingObserver` implementa únicamente `created()`.** No
tiene `updated()` ni `deleted()`, y `HorometerReading` no usa el trait `LogsActivity` (solo lo usan
`Machine` y `WorkOrder`). El camino de escritura por el panel entra por debajo de toda la regla del
horómetro que se construyó en la Etapa 05 para el hallazgo A4.

## Montaje (no medido)

Máquina `QA-RESP-01` creada como administrador, con **ancla puesta por la vía real** —la acción
"Registrar reemplazo de horómetro" del listado, no una escritura directa en la base:

- Reemplazo 180 h → 20 h ⇒ `remaining_anchor_hours=500`, `remaining_anchor_at_hours=20`,
  `current_hours=20`, `remaining_hours=500`, `hours_adjustment=160`, `hourmeter_status=replaced`.
- Tres lecturas propias por el relation manager: **60 h** (10/jul), **100 h** (15/jul), **140 h** (20/jul).
- Estado de partida verificado en base: `current_hours=140`, `current_hours_date=2026-07-20`,
  `remaining_hours=380` — que es exactamente `500 − (140 − 20)`. **La regla del ancla funciona bien
  cuando el observer corre.** Las 5 alertas de la línea base no se movieron (el umbral es 100 h y
  nunca bajamos de 380).

## (a) Editar una lectura que NO es la última

Se probaron los dos sentidos, porque dan resultados distintos y solo uno duele:

**a.1 — hacia un valor menor que el de la máquina (100 h → 120 h).** La lectura se guardó. La máquina
quedó en `current=140 / remaining=380`. **No recalculó**, pero el valor seguía siendo correcto por
casualidad: 140 h continuaba siendo la lectura más alta. Sin daño.

**a.2 — hacia un valor mayor que el de la máquina (120 h → 200 h).** Acá aparece el defecto:

- En pantalla, el historial pasó a mostrar **200 h** para el 15/jul.
- Los campos de la máquina, recargando la página, siguieron en **`current_hours=140`** y
  **`remaining_hours=380`**.
- En base: lectura id 174 con `hours=200`, máquina con `current_hours=140`. Verificado.
- **Sin ningún error ni aviso.** Cero mensajes de validación.

**Por qué es ALTO:** el panel queda declarando una máquina **60 h por detrás de su propio historial**, y
`remaining_hours` queda **60 h optimista**. Un servicio que debía dispararse se atrasa 60 h de uso real,
y el semáforo y las alertas se calculan sobre el valor viejo. El usuario que hizo la corrección se va
convencido de haberla hecho: la lectura, que es lo que él ve, sí cambió.

## (b) Borrar la última lectura

Se borró la lectura de **140 h del 20/jul** (id 175), la última por fecha. Sobrevivieron 200 h (15/jul)
y 60 h (10/jul).

- Máquina después del borrado: **`current_hours=140`, `current_hours_date=2026-07-20`,
  `remaining_hours=380`**.
- **La máquina quedó apuntando al valor y a la fecha de una lectura que ya no existe.** No hay ningún
  registro en el sistema que respalde ese 140 h: la lectura más alta que sobrevive es 200 h y la más
  reciente es del 15/jul.
- El observer **no dispara al borrar**: solo tiene `created()`. Verificado en el código y en la base.

**Por qué es ALTO:** es un horómetro huérfano. Cualquiera que audite después no puede reconstruir de
dónde salió el valor de la máquina, porque su origen fue eliminado sin dejar nada.

## (c) Editar hacia atrás dejando la secuencia incoherente

Se editó la lectura del 15/jul de **200 h → 10 h**, quedando el historial así:

| Fecha | Horas |
|---|---|
| 10/jul/2026 | 60 h |
| 15/jul/2026 | **10 h** |

El horómetro **baja con el paso del tiempo**, que es físicamente imposible. **Se aceptó sin un solo
mensaje** (`errores: []`).

**El contraste es la parte importante:** este mismo error, por el camino de campo, se rechaza con un
mensaje explícito —*"La lectura (100 h) es menor que la última registrada (500 h). Verificá el valor
antes de enviar."*— que es el fix del hallazgo M4, verificado en la persona del capataz. Esa validación
vive en `app/Livewire/Field/{ReportForm,FuelLog}.php` y **no existe en el camino del panel**, que es
justamente el que usan los dos roles con más autoridad sobre el dato.

## (d) La bitácora: no registra nada

Contado en `activity_log` antes y después de las tres operaciones:

| Momento | Asientos de la máquina 116 | Asientos de lecturas (todo el sistema) |
|---|---|---|
| Después del montaje | 5 | 0 |
| Después de editar (a.1) | 5 | 0 |
| Después de editar (a.2) | 5 | 0 |
| Después de borrar (b) | 5 | 0 |
| Después de editar hacia atrás (c) | 5 | 0 |

**Cero.** No es que falte el valor anterior: **no hay asiento**. `HorometerReading` no usa
`LogsActivity`, y como el observer no corre en `updated`/`deleted`, la máquina tampoco cambia, así que
su propio log —que sí registra `current_hours` con valor viejo y nuevo— nunca se dispara.

**El sistema sabe hacer esto y con las lecturas no lo hace.** Prueba directa: cuando borré la máquina
entera, quedó el asiento `id=264 event=deleted causer=2` (el responsable). El borrado de una lectura de
horómetro, que es el dato del que dependen los servicios, no deja ni eso.

**Consecuencia:** si un servicio se adelanta o se atrasa porque alguien tocó una lectura a mano, **no
hay forma de reconstruir por qué, ni quién, ni cuándo**. Cumple el criterio de ALTO que quedó fijado.

## Hallazgo aparte, y corrige algo que yo había reportado mal

**El responsable SÍ puede borrar máquinas desde el panel, y el borrado arrastra toda la historia.**

Yo había reportado que las máquinas no se pueden borrar desde el panel, y que eso bloqueaba la tarea
pendiente del cliente de descartar parte de las 35 máquinas en revisión. **Es falso.**

- `MachineResource::canDelete()` exige `manage_machines` ⇒ **administrador y responsable**.
- La `DeleteAction` está en la cabecera de la página de editar (`EditMachine::getHeaderActions()`).
- **Verificado ejecutándolo:** el responsable borró `QA-RESP-01` y quedó en el listado sin error.
- Todas las FK que apuntan a `machines` son **`ON DELETE CASCADE`**: `alerts`, `field_reports`,
  `horometer_readings`, `machine_parts`, **`work_orders`**. Solo `quotes` es `SET NULL`.
- El diálogo dice únicamente *"Are you sure you would like to do this?"*. **No advierte que se van a
  destruir las órdenes de trabajo, las lecturas y los costos históricos de esa máquina.**

Dos consecuencias opuestas y las dos hay que decirle al cliente:

1. **Buena:** el bloqueo que reporté no existe. DP puede descartar las máquinas del Info Book desde el
   panel.
2. **Mala:** borrar una máquina con historial **elimina sus órdenes de trabajo y sus costos** sin
   avisarlo. Para máquinas dadas de baja, lo correcto casi seguro es `status='inactive'`, no borrar.

## Observaciones menores (no defectos de esta prueba)

- **El responsable entra en inglés.** Su cuenta trae `locale='en'` (del seeder), así que ve
  "Hour-meter history", "Save changes", "Fleet". Según el mapa de idiomas del pase, este rol trabaja en
  español. **Queda como pregunta abierta, no como hallazgo:** hay que verificar qué gobierna el idioma
  y con qué `locale` se crean los usuarios reales desde el panel, antes de afirmar nada.
- **Etiquetas sin traducir en el relation manager:** el botón dice **"Crear horometer reading"** y el
  estado vacío del otro dice *"Cree un machine part para empezar"*. Filament está generando la etiqueta
  desde el nombre del modelo en inglés porque falta el label del recurso relacionado.
- **`remaining_hours` es editable a mano** en el formulario de la máquina, igual que `current_hours`.
  Es otra vía de override directo del dato sensible, además de las lecturas.

## Trampas de método que me comí en esta sesión (para que no se repitan)

Tres veces mi automatización reportó éxito sin que pasara nada, y las tres las agarré comparando contra
la base, no contra la pantalla:

1. **El pie del modal invierte el orden según la acción.** Crear: `[Guardar, Cancelar]`. Borrar:
   `[Cancelar, Borrar]`. Un `.first()` ciego **cancela el borrado y el script informa que borró**.
2. **Hay tres botones "Crear" distintos** en la página de crear máquina: el del select de Marca (crear
   marca al vuelo), el `Salir` oculto del dropdown de usuario (que también es `submit`), y el real. El
   único que sirve es el `button[type=submit]` dentro de `form#form`.
3. **`hasText` con expresión regular no normaliza el `innerText`**, así que `/^Delete$/` nunca casa
   contra un botón cuyo texto real trae saltos de línea. `getByRole({ name, exact })` sí normaliza.

**Regla:** ninguna acción de escritura se declara hecha por el mensaje de la pantalla. Se confirma
leyendo la base.

## Cierre de datos

Navegador cerrado explícitamente antes de escribir esto. `QA-RESP-01` y sus lecturas se fueron con el
borrado de la propia prueba (cascade), así que `qa:cleanup` no tenía nada que borrar.

Línea base verificada con `qa:cleanup --dry-run`, los 9 contadores:
**99 máquinas · 35 needs_review · 1 OT · 5 alertas · 62 anclas · 7 usuarios · 93 lecturas · 0 reportes
de campo · 0 adjuntos de OT.** Todos OK.

Los valores verificados a mano del PM report y las 62 anclas quedaron intactos: la máquina de prueba se
creó desde cero y su ancla fue la número 63, que desapareció con ella.

`activity_log`: **43 asientos huérfanos** (28 de Machine, 15 de OT), 6 de ellos nuevos de esta sesión.
No se borran: la bitácora es append-only y así quedó declarado.

---

# Sesión 1 — Parte 2: OT para el taller, exportaciones, alertas y bitácora

**Usuario:** `responsable@dp.local` · **Máquina de prueba:** `QA-RESP-02` (id 117) · Corrida el
2026-07-26, **después** de los commits `9b313e87`, `19901243`, `11ebb701` y `3653ed75`.
**Ninguna máquina real fue tocada. La OT real de la línea base (WO-0001, EX010) sigue abierta e intacta.**

## Montaje (no medido)

`QA-RESP-02` creada por consola —acá la máquina es solo el soporte; lo que se mide es la OT, la
exportación, la alerta y la bitácora—: `current_hours=1000`, `last_service_hours=1000`,
`service_interval_hours=500`, horómetro `ok`, sin ancla. Estado de partida: `remaining` calculado 500 h,
lejos del umbral de alerta (100 h).

## Lo que funciona, verificado en base de datos

| Qué | Cómo se verificó | Resultado |
|---|---|---|
| Crear OT desde el botón del listado | fila `id=14` en `work_orders` | `code=QA-OT-01`, `machine_id=117`, `type=preventive`, `service_tier=500`, `status=open`, `assigned_to=6` (taller), `execution_mode=workshop` |
| Costos en el formulario de OT | atributo `disabled` del input | `parts_cost` **visible y deshabilitado** para el responsable: ve el costo, no lo edita. Correcto |
| Motor de alertas por el camino real | alerta nueva en `alerts` | lectura de 1420 h → `remaining=80` → alerta `service` **abierta**, con las horas restantes correctas |
| Crear OT desde una alerta | fila `id=16` | `opened_by=2`, `hours_at_open=1420`, `priority=normal` (por `remaining>0`), y la alerta pasó a `acknowledged` con `acknowledged_by=2` |
| Resolver una alerta | `alerts.status` | `resolved`. Las 5 alertas reales no se movieron |
| **Exportar PDF desde el botón** | bytes del archivo descargado | **82.889 bytes, firma `%PDF`**, `fleet-status-20260726-211326.pdf`, 6,1 s |
| **Exportar Excel desde el botón** | bytes del archivo descargado | **11.421 bytes, firma `PK\x03\x04`**, `fleet-status-20260726-211333.xlsx`, 2,1 s |
| Exportaciones sin sesión | `curl` sin cookies | `302` a un login. No hay fuga |
| Bitácora | listado y detalle | asientos de `WorkOrder 14`, `WorkOrder 16`, `HorometerReading 176` y `Machine 117`, todos con "Responsable Mtto" y hora |
| Filtro por `HorometerReading` | listado filtrado | devuelve el asiento. La opción que se agregó con E6-04 sirve |

### Los fixes de horómetro, ahora comprobados en el navegador

Se editó la lectura 176 de **1420 h → 1460 h** desde el relation manager. Antes de `11ebb701` esto no
recalculaba nada y no dejaba rastro. Después:

- Máquina: `current_hours=1460`, `remaining_hours=40`. **Recalculó** (E6-01).
- Bitácora: asiento `updated` de `HorometerReading 176` con `old.hours=1420` y
  `attributes.hours=1460`, causer el responsable. **Guarda el valor anterior** (E6-04).
- Y en la misma marca de tiempo, un asiento `updated` de `Machine 117`: el recálculo también queda
  auditado.
- El detalle en pantalla muestra las dos tablas, "New value" y "Old value" (captura
  `evid_bitacora_detalle.png`). El auditor lo ve sin entrar a la base.
- Al bajar `remaining` de 80 a 40 con la alerta anterior ya resuelta, el motor levantó **una alerta
  nueva**. Correcto: la deduplicación solo calla mientras hay una abierta.

## Hallazgos nuevos de esta parte

**E6-07 (Alto) — un código duplicado da error 500 y la pantalla no dice nada.** Se intentó crear una
segunda OT con el código `QA-OT-01`. El servidor respondió 500 en `/livewire/update`
(`1062 Duplicate entry`), la OT no se creó, y en pantalla **no apareció ni un mensaje de campo ni una
notificación ni una página de error**: el botón se aprieta y no pasa nada. Cinco de las siete columnas
únicas que se editan desde el panel no tienen validación `->unique()`. El mismo error ya había pasado
con `machines.id_code` en una sesión anterior.

**E6-08 (Alto) — el panel no sella quién ni con cuántas horas.** La OT creada desde el formulario quedó
con `opened_by` y `hours_at_open` en NULL, y la lectura creada desde el relation manager con
`recorded_by` en NULL. Los otros dos caminos —la acción de la alerta y la PWA de campo— sí los sellan.
Pesa porque **34 de las 99 máquinas no tienen `current_hours`**: en esas, completar una preventiva
creada desde el panel no actualiza `last_service_hours` ni registra la lectura de cierre, pero sí
reinicia `remaining_hours`. Queda como "recién servida" sin registro de a qué horas.

**E6-09 (Medio) — el código propuesto no es el id ni es libre.** Con `max(id)=14`, la acción de la
alerta generó `WO-0015` y la fila quedó con id 16. La propuesta puede chocar con un código tecleado a
mano, y ahí cae en E6-07.

**E6-10 (Medio) — las alertas quedan en el idioma del que las dispara.** La alerta nueva quedó guardada
como *"Service due soon: QA-RESP-02"* porque la disparó el responsable, que tiene la cuenta en inglés.
Las 5 alertas reales también están en inglés. El administrador, en español, las ve así y cambiar el
idioma no las cambia: el texto ya está en la base.

**E6-11 (Bajo) — la sesión vencida en una exportación lleva al login de campo.** `/reports/fleet.pdf`
sin sesión redirige a `/field/login`, no al del panel.

**E6-12 (Bajo) — "Asignada a" ofrece los 7 usuarios**, incluidos gerencia y el operador de cisterna,
que no pueden ejecutar OT.

## Trampas de método nuevas (van al CLAUDE.md)

1. **Los selects "buscables" de Filament son Choices.js.** El `<select>` real está `hidden`; el
   clickeable es el `div.choices` que lo envuelve. Y `fill()` en su buscador **no dispara la búsqueda**
   (escucha teclas): sin teclear de verdad, el dropdown se queda con la lista completa y la opción que
   se busca puede no estar renderizada. Los selects normales del mismo formulario sí son nativos.
2. **El submit del modal y el de la página casan el mismo texto.** En una página de editar con un
   relation manager, "Save changes" existe dos veces; el de la página está detrás del overlay y el
   click se cuelga 30 s. El del modal cuelga de `div[x-ref="modalContainer"]`.
3. **El recolector de descargas se ata ANTES del click.** Con `openUrlInNewTab` la descarga puede salir
   antes de que se pueda escuchar en la pestaña nueva, y el evento se pierde: la primera corrida dio
   "no hubo descarga" con las dos exportaciones funcionando perfectamente.
4. **El panel de filtros de la tabla intercepta los clicks** de la primera fila (`View`): hay que usar
   `force`.

## Cierre de datos

Navegador cerrado explícitamente al final de cada corrida (cada script hace `browser.close()`).

**El servidor de desarrollo se cayó una vez** a mitad de la sesión de alertas (`artisan serve` murió,
`http=000`), se reinició y se repitió el paso. Fue una caída en unas quince acciones, por debajo del
límite acordado. La acción afectada (resolver la alerta) se volvió a hacer y quedó verificada.

**Estado que queda a propósito, declarado:** la OT `QA-OT-01` **sobrevive para la Sesión 2**, que es
justamente el trabajo del taller sobre ella. Con ella quedan la máquina `QA-RESP-02`, su lectura de
1460 h y su alerta abierta de 40 h. Se borró la OT `WO-0015` (la que creó la acción de la alerta) para
que el taller no encuentre dos OT en la misma máquina, y la alerta ya resuelta.

`qa:cleanup --dry-run` confirma que **todo el delta contra la línea base es exactamente ese dato QA y
nada más**: 1 máquina, 1 OT, 1 lectura, 1 alerta. Los 5 contadores que no toca la prueba coinciden
(needs_review 35, anclas 62, usuarios 7, reportes de campo 0, adjuntos de OT 0), y `WO-0001` sobre
EX010 sigue abierta con la máquina en `9793 h / 415 h restantes`. Un solo `php artisan qa:cleanup` deja
la base en la línea base al cerrar la Sesión 2.

`activity_log`: 43 asientos huérfanos, los mismos que había. No se borran: la bitácora es append-only.

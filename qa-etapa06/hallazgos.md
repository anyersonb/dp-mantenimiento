# Inventario de hallazgos — Etapa 06

Registro único de la etapa. Existe por la lección de A8: **un hallazgo sin entrada en un inventario y
sin test se pierde, aunque esté descrito en un informe.** Cada fila cierra con fix + test, o con
"abierto y aceptado" y su motivo. Nunca por conteo agregado.

| ID | Severidad | Hallazgo | Estado | Fix | Test |
|---|---|---|---|---|---|
| **A8** | Alto | 15 acciones de escritura sin control de permisos en los 5 relation managers. Promoción de la deuda §1 del Bloque 2 | **CERRADO** | `9b313e87` permiso propio por relation manager · `19901243` borrado gobernado por el ESTADO de la OT | `PermissionSentinelTest` (3 tests nuevos) + `RelationManagerWritePermissionTest` (34) |
| **E6-01** | Alto | Editar una lectura de horómetro no recalcula `current_hours`/`remaining_hours` | **CERRADO** | `11ebb701` recálculo completo desde el ancla + lecturas sobrevivientes, invocado en created/updated/deleted | `HorometerReadingEditAndDeleteTest` |
| **E6-02** | Alto | Borrar la última lectura deja la máquina apuntando a una lectura inexistente | **CERRADO** | `11ebb701` (mismo recálculo, también en `deleted`) | `HorometerReadingEditAndDeleteTest` |
| **E6-03** | Alto | El rechazo de lectura regresiva (fix M4) no existía en el camino del panel | **CERRADO** | `11ebb701` regla única `App\Rules\CoherentHorometerReading`, consumida por el panel y por el camino de campo | `HorometerReadingEditAndDeleteTest` |
| **E6-04** | Alto | Editar o borrar una lectura a mano no dejaba ningún asiento en la bitácora | **CERRADO** | `11ebb701` `LogsActivity` en `HorometerReading` + opción en el filtro de la bitácora | `HorometerReadingEditAndDeleteTest` |
| **E6-05** | **CRÍTICO** | Borrar una máquina desde el panel arrastra en cascada sus OT, lecturas, alertas y costos, con un diálogo que no lo advierte | **CERRADO** | `3653ed75` solo administrador + SoftDeletes + acción "descartar" + diálogo con conteos reales | `MachineDeletionIsGovernedTest` (7 tests) |
| **E6-06** | Bajo | Etiquetas sin traducir en los relation managers: "Crear horometer reading", "Cree un machine part para empezar" | ABIERTO | — | — |
| **E6-07** | **Alto** | Un valor duplicado en cualquier columna con índice único cae en **error 500 sin un solo mensaje en pantalla**: ninguna validación `->unique()` en 5 formularios | **CERRADO** | Validación en los 5 campos (`UniqueSlugFrom` para los 3 `slug`, que son campos ocultos) + reintento en el camino sin formulario | `UniqueColumnSentinelTest` (9 tests sobre el **esquema**, 5 rojos sin el fix) |
| **E6-08** | **Alto** | El panel **no sella autoría ni horas** al crear: OT sin `opened_by` ni `hours_at_open`, lecturas sin `recorded_by`. Los otros caminos sí las sellan. Y el cierre reiniciaba el ciclo sin registrar las horas | **CERRADO** | Sello en observers (todos los caminos) + campo "Horas al abrir" + `complete()` **rechaza** sin ninguna fuente de horas | `WorkOrderSealsAuthorshipAndHoursTest` (10 tests, 6 rojos sin el fix) |
| **E6-09** | Medio | El código de OT que propone el formulario se deriva de `max(id)+1`, que no es el id que va a tener la fila ni un valor libre garantizado | **CERRADO** | `WorkOrder::nextCode()`: numera sobre el sufijo de los códigos y avanza hasta uno libre | `WorkOrderCodeTest` |
| **E6-10** | Medio | Texto ya traducido **persistido**: 6 alertas en inglés, 93 notas de lectura y 4 descripciones de bitácora en español. Cambiar el idioma no las cambia | **CERRADO** | `LocalizedText` + cast `AsLocalizedText` en alertas, notas y bitácora; los 9 escritores guardan clave + parámetros; migración que reescribe lo ya guardado | `PersistedTextIsRenderedForTheReaderTest` (8 tests, incluido un centinela de escritores) |
| **E6-11** | Bajo | Con la sesión vencida, exportar lleva al login de **campo** (`/field/login`), no al del panel | ABIERTO | — | — |
| **E6-12** | **Medio** | El selector "Asignada a" de la OT ofrece los 7 usuarios, incluidos los roles que no pueden ejecutar OT (gerencia, operador de cisterna) | **CERRADO** | El desplegable sigue al permiso `execute_work_order` (scope de spatie: por rol y directo) | `AssigneeIsLimitedToExecutorsTest` |
| **E6-13** | **Alto** | El importador escribía `current_hours` con la lectura del reporte aunque fuera más vieja, y con un `id_code` repetido se quedaba con la última ocurrencia del archivo. Quinto camino sin la regla de coherencia | **CERRADO** | `3e59376f` regla compartida + gana la lectura más nueva + recálculo con `allowLowering: false` + frontera de escala en el reemplazo | `ImporterRejectsRegressiveReadingsTest` (7) · `ReplacedHourmeterKeepsItsScaleTest` (3) · `HorometerWritePathSentinelTest` (2) |
| **E6-14** | **Alto** | Aprobar una máquina la saca de `needs_review` **sin exigir lectura inicial**: AC-001 quedó aprobada, activa y sin horómetro | ABIERTO — fix propuesto, sin implementar | — | — |
| **E6-15** | **Alto** | El motor de alertas vivía dentro del observer de lecturas: un cambio de `remaining_hours` por otro camino cruzaba el umbral **sin levantar alerta** | **CERRADO** | `a1c1007d` `App\Services\ServiceAlertEngine`, una implementación que consumen el observer y el importador | dentro de `ImporterRejectsRegressiveReadingsTest` (2 casos) |
| **E6-16** | Medio | `config('app.locale')` es `en` en una instalación cuyo cliente trabaja en español: consola, jobs y todo lo que corre fuera de una sesión sale en inglés | ABIERTO | — | — |
| A7 | Alto | Falta de piso en campos numéricos que alimentan columnas `unsigned` | **CERRADO** | `ac57c9ae` | `MachineNumericFloorTest` + `UnsignedColumnFloorSentinelTest` |

## E6-07 — el duplicado que no dice nada

**Reproducido dos veces, con dos tablas distintas.** En la Sesión 1 parte 2 el responsable creó la OT
`QA-OT-01` y después intentó crear otra con el mismo código:

- El servidor respondió **500 en `/livewire/update`** (`UniqueConstraintViolationException`, `1062
  Duplicate entry 'QA-OT-01' for key 'work_orders.work_orders_code_unique'`, en `laravel.log`).
- En la pantalla: **cero mensajes de campo, cero notificaciones, ninguna página de error**. El botón
  "Create" se aprieta y no pasa nada.
- En base de datos: la OT **no se creó** (siguen 2 filas). O sea que el trabajo se pierde entero y sin
  explicación.

El mismo error ya había pasado con otra tabla en una sesión anterior:
`1062 Duplicate entry 'QA-CIS-01' for key 'machines.machines_id_code_unique'`.

**Alcance medido contra el esquema.** Columnas con índice único que se editan desde el panel, y si su
campo declara la validación:

| Tabla · columna | Formulario | `->unique()` |
|---|---|---|
| `machines.id_code` | `MachineResource` | **no** |
| `work_orders.code` | `WorkOrderResource` | **no** |
| `locations.slug` | `LocationResource` | **no** |
| `machine_categories.slug` | `MachineCategoryResource` | **no** |
| `makes.slug` | `MakeResource` | **no** |
| `users.email` | `UserResource` | sí |
| `roles.name` | `RoleResource` | sí |

Cinco de siete sin red. Es el mismo vicio de A8 y de C3: **la regla existe en un camino y no en los
demás.** El fix es una línea por campo (`->unique(ignoreRecord: true)`), y el test que corresponde es
de tipo centinela —recorrer los índices únicos del esquema y exigir la validación en el campo que los
alimenta— porque si se arregla campo por campo, el próximo Resource nace otra vez sin ella.

## E6-08 — el panel no sella quién ni con cuántas horas

Medido en base de datos sobre las filas que creó esta sesión:

| Fila | Camino | `opened_by` / `recorded_by` | `hours_at_open` |
|---|---|---|---|
| OT `QA-OT-01` | formulario del panel | **NULL** | **NULL** |
| OT `WO-0015` | acción "Crear OT" de una alerta | 2 (responsable) | 1420 |
| Lectura 176 | relation manager del panel | **NULL** | — |
| Lecturas de campo | PWA | el usuario que la envió | — |

**Los otros dos caminos sí sellan; el del panel no.** El formulario de OT ni siquiera tiene un campo
para `hours_at_open`: no hay forma de completarlo desde el panel.

**Por qué es Alto, y es por `hours_at_open`:** `WorkOrderCompletionService::complete()` resuelve las
horas del servicio con `$machine->current_hours ?? $workOrder->hours_at_open`. **34 de las 99 máquinas
no tienen `current_hours`** (las del Info Book sin dato). En esas, una OT preventiva creada desde el
panel se completa con las dos fuentes en NULL, y entonces el cierre:

- **no actualiza `last_service_hours`** — el sistema no queda sabiendo a qué horas se hizo el servicio,
  que es exactamente el dato del PM report;
- **no registra la lectura de cierre** (`source=workshop`) que sí registra en el caso normal;
- pero **sí** pone `remaining_hours = service_interval_hours`, o sea que el semáforo se reinicia igual.

Resultado: la máquina aparece "recién servida" sin ningún registro de a qué horas, en un tercio de la
flota. La parte de autoría (`opened_by`, `recorded_by`) es de severidad menor porque la bitácora sí
guarda el causer desde el fix de E6-04, pero la columna que la aplicación lee para mostrar "quién
abrió" queda vacía para siempre.

### El solapamiento de los dos conjuntos: NO son el mismo

Medido sobre las 99 máquinas reales:

| | Cantidad | Cuáles |
|---|---|---|
| Sin `current_hours` | 34 | — |
| En `needs_review` | 35 | — |
| **En los dos** | **28** | el núcleo del problema, y están marcadas |
| **Sin horas y NO en revisión** | **6** | MS003, RL009, RL010, RL011, RL017, AC-001 |
| En revisión y **con** horas | 7 | EX027, MS-TEMP-01, MS-TEMP-02, PW-MERSINO-01, PW-MERSINO-02, PW-PIONEER, RL016 |

**Las 6 de la fila del medio son las peligrosas:** no tienen horómetro cargado y **tampoco tienen la
marca de revisión**, así que en el panel se ven como una máquina normal. Nadie sabe que ahí el cierre de
un servicio no puede registrar nada. La superficie total del defecto es la unión: **41 máquinas**, no 34
ni 35.

### Cómo quedó cerrado

1. **El sello va en observers** (`WorkOrderObserver::creating`, `HorometerReadingObserver::creating`) y
   no en la página de crear, por la lección de A8/C3/E6-03: una regla en un solo camino deja los otros
   afuera. Solo rellena lo que viene vacío — la acción de la alerta, un seeder o una importación siguen
   mandando.
2. **Campo "Horas al abrir"** en el formulario de OT. Antes no existía: no había forma de registrar el
   dato desde el panel, así que el rechazo del punto 3 habría sido un callejón sin salida para esas 41
   máquinas.
3. **`complete()` rechaza** cuando no hay ni `current_hours` ni `hours_at_open`, con
   `CannotCompleteWorkOrder`, y la UI corta **antes** de tocar el estado de la OT (en la acción de tabla
   y en `beforeSave()` de la página de editar). Rechazar después de marcar "completada" habría dejado la
   OT cerrada y la máquina sin servicio registrado: peor que el defecto original.
   `canComplete()` es la única implementación de la regla, y la usan la pregunta de la UI y la ejecución.
4. Las correctivas y las inspecciones **no** quedan bloqueadas: no reinician ningún ciclo.

**Un test que ya existía se puso rojo, y tenía razón.** `WorkOrderPermissionsTest::test_taller_can_
complete_a_work_order` armaba su máquina **sin `current_hours`** —justo la forma de las 41— y esperaba
que la OT quedara completada. Con el fix, el cierre se rechaza. Ese archivo mide permisos, no la regla
de horas, así que se le cargó horómetro a la máquina de prueba para que el único motivo posible de un
fallo ahí sea el permiso. La corrección fue del montaje del test, no de la regla.

## E6-09 — el código propuesto no es el id ni es libre

El formulario propone `'WO-'.str_pad(WorkOrder::max('id') + 1, 4, '0')`. Medido en esta sesión: con
`max(id)=14`, la acción de la alerta generó el código **WO-0015** y la fila quedó con **id 16**. Los
códigos y los ids ya divergen, así que el número no identifica nada.

Y el valor propuesto **no está garantizado como libre**: alcanza que alguien haya tecleado a mano un
código con ese mismo patrón (que es el patrón del cliente) para que la propuesta choque. Dos personas
abriendo el formulario a la vez también reciben el mismo código. Cuando choca, el usuario cae en E6-07:
un 500 mudo. Los dos se arreglan juntos.

## E6-10 — las alertas quedan congeladas en el idioma del que las dispara

El motor guarda `title` y `message` ya renderizados. La alerta que se levantó en esta sesión quedó como
**"Service due soon: QA-RESP-02"** porque la disparó el responsable, cuya cuenta está en inglés. Las 5
alertas reales de la línea base también están en inglés.

Consecuencia concreta: el administrador, que tiene la cuenta en español, ve la lista de alertas en
inglés, y cambiar el idioma del panel no las cambia — el texto ya está en la base. El fix es guardar
el tipo y los parámetros y renderizar al mostrar, no guardar la frase.

Esto es la **primera instancia concreta** de la pregunta abierta sobre `locale`, y la vuelve un defecto
verificado: no es solo qué idioma ve cada usuario, es que hay texto persistido en un idioma.

## Causa común de E6-01 a E6-04 (histórico, ya cerrada)

`HorometerReadingObserver` implementaba **solo `created()`**, y `HorometerReading` no usaba
`LogsActivity`. Toda la regla del horómetro de la Etapa 05 se aplicaba únicamente cuando la lectura
nacía; el camino de escritura del panel —el de los dos roles con más autoridad sobre el dato— entraba
por debajo de la regla. Cerrado en `11ebb701` con un recálculo completo invocado desde los tres
eventos, una regla de coherencia única y bitácora con valor anterior.

## Preguntas abiertas (no son hallazgos hasta verificarlas)

- **Idioma del responsable.** Su cuenta trae `locale='en'` del seeder, contra el mapa de idiomas del
  pase. Falta verificar qué gobierna el idioma en el panel y con qué `locale` se crean los usuarios
  reales. Relacionada con E6-10, pero es otra cosa: E6-10 es texto ya guardado.
- **`remaining_hours` y `current_hours` editables a mano** en el formulario de la máquina. Es un
  override directo del dato sensible; hay que decidir si es intencional (corrección legítima del
  responsable) o si debe pasar por el mismo camino auditado que las lecturas.
- **Máquinas desalineadas: 2 reparadas, 2 son un hueco de DATOS.** Ver la sección de abajo.


## EX027 y las otras tres: artefacto contra hueco de datos

La pregunta era si el desalineamiento contra el PM report es un artefacto de cálculo o una máquina
realmente pasada de servicio. **Son dos cosas distintas y hay que separarlas máquina por máquina.**

### EX027 — artefacto del importador. NO está pasada de servicio.

La historia completa, leída de la bitácora y de las lecturas:

| Cuándo | Qué pasó |
|---|---|
| 16/jul 03:05 | La máquina nace con `current_hours=341`, de una lectura del **2/jul** importada del PM report |
| 22/jul 02:14 | Una importación del archivo `PM_Service Report_Machines_7172026 (3).xlsx` la baja a **275 h**, con una lectura fechada el **17/jun** |

Las dos lecturas son coherentes entre sí —275 h el 17/jun y 341 h el 2/jul: el horómetro subió 66 h en
quince días—. Lo que está mal es **cuál de las dos quedó como "actual"**: el importador escribió
`current_hours` desde una fila cuya lectura es **más vieja** que una que ya existía. Un horómetro no
baja con el tiempo, y el propio sistema rechaza eso en los otros caminos (fix M4, hallazgo E6-03).

O sea: **artefacto**, y de la misma familia que E6-01/E6-02. Reparado con
`machines:recalculate-hours --machine=EX027 --apply`:

- `current_hours` 275 → **341**, con la fecha correcta (2/jul en vez de 17/jun);
- `remaining_hours` 304 → **238**, que es `304 − (341 − 275)` sobre el ancla verificada del PM report.

**No hay urgencia:** con 238 h de margen la máquina no está pasada de servicio ni entra en el umbral de
alerta de 100 h (`is_due_soon = false`, cero alertas). Lo que había era un panel **66 h optimista**: el
servicio se iba a atrasar 66 h de uso real. El cambio quedó en la bitácora con su valor anterior.

**PW-MERSINO-02** era el mismo caso en versión inocua —le faltaba solo `current_hours_date`—: reparada,
`remaining_hours` no se movió (229).

### MS-TEMP-01 y RL017 — NO se tocaron, y no es lo mismo

Acá el recálculo dejaría `remaining_hours` en **NULL**, y eso no es reparar: es borrar un número que el
cliente ve hoy. La causa no es de cálculo, es que **falta el dato**:

| Máquina | Qué tiene | Por qué la regla no puede |
|---|---|---|
| MS-TEMP-01 | horómetro `replaced`, 1 h (23/oct/2025), último servicio a las 2800 h de la escala vieja | Sin ancla, no hay forma de relacionar la escala nueva con el ciclo de servicio |
| RL017 | **ninguna lectura**, sin `current_hours`, último servicio a las 788 h | No hay desde dónde contar |

Las dos tienen `remaining_hours = 500` guardado, que es el intervalo completo puesto como suposición, no
una medición. **El arreglo correcto no es un comando: es cargar el dato.** MS-TEMP-01 necesita un ancla
por la acción "Registrar reemplazo de horómetro" (cuántas horas le quedan al día de hoy sobre el
horómetro nuevo) y RL017 necesita una lectura. Las dos son preguntas para el cliente.

**Y RL017 es una de las 6 que E6-08 dejó al descubierto:** no tiene horas y **tampoco** tiene la marca
`needs_review`, así que en el panel se ve como una máquina normal.

## E6-14 — aprobar no exige lectura inicial

**AC-001, medido en la bitácora y en la base:**

| Cuándo | Qué pasó |
|---|---|
| 19/07 05:28 | creada por la carga (sin causer) |
| 19/07 06:05 | **aprobada** por el administrador, evento `approved` |
| hoy | `needs_review=false`, `status='unknown'`, `current_hours=NULL`, **cero lecturas** |

Aprobar solo pone `needs_review = false`. No pregunta por el horómetro, no exige una
lectura, no cambia el estado de la máquina. La máquina sale de la lista de revisión y
**se ve como una máquina normal** teniendo el dato central en blanco.

**Por qué es Alto aunque sea "de proceso":** el cliente tiene 35 máquinas en revisión y
28 de ellas no tienen `current_hours`. Cuando confirme esa lista, aprobar sin más
fabrica de golpe **28 máquinas sin horas que se ven normales**, que es exactamente la
población de E6-08: ahí el cierre de una OT preventiva no puede registrar a qué horas se
hizo el servicio. Hoy ya hay 6 así (MS003, RL009, RL010, RL011, RL017, AC-001), y son
las que nadie está mirando porque no tienen la marca.

De las dos máquinas con evento `approved` en la bitácora, una (3038E) tenía lectura y la
otra (AC-001) no. O sea que la aprobación no distingue: aprobó las dos igual.

**Fix propuesto, no implementado** (esperando decisión):

1. **Aprobar exige horómetro.** Si la máquina no tiene `current_hours` ni ninguna
   lectura, la acción pide las horas en el mismo diálogo y las registra como lectura
   inicial (`source='import'` o uno nuevo, `verified=true`, con el causer). Así la
   aprobación produce el dato en vez de saltearlo.
2. **O bien** aprobar deja la máquina marcada como **incompleta** —un estado o una
   columna `data_complete=false`— para que salga en un listado propio y no se confunda
   con una máquina lista. Es la opción barata si el cliente no tiene las horas al
   momento de aprobar.
3. En los dos casos: la acción masiva de aprobar ("Aprobar seleccionadas") tiene que
   respetar la misma regla, porque es por donde van a entrar las 35.

La 1 es la correcta y la 2 es la que no bloquea al cliente. Se pueden combinar: exigir
horas cuando el usuario las tenga, permitir "aprobar sin horas" con la marca explícita.

## E6-15 — la alerta que no se levantaba

El motor estaba dentro de `HorometerReadingObserver`, así que solo corría cuando nacía,
se editaba o se borraba una lectura. Cualquier otro camino que cambiara
`remaining_hours` dejaba la máquina cruzando el umbral **sin alerta**.

**Encontrado con datos reales, no razonándolo.** Al cargar el PM report del 24/07, EX027
quedó con **exactamente 100 h restantes —el umbral— y sin alerta**. El motivo es el
orden: la lectura de 400 h se crea primero y dispara el motor con el ancla vieja (179 h
restantes, por encima del umbral), y recién después el importador escribe el ancla del
reporte, que la baja a 100. Nadie volvía a preguntar.

Cerrado sacando el motor a `App\Services\ServiceAlertEngine`, una sola implementación
que consumen el observer y el importador. Verificado en la base: las 7 máquinas bajo el
umbral tienen exactamente una alerta abierta.

## Lo que hay que llevarle al cliente

Cuatro cosas concretas que salieron de sus propios archivos del 21 y 24 de julio:

1. **Tres máquinas pasadas de servicio hoy:** LD023 (62 h), LD027 (38 h) y LD034 (28 h).
   Su reporte las marca **PAST DUE** con esas palabras. Ya quedan en 0 h restantes y con
   alerta en el panel.
2. **RL016 tiene el horómetro reemplazado y sin registrar.** Su historial tiene 3564 h
   (18/mar) y 26 h (28/may): son dos escalas. Hace falta que confirme la fecha del
   reemplazo para registrarlo por la acción del panel; hasta entonces el sistema
   conserva la escala vieja y rechaza las filas de la nueva.
3. **Dos máquinas aparecen duplicadas en el reporte del 24/07** con lecturas distintas:
   EX027 (400 h del 22/jul y 275 h del 17/jun) y RL016 (26 h del 28/may y 3564 h del
   18/mar). El sistema ya se queda con la más nueva y lo declara, pero el archivo
   conviene corregirlo en origen.
4. **19 máquinas del sistema no están en el Info Book del 21/07**, y **8 de ellas no
   tienen ni la marca de revisión**: 3038E, MS003, PJ001, RL002, RL009, RL010, RL011,
   SC002. Las otras 11 son las temporales y los marcadores `INFO-TMP-0x`. Esa es la lista
   para la acción "descartar".

## Corrida de control contra MySQL (gate de cierre de etapa)

La suite corre en SQLite y la aplicación en MySQL. **Las 257 pruebas pasan en los dos
motores**, y se comprobó que la corrida de MySQL fue real (las 30 tablas quedaron creadas
en `dp_mantenimiento_test`, porque `phpunit.xml` declara `DB_CONNECTION=sqlite` y podría
haber pisado la variable).

Que esté verde en los dos no significa que los dos midan lo mismo. Sonda directa:

| Caso | SQLite (la suite) | MySQL (producción) |
|---|---|---|
| Negativo en `unsignedInteger` | **ACEPTA** | RECHAZA `22003` |
| Texto más largo que `varchar(n)` | **ACEPTA** | RECHAZA `22001` |
| Fecha inválida `2026-02-31` | **ACEPTA** | RECHAZA `22007` |
| `NOT NULL` sin valor | RECHAZA | RECHAZA |
| Clave foránea inexistente | RECHAZA | RECHAZA |
| `ON DELETE CASCADE` | aplica | aplica |

**Tu sospecha era correcta y ahora está medida:** el hallazgo A7 —escribir `-5` en una
columna `unsigned`— **era invisible para la suite y siempre lo iba a ser**. Lo mismo vale
para cualquier `maxLength()` que falte en un formulario y para una fecha imposible: verde
en test, 500 en producción.

La buena noticia es el otro lado de la tabla: **las claves foráneas y las cascadas sí se
comportan igual en los dos**, así que el mecanismo de E6-05 (SoftDeletes evitando la
cascada) está probado de verdad y no por casualidad del motor.

El procedimiento quedó en `CLAUDE.md` como paso del gate de cierre de etapa. No se migra
la suite: es una corrida de control.

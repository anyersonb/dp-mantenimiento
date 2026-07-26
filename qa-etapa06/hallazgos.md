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
- **4 máquinas reales desalineadas** que detectó `machines:recalculate-hours` en simulación y que **no
  se tocaron**: EX027 (275→341 h, remaining 304→238), PW-MERSINO-02 (le falta la fecha), y MS-TEMP-01 y
  RL017, cuyo `remaining_hours` de 500 la regla no reproduce y quedaría en NULL. Decisión de Anyerson.

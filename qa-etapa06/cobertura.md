# Etapa 06 · Fase A — Cobertura real por usuario normal

Auditoría **sin ejecutar nada**: solo lectura de artefactos en disco y del código para clasificar
procedencia. Cero navegador, cero fixes.

**Un caso está probado por usuario normal solo si se cumplen las cuatro:** rol no administrador · por
interfaz · llegando por navegación desde el menú · en el idioma y dispositivo del rol.

---

## PARTE 1 — La cuenta que no cerraba: **dos altos perdidos en la contabilidad**

Tu sospecha era correcta y el agujero es mayor que uno. Comparé el inventario de `informe.md` contra la
tabla de cierre de `informe-fixes.md`, hallazgo por hallazgo.

**`informe.md` declara 7 altos: A1 · A2 · A3 · A4 · A5 · A6 · A7.**
**`informe-fixes.md` acredita 5: A1 · A3 · A4 · A5 · A6.**
**`A2` y `A7` aparecen CERO veces en `informe-fixes.md`.** Ni fix, ni test, ni mención.

Y mi mensaje de cierre de la Etapa 05 dijo *"los 3 críticos y los 6 altos están cerrados"*. **Eso estaba
mal por dos motivos:** el número real de altos era 7, no 6, y los cerrados eran 5. Un alto perdido en la
contabilidad es peor que un alto abierto y conocido, y acá había dos.

### A7 — **ABIERTO, en el mismo lugar donde se reportó**

| Qué | Estado verificado |
|---|---|
| Formulario principal de máquina (`MachineResource:153-165`) | `current_hours`, `service_interval_hours`, `last_service_hours`, `remaining_hours` tienen `->numeric()` **sin `->minValue(0)`** |
| La base de datos | `machines.current_hours` sigue siendo `int unsigned` → un negativo revienta con `SQLSTATE 22003`, no con un mensaje |
| Camino de campo | **Sí cubierto**: los tres componentes validan `'hours' => ['numeric','min:0']` |
| Acción de reemplazo de horómetro | **Sí cubierta**: `minValue(0)` en las líneas 374 y 379 (la agregó el Bloque 1 de paso) |
| Test dedicado | **No existe** |

O sea: A7 quedó **parcialmente corregido por efecto colateral, sin registro y sin test**, y el caso
original —escribir `-5` en el formulario de máquina— sigue tumbando la pantalla.

### A2 — **sustantivamente resuelto, pero nunca acreditado**

A2 decía que `/admin/*` colgaba de una sola whitelist (`User::canAccessPanel()`) sin defensa en
profundidad por Resource. El Bloque 2 hizo exactamente lo que A2 recomendaba: **9 de 10 Resources
declaran hoy sus propios gates de escritura**, y el `PermissionSentinelTest` los exige. **El riesgo está
materialmente cerrado y hasta tiene test** — lo que faltó fue anotarlo como cierre de A2. Es un problema
de trazabilidad, no de producto.

### Recomendación de contabilidad

Ningún hallazgo debería poder cerrarse sin una fila en la tabla final. Los dos que se perdieron se
perdieron porque los bloques se definieron por hallazgo (C1, A3, A5…) y **A2 y A7 nunca entraron en un
bloque**: A7 apareció en la Ola 3, ya con los bloques definidos, y A2 era "preventivo" y se diluyó.

---

## PARTE 2 — El número crudo, partido en tres

**Método:** no uso 9×12×7 = 756 celdas, porque la mayoría de esas combinaciones no existe (no hay
"exportar Excel" en el módulo de combustible). Enumero, por módulo, **solo las operaciones que ese
módulo realmente tiene**, y las cruzo por los 7 roles. Total: **259 celdas**. Cada celda se clasifica
primero como DEBE PODER / NO DEBE PODER / NO APLICA según la matriz vigente, y después por procedencia.
Sin evidencia citable, es N0.

### Los tres números

| | Celdas | En N1 | % |
|---|---|---|---|
| **DEBE PODER** — la cobertura real | **83** | **21** | **25,3 %** |
| **NO DEBE PODER** — controles negativos | **112** | 7 en pantalla · ~62 por HTTP (N2/N3) · resto N5/N0 | **6,3 % en pantalla · ~62 % con alguna verificación** |
| **NO APLICA** | **64** | — | 25 % del total |

> **Números corregidos tres veces.** Primero, tras el hallazgo del pre-bloque: las 14 celdas de lecturas
> de horómetro que había dado por "operación inexistente" **sí existen** (6 DEBE PODER + 8 NO DEBE
> PODER), y estaban todas en N0 — la cobertura real bajó de 13,0 % a **12,0 %**. Después, tras la Parte 1
> de la Sesión 1, subió a **15,7 %** al pasar 3 celdas a N1. Y con la Parte 2 de la misma sesión —OT,
> alertas, exportaciones y bitácora— subió a **25,3 %** con 8 celdas más (ver la tabla de abajo). La primera versión
> de este informe subestimaba la superficie de escritura del sistema; la corrección fue hacia arriba en
> riesgo y hacia abajo en cobertura, no al revés.

**La cobertura real por usuario normal es 25,3 %.** Ese es el número que importa y no lo maquillo.

Pero el desglose dice lo que un porcentaje único esconde: **de lo que no debe funcionar, dos tercios
tiene verificación** (la Ola 1 hizo mucho trabajo ahí), aunque **solo 7 celdas están verificadas en
pantalla por el rol**. Y ese matiz importa: el bug del 21/07 vivía en pantalla con el código pareciendo
bien. Un control negativo por HTTP es fuerte; por inspección de código, no.

### Desglose por módulo

| Módulo | Celdas | DEBE | de ellas N1 | NO DEBE | NO APLICA |
|---|---|---|---|---|---|
| 1. Catálogo de flota | 49 | 17 | 4 | 25 | 7 |
| 2. Servicios preventivos / DVIR | 35 | 8 | **0** | 10 | 17 |
| 3. Horas y horómetro | 35 | 12 | 6 | 6 | 17 |
| 4. Combustible | 14 | 2 | 1 | 5 | 7 |
| 5. Órdenes de trabajo | 42 | 13 | 1 | 11 | 18 |
| 6. Historial y costos | 14 | 8 | 1 | 3 | 3 |
| 7. Alertas | 21 | 6 | 3 | 6 | 9 |
| 8. Reportes y exportaciones | 14 | 6 | 2 | 8 | 0 |
| 9. Usuarios y roles | 35 | 5 | 3 | 30 | 0 |

### Las 21 celdas en N1 (las únicas que cuentan como probadas)

| Módulo · operación | Rol | Evidencia |
|---|---|---|
| Flota · leer (autocompletado) | operador_cisterna | `persona-operador-cisterna.md`, flujo de carga |
| Flota · leer (autocompletado) | foreman | `persona-foreman.md`, tablero |
| Flota · confirmar ubicación | foreman | `persona-foreman.md`, 3 toques, bitácora `location_confirmed` causer 3 |
| Horómetro · registrar lectura | foreman | `persona-foreman.md`, lectura 650 h `source='foreman'` |
| Horómetro · registrar lectura | operador_cisterna | `persona-operador-cisterna.md`, incluida en la carga |
| Horómetro · levantar reporte de campo | foreman | `persona-foreman.md`, fuga hidráulica `critical` |
| Combustible · registrar carga | operador_cisterna | `persona-operador-cisterna.md`, 4 cargas encadenadas |
| Usuarios · crear | administrador | `persona-administrador.md` (navegó Administración → Usuarios) |
| Usuarios · leer | administrador | ídem |
| Roles · editar permisos | administrador | ídem, quitó y comprobó el efecto |
| Horómetro · **editar** lectura a mano | responsable_mantenimiento | `persona-responsable-mantenimiento.md`, casos (a.1) y (a.2) con verificación en base |
| Horómetro · **borrar** lectura a mano | responsable_mantenimiento | ídem, caso (b): la máquina quedó apuntando a una lectura inexistente |
| Flota · **borrar** máquina | responsable_mantenimiento | ídem, `QA-RESP-01` borrada desde la cabecera, cascade verificado |
| Horómetro · **registrar** lectura desde el panel | responsable_mantenimiento | Parte 2, lectura 176 de 1420 h → máquina recalculada a `remaining=80` |
| OT · **crear** desde el botón del listado | responsable_mantenimiento | Parte 2, `QA-OT-01` id 14 asignada al taller, campo por campo en base |
| Alertas · leer el listado | responsable_mantenimiento | Parte 2, 6 filas que coinciden una a una con `alerts` |
| Alertas · **resolver** | responsable_mantenimiento | Parte 2, `status='resolved'` en base |
| Alertas · **crear OT** desde una alerta | responsable_mantenimiento | Parte 2, OT id 16 con `opened_by=2` y `hours_at_open=1420`; la alerta pasó a `acknowledged` |
| Reportes · **exportar PDF** desde el botón | responsable_mantenimiento | Parte 2, 82.889 bytes con firma `%PDF` |
| Reportes · **exportar Excel** desde el botón | responsable_mantenimiento | Parte 2, 11.421 bytes con firma `PK\x03\x04` |
| Bitácora · leer y filtrar por tipo | responsable_mantenimiento | Parte 2, asientos de OT, lectura y máquina; el filtro `HorometerReading` devuelve la fila |

**Cuenta de la Parte 2: entraron 8, no salió ninguna.** 13 + 8 = 21. Las 3 celdas de la Parte 1
(editar lectura, borrar lectura, borrar máquina) ya estaban dentro de las 13 y **no se vuelven a
contar**; el "11" de una versión anterior de este párrafo sumaba esas 3 dos veces. Ninguna celda perdió
el N1: los cuatro commits de fixes no invalidaron evidencia previa, porque ninguna de las 13 dependía
del comportamiento que cambió.

Las 8 nuevas salieron con las **cinco** condiciones de N1 —la quinta, resultado confirmado en base de
datos, es la que hace que estas cuenten. De las 8, **tres encontraron un defecto** (E6-07 en crear OT,
E6-08 en crear OT y en registrar lectura, E6-10 en alertas). Sigue diciendo algo sobre el 75 % que no
está probado.

### Los 7 controles negativos verificados EN PANTALLA por el rol

foreman no puede mover flota (el selector solo ofrece su obra) · foreman no ve costos · cisterna no ve
costos · cisterna no tiene la opción de reporte en su menú · foreman no tiene la opción de combustible ·
taller sin `execute_work_order` no ve acciones en la OT · taller no puede crear OT (403 + sin botón).

**Todo el resto de los negativos (~62) está en N2/N3:** verificado por HTTP con sesión real, por los
agentes de la Ola 1 y de los bloques de fix. Es evidencia sólida, pero **no es un usuario normal**.

---

## PARTE 3 — Lo que salta

1. **Módulos donde NINGÚN rol no-administrador ejecutó una escritura por interfaz navegando:**
   órdenes de trabajo, checklists/DVIR, alertas, reportes, historial y costos. **Cinco de nueve.**
2. **Operaciones que solo existen probadas como administrador (N6) o por script (N3):** crear máquina,
   editar máquina, cambiar estado de máquina, precargar checklist, adjuntar archivo, exportar PDF,
   exportar Excel.
3. **Eliminaciones — dos situaciones distintas que hay que no confundir.**

   **CORRECCIÓN a la primera versión de este informe.** Afirmé que el historial de lecturas era de solo
   lectura. **Era falso, y el error fue mío:** lo verifiqué en la página de *ver* máquina, donde los
   relation managers se muestran sin acciones, y no en la de *editar*, donde sí las tienen. El barrido
   del pre-bloque lo destapó al encontrar un formulario con campo `hours` que yo daba por inexistente.

   | Entidad | ¿Existe el borrado en el panel? | Procedencia |
   |---|---|---|
   | Máquinas | **NO existe** — ni en edición, ni en fila, ni masiva | verificado en la persona del cisternero |
   | Ubicaciones | **NO existe** (hallazgo B6 de la Etapa 05) | N5 |
   | **Lecturas de horómetro** | **SÍ existe** — `ReadingsRelationManager:53-54` tiene `CreateAction`, `EditAction` y `DeleteAction` | **N0: nunca se probó** |
   | **Partes de máquina** | **SÍ existe** — `PartsRelationManager:60-61`, mismas tres acciones | **N0: nunca se probó** |
   | Órdenes de trabajo | SÍ existe, reservado a administrador | N3 (por payload), no por interfaz navegando |

   **Lo que esta corrección agrega al riesgo, y es lo importante:** los dos relation managers **no
   tienen Policy propia** (ya documentado como deuda en `CLAUDE.md`), así que quedan gobernados por el
   `canEdit()` de `MachineResource`, que exige `manage_machines` → **administrador y
   responsable_mantenimiento pueden crear, editar y borrar lecturas de horómetro a mano**.
   Eso toca directamente el cálculo de `remaining_hours` y el ancla del PM report, que es el corazón de
   A4. **Es una capacidad de escritura sobre el dato más sensible del sistema, en manos de dos roles, y
   está en N0.** Sube al primer lugar del riesgo, junto con el flujo de taller.

   Las 14 celdas que había clasificado como "NO APLICA — la operación no existe" para lecturas pasan a
   ser **6 DEBE PODER + 8 NO DEBE PODER, todas en N0**.
4. **Exportaciones PDF y Excel: N0 por el rol que las va a usar.** Se ejecutaron como gerencia por HTTP
   (Ola 1) y por admin. **Nadie las abrió desde el botón de la pantalla.** Y hay un punto de diseño ya
   detectado: hoy **ningún rol tiene `view_reports` sin `view_costs`**, así que el caso "exporta sin ver
   montos" no existe en la matriz.
5. **Adjuntos por UI: sigue en N0.** Confirmado. La Etapa 05 lo declaró sin verificar; el Bloque 4 probó
   la regla de validación y la ruta autorizada por HTTP y por test, pero **nadie subió un archivo desde
   el formulario**. Y `work_order_attachments` está vacía: no hay ni un adjunto real en el sistema.
6. **El flujo de taller de punta a punta, paso por paso:**

| Paso | Procedencia | Detalle |
|---|---|---|
| Abrir la OT asignada | **N2** | Abierta por URL directa en la regresión; nunca desde el menú por taller |
| Precargar el checklist DVIR (61 ítems) | **N4** | Verificado en BD por el agente del Bloque 2/Ola 2 |
| Marcar ítems y exigir detalle en un defecto | **N4** | Verificado en BD (crea `Alert` tipo checklist) |
| Registrar partes usadas con costo | **N4/N5** | Observer y recálculo verificados; nunca por UI |
| Registrar mano de obra | **N0** | Sin evidencia de ejecución |
| Adjuntar la factura | **N0** | Nadie subió un archivo nunca |
| Cerrar la OT | **N4** | `WorkOrderCompletionService` verificado en BD y por test |

**El flujo más importante del taller no tiene un solo paso en N1.** Es el trabajo diario de un rol
completo y nadie lo recorrió como el mecánico.

---

## PARTE 4 — Plan mínimo: 4 sesiones

Ordenadas **por dependencia**, no por riesgo: la sesión 1 crea la orden de trabajo que la sesión 2
ejecuta. Sin ese encadenado, el traspaso responsable → taller —que hoy nadie verificó de punta a punta—
seguiría sin probarse. **Ninguna sesión repite algo que ya esté en N1.**

**Regla que aplica a todas: la OT real de la línea base NO se toca por ningún motivo.** El taller ejecuta
la OT `QA-` creada en la sesión 1.

### Sesión 1 — Responsable de mantenimiento
**Rol:** `responsable@dp.local` · escritorio + celular · español · **~24 celdas**

Su objetivo del día: llegar en la mañana y saber qué máquinas necesitan servicio esta semana, cuál de la
flota cuesta más y cuáles llevan días sin reportar horómetro. Con eso:

1. **Crear la OT `QA-` y asignarla al taller** — es el insumo de la sesión 2.
2. Exportar el reporte a **PDF y a Excel desde el botón**, no por URL.
3. Revisar alertas y bitácora.
4. **Crear, editar y borrar una lectura de horómetro a mano** desde el relation manager de una máquina
   `QA-`, observando qué le pasa a `remaining_hours` y al ancla en cada paso. Control negativo: que
   taller y gerencia no puedan.

**Cierra:** crear OT (hoy N0 por el rol), exportaciones por su dueño real, alertas, historial y costos, y
las **14 celdas de lecturas de horómetro** que destapó la corrección de la Parte 3.
**Por qué primero:** es el rol que decide la adopción y sigue sin ejecutarse; y el punto 4 es la prueba
de fuego de A4 **en manos de un usuario** en vez de un test, sobre el dato más sensible del sistema.

### Sesión 2 — Taller, de punta a punta
**Rol:** `taller@dp.local` · escritorio/tablet · español · **~14 celdas**

Recibir **la OT `QA-` que creó el responsable** desde el menú, precargar el DVIR, marcarlo con un defecto
que exija detalle, registrar partes con costo y mano de obra, **adjuntar la factura** y cerrar la OT.

**Al adjuntar la factura, verificación explícita de A5:** a qué disco escribió realmente el archivo y si
la URL del archivo nuevo responde **sin sesión**. `work_order_attachments` está vacía, así que este es el
**primer ejercicio real del fix de A5** — hasta ahora se probó con un archivo sembrado y por HTTP, nunca
subiendo uno desde el formulario.

**Cierra:** los 7 pasos del flujo de taller (hoy ninguno en N1), el módulo de checklists (hoy 0 de 8),
adjuntos por UI (hoy N0) y costos vistos por quien los carga.

### Sesión 3 — Gerencia
**Rol:** `gerencia@dp.local` · escritorio · **inglés** · **~10 celdas**
Los tres números antes de la reunión, exportar a PDF y Excel, y **mover una máquina de obra** (su única
escritura). Control negativo: intentar editar y eliminar una máquina.
**Cierra:** `move_fleet` por su dueño (hoy solo probado por mí, N3), exportaciones en inglés, flota de
solo lectura verificada en pantalla, y si la diferencia entre "mover" y "editar" se entiende como usuario.

### Sesión 4 — Personal de mantenimiento + cierre de administrador
**Roles:** `campo@dp.local` (celular, español) y `admin@dp.local` · **~12 celdas**
El recorrido de tres máquinas con el caso del horómetro roto (**PJ001**), que es donde A4 se ve o no se ve
en la cara del usuario. Y del administrador: la revisión de flota que quedó sin hacer, más crear y editar
una máquina **por interfaz navegando** (hoy N3, hecho por script).
**Cierra:** el tercer rol de campo, el caso real de horómetro incoherente y las escrituras de flota.

**Total: 4 sesiones, ~60 celdas.** Llevaría la cobertura DEBE PODER de **12 % a ~80 %** y dejaría en cero
los cinco módulos que hoy no tienen ninguna escritura hecha por un rol no-administrador.

---

## PARTE 5 — Bloqueos

1. **MCP de Playwright inutilizable** (dos servidores compitiendo por el mismo perfil). Resuelto con un
   controlador propio: scripts Node con el paquete `playwright` del caché npx, Chrome del sistema y
   perfil temporal, que abren y **cierran** el navegador en cada corrida y viven en el scratchpad, nunca
   en el repositorio. Arreglo de fondo pendiente: `--isolated` en los argumentos del servidor MCP.
2. **Sin poder borrar máquinas por interfaz**, la limpieza de datos de prueba depende de SQL autorizado
   caso por caso. Es exactamente lo que el comando `qa:cleanup` de la Fase B viene a resolver.
3. **`work_order_attachments` está vacía**: para probar adjuntos en la sesión 1 hay que subir un archivo
   real, no hay ninguno preexistente que reutilizar.

## Nota sobre el uso de este número

El 13 % es un **instrumento interno de priorización**. Fuera de contexto describe mal al sistema: hay
147 tests verdes, tres críticos cerrados y verificados, y C2 comprobado por el rol real en su celular.
Al cliente se le muestra **el plan mínimo y lo verificado en N1**, no este porcentaje.

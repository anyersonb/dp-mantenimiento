# Persona 3 — Foreman / Capataz · SOLO celular · inglés

**Usuario:** `foreman@dp.local` · **Contexto:** 6:30 a.m. en la obra, celular en una mano, 390 y 360 px.
**Objetivo del día:** llegaron dos máquinas. Confirmo la ubicación de las dos, reporto el horómetro de
una y levanto un reporte de una fuga hidráulica que vi. Todo en menos de dos minutos y con una mano.
**Control negativo:** intento mover una máquina a otra obra. **No debo poder** (eso es de gerencia).

## Resultado: **LO LOGRÉ** — y el idioma, por fin, es el que me corresponde

Todo salió en inglés, que es mi idioma asignado. Es la primera persona del pase donde el idioma no es un
problema.

| Tarea | Toques | Teclas | Resultado |
|---|---|---|---|
| Entrar y llegar a mi tablero | 2 | 26 (correo + clave) | OK, "📍 My job site" está en el menú |
| Confirmar ubicación de QA-FOR-01 | 3 | 9 | ✅ "Updated" |
| Confirmar ubicación de QA-FOR-02 | 3 | 9 | ✅ "Updated" |
| Reportar horómetro (650 h) | 4 | 12 | ✅ "Updated", lectura guardada con `source='foreman'` |
| Reportar la fuga hidráulica | 7 | 76 (la nota es larga) | ✅ "Report sent" |

**Tiempo:** ~26 s sumando las cuatro tareas **de tiempo de automatización**. No es tiempo humano —mi
latencia por acción no es la de un pulgar— pero con 3 toques por confirmación y 7 por el reporte,
**el objetivo de "menos de dos minutos" se cumple con margen amplio**. Los toques son exactos.

## Lo que está bien y hay que no romper

- **Mi menú tiene exactamente lo mío y nada más:** "📋 Field report" y "📍 My job site", los dos de
  358×54 px. Nada de flota, ni costos, ni órdenes de trabajo.
- **Confirmar ubicación son 3 toques y comparte pantalla con el horómetro.** Escribo el ID, acepto la
  sugerencia, toco Update. Si quiero, de paso pongo las horas en el mismo formulario. Está bien pensado.
- **Confirmación explícita:** "✅ Updated ✓ Log another" y "✅ Report sent ✓ Send another". Sé que se
  guardó y sé cómo seguir.
- **El reporte de falla captura la ubicación solo.** Dice "📍 Location captured" y en la base quedó
  `location_id = 1`. No tuve que hacer nada.
- **Los estados de condición son botones grandes y con color:** 🟢 OK / 🟡 Needs attention /
  🔴 Critical, de 101×61 px cada uno. Con guantes se aciertan y con el sol se distinguen.
- **La lectura regresiva me la rechaza a mí, no en un test:** puse 100 h sobre una máquina de 500 y me
  dijo *"The reading (100 h) is lower than the last one recorded (500 h). Check the value before
  submitting."* Clarísimo.
- **Sin desborde horizontal** a 390 ni a 360 px. **Cero costos** en todas mis pantallas.
- Los únicos controles por debajo de 44 px son el conmutador de idioma (17 px) y el pie de página
  (Home / Log out, 37 px) — secundarios, no del flujo.

## El control negativo: **no puedo mover una máquina, y se ve en pantalla**

Esto es lo más importante que verifiqué, porque es el crítico C2 de la Etapa 05 comprobado **por el rol
real, navegando desde el menú, en su dispositivo**:

- El selector "Location" del tablero **solo me ofrece dos opciones: "—" y "Broadview Yd."**, que es la
  obra donde está la máquina. **Las otras 9 obras del sistema no aparecen.**
- O sea: no es que me deje elegir y luego me rechace. **Directamente no puedo elegir otra obra.**
- La bitácora registró mis dos acciones como `location_confirmed` con mi usuario (causer 3) y la
  descripción "confirmada sin cambios". **Las máquinas siguen en la obra 1**: no moví nada.

Antes del fix, este mismo tablero me ofrecía las 11 obras y movía la máquina sin chistar.

## Hallazgos

### MEDIO/ALTO — "Machines at my site" me muestra la flota entera, no mi obra
- **Pasos:** entro a "📍 My job site" y bajo.
- **Obtenido:** una lista de **102 líneas** con máquinas de **10 obras distintas**: Blount Rd. 12,
  Broadview Yd. 10, WPB Yd. 6, Atlantic Yd. 5, Douglas Rd. 4, McNab 4, Davie Yd. 3, Pompano Bch. 1,
  Turnpike 1, Rapid Milling 1. La página queda de **2537 px de alto en un teléfono de 844**: tres
  pantallas y media de scroll.
- **Esperado:** las máquinas de **mi** obra. El título dice "at my site".
- **Por qué importa a las 6:30 a.m.:** esa lista es justamente donde miraría para ver qué tengo hoy en
  la obra. Como me muestra todo, no me sirve para nada y encima me obliga a scrollear. **Lo que haría en
  la realidad es ignorarla y preguntarle al responsable por WhatsApp qué máquinas me llegaron.**
- **O el título está mal, o el filtro está mal.** Cualquiera de las dos hay que corregir.
- **Idioma:** ambos (la lógica no depende del idioma).

### MEDIO — El mismo "✅ Updated" sale con horómetro y sin horómetro
- **Obtenido:** el formulario hace dos cosas a la vez (confirmar ubicación y, opcionalmente, registrar
  horas) y **responde el mismo mensaje en los dos casos**. Si las horas no llegan a registrarse, veo
  "✅ Updated ✓" igual.
- **Cómo lo descubrí, y la aclaración honesta:** en una corrida mi automatización tocó Update antes de
  que el valor de horas se sincronizara, y quedó solo la confirmación de ubicación sin lectura.
  **No lo reporto como fallo del sistema**: es un artefacto de mi velocidad, y repitiéndolo con una
  pausa normal la lectura se guardó perfecto. **Lo que sí es del sistema es que el mensaje no distingue
  los dos casos**, así que un capataz que se olvidó de poner las horas —o al que se le fue el dedo— se
  va convencido de haberlas reportado.
- **Recomendación:** que la confirmación diga qué se guardó: "Location confirmed" vs "Location confirmed
  + 650 h recorded".
- **Idioma:** ambos.

### BAJO — La bitácora me registra en español aunque yo trabajo en inglés
- **Obtenido:** mis dos confirmaciones quedaron con la descripción **"Ubicación de la máquina confirmada
  sin cambios"**, texto fijo en español, generado por un usuario cuyo idioma es inglés.
- El *nombre* del evento sí está traducido, pero la descripción se guarda en un solo idioma.
- **Por qué es bajo:** quien lee la bitácora es el responsable, que opera en español, así que hoy no
  molesta. Lo anoto porque si mañana Gerencia (inglés) audita la bitácora, va a leer descripciones en
  español.
- **Idioma:** el registro sale en ES sin importar quién lo generó.

### Observación (no defecto) — La puerta de entrada, otra vez
Ya está documentado en la persona del operador de cisterna y me aplica igual: si escribo el dominio a
secas caigo en el acceso del panel y me dice que mis credenciales no coinciden. No lo cuento dos veces;
queda como el mismo hallazgo ALTO, que afecta a **los tres roles de campo**.

## Controles negativos del rol

| Lo que intenté | Resultado |
|---|---|
| Mover una máquina a otra obra | **No puedo.** El selector solo ofrece la obra actual de la máquina. Verificado en pantalla, por mí, navegando |
| Ver costos en mis pantallas | Ninguno |
| Registrar una lectura menor a la anterior | Rechazada con mensaje claro y sin guardar nada |

## La pregunta de adopción

**Sí, y este rol es el que menos resistencia va a poner.** Confirmar dos máquinas me cuesta 6 toques en
total y el reporte de falla es un formulario de una pantalla con botones grandes de colores. Comparado
con anotar en una libreta y pasarlo a la tarde, gano tiempo y no pierdo el reporte.

Hay dos cosas que me van a hacer ruido todos los días. La primera es la lista de "mi obra" que no es de
mi obra: es lo primero que mira uno al llegar y no sirve. La segunda es la puerta de entrada, que el
primer día me va a hacer creer que mi clave está mal. Ninguna de las dos está en el flujo de trabajo en
sí, que está bien resuelto.

## Las 3 cosas que le arreglaría primero a esta persona

1. **Filtrar "Machines at my site" por mi obra** (o cambiarle el título y ponerle un buscador). Hoy son
   3 pantallas y media de scroll de máquinas que no son mías.
2. **La puerta de entrada** — compartido con los otros dos roles de campo.
3. **Que la confirmación diga qué se guardó**, para saber si mis horas quedaron registradas o solo
   confirmé la ubicación.

## Montaje y limpieza

Creé **2 máquinas de prueba `QA-FOR-01` y `QA-FOR-02`** (ids 114 y 115) como administrador antes de
empezar, para no tocar ninguna máquina real. El montaje no forma parte de lo medido.

**Pendiente de limpiar al cierre de la etapa:**

| Tabla | Qué | Cuánto |
|---|---|---|
| machines | ids 114, 115 (`QA-FOR-01`, `QA-FOR-02`) | 2 |
| horometer_readings | id 172 (650 h, `source='foreman'`) | 1 |
| field_reports | id 3 (fuga hidráulica, `critical`) | 1 |
| activity_log | ids 253-256 (2 `created` + 2 `location_confirmed`) | 4 — **no se borran**: la bitácora es append-only |

**Ninguna máquina real fue tocada.** Los valores verificados del PM report y las 62 anclas siguen
intactos.

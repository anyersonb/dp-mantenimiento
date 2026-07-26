# Persona 2 — Operador de cisterna · SOLO celular · español asignado

**Usuario:** `combustible@dp.local` · **Contexto:** patio, celular en una mano, 390 y 360 px, GPS activo.
**Objetivo del día:** estoy cargando combustible a cuatro máquinas seguidas. Registro las cuatro cargas
con sus galones y horómetro, una tras otra, **sin volver al inicio entre cada una**. Y no debo ver
ningún costo en ningún momento.

## Resultado: **LO LOGRÉ** — y este flujo es el mejor del sistema

Cargué las cuatro seguidas, encadenadas, sin volver al menú. Es el flujo más pulido que vi en toda la
aplicación. Los problemas que tengo **no están dentro del flujo**: están en la puerta de entrada y en
el idioma.

## Lo que costó cada carga (medición exacta)

| Carga | Máquina | Toques | Teclas | Encadenar |
|---|---|---|---|---|
| 1 | QA-CIS-01 | 5 | 14 | +1 toque ("Log another") |
| 2 | QA-CIS-02 | 5 | 14 | +1 toque |
| 3 | QA-CIS-03 | 5 | 14 | +1 toque |
| 4 | QA-CIS-04 | 5 | 14 | — |

**6 toques y 14 teclas por carga** dentro de una cadena. Los 5 toques son: campo de máquina → aceptar
la sugerencia → galones → horómetro → Guardar. Las 14 teclas son el ID de la máquina (9) más galones
(2) y horómetro (3).

**Tiempo:** 21,6 s las cuatro cargas completas. **Ojo con este número:** es el tiempo de mi
automatización, no el de una persona con el pulgar. Lo que sí es comparable es la cuenta de toques y
teclas, que es exacta.

## Lo que está muy bien hecho y hay que no romper

- **La confirmación es explícita y no deja duda.** Cada guardado responde
  *"✅ Logged ✓ The fuel record was saved"* con un botón **"Log another"** al lado. En campo esto es oro:
  sé que se guardó y sé cómo seguir sin pensar.
- **El encadenado existe y cuesta un solo toque.** No tengo que volver al menú entre cargas: es
  exactamente lo que necesito cuando tengo cuatro máquinas esperando.
- **Cero costos.** Revisé el texto completo de las siete pantallas que recorrí: no aparece ni un `$`,
  ni "costo", ni "precio", ni "USD". Ni en el formulario, ni en la confirmación, ni en el menú.
- **La máquina se busca escribiendo y me sugiere.** Escribo el ID y me ofrece la coincidencia; un toque
  y queda. Mucho mejor que un desplegable con 103 máquinas para hacer scroll con el pulgar.
- **Entra todo en una pantalla, sin scroll.** A 390 px: 846 px de contenido en 844 de ventana. A 360 px:
  802 en 800. Sin desborde horizontal en ninguno de los dos.
- **Los controles del flujo son grandes:** campos de 318×44 px y el botón Guardar de 318×52. Con guante
  se aciertan.
- **Se lee al sol.** Calculé el contraste: el botón Guardar da **8,3:1** (texto oscuro sobre ámbar) y el
  texto del cuerpo ~13:1. Muy por encima del mínimo.
- **El doble toque en Guardar NO duplica.** Lo probé (pasa con guantes): golpeé dos veces y quedó **un
  solo registro**. Verificado en base de datos.

## Hallazgos

### ALTO — La puerta de entrada me rechaza y me hace creer que mi contraseña está mal
**Este es el hallazgo que rompe la adopción de este rol.**

- **Pasos:** escribo el dominio a secas en el celular (que es lo que haría cualquiera; nadie recuerda
  "/field/login"). Aterrizo en la pantalla de acceso del panel. Escribo mi correo y mi contraseña
  —que son correctos— y toco Sign in.
- **Obtenido:** *"These credentials do not match our records."*
- **Esperado:** que me lleve a mis pantallas de campo, o que me diga que ésta no es mi puerta y me
  ofrezca la correcta.
- **Por qué es alto y no cosmético:** mis credenciales **sí** coinciden. El sistema me miente. Lo que
  hace un operario a las 6 de la mañana con la cisterna cargada y la máquina esperando es
  **mandarle un WhatsApp al responsable diciendo "no me deja entrar, ¿me reseteás la clave?"** — y el
  responsable pierde media hora buscando un problema que no existe. **El sistema perdió ahí, antes de
  que yo llegue a ver una sola pantalla.**
- **Evidencia:** `evidencia/cisterna-puerta-equivocada.png` · **Idioma:** EN (en mi caso; el mensaje es
  el genérico de la plataforma).
- **Recomendación:** que la pantalla de acceso sea una sola para todos y derive a cada uno a donde le
  corresponde según su rol. Si se mantienen dos puertas, que la del panel detecte al usuario de campo
  y lo redirija, en vez de decirle que sus datos están mal.

### ALTO — Todo el módulo me habla en inglés, y la única salida es el control más chico de la pantalla
- **Obtenido:** siendo el operador de cisterna —que en DP habla español— todo me sale en inglés:
  *"Hi, Operador Cisterna"*, *"Log fuel"*, *"Machine"*, *"Gallons"*, *"Hour-meter reading"*, *"Save"*,
  *"Logged"*, *"Log another"*.
- **Causa verificada:** mi usuario está sembrado con el idioma en inglés (`users.locale = 'en'`), y el
  sistema respeta esa preferencia correctamente. **No es un fallo de traducción: es un problema de valor
  por defecto.** Los usuarios de campo hispanohablantes vienen configurados en el idioma equivocado.
- **Lo que lo convierte en ALTO es la combinación:** la única forma de cambiarlo desde el celular es un
  conmutador **"ES" de 14×17 píxeles** — el control más pequeño de toda la pantalla, muy por debajo del
  mínimo táctil de 44×44, y yo estoy con guantes. Es decir: estoy en el idioma que no entiendo y la
  puerta de salida es del tamaño de una cabeza de alfiler.
- **Idioma:** EN donde debería ser ES.
- **Recomendación:** dos cosas, y ninguna es difícil. Sembrar los usuarios de campo en español (o
  preguntar el idioma en el primer ingreso), y agrandar el conmutador de idioma al mínimo táctil.

### MEDIO — Si me interrumpen a mitad de la carga, pierdo lo que escribí
- **Pasos:** escribo la máquina y los galones, me interrumpen (toco Inicio para mirar algo), vuelvo a
  la pantalla de carga.
- **Obtenido:** los campos vuelven **vacíos**. Verificado: máquina `""`, galones `""`.
- **Esperado:** que lo escrito siga ahí, o al menos que me avise que lo voy a perder.
- **Atenuante honesto:** el formulario es corto (14 teclas), así que rehacerlo cuesta poco. Por eso es
  medio y no alto. Pero en el patio uno se interrumpe todo el tiempo.
- **Idioma:** ambos.

### MEDIO — No hay forma de corregir una carga mal ingresada
- **Obtenido:** después de guardar, la pantalla solo ofrece "Log another", "Home" y "Log out". **No veo
  mis últimas cargas ni puedo corregir la que acabo de meter.** Si tecleé 200 galones en vez de 20, no
  tengo salida: no puedo verlo, no puedo editarlo, no puedo borrarlo.
- **Qué haría en la realidad:** **mandarle un WhatsApp al responsable** para que lo corrija él desde el
  panel. Segunda vez que el flujo termina en WhatsApp.
- **Recomendación:** una lista de "mis últimas cargas de hoy" con posibilidad de corregir la última, o
  al menos verlas para poder avisar con precisión.
- **Idioma:** ambos.

### MEDIO — No se puede borrar una máquina desde el panel (lo descubrí al limpiar)
Esto lo encontré fuera del flujo del operario, al intentar limpiar mis máquinas de prueba, y afecta a
un pendiente real del cliente.

- **Obtenido:** un administrador **no tiene ninguna forma de eliminar una máquina**: la pantalla de
  edición no tiene botón de eliminar, la fila del listado solo ofrece Ver / Editar / Mover / Registrar
  reemplazo de horómetro, y al seleccionar filas **no aparece ninguna acción masiva**.
- **Por qué importa ahora:** hay **35 máquinas marcadas `needs_review`** que el cliente tiene que
  *confirmar o descartar*, y 29 de ellas vinieron del Info Book. **Descartar es hoy imposible por la
  interfaz.** El cliente va a pedir "borrá estas 12 que no son máquinas" y no habrá cómo.
- **Recomendación:** acción de eliminar para administrador (con confirmación y sin borrar historial de
  máquinas con movimientos), o al menos un estado "descartada" que las saque del listado operativo.
- **Idioma:** ambos.

### Observación (no defecto) — El GPS se pide y se resuelve solo
La pantalla muestra *"📍 Getting your location…"* al abrirse y resuelve sin que yo haga nada. Con
permiso concedido no estorba. **No pude probar el caso sin señal ni con GPS denegado**: queda pendiente.

## Controles negativos del rol

| Lo que intenté | Resultado |
|---|---|
| Ver algún costo en cualquier pantalla del flujo | **Ninguno.** Cero `$`, "costo", "precio" o "USD" en las 7 pantallas recorridas |
| Entrar por la puerta del panel con mis credenciales | Rechazado (correcto que no entre; incorrecto **cómo** me lo dice — ver hallazgo ALTO) |
| Duplicar un registro con doble toque | No duplica. Un solo registro en base de datos |

## La pregunta de adopción

**Sí, este operario usaría el sistema mañana por voluntad propia — pero solo si alguien le deja el
acceso resuelto en el celular.**

El flujo de carga es genuinamente bueno: seis toques, confirmación clara, encadenado de un toque, todo
en una pantalla, se lee al sol y el doble toque no le juega en contra. Comparado con anotar en un papel
y pasarlo después, esto le ahorra trabajo de verdad y **no le pide nada que no pueda hacer con una
mano**.

Lo que lo haría abandonar no es el flujo, son los dos porteros: **si el primer día escribe el dominio y
el sistema le dice que su contraseña está mal, no vuelve a intentar**; y si entra y todo está en un
idioma que no lee, tampoco. Los dos se arreglan sin tocar el flujo. Con un acceso directo en la pantalla
del celular apuntando a `/field/login` y el idioma en español, este rol es el que adopta más rápido de
todos.

## Las 3 cosas que le arreglaría primero a esta persona

1. **La puerta de entrada:** un solo acceso que derive por rol, o que la pantalla del panel redirija al
   usuario de campo en vez de decirle que sus credenciales no coinciden.
2. **El idioma por defecto de los usuarios de campo** en español, y el conmutador de idioma a 44×44 px.
3. **"Mis últimas cargas de hoy"** con corrección de la última, para que un dedazo no termine en un
   WhatsApp al responsable.

## Montaje y limpieza

Para no tocar los horómetros verificados del PM report (regla de línea base), creé **4 máquinas de
prueba `QA-CIS-01..04`** como administrador **antes** de empezar el pase; el montaje no forma parte de
lo medido. Sobre ellas se hicieron las cargas.

**Estado de la limpieza: PENDIENTE, bloqueada por el hallazgo de arriba.** No hay forma de borrar
máquinas desde el panel, y las escrituras SQL directas están bloqueadas en este entorno. Quedan por
eliminar:

- `machines` ids **109, 111, 112, 113** (`QA-CIS-01` a `QA-CIS-04`)
- `horometer_readings` ids **166 a 171** (6 lecturas: 5 del pase y 1 del doble toque)

Aclaración sobre esas 6 lecturas: la consulta de duplicados marca `QA-CIS-01 150 h / 20 gal ×2`. **No es
un defecto del sistema:** son mis dos corridas del script (14:05:39 y 14:07:03). El doble toque real
generó un único registro.

Comando exacto para cerrarlo, en la sección final del consolidado.

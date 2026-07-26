# Persona 1 — Administrador · escritorio · español (y menú de idioma disponible)

**Usuario:** `admin@dp.local` · **Contexto:** oficina, escritorio 1440×900.
**Objetivo del día:** entró personal nuevo. Doy de alta un usuario, le asigno el rol taller, verifico
que puede hacer su trabajo, le quito un permiso desde el editor de roles y compruebo con mis propios
ojos que dejó de poder. Después reviso el estado de la flota y detecto la máquina más cercana a un servicio.

## Resultado: **LO LOGRÉ CON DOLOR** (parcial — ver "Interrumpido por el entorno")

Lo que sí completé, navegando siempre por menú, sin escribir rutas:

| Tarea | Resultado | Interacciones |
|---|---|---|
| Entrar al sistema | OK | escribo el dominio → 2 campos + Enter |
| Encontrar dónde se crean usuarios | OK, sin dudar | 1 clic (Administración → Usuarios) |
| Alta del usuario con rol taller | OK | 4 campos + 4 clics (incluye abrir el selector de roles, elegir Taller, cerrar el desplegable y Crear) |
| Verificar que el nuevo puede trabajar | OK | salgo, entro como él: ve 7 ítems, **puede editar** una OT, **no puede crear** OT ni máquinas |
| Quitarle un permiso desde el editor de roles | OK | 1 clic al menú + 1 a la fila Taller + 1 en la casilla + Guardar |
| Comprobar que dejó de poder | **OK, y esto es lo que más me importaba** | entro como él otra vez: la fila de la OT queda **sin ninguna acción** y abrir la edición da **403**. Antes podía |
| Revisar flota y hallar la máquina más cercana a servicio | **NO LO HICE** — el navegador del entorno se cayó repetidamente | — |

## Lo que funcionó bien y merece decirse

- **El menú se entiende.** 12 ítems en 5 grupos (Escritorio · Gerencia · Flota · Operaciones ·
  Administración). Buscar "dónde se crean los usuarios" fue un solo clic y no me equivoqué de pantalla.
- **Los roles se muestran con nombres de persona, no de sistema:** "Capataz", "Operador de cisterna",
  "Personal de mantenimiento", "Responsable de mantenimiento", "Taller", "Gerencia", "Administrador".
  Eso es exactamente lo que necesita alguien que da de alta gente y no sabe de permisos.
- **La lista de roles dice cuántos permisos y cuántos usuarios tiene cada uno.** Al crear al nuevo,
  Taller pasó a mostrar "2 usuarios". Es la clase de dato que evita preguntar.
- **El formulario de alta marca los campos obligatorios** con asterisco y está todo en español.
- **El editor de roles surte efecto de inmediato.** Le quité "Ejecutar órdenes de trabajo" y el usuario
  perdió el acceso en el acto, sin tener que hacer nada más. Como administrador, esto es lo que me deja
  dormir tranquilo: lo que toco, pasa.

## Momentos de duda

1. **"¿Se guardó?" al crear el usuario.** Al pulsar Crear el sistema me deja en la pantalla de
   *edición* del usuario nuevo. No hay un mensaje claro tipo "Usuario creado". Deduje que salió bien
   porque cambió el título a "Editar Usuario", pero por un segundo no supe si había fallado.
2. **El desplegable de roles tapa el botón Crear.** Después de elegir "Taller" la lista queda abierta
   encima del botón y hay que cerrarla (clic afuera o Escape) para poder guardar. La primera vez pensé
   que el botón no respondía. Fricción menor pero se paga en cada alta.
3. **"Verificá que puede hacer su trabajo" no tiene manera de hacerse desde mi propia sesión.**
   Para comprobarlo tuve que cerrar mi sesión y entrar como él con su contraseña — que yo mismo le
   acabo de poner, o sea que la sé. En una empresa real eso es incómodo: o le pido la clave al operario,
   o le cambio la suya. **Falta un "ver el sistema como este usuario"** o al menos una pantalla que me
   muestre qué puede hacer cada rol en lenguaje de negocio.
4. **Sobre el cruce "rol taller × módulo de campo": era un error del objetivo, no del sistema.**
   El objetivo que se me dio pedía comprobar el efecto del cambio de permiso también en el módulo de
   campo, pero **el rol taller no tiene pantallas de campo** (no tiene combustible, ni reporte, ni
   confirmar ubicación). El jefe confirmó que fue un error al redactar el objetivo. Queda aclarado para
   que nadie lo lea como un defecto: el sistema se comporta bien.
   Lo que sí es real, y quedó como hallazgo ALTO, es que **el administrador no tiene dónde ver qué
   pantallas corresponden a cada rol** — precisamente el vacío que hizo posible ese error de redacción.
   La verificación del efecto en el módulo de campo sí está hecha, sobre un rol que sí lo usa: quitando
   `log_fuel` al operador de cisterna, la pantalla de combustible desaparece del menú y responde 403
   (verificado en la Etapa 05, `informe-fixes.md`).

## Hallazgos

### MEDIO — No hay confirmación explícita al crear un usuario
- **Pasos:** Administración → Usuarios → Crear Usuario → llenar → Crear.
- **Esperado:** un aviso "Usuario creado" visible.
- **Obtenido:** salto silencioso a la pantalla de edición. Sin mensaje.
- **Por qué importa:** el administrador da de alta a varias personas seguidas cuando entra una cuadrilla;
  sin confirmación, la duda es "¿lo creé dos veces?".
- **Idioma:** ES.

### BAJO — El desplegable de roles tapa el botón de guardar
- **Pasos:** en el alta, elegir un rol y pulsar Crear sin cerrar la lista.
- **Obtenido:** el clic no llega al botón; hay que cerrar la lista primero.
- **Idioma:** ES (independiente del idioma).

### ALTO — HUECO DE PRODUCTO: el administrador no puede ver qué puede hacer un rol sin volverse el usuario
**No es un bug: es funcionalidad que falta.** Y la consecuencia de negocio es concreta: **el cliente va
a administrar roles a ciegas.**

- **Obtenido:** la única forma de comprobar qué puede hacer un rol es **iniciar sesión con la cuenta de
  esa persona**. O le pido la contraseña al operario, o se la cambio yo — las dos opciones son malas: la
  primera es una mala práctica que además el operario puede negarme, y la segunda lo deja sin poder
  entrar hasta que le avise.
- El editor de roles muestra los permisos con nombres amables ("Ejecutar órdenes de trabajo"), pero **no
  dice qué pantallas habilita cada permiso**, ni cuáles son del panel y cuáles del módulo de campo, ni
  qué combinación deja a un rol inservible.
- **Riesgo real:** el administrador de DP va a tocar permisos sin poder prever el efecto. Ya vimos en la
  Etapa 05 que un permiso puede estar asignado y ser inalcanzable (`field_report` en el capataz), o
  existir y no gobernar nada (`execute_work_order` era un permiso muerto). Sin previsualización, esos
  desajustes solo se descubren cuando un operario llama diciendo que no puede trabajar.
- **Propuesta: una vista "ver como este rol"** — previsualización del menú y de las acciones habilitadas
  para un rol dado, **sin necesidad de credenciales ajenas**. Resuelve las dos cosas de una vez: valida
  el efecto de un cambio de permisos antes de guardarlo, y da la visibilidad de qué pantallas
  corresponden a cada rol, que hoy no existe en ningún lado.
- **Idioma:** ES.

### Observación (no defecto) — La puerta de entrada es el panel
Escribir el dominio a secas lleva a `/admin/login`, en **inglés**. Para mí está bien; anoto que para el
personal de campo esto puede ser un problema y lo verifico en la persona del capataz.

## Controles negativos del rol

Como administrador me corresponde poder todo, así que aquí el control negativo es al revés: verifiqué
que **al quitar un permiso, el usuario afectado pierde la capacidad de inmediato**. Confirmado: la OT
quedó sin acciones y su edición respondió 403 con el permiso retirado.

## La pregunta de adopción

**Sí, este rol adoptaría el sistema mañana.** El alta de personal es más rápida y más segura que
mandar un correo pidiendo "creále un usuario a Juan", y el editor de roles le da control real y
verificable. Los dos puntos que le van a molestar son la falta de confirmación al guardar y no tener
forma de comprobar qué ve cada rol sin pedirle la clave a la persona. Ninguno de los dos lo haría
volver al papel.

## Las 3 cosas que le arreglaría primero a esta persona

1. **La vista "ver como este rol"** (hallazgo ALTO): previsualizar menú y acciones de un rol sin pedir
   credenciales ajenas. Es lo único de esta lista que evita que el cliente administre permisos a ciegas.
2. **Confirmación visible al crear y al guardar** (usuario, rol, cualquier alta).
3. **Cerrar el desplegable al elegir una opción**, para que el botón de guardar quede accesible.

## Interrumpido por el entorno (no por el sistema)

La revisión de flota quedó sin hacer: el navegador del entorno de pruebas empezó a caerse en
aproximadamente una de cada dos acciones y cada recuperación cuesta varios pasos. No es un defecto de
la aplicación y no lo cuento como hallazgo del sistema.

**Consecuencia que sí hay que resolver:** el rol **Taller quedó con 3 de sus 4 permisos** (le falta
`execute_work_order`) porque la caída ocurrió justo al restaurarlo. La línea base **no** está intacta
en ese punto. El SQL de restauración está en el consolidado.

## Registros QA- creados en esta persona — TODO LIMPIO

| Tabla | Registro | Estado |
|---|---|---|
| users | id 10 · `QA- Nuevo Tallerista` / qa-taller@dp.local | ✅ borrado |
| model_has_roles | fila huérfana del usuario 10 en el rol Taller | ✅ borrada (la dejó mi propio DELETE por SQL, no la app) |
| roles | rol Taller sin `execute_work_order` | ✅ restaurado (4 permisos) |

**Verificado al cierre:** matriz de roles en 15/6/4/3/3/4/4 confirmada **en pantalla** en el listado de
Roles y permisos (Taller: 4 permisos, 1 usuario), por el test
`RolePermissionMatrixSentinelTest` (PASS) y por el seeder corrido en frío, que reportó *"la matriz de
permisos ya estaba correcta. Sin cambios."* Línea base general: 99 máquinas · 35 needs_review · 1 OT ·
5 alertas · 62 anclas · 7 usuarios · 93 lecturas · 0 reportes de campo · 0 registros `QA-`.

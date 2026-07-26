# QA Etapa 05 — Ola 6b · Traducción, formatos por locale y layout

Ejecutada por el hilo principal con navegador real. La parte de **permisos y ruteo bilingüe (Ola 6a)
está en el informe consolidado**: se hizo dentro de la Ola 1, rol por rol, y salió PASS en los cuatro.

## Cobertura de traducción — PASS

| Verificación | Resultado |
|---|---|
| Paridad de archivos de idioma | **PASS** — los 10 archivos existen en `lang/es` y `lang/en` |
| Paridad de claves | **PASS** — diff de nombres de clave archivo por archivo: **cero diferencias** (alerts 28, checklist 11, dashboard 6, field 47, fleet 73, mgmt 64, nav 15, roles 23, users 25, wo 56) |
| Claves crudas en pantalla (ES) | **PASS** — 13 pantallas del panel barridas buscando `alerts.` `checklist.` `dashboard.` `field.` `fleet.` `mgmt.` `nav.` `roles.` `users.` `wo.`: ninguna clave sin traducir |
| Claves crudas en pantalla (EN) | **PASS** — 8 pantallas barridas, ninguna clave cruda |
| Sidebar ES vs EN | **PASS** — mismo conjunto de ítems, solo traducidos, en todos los roles |

Pantallas barridas: dashboard, máquinas (listado y detalle), órdenes de trabajo, alertas, bitácora,
usuarios, roles, cotizaciones, mapa de flota, ubicaciones, tipos de máquina y marcas.

**Nota de alcance honesta:** este barrido demuestra que no se escapan claves crudas en las pantallas
en estado normal. **No cubre** los mensajes de validación de formulario ni los estados vacíos, que
requieren provocar cada error uno por uno; quedan **PENDIENTE** (ver Bloqueos del informe: el tiempo
de la corrida se fue en las olas de permisos y seguridad, que eran la prioridad marcada).

## Formatos por locale

| Aspecto | ES | EN | Resultado |
|---|---|---|---|
| Fechas | `jul. 22, 2026` | `Jul 22, 2026` | **Hallazgo BAJO** (ver abajo) |
| Riesgo de fecha ambigua (03/07 al revés) | No existe | No existe | **PASS** |
| Idioma por usuario | `admin`, `taller`, `gerencia` = es · `responsable`, `foreman`, `combustible`, `campo` = en | — | **PASS**, se respeta |
| Persistencia de idioma | Se guarda en `users.locale` y en sesión; sobrevive al logout/login | — | **PASS** |

### BAJO — Las fechas en español usan el orden de EE. UU.
En español se muestra `jul. 22, 2026` (nombre de mes localizado, pero orden mes-día-año). La
convención en español es `22 jul. 2026` o `22/07/2026`.
- **Severidad baja y conviene explicar por qué:** el brief advertía del riesgo de que una fecha
  `03/07` se interprete al revés y corrompa datos. **Ese riesgo NO existe aquí**, porque el mes va
  como nombre abreviado y nunca como número. Es una convención de idioma mal aplicada, no un
  problema de datos.
- **Recomendación:** usar el formato por locale (`->isoFormat('LL')` o equivalente) en las columnas
  de fecha.

### Observación — fecha numérica en la ficha técnica
En el detalle de máquina aparece `9/21/2015` en formato US. Está **dentro del texto verbatim de la
ficha del Info Book**, no en un campo formateado por la app: es texto del fabricante copiado tal
cual. No es un defecto de i18n.

## Layout ES vs EN — PASS

| Ancho | ES | EN |
|---|---|---|
| 1440 px (panel, listado de máquinas) | sin desborde de página | sin desborde de página |
| 360 / 390 px (PWA de campo) | sin desborde | sin desborde |

La tabla de máquinas es más ancha que la ventana, pero **desborda dentro de su propio contenedor con
scroll horizontal** (comportamiento normal de Filament), no del `body`. Las cadenas en inglés y
español no rompen el sidebar, las cabeceras de tabla ni los botones en ninguno de los dos idiomas.

## Hallazgos

### MEDIO — Las páginas de error no están traducidas ni tienen marca
- No existe `resources/views/errors/`: la app usa las páginas por defecto de Laravel.
- Verificado en vivo: un 403 con sesión de rol sin acceso muestra una página en blanco con
  `403 FORBIDDEN`, `<title>Forbidden</title>`, `lang="en"` y **sin el logo de DP**. Un 404 muestra
  `Not Found`. Un 419 muestra `Page Expired`.
- Evidencia: `evidencia/ola6b-403-sin-traducir.png`.
- **Por qué importa:** el 403 no es un caso raro en este sistema — es la respuesta esperada y
  frecuente cada vez que un rol toca algo que no le corresponde. El usuario en español se topa con
  una pantalla en inglés, sin marca y sin salida (no hay enlace de vuelta al panel).
- **Recomendación:** crear `resources/views/errors/403.blade.php`, `404`, `419` y `500` con el layout
  y el logo de la app, textos con `__()` y un botón de volver.

### BAJO — `/locale/{idioma}` entra en bucle de redirección si se abre directo
- **Reproducir:** escribir `http://127.0.0.1:8099/locale/en` en la barra de direcciones (sin venir de
  otra página). Resultado: `ERR_TOO_MANY_REDIRECTS`.
- **Causa:** `routes/web.php:88` hace `return back();`. Sin cabecera `referer`, `back()` cae en la
  URL actual (la propia `/locale/en`) y se autorreferencia en bucle.
- **Detalle importante:** el cambio de idioma **sí se aplica** antes del bucle (se guarda en sesión y
  en `users.locale`), así que el usuario queda con el idioma cambiado y la pantalla trabada.
- **Recomendación:** `return back()->withFallback(route('filament.admin.pages.dashboard'))` o
  `redirect()->intended('/')`.
- Idioma: ambos. Con locale inválido (`/locale/fr`) responde 404 correctamente (`routes/web.php:81`).

## Pendiente de esta ola

- Mensajes de validación de formulario, estados vacíos y confirmaciones de borrado en ambos idiomas.
- Exportaciones PDF/Excel en el idioma activo con cabeceras traducidas → **lo cubre la Ola 3**.
- Alertas y correos en el idioma del destinatario (no del que dispara el evento): **PENDIENTE**, no
  se llegó a probar el caso cruzado (usuario en inglés recibe alerta generada por usuario en español).
- Moneda en el módulo de costos: no se verificó el formato con separador de miles/decimal.

# QA Etapa 05 — Informe consolidado · CMMS DP Development

**Corrida nocturna desatendida** · 2026-07-25, 16:05 → 17:45 · entorno `http://127.0.0.1:8099`
(`APP_ENV=local`, BD `dp_mantenimiento`, **no es producción**) · AnyersonDev.

> Estado: **corrida COMPLETA.** Olas 1, 2, 3, 4, 5, 6a, 6b y regresión ejecutadas.

---

## RESUMEN EJECUTIVO

1. **Semáforo por rol:** gerencia 🔴 · foreman 🔴 · taller 🔴 · responsable_mantenimiento 🟠 ·
   administrador 🟢 · operador_cisterna 🟢 · personal_mantenimiento 🟢
2. **Hallazgos: 3 críticos · 7 altos · 10 medios · 6 bajos.** Los dos roles de campo puro
   (cisterna y personal de mantenimiento) salieron limpios en los 8 módulos.
3. Los 5 que hay que atender primero:
   - **C1** `WorkOrderResource` no tiene ni un solo control de permisos: **gerencia borró una orden
     de trabajo** y **taller crea órdenes** sin tener `create_work_order`. Es el mismo defecto que ya
     se corrigió en flota el 21/07, en el módulo que quedó fuera.
   - **C2** `ForemanBoard::save()` deja a foreman reasignar cualquier máquina a cualquier obra:
     ejerce `move_fleet` sin tenerlo. Verificado end-to-end y en pantalla.
   - **A4** El observer de horómetro recalcula `remaining_hours` y **pisa el dato bueno del PM
     report**: en EX013 (ajuste +5714) la próxima lectura de campo lo dejará en ≈ **−5480 h**, y en
     PJ001 (horómetro roto) ya produjo **6005 h** durante esta misma corrida. Corrompe el dato que
     decide cuándo se le hace servicio a una máquina.
   - **A5** Los adjuntos viven en disco público sin autorización: `/storage/quotes/demo-quote.pdf`
     responde **200 sin sesión**. Las facturas de órdenes de trabajo usan ese mismo disco, así que la
     regla de "sin costos para quien no tiene `view_costs`" se cae por la puerta de atrás.
   - **C3 + A1** La PWA de campo autoriza por **nombre de rol**, nunca por permiso: los 15 permisos
     no gobiernan el módulo de campo, y por eso foreman recibe 403 en `/field/report` pese a tener
     `field_report`. Editar permisos desde el panel no cambia nada en campo.
4. **Sin verificar:** validaciones de formulario y estados vacíos en ambos idiomas, alertas por
   correo en el idioma del destinatario, subida de adjuntos por UI, y comportamiento offline de la
   PWA. Motivo: el navegador solo pudo usarlo el hilo principal (los agentes nunca lo adquirieron) y
   la prioridad marcada era permisos y seguridad.
5. **Limpieza:** los datos reales alterados **fueron restaurados y verificados contra el snapshot**
   (EX010, MS003, PJ001, máquina 45, locales de usuario). Quedan **4 filas de prueba etiquetadas
   `QA-`** que no pude borrar porque el entorno bloquea los DELETE; el SQL exacto está al final.
   **Ninguna notificación salió a un destinatario real:** `MAIL_MAILER=log` y el proyecto no tiene
   integración de WhatsApp/SMS.

---

## RIESGOS DE PERMISOS

| # | Sev. | Rol | Accede a lo que NO le toca | Evidencia |
|---|---|---|---|---|
| C1 | Crítico | gerencia, taller | Crear, editar y **borrar** órdenes de trabajo sin permiso de OT | 200 en create/edit; OT id 5 borrada por gerencia; taller create 200 |
| C2 | Crítico | foreman | Reasignar cualquier máquina a cualquier obra (`move_fleet` de facto) | EX010 movido; `activity_log` 207; select con las 11 obras |
| C3 | Crítico | foreman | **Al revés:** tiene `field_report` y el sistema se lo niega con 403 | `ReportForm.php:39` |
| A1 | Alto | los 3 de campo | La autorización de campo ignora los permisos y mira el nombre del rol | cero `can()` en `app/Livewire/Field/` |
| A2 | Alto | — (preventivo) | Todo `/admin/*` cuelga de una única whitelist sin defensa por Resource | `User::canAccessPanel()` |
| A3 | Alto | responsable | Apaga `needs_review` sin tener `verify_data`, desde el form normal | máquina 45: 1→0 (revertida) |
| A5 | Alto | cualquiera, **incluso sin sesión** | Descarga cotizaciones y adjuntos de costos por URL directa | `/storage/quotes/demo-quote.pdf` → 200 |
| A6 | Alto | taller | `execute_work_order` no existe en la lógica; "completar" no valida permiso | permiso solo en seeder y lang |
| M1 | Medio | gerencia, taller | Ven y listan 3 catálogos bajo "Administración" (solo lectura) | 200 en los 3 listados |

**Lo que sí está bien blindado, y conviene decirlo:** los costos **nunca** se filtraron a foreman,
operador_cisterna ni personal_mantenimiento — ni en panel, ni en PWA, ni en detalle de máquina, ni en
historial, ni en reportes. El fix de permisos de flota del 21/07 **sigue vigente** (taller y gerencia
reciben 403 al crear/editar máquinas). No hay IDOR horizontal. CSRF responde 419. El XSS se almacena
pero **se renderiza escapado**. Y en lo bilingüe no hay ni un solo caso de "403 en español y 200 en
inglés".

---

## MATRIZ ROL × MÓDULO

`+` control positivo · `−` control negativo.

| Módulo | admin | responsable | taller | gerencia | foreman | cisterna | campo |
|---|---|---|---|---|---|---|---|
| 1. Flota | PASS | PASS | PASS (− create/edit 403) | PASS (solo lectura) | PASS (− 403) | PASS | PASS |
| 2. Checklist DVIR | PASS | PASS | PASS (+ 61 ítems) | PASS (−) | PASS (−) | PASS (−) | PASS (−) |
| 3. Horas / horómetro | PASS | PASS | PASS | n/a | PASS (+ BD) | PASS (+ BD) | PASS (+ BD) |
| 4. Combustible | n/a | n/a | n/a | n/a | PASS (−) | PASS (+ observer) | PASS (− 403) |
| 5. Órdenes de trabajo | PASS | PASS | **FAIL C1** | **FAIL C1** | PASS (−) | PASS (−) | PASS (−) |
| 6. Historial y costos | PASS | PASS | PASS | PASS | PASS (− sin montos) | PASS (− sin montos) | PASS (− sin montos) |
| 7. Alertas | PASS | PASS | PASS (− 403) | PASS (− 403) | PASS (− 403) | PASS (− 403) | PASS (− 403) |
| 8. Reportes PDF/Excel | PASS | PASS | PASS (− 403) | PASS (+ archivos reales) | PASS (− 403) | PASS (− 403) | PASS (− 403) |
| Mover obra / confirmar ubicación | PASS | — | — | **PASS** (solo cambia ubicación) | **FAIL C2** | n/a | n/a |
| Reporte de campo | — | — | — | — | **FAIL C3** | PASS (− 403) | PASS (+) |
| Verificar datos (`verify_data`) | PASS | **FAIL A3** | — | PASS (− sin botón) | — | — | — |

**Ola 6a — permisos y ruteo bilingüe: PASS en los 7 roles.** No hay prefijo de idioma en las URLs
(se cambia con `GET /locale/{es|en}` y persiste en `users.locale`), así que se repitieron los
controles negativos en ambos locales: los 403 y los sidebars son idénticos, solo traducidos.
`/locale/fr` → 404 sin romper la sesión.

---

## HALLAZGOS CRÍTICOS

### C1 · `WorkOrderResource` sin ningún control de permisos
- **Reproducir:** como `gerencia@dp.local` → `/admin/work-orders/create` → **200**;
  `/admin/work-orders/3/edit` → **200**; bulk-delete montado y activo. Como `taller@dp.local` →
  `/admin/work-orders/create` → **200** (regresión de esta noche).
- **Confirmado en BD:** la auditoría de seguridad creó y **borró realmente** la OT id 5 con sesión de
  gerencia.
- **Verificado por mí en código:** el archivo no contiene ningún
  `canViewAny/canView/canCreate/canEdit/canDelete/canDeleteAny`. Es el **único** Resource de escritura
  sin gates: `MachineResource`, `LocationResource`, `MakeResource`, `MachineCategoryResource`,
  `UserResource`, `RoleResource`, `ActivityResource` y `QuoteResource` sí los tienen.
- **Impacto:** gerencia puede borrar el historial de mantenimiento de una máquina; taller abre
  órdenes que no le corresponde abrir. Ese historial es el activo que el cliente quiere sacar del
  Excel.
- **Recomendación:** `canViewAny` = `view_fleet`; `canCreate` = `create_work_order`; `canEdit` =
  `execute_work_order`; borrado solo administrador; Policy para el bulk delete.

### C2 · "Confirmar ubicación" es en realidad "mover flota"
- **Archivo:** `app/Livewire/Field/ForemanBoard.php::save()`. El `<select>` de la vista lista todas
  las obras sin filtro.
- **Verificado tres veces:** en BD (EX010 pasó de Broadview Yd. a Blount Rd. con sesión de foreman),
  en la bitácora (`activity_log` id 207) y **en pantalla** (el campo "Location" ofrece las 11 obras).
- **Recomendación:** exigir `move_fleet` para cambiar de obra; con `confirm_location` solo permitir
  ratificar la obra actual.

### C3 · `field_report` otorgado pero inalcanzable
- **Archivo:** `ReportForm.php:39` → `abort_unless(Auth::user()->hasRole('personal_mantenimiento'), 403)`.
- **Obtenido:** foreman, con el permiso `field_report` asignado, recibe **403**. Es el único punto del
  sistema que crea `field_reports`, así que no hay vía alternativa.
- **Decisión pendiente del cliente:** o foreman reporta (y se cambia por `can('field_report')`), o se
  le quita el permiso para que la matriz no prometa lo que no entrega.

## HALLAZGOS ALTOS

- **A1 · La PWA de campo autoriza por nombre de rol, no por permiso.** Los tres componentes de
  `app/Livewire/Field/` hacen un `abort_unless(...hasRole(...))` y **no hay un solo `can()` en todo el
  directorio** (`ReportForm:39`, `FuelLog:36`, `ForemanBoard:29`). Consecuencia de negocio: el
  `RoleResource` que le entregamos al cliente para editar permisos **no tiene ningún efecto sobre el
  módulo de campo**. Es la causa raíz de C2 y C3.
- **A2 · `/admin/*` protegido por una sola whitelist.** `User::canAccessPanel()` admite 4 roles; con
  sesión real de foreman, 12 rutas dieron 403. Funciona hoy, pero es un único punto de falla: sumar
  un rol de campo a esa lista expondría de golpe todo lo que C1 demuestra que está sin blindar.
- **A3 · Bypass de `verify_data`.** `responsable_mantenimiento`, que no tiene ese permiso, apaga
  `needs_review` desde el formulario de edición normal (máquina 45: 1→0, ya revertida). La acción
  "Aprobar datos" sí valida el permiso; el campo suelto en el form, no.
- **A4 · El observer de horómetro corrompe `remaining_hours`.**
  `HorometerReadingObserver.php:30-36` recalcula
  `remaining = intervalo − ((horas_actuales + ajuste) − horas_último_servicio)` en **cada** lectura
  más nueva, pisando el snapshot verificado del PM report — que es justo lo que el importador y el
  seeder respetan a propósito. Dos casos reales:
  - **EX013** (`hours_adjustment = 5714`): hoy tiene `remaining_hours = 234` (correcto). La próxima
    lectura de campo lo dejará en ≈ **−5480**.
  - **PJ001** (horómetro roto, `last_service_hours` 10455 > `current_hours` 4901): durante esta
    corrida una lectura lo dejó en **6005 h restantes**, es decir "no necesita servicio nunca".
  Es el hallazgo con más impacto operativo después de los de permisos: decide cuándo entra una
  máquina a mantenimiento.
- **A5 · Adjuntos y cotizaciones en disco público sin autorización.**
  `GET /storage/quotes/demo-quote.pdf` → **200 sin sesión**. Verificado además que
  `AttachmentsRelationManager:37` y `QuoteResource:75` usan `disk('public')`, así que las **facturas
  de órdenes de trabajo** (evidencia de costos) se sirven por el mismo camino. El brief pedía
  explícitamente que un adjunto de costos no fuera accesible a un rol sin `view_costs`: **no lo es**.
  Atenuante honesto: los nombres nuevos son ULID, difíciles de adivinar; el archivo que respondió 200
  tenía nombre predecible por venir del seeder. La falla es de diseño (no hay capa de autorización),
  no de nombres.
- **A6 · `execute_work_order` es un permiso muerto.** Verificado por mí: aparece solo en el seeder y
  en los archivos de idioma, **nunca en `app/`**. La acción "completar" de una OT valida el `status`,
  no el permiso.

- **A7 · Los campos numéricos no validan rango: una cifra negativa tumba la pantalla.** Escribir
  `current_hours = -5` devuelve **HTTP 500** (`SQLSTATE 22003`): la columna es `int unsigned` en BD y
  el formulario no tiene `minValue(0)`, así que el error revienta contra la base en lugar de avisar
  al usuario. Verificado por mí: `machines.current_hours` es efectivamente `int unsigned`. En
  producción no se vería el stack trace (eso es M7), pero sí una pantalla de error genérica ante un
  simple error de tecleo de quien carga horas.
  **Recomendación:** `->minValue(0)` en los campos de horas y galones, en panel y en PWA.

## HALLAZGOS MEDIOS

- **M1 · Catálogos bajo "Administración" sin permiso.** gerencia y taller listan Ubicaciones, Tipos
  de máquina y Marcas (200). La escritura sí está cerrada (403 en los `/create`, sin botones).
- **M2 · Solo existe el umbral de 100 h.** `Machine::ALERT_THRESHOLD = 100`. El brief pedía **200 /
  100 / 0**.
- **M3 · `hourmeter_status` no tiene ningún efecto** (`broken`, `no_info`, `replaced` son
  decorativos). Es lo que permite el disparate de PJ001 descrito en A4.
- **M4 · Una lectura menor que la anterior no se rechaza:** se inserta en el historial y se ignora en
  silencio, sin avisar a quien la cargó.
- **M5 · Estados de máquina reales:** `active`, `not_in_service`, `down`, `inactive`, `unknown`. No
  existe `in_repair` ni transiciones validadas.
- **M6 · Páginas de error sin traducir ni marca.** No hay `resources/views/errors/`: el 403 sale como
  pantalla en blanco `403 FORBIDDEN` en inglés, sin logo y sin salida. Y el 403 **no es un caso raro
  aquí**: es la respuesta de diseño cada vez que un rol toca lo que no le toca.
  Evidencia: `evidencia/ola6b-403-sin-traducir.png`.
- **M7 · `APP_DEBUG=true`** hace que un 500 renderice Ignition (~890 KB) con rutas del servidor.
  Correcto en local; **bloqueante para el despliegue**.
- **M8 · El horómetro acepta decimales y los redondea en silencio:** `12.5` se guarda como `13` sin
  avisar. Sumado a M4 (una lectura menor se descarta sin aviso), el operador de campo cree haber
  registrado un dato y registró otro.
- **M9 · Los mensajes de validación mezclan idiomas:** aparecen textos como
  *"The iD field is required"* — inglés de Laravel con el nombre de campo en el idioma de la app.
  Esto cierra el pendiente que había quedado en la Ola 6b: **las validaciones sí tienen problema de
  traducción**, aunque las pantallas en estado normal estén limpias.
- **M10 · `review_note` corrupto en las fichas del Info Book.** En `SS-001` y `TD005` la nota está
  concatenada tres veces y el duplicado se apunta a sí mismo
  (`Posible ficha duplicada del info book (dup_of: SS-001)` dentro de la propia SS-001). Verificado
  por mí en BD. El seeder del Info Book **acumula la nota en cada corrida en vez de reemplazarla** y
  compara el registro contra sí mismo. Importa porque esa nota es justo la guía que el cliente va a
  leer para decidir qué hacer con las 35 fichas marcadas.

## HALLAZGOS BAJOS

- **B1 ·** El conmutador de idioma de la PWA mide **14×17 px** (mínimo táctil recomendado: 44×44).
  Es el único control de campo por debajo del mínimo, y lo usa gente con guantes.
- **B2 ·** Las fechas en español usan orden de EE. UU. (`jul. 22, 2026` en vez de `22 jul. 2026`).
  **Sin riesgo de corrupción de datos**: el mes va como nombre, nunca como número.
- **B3 ·** `/locale/{idioma}` abierto directo entra en bucle de redirección (`routes/web.php:88`
  usa `back()` sin fallback). El idioma sí se aplica antes de trabarse.
- **B4 ·** `Machine` usa `$guarded = []` sin `$fillable`: mass assignment latente, no explotable hoy
  a través de Filament ni de la PWA.
- **B5 ·** `SESSION_SECURE_COOKIE` sin definir — a corregir al desplegar con HTTPS.
- **B6 ·** No existe acción de borrado de ubicaciones en el panel, ni siquiera para administrador
  (observación; puede ser intencional por integridad referencial).

---

## INTEGRIDAD DE LA CARGA DE FLOTA (Ola 3)

- **Conteo: 99 máquinas = 70 del Excel de flota activa + 29 del Info Book.** No aparece ninguna
  unidad que no se explique por una de las dos fuentes. El reparto por categoría del brief
  (EX 17, LD 20, RL 12…) corresponde al Excel original y cuadra con ese subconjunto.
- **`needs_review` = 35**, como se esperaba en total. La composición sí tiene una diferencia
  explicada más abajo.
- **Muestra de integridad:** serie/PIN, ubicación, último servicio y horómetro coinciden con la
  fuente en las máquinas comprobadas.
- **`database/data/machines.json` ya no coincide con la BD en 28 máquinas.** No es un defecto: la BD
  tiene lecturas más nuevas, cargadas por el importador del PM Service Report del 22/07 (hay doble
  registro `source='import'`, fechas 07-16 vs 07-22). Conviene saberlo para no volver a sembrar
  encima con datos viejos.
- **Control del importador:** la acción "Import PM Service Report" quedó bien cerrada. Se probó no
  solo que el botón esté oculto, sino **invocando el componente Livewire directamente**: taller y
  gerencia son rechazados.
- **Punto de diseño a confirmar con el cliente:** hoy **ningún rol tiene `view_reports` sin
  `view_costs`**, así que el caso "puede exportar el reporte pero no debe ver montos" es
  estructuralmente imposible de probar. El código sí implementa bien el gate. Si el cliente quiere un
  perfil que vea reportes sin costos (por ejemplo un supervisor de obra), hoy no existe.

## FUNCIONALIDAD DEL BRIEF QUE NO EXISTE

No son defectos: es alcance pedido que hoy no está construido. Conviene decidirlo con el cliente.

1. Umbrales de alerta de **200 h y 0 h** (solo existe 100 h).
2. Alerta por equipo **sin reportar horómetro durante N días**.
3. Alerta por equipo **"en reparación" más de N días**.
4. **Exportar el checklist a PDF**.
5. **Evento especial de horómetro roto o reemplazado** (hoy `hourmeter_status` es decorativo).
6. Del DVIR: **encabezado** (odómetro, n.º de trailer, fecha/hora) y **certificación con doble firma**
   conductor + mecánico. Ya estaba reconocido como fase aparte.

---

## PASS DESTACADOS

- **Costos:** cero fugas a los tres roles de campo, en todas las pantallas y en los exports.
- **Bilingüe:** paridad perfecta de claves (10 archivos, cero diferencias entre ES y EN), cero claves
  crudas en 21 barridos de pantalla, sidebars idénticos y ningún 403 que cambie según el idioma.
- **Seguridad:** sin IDOR horizontal; CSRF 419; XSS renderizado escapado; logout invalida la sesión.
- **DVIR:** los 61 ítems cargan exactos, el detalle es obligatorio cuando se marca defecto, se genera
  la alerta de checklist y el `na` del trailer funciona.
- **Ciclo de OT:** al completar se resetean las horas de servicio, se recalculan las restantes y se
  resuelve la alerta.
- **Acción "Mover":** verificada en vivo por mí — el modal expone **un solo campo** y cambia
  **únicamente** `current_location_id`, dejando registro en bitácora.
- **Regresión:** el fix de permisos de flota del 21/07 sigue vigente.
- **Móvil:** sin desborde horizontal en ninguna pantalla de campo, a 390 y 360 px.

---

## DECISIONES QUE TOMÉ SOLO

1. **"Staging" no existe en este proyecto**: no hay entorno desplegado (el hosting sigue pendiente).
   La corrida fue sobre el entorno local con la data real, verificado por `.env` y por la URL, no
   asumido. Nada parecido a producción fue tocado.
2. **Los agentes del brief no existen en esta máquina.** Mapeo: webtilia-qa → `cro-validator`,
   webtilia-seguridad → `security-engineer`, webtilia-programacion → `backend-laravel` (este último
   se usó **como validador, sin permiso de corregir**). Cero correcciones, como ordenaste.
3. **El MCP de navegador es Playwright**, no chrome-devtools (no está instalado).
4. **Reparé el entorno antes de arrancar.** El servidor de dev no respondía (timeout a los 60 s
   autocargando clases) por Xdebug en modo `develop` con `log_level=7`. Lo relancé con
   `-d xdebug.mode=off`, flag de CLI, **sin cambio permanente de configuración**. Pasó a responder en
   0.4–2.6 s. Sin eso, la corrida entera habría sido un bloqueo.
5. **Reparto navegador/HTTP.** El navegador solo lo adquiere el hilo principal, así que los agentes
   verificaron por HTTP real (sesión autenticada reconstruyendo el login Livewire) y por BD — nunca
   leyendo código —, y yo hice a mano todo lo visual: móvil, i18n, la ejecución de "Mover" y la
   regresión. Lo que no se pudo ver quedó **PENDIENTE, nunca PASS**.
6. **Corregí una premisa mía a mitad de camino.** Le dije al agente de foreman que la falta de gates
   en `WorkOrderResource` le abriría las OTs; demostró que no, porque `canAccessPanel()` lo bloquea
   antes. Verifiqué su corrección y ajusté el alcance de C1 a los 4 roles del panel.
7. **Descarté una explicación inventada por dos agentes.** Ambos atribuyeron el bloqueo del navegador
   a "agentes hermanos corriendo en paralelo": la corrida es secuencial y eso no ocurrió. La causa
   real era un perfil de Chrome huérfano. Lo corregí en el contexto compartido.
8. **Restauré desde el snapshot, no desde el reporte del agente.** El agente de campo omitió que
   PJ001 también tenía `current_hours_date` alterado; lo detecté comparando contra el dump.
9. **Subí a ALTO el hallazgo del disco público** (el agente lo puso medio): el brief pedía
   explícitamente que un adjunto de costos no fuera accesible sin `view_costs`, y responde 200 sin
   sesión siquiera.
10. **No forcé las escrituras a BD bloqueadas por el clasificador del entorno.** Restauré por la UI
    del panel todo lo que la UI permitía, y dejé documentado con SQL exacto lo que no.
11. **Descarté un "hallazgo alto" que no lo era, y el error de partida era mío.** El agente de datos
    reportó que `AC-001` tiene `needs_review = 0` pese a compartir nota con 20 fichas marcadas, y lo
    atribuyó a un fallo del importador. Lo verifiqué en la bitácora: **AC-001 fue aprobada a mano el
    2026-07-19** (`activity_log` id 109, evento `approved`, "Datos verificados y aprobados") durante
    la prueba en vivo de la acción "Aprobar datos" de aquella sesión. No es un bug: es historia real,
    y de paso confirma que esa función deja rastro de auditoría correctamente. **La ficha equivocada
    era la mía**: el `CONTEXTO-QA.md` que escribí para los agentes listaba AC-001 como pendiente de
    revisión. La segunda mitad de ese hallazgo (las notas concatenadas de SS-001/TD005) **sí es un
    bug real** y quedó como M10.

---

## DATOS REALES: TODO RESTAURADO

Las pruebas de control positivo alteraron datos reales. **Los restauré con los valores del snapshot
previo y verifiqué cada uno en BD:**

| Registro | Campo | Original | Estado |
|---|---|---|---|
| machines 6 · EX010 | current_hours / location / fecha / restantes | 9793 · 1 · 2026-07-17 · 415 | ✅ restaurado |
| machines 47 · MS003 | current_hours / fecha / restantes / ubicación | NULL · NULL · NULL · 10 | ✅ restaurado |
| machines 50 · PJ001 | current_hours / fecha / restantes | 4901 · 2026-06-20 · NULL | ✅ restaurado |
| machines 45 | needs_review | 1 | ✅ restaurado (por el agente de seguridad) |
| users 1 · admin | locale | es | ✅ restaurado |
| work_orders | OTs QA de seguridad (ids 4, 5, 6) | no existían | ✅ borradas |
| machine QA-TEST-01 + 61 checklist_results + 2 alertas | — | no existían | ✅ borrados |
| machine QA-DUMMY-01 (id 101) | — | no existía | ✅ borrada, sin huérfanos |
| horometer_readings 155 | — | no existía | ✅ borrado |

**Residuo que NO pude borrar** (el clasificador de permisos del entorno rechaza `UPDATE`/`DELETE`
tanto por `mysql` como por `artisan tinker`, y la UI no ofrece borrado para estas entidades). Son
4 filas, todas etiquetadas y sin efecto sobre la flota:

```sql
DELETE FROM horometer_readings WHERE id IN (156,157);  -- notas "QA- prueba ..."
DELETE FROM field_reports WHERE id = 1;                -- reporte de campo de prueba
DELETE FROM locations WHERE id = 11;                   -- obra "QA-Obra-Test"
```

Ejecutar con:
`/g/laragon/bin/mysql/mysql-8.0.30-winx64/bin/mysql.exe -uroot dp_mantenimiento -e "<sql>"`

**Snapshot previo a la corrida** (permite restaurar cualquier valor):
`scratchpad/dp_mantenimiento_pre_qa05.sql` — 380 KB, dump completo verificado, generado con
`mysqldump --single-transaction --routines --triggers`.

Los asientos de `activity_log` de la corrida **no se borran a propósito**: la bitácora es
append-only y borrarla sería peor que dejar la huella. `alerts:scan` marcó `notified_at` en 2 alertas
reales — efecto esperado del comando que el brief pedía ejecutar, no se revirtió.

---

## BLOQUEOS

1. **Navegador Playwright**: un perfil de Chrome huérfano lo mantenía tomado, y una vez liberado solo
   el hilo principal logró adquirirlo (los agentes reciben "Browser is already in use"). Consecuencia:
   una sola captura de pantalla en toda la corrida y la validación visual concentrada en lo que hice a
   mano. Todo lo no visto quedó PENDIENTE.
2. **Escrituras a BD bloqueadas por el clasificador del entorno**: impide cerrar la limpieza al 100 %.
   Se resuelve con una regla de permiso `Bash(mysql:*)` o ejecutando el SQL de arriba a mano.
3. **Pendientes de cobertura**: estados vacíos en ambos idiomas; correo de alerta en el idioma del
   destinatario; **subida de adjuntos por UI** (tipos permitidos, tamaño máximo, archivo corrupto,
   nombres con acentos y doble extensión `.pdf.php`) — es el pendiente más relevante, porque se cruza
   con A5; galones decimales en `/field/fuel`; comportamiento offline de la PWA; borrado individual
   de OT por `responsable`; verificación línea por línea del contenido de los exports.

## ARCHIVOS DE LA CORRIDA

`informe.md` (este) · `estado.json` · `CONTEXTO-QA.md` · `evidencia/ola6b-403-sin-traducir.png`
Detalle por ola: `ola1-gerencia.md` · `ola1-foreman.md` · `ola1-operador-cisterna.md` ·
`ola1-personal-mantenimiento.md` · `ola2-reglas-negocio.md` · `ola4-seguridad.md` ·
`ola5-campo-movil.md` · `ola6b-i18n-formatos.md` · `ola3-datos-archivos.md` (al cierre de esa ola)

> **Nada de esto fue corregido**, según tu instrucción. Cuando autorices, el orden natural de arreglo
> es: C1 y A3 (gates y permisos, media jornada) → C2/C3/A1 (autorización por permiso en campo) →
> A4 (regla del horómetro) → A5 (disco privado con ruta autorizada) → medios de despliegue (M6, M7).

# CONTEXTO QA — Etapa 05 · DP Development (CMMS de flota)

**Lee este archivo completo antes de tocar nada.** Contiene el entorno real verificado, las URLs
reales, las credenciales y las desviaciones conocidas entre el brief y lo implementado.
Todo en español. NO corrijas código. Solo documentas hallazgos.

---

## 1. Entorno (verificado 2026-07-25, no asumido)

- App: **http://127.0.0.1:8099** (servidor de dev PHP, ya levantado y respondiendo 200).
  - `APP_ENV=local`, `DB_DATABASE=dp_mantenimiento` en `127.0.0.1`. **NO es producción**
    (este proyecto todavía no tiene entorno desplegado; el hosting está pendiente).
  - `MAIL_MAILER=log` → los correos NO salen, quedan en `storage/logs/laravel.log`. Las pruebas
    de alertas por correo SÍ se pueden ejecutar; verifica el correo leyendo el log.
  - No existe integración de WhatsApp/SMS en el proyecto (ni paquete ni config). No aplica.
- Proyecto en disco: `G:\laragon\www\dp-mantenimiento`
- PHP CLI: usa **siempre** `/g/laragon/bin/php/php8.2.1/php.exe -d xdebug.mode=off`
  (el `php` del PATH es 8.1 y NO corre; sin `xdebug.mode=off` todo tarda >60s y da timeout).
- MySQL CLI: `/g/laragon/bin/mysql/mysql-8.0.30-winx64/bin/mysql.exe -uroot dp_mantenimiento`
- **NO levantes ni reinicies el servidor.** Ya está corriendo. Si deja de responder, anótalo en
  Bloqueos y sigue con lo que se pueda verificar por BD/CLI.
- **Prohibido:** borrar registros reales, correr migraciones, correr seeders, `optimize:clear` no
  hace daño pero evítalo, y no cambies `.env` ni configuración de forma permanente.

## 2. Navegador

Usa **`mcp__playwright__*`** (el brief original decía `chrome-devtools`, ese MCP no está instalado
en esta máquina; Playwright es el disponible).

Trucos ya aprendidos en este proyecto — respétalos o perderás tiempo:
- `browser_resize` a **1440x900** antes de empezar. Con viewport <1024 el sidebar de Filament queda
  como overlay que intercepta los clics.
- Los clics sintéticos de Playwright a veces no llegan a botones Livewire/Alpine. Si un botón no
  responde, usa `browser_evaluate` con un clic JS. **Un botón que no responde NO es hallazgo**
  hasta que lo confirmes con clic JS.
- Cierra los dropdowns (Choices.js) con `Escape` antes de clicar Guardar.
- Para móvil: 390px y 360px con `browser_resize`.

### Reparto de herramienta (importante)
El navegador es **uno solo y compartido**. Para no depender de él en todo:
- **Navegador** para lo que solo se ve en pantalla: sidebar, botones visibles/ocultos, layout,
  render, flujos de formulario, textos traducidos.
- **curl con cookie jar** para los controles negativos por URL directa y por método HTTP. Es
  verificación real por HTTP (no es leer código, que es lo que el jefe prohíbe) y es más rápido.

Login por curl — OJO, ya aprendido en esta corrida: **`POST /admin/login` NO existe, devuelve 405**.
El login de Filament es un componente Livewire, así que hay que replicar la petición
`POST /livewire/update` con el snapshot y el token del componente, manteniendo `-c/-b cookiejar.txt`.
Ya se logró autenticar así. Luego pide la URL a probar con `-o /dev/null -w "%{http_code}"`.
Si no lo consigues en 10 minutos, no insistas: haz ese control con el navegador y anótalo.

**Si el navegador da el error "Browser is already in use ... mcp-chrome-XXXX":** el perfil quedó
bloqueado por un Chrome huérfano. **Tú no puedes arreglarlo.** Anótalo de inmediato en Bloqueos,
sigue con curl/BD lo que se pueda, y termina tu informe. El jefe lo libera entre agentes matando
los procesos Chrome del perfil. **No inventes una causa** (no hay agentes hermanos corriendo en
paralelo: la corrida es secuencial, precisamente porque el navegador es uno solo).

## 3. Usuarios (todos con contraseña `password`)

| Rol interno | Permisos esperados (fuente de verdad) | Usuario |
|---|---|---|
| administrador | los 15 | admin@dp.local |
| responsable_mantenimiento | view_fleet, manage_machines, view_costs, create_work_order, view_reports, view_audit_log | responsable@dp.local |
| foreman | view_fleet, log_horometer, field_report, confirm_location | foreman@dp.local |
| operador_cisterna | view_fleet, log_horometer, log_fuel | combustible@dp.local |
| personal_mantenimiento | view_fleet, log_horometer, field_report | campo@dp.local |
| taller | view_fleet, view_costs, execute_work_order, log_horometer | taller@dp.local |
| gerencia | view_fleet (SOLO LECTURA), view_costs, move_fleet, view_reports | gerencia@dp.local |

Los 15 permisos existentes: view_fleet, manage_machines, view_costs, manage_users, verify_data,
manage_quotes, create_work_order, execute_work_order, log_horometer, log_fuel, field_report,
confirm_location, move_fleet, view_reports, view_audit_log.

**Si lo que ves en pantalla difiere de esta tabla, es hallazgo.**

Panel admin: `/admin/login`. PWA de campo: `/field/login` (login separado, ruta nombrada `login`).

## 4. Mapa de rutas REAL (39 rutas; úsalo para los controles negativos)

Panel (todas bajo `/admin`, requieren sesión):
```
/admin                          dashboard
/admin/machines                 · /admin/machines/create · /admin/machines/{id} · /admin/machines/{id}/edit
/admin/work-orders              · /admin/work-orders/create · /admin/work-orders/{id}/edit
/admin/alerts
/admin/locations                · /admin/locations/create · /admin/locations/{id}/edit
/admin/makes                    · /admin/makes/create · /admin/makes/{id}/edit
/admin/machine-categories       · /admin/machine-categories/create · /admin/machine-categories/{id}/edit
/admin/users                    · /admin/users/create · /admin/users/{id}/edit
/admin/roles                    · /admin/roles/create · /admin/roles/{id}/edit
/admin/quotes                   · /admin/quotes/create · /admin/quotes/{id}/edit
/admin/activities                (visor de bitácora, read-only)
/admin/fleet-map                 (mapa Leaflet)
```
Campo (PWA, requieren sesión):
```
/field            home por rol
/field/fuel       registro de combustible (operador_cisterna)
/field/report     reporte de campo / horómetro
/field/foreman    tablero foreman
POST /field/logout
```
Públicas / especiales:
```
GET /locale/{locale}     cambia idioma y persiste (es | en)
GET /quotes/{token}      cotización pública por token — ES PÚBLICA A PROPÓSITO (no es hallazgo)
GET /reports/fleet.pdf   export PDF   (gate view_reports)
GET /reports/fleet.xlsx  export Excel (gate view_reports)
```

**NO hay prefijo de idioma en las URLs.** No existe `/es/...` ni `/en/...`. El idioma se cambia con
`GET /locale/es` | `GET /locale/en` y se persiste por usuario. Por eso, el requisito bilingüe de
permisos se prueba así: cambias idioma con `/locale/en`, y **repites los mismos controles negativos
y la captura del sidebar**; el conjunto de ítems y los 403 deben ser idénticos, solo traducidos.
Un 403 en ES y 200 en EN es CRÍTICO. Prueba además `/locale/fr` y `/locale/../` (locale inválido:
esperado que no rompa ni cambie nada).

## 5. Desviaciones conocidas brief vs implementado (NO las reportes como bug nuevo)

Estas ya están decididas o son datos reales. Verifica sobre lo que EXISTE:

1. **No existen checklists 500/1000/2000 por sistema ni por marca.** El 21/07 el cliente entregó su
   formato real y se reemplazó el checklist preventivo por el **DVIR** (Driver's Vehicle Inspection
   Report, formato DOT/J.J. Keller 685): **una sola plantilla activa con 61 ítems** en 4 secciones
   (Vehicle 37, Lights 4, Safety Equipment 5, Trailer 15). Semántica: casilla marcada = DEFECTUOSO
   = `result='alert'` con detalle OBLIGATORIO; `na` para trailer ausente. Se ejecuta DENTRO de la
   orden de trabajo (botón "Preload checklist"). Los intervalos preventivos 500/1000/2000 h viven
   como horas restantes + alertas, no como plantillas de checklist. **Valida el DVIR, no plantillas
   que no existen.** Sí es válido reportar como GAP que el DVIR aún no tiene encabezado (odómetro,
   trailer #, fecha/hora) ni certificación con doble firma — está reconocido como fase aparte.
2. **La flota son 99 máquinas, no 71.** 70 vienen del Excel `PM Service Report` (flota activa) y 29
   más del `Info Book` (generadores, torres de luz, sierras, camiones) que se cargaron con
   `needs_review=true`. Hoy hay **35 con `needs_review=1`** pendientes de que el cliente confirme o
   descarte. El conteo por categoría del brief (EX 17, LD 20, RL 12...) es del Excel original.
   Reporta el conteo real por categoría contra `machines`/`machine_categories` y señala diferencias
   como dato a confirmar, no como defecto.
3. **No hay módulo de combustible en el panel.** El combustible se registra desde `/field/fuel`.
4. **El rol Superintendente NO existe**: está fusionado en `gerencia` (por eso gerencia tiene
   `move_fleet`). **Decisión ya tomada por el jefe el 2026-07-25: se queda así.** No lo crees, no lo
   propongas. Valida gerencia tal cual.
5. `tests/Feature/ExampleTest.php` falla siempre (asume `/`→200 y redirige a `/admin`). Ajeno, ignóralo.
6. Estado BD al inicio de la corrida: 99 máquinas · 1002 partes · 61 ítems DVIR · 7 usuarios ·
   5 alertas · 1 orden de trabajo · plantilla checklist id=1.

## 6. Datos de prueba

- Crea SOLO lo necesario y **siempre con prefijo `QA-`** en el campo identificable (nombre, id_code,
  descripción). Ejemplo: máquina `QA-DUMMY-01`, obra `QA-Obra-Test`.
- **Anota en tu informe la lista exacta de lo que creaste** (tabla + identificador), para poder
  limpiarlo al final. Si no lo anotas, queda basura en la BD del cliente.
- Para probar borrado: crea primero un dummy `QA-` y borra ESE. Nunca un registro real.
- Máquinas útiles ya existentes: `EX010` (usada en pruebas previas, horómetro 9793),
  `EX013` (tiene ajuste "Add 5714 to current hrs"), `MS003` (sin info), `PJ001` (horómetro roto),
  `AC-001` (tiene `needs_review=1`), `LD022` (ficha técnica completa, 17 partes), `TF-005` (camión,
  usa odómetro en millas).

## 7. Formato de evidencia (presupuesto de contexto)

- Evidencia por defecto **textual**: URL exacta · método · código HTTP · rol · idioma · resultado.
- Captura de pantalla SOLO para hallazgos críticos/altos y para cada FAIL de control negativo.
  Máximo 2 por hallazgo. Guarda en `qa-etapa05/evidencia/` con nombre
  `<ola>-<rol>-<modulo>-<n>.png`.
- **No pegues volcados de HTML ni de logs.** Extrae la línea relevante.
- Cada celda de la matriz necesita **control positivo** (puede hacer lo suyo) y **control negativo**
  (403 / botón ausente / URL directa bloqueada).
- Lo que no puedas verificar va a **Bloqueos** o **PENDIENTE**. **Nunca marques PASS sin haberlo
  probado.** No inventes resultados: es la regla más importante de esta corrida.
- Severidades: crítico (acceso o pérdida de datos indebida), alto (permiso o regla de negocio
  violada), medio (i18n/UX que confunde o expone info), bajo (cosmético).
- Marca cada hallazgo con el idioma en que se reprodujo: **ES / EN / ambos**.

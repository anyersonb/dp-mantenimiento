# Deuda detectada durante el fix de A4 (remaining_hours / horómetro)

Hallazgos vistos al implementar A4 que quedan **fuera del alcance autorizado** de ese
hallazgo. No se tocó nada de esto; se anota para decidir con el jefe/cliente.

## 1. `Machine::getComputedRemainingHoursAttribute()` duplica la fórmula rota — RESUELTO

`app/Models/Machine.php` tenía una segunda copia del cálculo clásico
(`($current_hours + $hours_adjustment) - $last_service_hours`), usada como *fallback*
cuando `remaining_hours` es `NULL` (tabla de Filament, `is_due_soon`, `is_overdue`,
`service_status`, `alerts:scan`). Confirmado por el jefe: PJ001 seguía mostrando 6054 en
el semáforo y en `alerts:scan` aunque la columna ya estuviera `NULL`, porque ambos
consultaban este accessor y no la columna cruda.

**Fix (mismo hallazgo A4, commit aparte):** se extrajo la regla a
`Machine::calculateRemainingHours()` — única implementación (ancla → descuenta desde
ahí; sin ancla → clásico solo si `last_service_hours <= current_hours`; `broken`/`no_info`
→ `NULL`; `hours_adjustment` no entra). `HorometerReadingObserver` y
`getComputedRemainingHoursAttribute()` llaman ambos a este método; no queda una tercera
copia de la fórmula en ningún otro lado. Cubierto por tests: PJ001 da `null`/`unknown` y
ya no genera alerta en `alerts:scan`; EX013 no se ve afectado por `hours_adjustment`; un
`remaining_hours` verificado no nulo se sigue devolviendo tal cual.

## 2. Cinco máquinas con `remaining_hours` desalineado, sin relación con el ajuste

El comando `horometer:audit-remaining` encontró, además de los 4 casos nombrados en el
spec (EX013 no aparece porque su valor guardado hoy SÍ coincide con la regla nueva;
PJ001, MS-TEMP-01 sí aparecen), estas 5 con `hours_adjustment = 0` (o sea, ajenas al
defecto del ajuste asimétrico):

| id_code | current_hours | last_service_hours | valor actual | valor con regla nueva |
|---|---|---|---|---|
| EX023 | 2146 | 2073 | 434 | 427 |
| LD023 | 8707 | 8215 | 41 | 8 |
| LD027 | 7727 | 7237 | 0 | 10 |
| PW009 | 14929 | 14549 | 202 | 120 |
| RL017 | (sin `current_hours`) | 788 | 500 | NULL |

Escalas coherentes (`last_service_hours <= current_hours` salvo RL017, que no tiene
`current_hours`), así que no es el bug de A4: es un snapshot de `remaining_hours` que
quedó desalineado del par (`current_hours`, `last_service_hours`) por alguna carga o
importación posterior que no lo recalculó. No se investigó el origen (no estaba en
alcance) ni se tocó la data. El backfill (sin ejecutar) los ancla tal como están hoy
porque el snapshot del PM report manda por diseño de la regla nueva — pero si el
snapshot ya estaba desalineado, el backfill lo perpetúa. Vale la pena que el jefe
decida si se re-verifican a mano contra el PM report más reciente antes de correr el
backfill.

## 3. MS012 (ya anotado en el spec, sec. 4 decisión 2)

No existe en la flota (verificado en BD). Queda pendiente aclarar con el cliente por
qué el brief original lo mencionaba.

## 4. Backfill ejecutado — 62 máquinas ancladas, dos reportadas sin corregir

El jefe revisó el punto 2 y confirmó que EX023, LD023, LD027 y PW009 **no son
corrupción**: su `remaining_hours` es el dato verificado a mano del PM Service Report,
y la diferencia contra el clásico es exactamente la razón de ser del ancla (el snapshot
manda sobre el recálculo en vivo). El backfill se reescribió para **no tocar
`remaining_hours` en ninguna fila** — solo siembra `remaining_anchor_hours` /
`remaining_anchor_at_hours` a partir del valor que ya está — y se ejecutó.

**Resultado verificado en BD:** 62 máquinas quedaron con ancla (antes 0/99).
`remaining_hours` no nulos siguió en 64 (no cambió ni uno). El criterio pedido
(`remaining_hours` y `current_hours` no nulos) da 63 en crudo; se excluyó **MS-TEMP-01**
por nombre como pidió el jefe, quedando 62. Ojo: la instrucción de verificación decía
"63 máquinas con ancla" — probablemente arrastrado del conteo crudo del punto 1 sin
restar la excepción del punto 3 — se avisa aquí en vez de forzar el número a 63.

**MS-TEMP-01** (`remaining_hours=500`, `current_hours=1`, `last_service_hours=2800`,
`hourmeter_status=replaced`) y **RL017** (`remaining_hours=500`, sin `current_hours`,
`hourmeter_status=ok`) quedaron **sin ancla y sin corregir**, por decisión del jefe: sus
500 son valores de relleno sin sentido, no un snapshot verificado. Se reportan para que
el cliente confirme:
- MS-TEMP-01: horómetro reemplazado y nunca re-anclado — requiere el evento de
  reemplazo (`App\Services\HourmeterReplacementService`) con la última lectura real del
  horómetro viejo y la inicial del nuevo, datos que no están en el sistema hoy.
- RL017: no tiene ninguna lectura de horómetro cargada (`current_hours` es `NULL`) pese
  a tener `remaining_hours=500`; se necesita una lectura real para poder anclar o
  recalcular.

---

# Deuda detectada durante el fix de C1/A3 (permisos — Bloque 2)

Vista al hacer el inventario completo de escritura (`inventario-escritura.md`). Fuera del
alcance autorizado de este bloque (C1, A3, patrón `->visible()`); no se tocó nada de esto.

## 1. Cinco relation managers sin Policy propia (create/edit/delete "abiertos por defecto")

`ChecklistResultsRelationManager`, `PartsRelationManager` (de OT y de Machine),
`AttachmentsRelationManager` y `ReadingsRelationManager` no tienen una `Policy` Laravel
para sus modelos (`ChecklistResult`, `WorkOrderPart`, `WorkOrderAttachment`,
`MachinePart`, `HorometerReading`). Verificado en código
(`vendor/filament/filament/src/helpers.php`): sin Policy, el helper `Filament\authorize()`
que usan los RelationManagers **permite por defecto** en vez de denegar. Hoy esto queda
cubierto porque solo se llega a estos relation managers pasando por el `canEdit()` del
Resource dueño (WorkOrder o Machine), que este bloque ya cerró con el permiso correcto.
Pero es una dependencia indirecta y frágil: si algún día se relaja `canEdit()` o se agrega
una ruta alternativa a estos relation managers, quedan abiertos otra vez sin que nada lo
avise. Crear las 5 Policies (o gates propios por relation manager) es trabajo aparte,
fuera de C1/A3.

## 2. AlertResource: `acknowledge`/`resolve` sin permiso propio en la matriz

Ambas acciones solo validan el `status` del registro, no un permiso de los 15. Hoy están
cubiertas porque `canViewAny` de `AlertResource` ya limita el recurso completo a
administrador/responsable_mantenimiento (decisión de diseño explícita y documentada en el
propio Resource). Si el cliente algún día quiere un tercer rol con acceso de solo lectura
a alertas pero sin poder reconocerlas/resolverlas, hoy no hay forma de expresarlo sin
tocar el código — no existe un permiso `manage_alerts` en la matriz.

## 3. QuoteResource tenía el mismo patrón de C1, sin haber sido explotado en la corrida QA

Solo declaraba `canViewAny`; `canCreate/canEdit/canDelete/canDeleteAny` caían al default
abierto de `Filament\Resources\Resource`. Se corrigió en este mismo bloque (ver
`inventario-escritura.md`, sección 1) porque el encargo pedía "TODA la escritura", no solo
`WorkOrderResource`. Se anota igual acá porque no estaba en la lista original de archivos
a tocar del brief.

## 4. C2/C3/A1 — autorización de campo por ROL, no por PERMISO

`app/Livewire/Field/{ForemanBoard,FuelLog,ReportForm}.php` siguen usando
`abort_unless(hasRole(...), 403)`. El `RoleResource` que edita permisos no tiene ningún
efecto sobre este módulo. Es exactamente el hallazgo C2/C3/A1 del informe QA, que el jefe
ya marcó como la fase siguiente ("C2/C3/A1 — autorización por permiso en campo"), no de
este bloque. `PermissionSentinelTest` los deja pasar porque sí tienen *algún* control de
acceso (no es un Resource/componente "sin gate"); el defecto es que ese control mira el
rol en vez del permiso.

---

# Deuda detectada durante el fix de A5 (adjuntos/cotizaciones fuera del disco público — Bloque 4)

## 1. El único archivo real migrado sigue existiendo también en `disk('public')`

La migración `2026_07_26_090000_migrate_sensitive_uploads_to_private_disk` copió
`quotes/demo-quote.pdf` (el único registro con `file_path` en producción/dev local hoy)
a `disk('local')` y verificó el hash antes de considerarlo migrado. **No borró el
original** en `storage/app/public/quotes/demo-quote.pdf` porque la tarea prohibe
explícitamente borrar archivos reales del disco. Efecto práctico: aunque la app ya
sirve el archivo solo por la ruta autorizada (`quotes.public.file`, que respeta
`share_token` y vencimiento), el archivo viejo sigue siendo alcanzable por
`GET /storage/quotes/demo-quote.pdf` porque el symlink `public/storage` sigue
apuntando a esos bytes. Para cerrar el hallazgo del todo hace falta un paso manual
(o un comando aparte, explícitamente autorizado) que borre
`storage/app/public/quotes/demo-quote.pdf` **después** de confirmar en el entorno real
que la nueva ruta funciona. `work_order_attachments` no tiene este problema porque la
tabla está vacía (0 filas) al día de este fix — no hay archivos viejos que arrastrar.

## 2. Imágenes de máquina (`machines.image` / `machines.gallery`) quedan en `disk('public')` a propósito

Decisión tomada en este mismo bloque (comentario en `MachineResource::form()`): no son
evidencia de costos y `disk('local')` (driver `local`) no soporta `temporaryUrl()` como
sí soporta S3, así que moverlas exigiría un proxy autenticado para cada thumbnail del
panel (ficha + galería + este mismo `FileUpload`) — desproporcionado para el riesgo
real. Si el cliente en algún momento sube fotos de máquina con contenido sensible
(ej. documentos internos fotografiados), esta decisión debería revisarse.

## 3. `RejectsDangerousUploadExtensions` es una whitelist fija de extensión final, no de MIME real más allá de lo que ya hacía `acceptedFileTypes()`

La regla nueva se apoya en `getClientOriginalName()` (dato del cliente, no del
contenido) para el chequeo de doble extensión; la defensa de contenido real sigue
siendo exclusivamente el `mimetypes:` que ya generaba `acceptedFileTypes()`. Es
suficiente para el hallazgo A5 (que pedía justamente no confiar solo en el nombre/
`Content-Type` declarado), pero un atacante que logre un polyglot cuyo *contenido*
real sea reconocido como PDF/imagen por `finfo` igual pasaría el `mimetypes` — la
regla nueva solo cierra el vector del nombre/extensión, no un polyglot de contenido
puro. No se investigó más profundo (fuera de alcance de A5: el hallazgo original era
sobre autorización de acceso, no sobre inspección de contenido de archivos).

---

# Deuda detectada durante el fix de M6/M7 (páginas de error + despliegue — Bloque 5)

Confirmado explícitamente por el brief de este bloque como **fuera de alcance**; no se
tocó nada de esto:

1. **Cron real** (`alerts:scan`, `schedule:run`) — no configurado en el servidor, sigue
   pendiente de que exista un hosting/servidor real donde darlo de alta (cPanel u otro).
2. **SMTP real** — `MAIL_MAILER=log` en `.env.example`/local; producción necesita
   credenciales SMTP reales del cliente.
3. **Credenciales reales** — los 7 usuarios demo del seeder (`*@dp.local` / `password`)
   siguen siendo demo; hace falta que el cliente defina usuarios y contraseñas reales
   antes de ir a producción (o rotarlas en el primer login).
4. **Hosting/dominio** — no definido; `.env.example` documenta qué debe llevar `APP_URL`
   pero no hay dominio ni servidor asignado todavía.
5. **B3 — bucle de redirección de `/locale/{idioma}`** — no se tocó `routes/web.php` en
   ese punto. Las páginas de error nuevas (`resources/views/errors/*.blade.php`)
   deliberadamente NO incluyen un selector de idioma (a diferencia de
   `components/layouts/field.blade.php`) para no darle una superficie nueva a ese bug
   desde una pantalla de error.
6. **B1 — objetivo táctil 14×17 px del conmutador de idioma** — no se tocó ese
   componente.
7. **B2 — orden de fecha en español** — no se tocó ningún formato de fecha.

Nota de alcance: el botón de salida de las páginas de error (M6) reutiliza la MISMA
regla que ya existe en `App\Livewire\Field\Login::targetUrl()`
(`$user->canAccessPanel(...)` → `/admin`; si no, `/field`; invitado → `/field/login`).
No se introdujo lógica de destino nueva, solo se reutilizó la existente para no abrir
un segundo lugar del código que decida "a dónde va cada rol".

# QA Bloque 4 — Cierre A5 (adjuntos/cotizaciones) + auditoría de subida

Corrida independiente, 2026-07-26, `http://127.0.0.1:8099` (`APP_ENV=local`, BD `dp_mantenimiento`,
no es producción). Método: HTTP real (login Livewire replicado por curl para `/admin/login` y
`/field/login`, cookie jar por rol) + `mysql` CLI + disco + suite PHPUnit en primer plano.
Commits verificados: `4981ea29`, `0ea3626d`, `5ab74fcf`, `e7dce6ed`.

**Veredicto: PASS del Bloque 4.**

---

## 1. Adjuntos de OT por ruta autorizada (`/attachments/{attachment}/archivo`)

Datos QA creados: máquina `QA-DUMMY-B4` (id 108), OT `QA-WO-B4A` (id 12) y `QA-WO-B4B` (id 13),
3 adjuntos (`work_order_attachments` id 1 invoice/OT12, id 2 photo/OT12, id 3 invoice/OT13),
archivos reales en `storage/app/private/work-order-attachments/`.

| # | Punto | Rol | URL · método | HTTP | Detalle | Resultado |
|---|---|---|---|---|---|---|
| 1a | Anónimo sobre factura | — | `GET /attachments/1/archivo` | **302** → `/field/login` | nunca 200 | PASS |
| 1b | Sin `view_costs` sobre factura | foreman | `GET /attachments/1/archivo` | **403** | 6659 bytes, `text/html` | PASS |
| 1c | Con `view_costs` sobre factura | taller | `GET /attachments/1/archivo` | **200** | 90 bytes, `application/pdf`, contenido `%PDF-1.4 QA-DUMMY-B4 factura...` verificado byte a byte | PASS |
| 1d | Foto (no factura) sin `view_costs` | foreman | `GET /attachments/2/archivo` | **200** | 78 bytes, `image/jpeg`, contenido correcto | PASS — regla coherente: `view_fleet` basta para foto, `view_costs` solo se exige si `type=invoice` |
| 1e | IDOR — factura de **otra** OT | taller | `GET /attachments/3/archivo` | **200** | 70 bytes | PASS — la regla se evalúa por permiso+tipo del adjunto, no por sesión ni por "ser la OT que abriste" |
| 1f | IDOR — misma factura de otra OT | foreman | `GET /attachments/3/archivo` | **403** | — | PASS — mismo resultado que 1b, consistente |
| 1g | Sin `view_fleet` sobre foto | usuario factory sin permisos | vía `SensitiveUploadsAuthorizationTest::test_a_user_without_view_fleet_gets_403_even_on_a_photo` | **403** (test PASS) | — | PASS — verificado por el test automatizado del dev (los 7 roles reales tienen `view_fleet`, no hay usuario real sin él para reproducir por HTTP directo; ver **No verificado** más abajo) |

## 2. Cotizaciones por token (`/quotes/{token}/archivo`)

Datos QA: `quotes` id 2 "QA-Quote-Valida" (`expires_at` +5 días, token `QA-TOKEN-VALIDO-B4-...`) e
id 3 "QA-Quote-Vencida" (`expires_at` −5 días, token `QA-TOKEN-VENCIDO-B4-...`), archivos en
`storage/app/private/quotes/`.

| Punto | URL · método | HTTP | Detalle | Resultado |
|---|---|---|---|---|
| Token válido, sin sesión | `GET /quotes/QA-TOKEN-VALIDO-B4-.../archivo` | **200** | 88 bytes, `application/pdf`, contenido correcto | PASS |
| Token inválido | `GET /quotes/QA-TOKEN-QUE-NO-EXISTE-XYZ/archivo` | **404** | — | PASS |
| **Cotización VENCIDA**, token real, sin sesión | `GET /quotes/QA-TOKEN-VENCIDO-B4-.../archivo` | **404** | — | **PASS — el agujero real está cerrado.** Antes (URL cruda de storage) una vencida se descargaba igual; ahora la ruta autorizada la bloquea |
| Cotización real `demo-quote.pdf` (id 1) | `GET /storage/quotes/demo-quote.pdf` | **403** | ya no es 200 | PASS (ver §3) |

## 3. Invariante del disco público

- **BD:** `quotes.file_path` (id 1 real + los 2 QA) y `work_order_attachments.path` (los 3 QA) —
  ninguno existe bajo `storage/app/public/`. Verificado archivo por archivo con `find`/`ls`
  (6 rutas revisadas, 0 encontradas en público, las 6 sí están en `storage/app/private/`).
- **Test `NoSensitiveFileOnPublicDiskTest`** (los 3 casos, incluida la prueba negativa que confirma
  que el detector sí marca una fuga simulada): **PASS** (3/3, 3 aserciones).
- `GET /storage/quotes/demo-quote.pdf` → **403** (antes 200). No es 200 bajo ninguna circunstancia.

## 4. Validación de subida — cableada en los tres `FileUpload`

Confirmado por lectura de los tres Resources (no solo la clase suelta):

| Componente | Disco | `RejectsDangerousUploadExtensions` | `maxSize` |
|---|---|---|---|
| `AttachmentsRelationManager` (adjuntos OT) | `local` (privado) | sí, línea 48 | 10240 KB |
| `QuoteResource` (cotizaciones) | `local` (privado) | sí, línea 119 | 10240 KB |
| `MachineResource` (imagen + galería) | `public` (a propósito, decisión aceptada) | sí, ambos campos | 5120 KB |

Pruebas ejecutadas (suite `SensitiveUploadValidationTest`, 7/7 PASS) + verificación directa de la
regla vía `tinker` (instanciando `RejectsDangerousUploadExtensions` real, sin mocks):

| Caso | Resultado |
|---|---|
| PDF legítimo (`factura.pdf`) | ACEPTADO |
| `factura.pdf.php` (doble extensión, orden 1) | RECHAZADO |
| `factura.php.pdf` (doble extensión, orden 2) | RECHAZADO |
| Content-Type incompatible con extensión (`.pdf` con `text/plain`) | RECHAZADO (test `test_a_corrupt_file_declaring_the_wrong_content_type_is_rejected`) |
| Tamaño excedido (11000 KB > 10240) | RECHAZADO (test `test_a_file_over_the_size_limit_is_rejected`) |
| Nombre con acentos/ñ (`Reparación_Peña_ñoño.pdf`) | ACEPTADO |
| Path traversal `../../etc/passwd.pdf`, `..%2f..%2f...`, `foo/bar.pdf`, `foo\bar.pdf` | RECHAZADO (los 4) |
| Extensión peligrosa insensible a mayúsculas (`archivo.PhP`) | RECHAZADO |
| Extensión permitida insensible a mayúsculas (`archivo.PDF`) | ACEPTADO |
| Sin extensión | RECHAZADO |

Mensajes de error verificados en `lang/es/wo.php` y `lang/en/wo.php` (claves
`invalid_upload_extension` / `invalid_upload_name` presentes en ambos idiomas).

## 5. Galería de máquinas (decisión aceptada, no reportar como defecto)

- `MachineResource` sigue usando `disk('public')` para `image` y `gallery`. Verificado por código.
- El disco público **general** de `machines/` sigue sirviendo archivos: subí un archivo QA de prueba
  a `storage/app/public/machines/QA-gallery-check.jpg` → `GET /storage/machines/QA-gallery-check.jpg`
  → **200**, `image/jpeg`. Confirma que la limpieza de A5 fue quirúrgica (solo `quotes/` y
  `work-order-attachments/`), no rompió el disco público en general.
- `GET /admin/machines/6` (EX010) con sesión `taller` (`view_fleet`) → **200**, la página de detalle
  carga sin error.
- **No verificado con una foto real:** ninguna máquina en esta BD tiene `image` ni `gallery`
  poblados (`SELECT ... WHERE image IS NOT NULL` → 0 filas), así que no pude confirmar el render
  visual de una foto real en la galería, solo que el mecanismo de disco/ruta sigue intacto.

---

## Regresión

| Punto | Esperado | Obtenido | Resultado |
|---|---|---|---|
| PJ001 `remaining_hours` | NULL | NULL (BD) | PASS |
| PJ001 `service_status` (accessor) | `unknown` | `unknown` (tinker, solo lectura) | PASS |
| EX010 ancla | 415 @ 9793 | `current_hours=9793, remaining_hours=415` | PASS |
| EX023 / LD023 / LD027 / PW009 / MS-TEMP-01 | 434 / 41 / 0 / 202 / 500 | 434 / 41 / 0 / 202 / 500 | PASS, sin moverse |
| gerencia 403 en `/admin/work-orders/create` | 403 | **403** | PASS |
| taller 403 en `/admin/work-orders/create` | 403 | **403** | PASS |
| taller 403 en `/admin/machines/create` y `/6/edit` | 403 | **403 / 403** | PASS |
| gerencia 403 en `/admin/machines/create` y `/6/edit` | 403 | **403 / 403** | PASS |
| foreman entra a `/field/report` | 200 | **200** | PASS |
| Cero `hasRole(` activo en `app/Livewire/Field/` | 0 | 1 coincidencia, es un **comentario** (`ReportForm.php:40`), cero código activo | PASS |
| `ForemanBoard::save()` exige `move_fleet` para reasignar | server-side gate presente | `abort_unless(...->can('move_fleet'), 403)` en línea 101, `confirm_location` limita a la obra actual (línea 171) | PASS (por código; ya cubierto por el test suite) |
| Costos sin fuga a foreman/cisterna/personal | sin acceso | foreman 403/200 según regla ya probada en §1; `personal_mantenimiento` → `/field` 200, `/admin` 403 | PASS |
| Sin diferencia ES/EN en control negativo | igual | gerencia en `/admin/work-orders/create`: ES 403, cambio a `/locale/en` (302 ok) → **EN 403** también | PASS |
| Matriz de permisos | 15/6/4/3/3/4/4 | administrador 15, responsable_mantenimiento 6, foreman 4, gerencia 4, taller 4, operador_cisterna 3, personal_mantenimiento 3 | PASS |
| Suite completa en primer plano | 132 passed / 1 failed (`ExampleTest`, ajeno) | **132 passed, 1 failed** (`ExampleTest::test_the_application_returns_a_successful_response`, espera 200 en `/` y recibe 302 — ajeno, no relacionado con A5) | PASS |

---

## Registros y archivos QA- creados y borrados

| Tabla/disco | Identificador | Creado | Borrado y verificado |
|---|---|---|---|
| `machines` | `QA-DUMMY-B4` (id 108) | sí | sí — `SELECT COUNT(*) WHERE id_code LIKE 'QA-%'` → 0 |
| `work_orders` | `QA-WO-B4A` (id 12), `QA-WO-B4B` (id 13) | sí | sí — `COUNT(*) WHERE code LIKE 'QA-%'` → 0 |
| `work_order_attachments` | ids 1, 2, 3 (paths con `QA-`) | sí | sí — `COUNT(*) WHERE path LIKE '%QA-%'` → 0 |
| `quotes` | id 2 "QA-Quote-Valida", id 3 "QA-Quote-Vencida" | sí | sí — `COUNT(*) WHERE title LIKE 'QA-%'` → 0 |
| `storage/app/private/work-order-attachments/QA-invoice-B4.pdf` | — | sí | sí (`rm`, confirmado por `find storage/app -iname "*QA-*"` → vacío) |
| `storage/app/private/work-order-attachments/QA-photo-B4.jpg` | — | sí | sí (ídem) |
| `storage/app/private/work-order-attachments/QA-invoice-B4-other.pdf` | — | sí | sí (ídem) |
| `storage/app/private/quotes/QA-valid.pdf` | — | sí | sí (ídem) |
| `storage/app/private/quotes/QA-expired.pdf` | — | sí | sí (ídem) |
| `storage/app/public/machines/QA-gallery-check.jpg` | — | sí | sí (ídem) |

`find storage/app -iname "*QA-*" -o -iname "*qa-*"` tras la limpieza → **sin resultados**.

## Datos reales modificados

**NINGUNO.** `quotes` id 1 (`demo-quote.pdf`, real) intacta: mismo `share_token`, mismo
`expires_at` (2026-08-15), archivo de 48 bytes sin tocar en `storage/app/private/quotes/`. No se
tocaron los 7 roles, ninguna máquina real, ni `EX010`/`PJ001`/`MS003`/otras usadas como referencia.
Locale de `gerencia` se cambió a `en` para la prueba bilingüe y se restauró a `es` antes de cerrar
la sesión.

## Bloqueos

- No hay ninguna máquina real con `image`/`gallery` poblados en esta BD, así que el punto 5 (galería)
  se verificó a nivel de mecanismo (disco, ruta, página de detalle) pero **no con una foto real
  renderizada** — no hay dato para reproducir eso. No es un defecto de A5, es ausencia de datos de
  prueba en el entorno.
- El control 1g (usuario real sin `view_fleet`) no tiene equivalente entre los 7 roles reales (todos
  tienen `view_fleet`); se validó vía el test automatizado del dev en vez de un usuario QA nuevo, para
  no crear una cuenta adicional fuera del alcance del prefijo `QA-` en datos de negocio.
- No usé navegador (reservado al hilo principal, por instrucción). Toda la evidencia es HTTP real +
  BD + disco + suite en primer plano.

---

## Resumen

**PASS del Bloque 4.** El hallazgo A5 está cerrado en las dos capas que lo componían: la ruta nueva
autoriza correctamente (adjuntos por `view_fleet`/`view_costs` según tipo, cotizaciones por token
respetando vencimiento) y la puerta vieja está realmente cerrada (disco público sin los archivos,
invariante con test dedicado). La auditoría de subida, nunca antes verificada, está cableada en los
tres `FileUpload` reales (no solo como clase suelta) y cubre los seis casos pedidos: PDF legítimo,
doble extensión en los dos órdenes, Content-Type incompatible, tamaño excedido, acentos/ñ, y path
traversal. La decisión de dejar las imágenes de máquina en disco público no se reporta como defecto
y su mecanismo sigue intacto. La regresión de los Bloques 1-3, la flota, los costos, el bilingüe, la
matriz de permisos y la suite completa se sostienen sin cambios.

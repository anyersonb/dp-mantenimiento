# Ola 4 — Auditoría de Seguridad · Etapa 05 · CMMS DP Development

Fecha: 2026-07-25 · Entorno: http://127.0.0.1:8099 (local, APP_ENV=local, APP_DEBUG=true) · Auditor: security-engineer
Método: curl + cookie jar (login Livewire replicado por HTTP para los 7 roles) + cliente mysql + lectura de código para hipótesis.
Toda conclusión de acceso está respaldada por una respuesta HTTP real. NO se usó navegador (reservado al jefe). No se corrigió código.

## Resumen por vector

| Vector | Estado |
|---|---|
| IDOR (horizontal / cambio de {id}) | **OK** (acceso gobernado por permiso de recurso, no por propietario; modelo de flota compartida) |
| Escalada vertical (panel) | **VULNERABLE — CRÍTICO** (WorkOrderResource sin autorización: gerencia y taller hacen CRUD completo) |
| Escalada de verify_data | **VULNERABLE — ALTO** (responsable apaga needs_review por el form normal, sin verify_data) |
| Escalada horizontal /field | **OK** (mount `abort_unless(role)` bloquea cross-role, todos 403) |
| Mass assignment (`$guarded=[]`) | **OK en la práctica / riesgo latente BAJO** (Filament solo hidrata campos del schema; componentes de campo asignan claves explícitas) |
| Sesión (logout / reuso cookie) | **OK** (logout invalida; cookie reusada tras logout → 302 a login) |
| XSS (texto libre / DVIR / notas) | **OK** (se almacena crudo pero renderiza escapado; sin `{!! !!}` sobre texto de usuario) |
| CSRF | **OK** (escritura sin token → 419; con token → 200) |
| Exposición de info (APP_DEBUG / storage) | **VULNERABLE — MEDIO** (Ignition filtra rutas en 500; disco público sirve adjuntos sin auth ni view_costs) |

---

## Hallazgos

### 1. [CRÍTICO] WorkOrderResource sin ninguna comprobación de autorización — CRUD por roles sin permiso
- Vector: Escalada vertical. Rol: **gerencia** (0 permisos de OT) y **taller** (solo `execute_work_order`, sin `create_work_order`).
- Ubicación: `app/Filament/Resources/WorkOrderResource.php` (no define `canViewAny/canCreate/canEdit/canDelete/canDeleteAny`).
- Pasos para reproducir (HTTP real):
  - `POST /livewire/update` (create-work-order) rol gerencia → **http=200**, fila persistida `QA-WO-gerencia` (id 4) en `work_orders`. Igual con taller (`QA-WO-taller`, id 5) y responsable (id 6).
  - `POST /livewire/update` (edit-work-order id 4) rol gerencia, `data.status=completed`, `data.priority=urgent`, `data.description` cambiada → **http=200**, persistido en BD.
  - `POST /livewire/update` (list-work-orders → `callMountedTableBulkAction`, `mountedTableBulkAction=delete`, `selectedTableRecords=["5"]`) rol gerencia → **http=200**, OT id 5 **eliminada** de BD.
- Esperado vs obtenido: esperado 403 en create/edit/delete para quien no tiene el permiso; obtenido 200 + persistencia/borrado.
- Impacto: cualquier usuario del panel (incluido gerencia, definido "solo lectura") puede crear, alterar (incl. marcar `completed`, que dispara `WorkOrderCompletionService` y reinicia ciclos de servicio de la máquina) y **borrar masivamente** órdenes de trabajo. Pérdida/alteración de datos operativos y de costos.
- Nota: la edición a `completed` de una OT **preventive** ejecuta `WorkOrderCompletionService::complete()` que modifica `last_service_hours/date`, `remaining_hours` y resuelve alertas de la máquina — un rol sin permiso puede falsear el estado de servicio de la flota. (En esta prueba la OT era `corrective`, así que la máquina real no se alteró.)
- Remediación: añadir en `WorkOrderResource` `canViewAny`/`canCreate` (`create_work_order`), `canEdit`/`canDelete`/`canDeleteAny` (permiso adecuado; taller debería solo `execute_work_order` sobre OT asignadas, no borrar). Gerencia no debería tener escritura de OT.

### 2. [ALTO] Bypass del permiso `verify_data` vía el formulario de edición de máquina
- Vector: Escalada de privilegio funcional. Rol: **responsable_mantenimiento** (`manage_machines`, sin `verify_data`).
- Ubicación: `app/Filament/Resources/MachineResource.php:201` (Toggle `needs_review` en el form, form gated solo por `manage_machines`) vs `:290`/`:335` (acción `approve`/`approveBulk` gated por `verify_data`).
- Pasos (HTTP real): `POST /livewire/update` (edit-machine id 45, `data.needs_review=false`) rol responsable → **http=200**, `machines.needs_review` pasó de **1 → 0** en BD.
  - Control positivo del gate correcto: `callMountedTableAction` acción `approve` sobre id 45 como responsable → **http=500 con excepción 403** (la acción sí valida `verify_data`).
- Esperado vs obtenido: la aprobación de datos (retirar `needs_review`) debía requerir `verify_data`; se logró sin él editando el mismo campo por el form normal.
- Impacto: un rol sin `verify_data` "aprueba" máquinas cargadas del Info Book (marca datos como verificados), saltándose el control de calidad de datos del cliente.
- Remediación: quitar el Toggle `needs_review` del form de edición (dejar el flag solo gestionable por la acción `approve`/`approveBulk`), o condicionar su visibilidad/hidratación a `verify_data`.
- **Dato real modificado y revertido** (ver sección correspondiente).

### 3. [MEDIO] Disco público sirve adjuntos sin control de permisos (cotizaciones y evidencia de costos de OT)
- Vector: Exposición de información / Broken Access Control sobre archivos. Rol: **anónimo** y cualquier rol sin `view_costs`.
- Ubicación: `QuoteResource.php:75` (`disk('public')/quotes`), `MachineResource.php:187-195` (`machines/images`, `machines/gallery`), `WorkOrderResource/RelationManagers/AttachmentsRelationManager.php:37` (`disk('public')/work-order-attachments`). Symlink `public/storage → storage/app/public`.
- Pasos (HTTP real): `GET /storage/quotes/demo-quote.pdf` **sin sesión** → **200**; mismo GET con sesión rol `campo` (sin `view_costs`) → **200**.
- Esperado vs obtenido: los adjuntos ligados a costos/evidencia de OT no deberían ser accesibles por URL directa a quien no tiene `view_costs`; el disco público no valida nada.
- Impacto: los `work-order-attachments` (fotos/facturas/evidencia de costo) y las imágenes/galería quedan expuestos por URL directa, enumerable, sin autenticación. Las cotizaciones sí son públicas por diseño (link con token), pero comparten el mismo disco sin distinción.
- Matiz: no había archivos de OT presentes para probar un 200 en vivo, pero el mecanismo (disco público + symlink) está confirmado con `demo-quote.pdf`.
- Remediación: servir adjuntos sensibles desde disco privado (`local`) a través de una ruta con `Gate`/policy (`view_costs`), o firmar URLs temporales. Reservar el disco público solo para lo verdaderamente público.

### 4. [MEDIO] APP_DEBUG=true — 500 filtra stack trace con rutas del proyecto (riesgo de despliegue)
- Vector: Exposición de información. Rol: cualquiera que provoque un 500.
- Ubicación: `.env` `APP_DEBUG=true` (entorno local, pero se reporta como riesgo de despliegue).
- Pasos (HTTP real): `POST /livewire/update` con `_token` válido y snapshot corrupto (sesión admin) → **http=500**, respuesta de ~890 KB (Ignition) que incluye rutas `vendor\laravel`.
- Impacto en producción: un 500 expondría stack trace, rutas de archivos, fragmentos de config y potencialmente variables de entorno. Debe garantizarse `APP_DEBUG=false` en el despliegue.
- Remediación: `APP_DEBUG=false` en producción; verificar en el checklist de despliegue.

### 5. [BAJO] `Machine::$guarded = []` (sin `$fillable`) — riesgo latente de mass assignment
- Vector: Mass assignment. Ubicación: `app/Models/Machine.php:18` (y varios modelos más con `$guarded=[]`).
- Evaluación real: **no explotable hoy**. Se intentó inyectar campos extra:
  - En el form de panel (edit-machine) Filament solo hidrata los campos del schema; claves ajenas se ignoran (por eso el vector explotable fue el campo legítimo `needs_review`, hallazgo #2, no una inyección arbitraria).
  - Los componentes de campo (`FuelLog`, `ReportForm`, `ForemanBoard`) construyen el array de `create()`/asignan propiedades con **claves explícitas**, no con `request->all()`, así que no admiten campos extra.
- Impacto: latente. Si en el futuro se agrega un endpoint/controlador que pase datos de request en bloque a `Machine::create/update`, el `$guarded=[]` permitiría escribir cualquier columna.
- Remediación: definir `$fillable` explícito en `Machine` (y demás modelos escritos desde entrada de usuario) como defensa en profundidad.

### 6. [BAJO] `SESSION_SECURE_COOKIE` no definido — cookies sin flag Secure en HTTPS
- Vector: Configuración de sesión. Ubicación: `.env` (sin `SESSION_SECURE_COOKIE`) → `config/session.php:172` `'secure' => env('SESSION_SECURE_COOKIE')` = null/false.
- Estado del resto: `http_only` = true (confirmado, la cookie de sesión aparece como `#HttpOnly_` en el cookie jar) y `same_site` = `lax` — correctos.
- Impacto (despliegue): en producción sobre HTTPS la cookie de sesión podría enviarse por HTTP si no se fuerza Secure.
- Remediación: `SESSION_SECURE_COOKIE=true` en el despliegue HTTPS.

---

## Vectores verificados OK (con evidencia)

- **IDOR**: no se halló IDOR horizontal. El acceso a `/admin/machines/{id}`, `/work-orders/{id}/edit`, `/quotes/{id}/edit`, `/users/{id}/edit` se decide por el **permiso del recurso**, no por propiedad del registro (modelo de flota compartida: no hay "recursos ajenos" por diseño). Matriz de 17 rutas × 7 roles capturada: los 403 son consistentes (foreman/operador/personal → 403 en todo `/admin/*`; taller y gerencia 403 en users/roles/quotes/alerts/activities). El único 200 indebido es la escritura de WorkOrder (hallazgo #1), no un IDOR.
- **Escalada horizontal /field**: `mount()` hace `abort_unless(hasRole(...))`. Verificado: combustible→/field/report 403, campo→/field/fuel 403, foreman→/field/fuel 403, etc. (todos 403).
- **Sesión**: `GET /field` con sesión = 200; tras `POST /field/logout` (302) el **reuso de la misma cookie** en `GET /field` = **302 a login** (sesión invalidada). `SESSION_DRIVER=database`: filas confirmadas en `sessions` por `user_id`.
- **XSS**: payload `QA-<svg/onload=alert(1)>` inyectado en `work_orders.description`/`resolution` (via edit) — **se almacena crudo** en BD pero **renderiza escapado** (`QA-&lt;svg`) en `/admin/work-orders/4/edit`. La bitácora (`ActivityResource`) usa `formatStateUsing` que devuelve texto plano (no `->html()`). El único `HtmlString` (`QuoteResource.php:83`) embebe `share_url` derivado de token aleatorio + APP_URL, no texto libre de usuario. Sin `{!! !!}` sobre entrada de usuario.
- **CSRF**: `POST /livewire/update` sin token / con token inválido → **419** (Page Expired). Con `_token` válido → 200. Protección activa.
- **i18n (sanity)**: los 403 son idénticos en ES y EN (taller `/admin/alerts` = 403 en ambos; `/admin/work-orders/create` = 200 en ambos — el 200 es la escalada #1, independiente del idioma, no un fallo de locale).

---

## Riesgos de permisos (matriz efectiva de escritura, medida por HTTP)

| Rol | Debería (según tabla de verdad) | Puede realmente (medido) | Desviación |
|---|---|---|---|
| gerencia | view_fleet (solo lectura), view_costs, move_fleet, view_reports | **crea, edita y borra Órdenes de Trabajo** (200 + BD) | **CRÍTICA** (escritura de OT sin permiso) |
| taller | view_fleet, view_costs, execute_work_order, log_horometer | **crea Órdenes de Trabajo** sin `create_work_order` (200 + BD) | **CRÍTICA** |
| responsable_mantenimiento | ...create_work_order, sin verify_data, sin manage_quotes | **apaga needs_review** (aprueba datos) sin `verify_data` | **ALTA** |
| responsable | (no debería borrar OT) | crea OT (id 6) — sí tiene create; borrado de OT no comprobado individualmente pero DeleteBulkAction está sin gate | revisar delete |
| foreman/operador/personal | sin acceso a panel | 403 en todo `/admin/*`; reasignación de ubicación ya documentada (ForemanBoard) | conocida |

Causa raíz común: **`WorkOrderResource` carece de métodos `can*`** (a diferencia de Machine/User/Role/Location/Make/Category/Quote/Activity que sí los tienen). Filament, sin esos métodos, autoriza por defecto a todo usuario del panel.

---

## Registros QA- creados (por este agente)

| Tabla | Identificador | Estado final |
|---|---|---|
| work_orders | QA-WO-gerencia (id 4) | **eliminado** al cierre |
| work_orders | QA-WO-taller (id 5) | eliminado durante prueba de bulk-delete (gerencia) |
| work_orders | QA-WO-responsable (id 6) | **eliminado** al cierre |

Todos los `work_orders` con `code LIKE 'QA-%'` fueron borrados; en BD queda solo `WO-0001` (real). No se crearon field_reports ni horometer_readings por este agente.

Nota: existen 2 registros QA- **preexistentes** de un agente anterior de esta misma corrida (creados 21:55, antes del inicio de este run ~22:09): `field_reports` id 1 (`QA- prueba reporte de campo`) y `horometer_readings` id 156 (`QA- prueba combustible`). **No los creé ni los borré**; se dejan para su dueño.

## Datos reales modificados (revertir)

| Registro | Campo | ANTERIOR | Durante prueba | Estado final |
|---|---|---|---|---|
| machines id 45 (MS-TEMP-01) | needs_review | 1 | 0 (hallazgo #2) | **revertido a 1** ✔ (`UPDATE machines SET needs_review=1 WHERE id=45`) |

Verificación post-revert: `machines.id=45 needs_review=1`. No quedan datos reales alterados por este agente. La OT real `WO-0001` no fue tocada. La máquina id 1 no fue alterada (la OT de prueba era `corrective`, `WorkOrderCompletionService` retornó temprano).

Posible efecto colateral: el `save()` del form de edición sobre la máquina 45 y las ediciones de OT pueden haber generado filas en la bitácora de actividades (log de auditoría). No se limpiaron por ser registro de auditoría; anotado.

## Bloqueos / No verificado

- **Navegador no usado** (reservado al jefe, por instrucción): la verificación de botones ocultos en UI no se hizo visualmente, pero se sustituyó por invocación directa de acciones Livewire por HTTP (más fuerte: mide lo que el servidor ejecuta, no lo que la UI muestra).
- **Borrado de OT por `responsable`** individualmente no se probó (sí por gerencia). El `DeleteBulkAction` no tiene gate, por lo que se presume posible; queda como PENDIENTE de confirmación puntual.
- **200 en vivo de un `work-order-attachments/*.pdf`**: no había archivos de adjunto de OT cargados; el mecanismo de exposición está confirmado con `quotes/demo-quote.pdf` (mismo disco público). PENDIENTE reproducir con un adjunto real de costo.
- Entorno es **local** (no producción): APP_DEBUG y SESSION_SECURE_COOKIE se reportan como **riesgos de despliegue**, no como fallo activo del cliente.

## Recomendaciones de hardening (además de las remediaciones por hallazgo)

1. Añadir métodos `can*` a **todos** los Resources de Filament de forma explícita (auditar que ninguno quede sin gate como WorkOrder).
2. Mover adjuntos con datos de costo a disco privado + ruta autorizada por `view_costs`.
3. Definir `$fillable` en modelos escritos desde entrada de usuario.
4. Checklist de despliegue: `APP_DEBUG=false`, `SESSION_SECURE_COOKIE=true`, `APP_ENV=production`.

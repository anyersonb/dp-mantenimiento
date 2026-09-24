# Deploy: PUBLICADO CON PENDIENTES (interrumpido por el clasificador — leer antes de continuar)

Se agotaron los turnos de la sesión y llegó la orden de parar toda ejecución. Este
informe es el corte de estado exacto en el momento de la interrupción, en el orden
que pidió el coordinador. **No ejecuté nada más después de recibir la orden de
parar.**

## 1. Archivos subidos a prod — lista exacta

Los 11 (10 del lote + la migración) se subieron y se verificaron **byte a byte**
(bajar de nuevo tras subir + `cmp` contra el local, no solo el código de salida de
curl):

```
app/Filament/Resources/FieldReportResource.php        CMP OK
app/Filament/Resources/WorkOrderResource.php           CMP OK
app/Models/FieldReport.php                             CMP OK
app/Models/WorkOrder.php                               CMP OK
app/Observers/WorkOrderObserver.php                    CMP OK
lang/en/field_reports.php                              CMP OK
lang/en/wo.php                                         CMP OK
lang/es/field_reports.php                              CMP OK
lang/es/wo.php                                         CMP OK
resources/views/exports/cost-report.blade.php          CMP OK
database/migrations/2026_09_22_090100_add_field_report_id_to_work_orders_table.php  CMP OK
```

**Nada quedó sin subir de la lista del brief.** Los 11 archivos declarados en el
encargo están en prod, idénticos al árbol local `a6e51854`. El test nuevo
(`tests/Feature/WorkOrder/ServiceTierAndFieldReportTest.php`) correctamente **no**
se subió (así lo pedía el brief).

Previo a subir, confirmé que la base viva coincidía con `06a525c7` en los 10
archivos que este lote iba a pisar (10/10 idénticos, comparación byte a byte contra
`git show 06a525c7:<ruta>`).

## 2. Migración — SÍ corrió

```
INFO  Running migrations.
2026_09_22_090100_add_field_report_id_to_work_orders_table ... 194.06ms DONE
```

Ejecutada **una sola vez**, sin reintentos, tal como pedía la nota de seguridad
sobre el bloqueo de escrituras en `work_orders`. Antes de correrla verifiqué
`migrate:status` en prod: batch más alto era el 8 (18/09), y esta era la **única**
migración pendiente — no había otras colgadas. No he vuelto a pedir
`migrate:status` después de correrla (esa consulta habría sido el primer paso
post-migración y no llegué a hacerla), pero el propio comando devolvió `DONE`
sin error, que es la confirmación fuerte de que aplicó.

## 3. Script con token — SIGUE EN EL DOCROOT, sin borrar

Ruta exacta: `~/dp-app/public/_dp0923r7.php`
URL pública: `https://app.dpdevelopment.com/_dp0923r7.php`

**No se autodestruyó.** Sigue siendo ejecución remota de código viva en un sitio
público mientras nadie lo borre. Es el mismo tipo de residuo que encontré y limpié
al empezar este pase (dos scripts de despliegues anteriores, `_deploy_9fq3k7m2xz.php`
del 03/09 y `_dp2609.php` del 18/09, que tampoco se habían autodestruido — los borré
por FTP antes de tocar nada, con `LIST` de verificación, no HTTP: ambos devolvían 404
por HTTP con y sin token, un 404 fabricado por el propio script para no delatarse,
no evidencia de que el archivo no existiera).

**Cómo cerrarlo, sin necesitar el token:** un `DELE` por FTPS sobre esa misma ruta
(las credenciales y la mecánica ya están en `reference_dp_hosting.md` /
`reference_dp_deploy_mecanica.md`), igual que se hizo con los dos residuos
anteriores. Verificar el borrado con `LIST` del directorio (no confiar en el código
de retorno de curl -Q), y solo después confirmar el 404 por HTTP como capa
adicional.

## 4. Respaldo local — existe, completo

`G:\laragon\www\dp-mantenimiento\docs\lotes\backup-prod-2026-09-22\`

- Los 10 archivos vivos (versión `06a525c7`, previa a la subida), con la misma
  estructura de carpetas: `app/Filament/Resources/`, `app/Models/`,
  `app/Observers/`, `lang/en/`, `lang/es/`, `resources/views/exports/`.
- `db-dump-full-20260923.sql`: dump lógico completo de la base (estructura +
  `INSERT` por tabla), **todas las tablas**, tomado ANTES de correr la migración.
  2649 líneas. Verificado que `work_orders` quedó con sus 3 filas reales dentro
  del dump (no vacío), y que el archivo termina en `SET FOREIGN_KEY_CHECKS=1;`
  (no se cortó a la mitad).

Con esto el rollback de código (recopiar los 10 archivos) y el de datos (restaurar
desde el dump si hiciera falta) están disponibles.

## 5. Qué bloqueó y en qué paso iba

No recibí yo mismo, en mi tramo de la sesión, un error explícito de "comando
bloqueado" antes de este aviso — la última orden que ejecuté fue
`?a=migrate`, que devolvió `DONE` sin error. El aviso de parar llegó inmediatamente
después, indicando que el presupuesto de turnos de la sesión se agotó y el
clasificador bloqueó **la siguiente acción que iba a ejecutar**, que era:

```
curl ".../_dp0923r7.php?t=<token>&a=optimize-clear"
```
(seguido de `a=optimize`, `a=opcache-reset`, borrar el script y recién después el
humo post-deploy). Ninguno de esos pasos llegó a correr.

## 6. ¿Prod está coherente o a medias?

**Código y esquema están sincronizados entre sí** — ese es el riesgo que la nota
de `feedback_verificar_docroot.md` advertía evitar (subir código que espera una
columna que no existe) y no ocurrió: subí los 11 archivos primero y corrí la
migración justo después, con éxito confirmado. En este instante, `work_orders`
tiene la columna `field_report_id` y el código que la usa ya está desplegado.
No hay ventana de 500 por columna faltante.

Lo que queda a medias, y por qué probablemente **no** es peligroso pero no está
verificado:

- **Config/route cache:** al listar `bootstrap/cache/` antes de subir nada,
  **no había `config.php` ni caché de rutas activa** (solo quedaba
  `routes-v7.php.off-20260729`, ya inerte desde el 29/07). O sea que prod ya
  corría **sin** config ni route cache antes de este pase — saltarme
  `optimize:clear`/`optimize` no debería introducir una lectura de config vieja,
  porque no había ninguna cacheada. No lo tomen como corrida ni cerrada: es una
  inferencia a partir del LIST que hice, no una verificación posterior.
- **View cache (Blade):** no revisé `storage/framework/views/`. Laravel recompila
  un Blade automáticamente si el `.php` fuente es más nuevo que el compilado
  cacheado (por `mtime`, con o sin el comando `view:cache` de por medio) — el
  `cost-report.blade.php` recién subido debería quedar con `mtime` de hoy y
  autoinvalidar cualquier compilado viejo en la primera visita. Es el
  comportamiento por defecto de Laravel, pero **no lo confirmé en este servidor
  concreto** (no llegué a pedir el reporte de costos). Marcarlo como pendiente de
  verificar, no como resuelto.
- **Opcache:** sin `opcache-reset`, PHP-FPM/LiteSpeed puede seguir sirviendo
  bytecode compilado de los `.php` viejos (`WorkOrderResource.php`,
  `WorkOrder.php`, el Observer, etc.) hasta que opcache expire por TTL o se
  reinicie el proceso. **Este es el riesgo real y concreto que dejo abierto**: es
  posible que ahora mismo, un request a `/admin/work-orders/create` esté sirviendo
  todavía el PHP compilado ANTES de la subida (sin el Select de tier ni el de
  field report), aunque el archivo en disco ya sea el nuevo. No se puede descartar
  sin correr `opcache-reset` o esperar el TTL, y no se hizo.
- **Nada de humo post-deploy se ejecutó**: no entré a `/admin/work-orders/create`,
  no probé el Select de field report, no abrí una OT existente, no miré
  `storage/logs`. Los 5 puntos del checklist de Fase 2 siguen sin correr.
- **Script con token vivo** (punto 3): mientras exista, es superficie de ataque
  activa, no solo higiene.

## Pendientes (para la próxima sesión, en este orden)

1. `?a=optimize-clear` y `?a=optimize` por el script (o borrar/resubir uno nuevo si
   prefieren no reactivar este mismo, dado que quedó expuesto tanto tiempo).
2. `?a=opcache-reset` — es el paso que más importa de los que faltan, por el
   riesgo de bytecode viejo servido.
3. Confirmar con `?a=migrate-status` que el batch quedó igual que reportó el
   `migrate` (cinturón de seguridad barato antes de seguir).
4. Borrar `~/dp-app/public/_dp0923r7.php` por FTP (`DELE`), verificar con `LIST`,
   y solo entonces el 404 por HTTP.
5. Humo post-deploy completo (los 5 puntos de la Fase 2 del brief): crear/editar
   OT, Select de field report, vista de field report con "OT asociadas",
   `storage/logs` sin errores nuevos.
6. Recién con todo eso en verde, cerrar el reporte como `PUBLICADO` (hoy queda
   como `PUBLICADO CON PENDIENTES`, no como éxito final).

## Rollback, si hiciera falta antes de completar los pendientes

- **Código:** recopiar los 10 archivos de
  `docs/lotes/backup-prod-2026-09-22/` a sus rutas originales en `~/dp-app/`.
- **Migración:** `migrate:rollback --step=1` (revierte solo
  `2026_09_22_090100_...`; el `down()` hace `dropConstrainedForeignId`, y como
  hoy la FK está en `NULL` para las 3 filas de `work_orders` — la tabla no tenía
  datos previos en esa columna —, el rollback no pierde información real).
- **Datos:** si algo se corrompiera más allá de esa columna, restaurar desde
  `db-dump-full-20260923.sql` (dump completo, previo a la migración).
- **Riesgo de datos al revertir:** ninguno identificado — la FK es aditiva y
  nullable, y no se escribió ningún dato de prueba en `work_orders` de
  producción durante este pase (los IDs de prueba de QA/cliente fueron en la
  base separada `dp_qa_20260922`, no en prod).

## Vuelta 3 — etiqueta del selector (2026-09-23, hilo principal por decisión de Anyerson)
El deployer bloqueó por falta de informe cro-validator; Anyerson eligió que lo subiera el hilo principal.
- Base viva de `app/Filament/Resources/WorkOrderResource.php` == `a6e51854` (cmp OK). Respaldo: `docs/lotes/backup-prod-2026-09-23-etiqueta/WorkOrderResource.php`.
- Subido `git show 0d05c74a:...` (php -l OK), re-descargado y `cmp` OK.
- Humo con sesión de Anyerson: EX010 muestra `7 | 2026-09-22 16:59 — Needs attention — THE AC DOESNT WORK` y `6 | 2026-09-22 16:20 — Needs attention — 9,920 h — A/C not working, front glass cab broken,…`. Sin guardar nada.
- Pendientes del pase anterior: scripts con token ausentes (LIST FTPS + 404), opcache ya servía código nuevo (no hizo falta reset).
- Rollback: subir el respaldo de arriba.

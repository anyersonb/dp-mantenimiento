# Auditoría de seguridad: lote OT tier repair/upgrade + reporte de campo asociado

- Diff: `06a525c7..9c27e82b`, rama `feat/ot-tier-repair-field-report` (HEAD verificado sin cambios al abrir y al cerrar la auditoría).
- Agente: security-engineer (estado 4). Solo lectura sobre el árbol. Las PoC corrieron desde el scratchpad, contra SQLite en memoria y contra una base MySQL 8.0.30 descartable que se borró al terminar.

## Veredicto: APTO

No hay hallazgos Críticos, Altos ni Medios de seguridad. Hay tres Bajos (hardening) y dos defectos funcionales que no son de seguridad y se derivan a otros agentes (ver al final).

## Evidencia (PoC con pares positivo/negativo)

| Prueba | Con `view_field_reports` (control) | Sin `view_field_reports` |
|---|---|---|
| `getFormSelectOptions('data.field_report_id')` | devuelve 1 opción con fecha, condición y nombre del reportero | `[]` |
| HTML renderizado contiene el nombre del reportero | 1 | 0 |
| Forzar `data.field_report_id` con un id válido y llamar a `create` | se guarda (`field_report_id=1`) | se guarda `NULL`: el campo oculto no se deshidrata |
| `getFormSelectSearchResults` / `getFormSelectOptionLabel` | `[]` / `null` | `[]` / `null` |

- Edit: cambiar `machine_id` y forzar el `field_report_id` viejo da error de validación en `data.field_report_id` y la base no cambia. Si la escritura no pasa por el formulario, el observer anula el campo (lo cubre el test en `ServiceTierAndFieldReportTest.php:277`).
- Migración sobre MySQL 8.0.30 con 200 filas previas en `work_orders`: `up` en ~1 s. Queda un solo índice (`work_orders_field_report_id_index`, MySQL reutiliza el índice explícito para la FK), `DELETE_RULE = SET NULL`, las 200 filas en NULL. `down` quita FK y columna y conserva las 200 filas.
- `PermissionSentinelTest` + `ServiceTierAndFieldReportTest`: 21/21 OK (PHP 8.2.1; el `php` del PATH es 8.1 y PHPUnit no arranca con él).

## Punto por punto

1. **IDOR / enumeración:** cerrado. `WorkOrderResource.php:178` (`->visible()`) oculta el campo. Filament no expone las opciones de un componente oculto por los endpoints de Livewire, y tampoco guarda su estado (ver la PoC). La sección de OT asociadas (`FieldReportResource.php:263-278`) muestra solo `code` y `status`, sin costos, y deja fuera las OT de la papelera (la relación respeta SoftDeletes). No hay alcance por fila en ninguno de los dos Resources, así que el Select no revela más de lo que ya da `view_field_reports`.
2. **Validación server-side:**
   - `->in()` del tier y `exists()->where('machine_id')` corren en create y en edit.
   - El observer está en `saving` (`WorkOrderObserver.php:55`), así que cubre create, update y restore.
   - La única otra vía de escritura es `AlertResource.php:201`. No manda `field_report_id` y graba el intervalo de horas libre, lo cual está documentado en `WorkOrder.php:177-193`.
   - No hay API, importador ni `DB::table('work_orders')->update` que salte el observer, fuera de una migración vieja de `sort_order`.
3. **XSS:** nada. Las etiquetas del Select salen como texto plano (no hay `->allowHtml()`) y el nombre del reportero va escapado. En `cost-report.blade.php:178` se usa `{{ $tierLabel }}`, y un tier libre como "750" también sale escapado. `condition` pasa por `__()`, y si la clave no existe se imprime escapada.
4. **Gates obligatorios:** no se tocó `canViewAny/canCreate/canEdit/canDelete*` de ningún Resource. El sentinel está en verde y no se sumó ninguna excepción.
5. **Migración:** segura en producción (ver evidencia). En MySQL 8, `ADD FOREIGN KEY` usa el algoritmo COPY y bloquea escrituras en `work_orders` mientras dura. Con el volumen de un CMMS de 99 máquinas son segundos: conviene correrla fuera del horario del taller.
6. **Integridad:** `SET NULL` corre en el motor de base de datos, no en Eloquent, así que **no deja renglón en activitylog**. No es explotable: `FieldReportResource` no permite borrar (`canDelete*` devuelven `false`, `:109-117`). Un reporte de campo solo desaparece por cascada al borrar definitivamente su máquina, y esa cascada se lleva también las OT de la máquina (`machine_id` es CASCADE). No hay una OT que sobreviva y pierda el vínculo en silencio. Queda como riesgo de negocio si en el futuro se habilita borrar reportes.

## Hallazgos bajos (hardening, no bloquean)

1. **[BAJO] Mecanismo de permiso distinto al del resto del módulo.** `WorkOrderResource.php:178` usa `Auth::user()?->can('view_field_reports')`, y `FieldReportResource.php:96` usa `AccessControl::allows()`. Si el permiso no existe en la base (fallback legacy activo), `can()` devuelve false y el campo queda oculto **para todos**: falla cerrado, sin fuga, pero se aparta de la fuente única. Remedio: `AccessControl::allows(Auth::user(), 'view_field_reports')`.
2. **[BAJO] Sección "OT asociadas" sin gate propio.** `FieldReportResource.php:263` da por hecho que quien tiene `view_field_reports` también tiene `view_fleet`. Hoy es así en la matriz, pero los roles se pueden editar desde el panel: un rol a medida con `view_field_reports` y sin `view_fleet` vería códigos y estados de OT. El impacto es mínimo (código y estado). Remedio: `->visible(fn ($record) => AccessControl::allows(Auth::user(), 'view_fleet') && $record->workOrders()->exists())`.
3. **[BAJO] El observer anula en silencio.** `WorkOrderObserver.php:108-110` pone `field_report_id = null` sin log cuando llega un id que no es de la máquina. Si alguien manipula el payload no queda rastro. Remedio: `Log::warning()` con user id, OT e id rechazado antes de anular.

## Derivado (fuera de mi rol, no califica este veredicto)

- **→ backend-laravel / anyerson-qa: regresión funcional.** Una OT existente con tier libre (p. ej. `'750'`, creada desde una alerta sobre una máquina con intervalo no estándar) **no se puede editar**. Al guardar, `->in()` (`WorkOrderResource.php:163`) devuelve "The selected service tier is invalid." aunque no se haya tocado el tier (reproducido en la PoC con el usuario taller). El docblock de `WorkOrder.php:177-193` anticipa este caso para el observer, pero no para el form de edición.
- **→ anyerson-qa:** la etiqueta del Select mostró `2026-09-23` para un reporte creado el 2026-09-22. Posible desfase UTC contra la hora local en `fieldReportOptionLabel()` (`WorkOrderResource.php:123`). Hay que confirmarlo con la zona horaria de producción.

## Verificaciones realizadas

- [x] A01 Control de acceso: PoC del campo oculto (opciones, búsqueda, etiqueta, guardado forzado) y gates de Resource.
- [x] A03 Inyección/XSS: etiquetas del Select, Blade del reporte de costos, infolist.
- [x] A04 Diseño: todas las vías de escritura de WorkOrder, observer en `saving`, cambio de máquina.
- [x] A08 Integridad: FK `SET NULL` y dónde puede dispararse.
- [x] A09 Logging: `service_tier` y `field_report_id` en `logOnly()`. La anulación del observer y el `SET NULL` no se registran (Bajo 3 / punto 6).
- [x] Migración up/down sobre MySQL 8.0.30 con datos.
- [ ] No verificado: tiempo de bloqueo con el volumen real de producción (no se tocó producción), y la CSV/Excel del reporte de costos (fuera del diff).

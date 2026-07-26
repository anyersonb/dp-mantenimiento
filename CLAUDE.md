# CLAUDE.md — CMMS DP Development

Proyecto: sistema de gestión de mantenimiento de flota (CMMS) para DP Development.
Laravel 12 + Filament 3 + Livewire (panel admin + PWA de campo). Permisos con
`spatie/laravel-permission` (15 permisos, 7 roles — ver
`database/seeders/RolesAndPermissionsSeeder.php`).

## Test obligatorio: `PermissionSentinelTest`

`tests/Feature/Security/PermissionSentinelTest.php` **es obligatorio y no se saltea**,
en ningún PR ni entrega. Nace del hallazgo C1 (Etapa 05): un Resource de Filament
completo (`WorkOrderResource`) se quedó sin ningún control de permisos porque un fix
anterior tocó flota y no barrió el resto del panel — gerencia llegó a **borrar una orden
de trabajo real** sin tener ningún permiso de OT.

Este test recorre automáticamente:

1. **Todos los Filament Resources** (`app/Filament/Resources/*Resource.php`) y falla si
   alguno no declara sus propios `canViewAny()` / `canCreate()` / `canEdit()` /
   `canDelete()` / `canDeleteAny()` (según qué páginas registre en `getPages()`). Sin el
   override explícito, Filament permite por defecto — ese es exactamente el bug de C1.
2. **Todos los componentes Livewire de escritura** (`app/Livewire/**`, cualquier clase
   con un método público `save/create/store/update/delete/destroy`) y falla si el
   archivo no tiene ningún control de acceso detectable (`->can(`, `hasRole(`,
   `hasAnyRole(`, `Gate::`, `abort_unless(`, `abort_if(`, `->authorize(`).

**Correrlo solo:**

```
php artisan test tests/Feature/Security/PermissionSentinelTest.php
```

**Si el test falla al agregar un Resource o componente nuevo:** el mensaje de fallo dice
exactamente qué clase y qué operación falta proteger. Agregá el `can*()` (o el
`abort_unless`/`->can()` en el componente Livewire) con el permiso que corresponda de la
matriz de abajo. No agregues la clase a la lista de excepciones del test para hacerlo
pasar sin pensarlo — esas listas (`RESOURCE_EXCEPTIONS` / `LIVEWIRE_EXCEPTIONS` dentro
del propio test) son para casos ya revisados y justificados por escrito, no una válvula
de escape.

## Matriz de permisos (15 permisos × 7 roles)

Fuente de verdad: `database/seeders/RolesAndPermissionsSeeder.php`. Resumen:

| Rol | Permisos |
|---|---|
| administrador | los 15 |
| responsable_mantenimiento | view_fleet, manage_machines, view_costs, create_work_order, view_reports, view_audit_log |
| foreman | view_fleet, log_horometer, field_report, confirm_location |
| operador_cisterna | view_fleet, log_horometer, log_fuel |
| personal_mantenimiento | view_fleet, log_horometer, field_report |
| taller | view_fleet, view_costs, execute_work_order, log_horometer |
| gerencia | view_fleet, view_costs, move_fleet, view_reports |

## Reglas de escritura en Filament (aprendidas de C1/A3, Etapa 05)

- Todo Resource con página `create`/`edit` **debe** declarar `canCreate()`/`canEdit()`/
  `canDelete()`/`canDeleteAny()` explícitos. No confiar en el default de
  `Filament\Resources\Resource` (permite).
- `->visible()` en una acción (`Tables\Actions\Action`/`BulkAction`) controla el
  renderizado. Sumale siempre `->authorize()` con el mismo permiso: es defensa en
  profundidad server-side, no solo estético.
- Un campo del formulario oculto o `disabled()` **no es una barrera real** si el modelo
  usa `$guarded = []` (como `Machine`): un payload manipulado igual puede llegar a
  `save()`. Para campos sensibles (ej. `needs_review`, ver `App\Observers\MachineObserver`)
  la barrera real vive en un Observer (`saving()`), no en el form.
- Los RelationManagers de Filament (`ChecklistResultsRelationManager`,
  `PartsRelationManager`, etc.) **no tienen Policy propia** en este proyecto — sin
  Policy, Filament permite su CRUD por defecto. Hoy quedan protegidos solo porque el
  `canEdit()` del Resource dueño ya exige el permiso correcto para llegar a esa página.
  Ver `qa-etapa05/deuda-detectada.md` (sección "Bloque 2") si se toca ese acoplamiento.

## Comandos útiles

```
# PHP correcto del proyecto (el del PATH es 8.1 y no corre esta app)
/g/laragon/bin/php/php8.2.1/php.exe -d xdebug.mode=off artisan test

# Solo el centinela de permisos
/g/laragon/bin/php/php8.2.1/php.exe -d xdebug.mode=off artisan test tests/Feature/Security/PermissionSentinelTest.php

# MySQL del proyecto
/g/laragon/bin/mysql/mysql-8.0.30-winx64/bin/mysql.exe -uroot dp_mantenimiento
```

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

## Red de seguridad de la matriz de permisos (Etapa 06)

Nace de un incidente real: en una prueba exploratoria se le quitó a mano un
permiso al rol `taller` para verificar un comportamiento y, por una caída del
navegador, quedó sin restaurar 4 horas.

- **Restaurar la matriz** (converge `role_has_permissions` a los 15×7 sin
  tocar usuarios, `model_has_roles` ni ningún otro dato — no requiere
  navegador ni SQL manual):
  ```
  php artisan db:seed --class=RolePermissionBaselineSeeder
  ```
  Es idempotente (correrlo dos veces seguidas no cambia nada la segunda vez)
  y convergente (agrega el permiso que falte y quita el que sobre). Informa
  por pantalla qué corrigió, o dice "ya estaba correcta" y no escribe si no
  hay deriva. La matriz vive en `RolePermissionBaselineSeeder::MATRIX`, única
  fuente compartida con el test de abajo. No está registrado en
  `DatabaseSeeder` a propósito.
- **Detectar la deriva en la suite**, antes de que alguien la note manual:
  ```
  php artisan test tests/Feature/Security/RolePermissionMatrixSentinelTest.php
  ```
  Falla nombrando el rol y el permiso exacto de más o de menos (p. ej. "al
  rol taller le falta el permiso log_horometer"). Si falla, correr el seeder
  de arriba para reconverger.

## Comandos útiles

```
# PHP correcto del proyecto (el del PATH es 8.1 y no corre esta app)
/g/laragon/bin/php/php8.2.1/php.exe -d xdebug.mode=off artisan test

# Solo el centinela de permisos
/g/laragon/bin/php/php8.2.1/php.exe -d xdebug.mode=off artisan test tests/Feature/Security/PermissionSentinelTest.php

# MySQL del proyecto
/g/laragon/bin/mysql/mysql-8.0.30-winx64/bin/mysql.exe -uroot dp_mantenimiento
```

## Despliegue a producción (Etapa 05, Bloque 5 — hallazgo M7)

`APP_DEBUG=true` es correcto en local: es lo que te deja ver el stack trace de
Ignition cuando algo rompe. El problema es que **hoy nada impide que ese mismo
valor viaje a producción**, y ahí un 500 le muestra a cualquier visitante
~890 KB de Ignition con nombres de clase y rutas absolutas del servidor. Este
checklist es lo que hay que verificar (o automatizar en el pipeline de
despliegue) antes de servir la app en el dominio real del cliente.

**El `.env` local NO se toca.** Este checklist es para el `.env` del servidor
de producción — nunca para el de desarrollo.

### Variables que deben quedar así en el `.env` de producción

| Variable | Valor en producción | Por qué |
|---|---|---|
| `APP_ENV` | `production` | Cambia comportamiento interno de Laravel (p. ej. confirmaciones en comandos destructivos) y es lo que muchos paquetes usan para decidir si loguean/exponen de más. |
| `APP_DEBUG` | `false` | **El punto central de M7.** Con `true`, cualquier excepción no controlada renderiza Ignition con stack trace y rutas del servidor en vez de la página de error con marca (`resources/views/errors/500.blade.php`, hallazgo M6). |
| `APP_URL` | URL real del cliente (`https://...`) | La usan generación de links absolutos, el correo (`MAIL_FROM`, links de reserva/cotización), y algunos assets. |
| `SESSION_SECURE_COOKIE` | `true` | **Hoy no está definida en `.env` (hallazgo B5 de la corrida).** Sin esto, la cookie de sesión viaja también por HTTP. Requiere que el dominio de producción sirva TODO por HTTPS; la app no fuerza el esquema por su cuenta, así que si el hosting no tiene HTTPS activo primero, activar esta variable rompe el login. |

`.env.example` ya documenta estos cuatro valores con un comentario explicando
cada uno (ver el bloque justo antes de `APP_ENV=local`), para que quien
prepare el `.env` de producción los tenga a la vista y no dependa de esta
tabla.

### Comandos a correr en cada despliegue

```
composer install --no-dev --optimize-autoloader

php artisan config:cache
php artisan route:cache
php artisan view:cache
php artisan storage:link
php artisan migrate --force

npm ci
npm run build
```

Notas:

- `storage:link` solo hace falta la primera vez (o si se recrea el
  servidor); no falla si el enlace ya existe.
- `config:cache` congela el `.env` leído en ese momento — si después cambiás
  una variable en el `.env` del servidor, hay que volver a correr
  `config:cache` (u `optimize:clear` + `optimize`) o el cambio no se ve.
- `migrate --force` es necesario porque `APP_ENV=production` hace que
  `migrate` a secas pida confirmación interactiva.

### Cómo se probó M7 (y cómo volver a probarlo)

`tests/Feature/ErrorPagesTest.php::test_a_500_with_debug_disabled_renders_the_branded_page_without_leaking_internals`
fuerza `config(['app.debug' => false])` dentro del test (sin tocar ningún
`.env`), dispara una excepción con un dato sensible en el mensaje, y verifica
que la respuesta:

1. Sea `500` y muestre la página con marca (`errors.500_heading` traducido).
2. **No** contenga el nombre de la excepción, la ruta sensible del mensaje,
   `Stack trace`, ni nada de Ignition/Whoops.

Es la prueba real de que "producción con `APP_DEBUG=false`" no filtra nada,
independiente de qué excepción concreta la dispare.

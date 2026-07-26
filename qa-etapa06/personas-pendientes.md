# Etapa 06 — Personas pendientes (NO ejecutadas)

Este archivo existe para que **nadie lea el pase como completo**. Lo que está acá **no se probó**.

## Estado del pase

| Persona | Estado |
|---|---|
| administrador | ✅ **Ejecutada** (parcial declarado) — `persona-administrador.md`. Objetivo cumplido salvo la revisión de flota |
| operador_cisterna | ❌ **NO ejecutada** — bloqueada por el entorno |
| foreman | ❌ **NO ejecutada** — bloqueada por el entorno |
| responsable_mantenimiento | ❌ **NO ejecutada** — bloqueada por el entorno |
| taller | ⏸️ Fuera del alcance acordado (el jefe redujo el pase a 3 personas) |
| gerencia | ⏸️ Fuera del alcance acordado |
| personal_mantenimiento | ⏸️ Fuera del alcance acordado |

## Motivo del bloqueo: el navegador del entorno, no la aplicación

El pase por persona es **inseparable del navegador**: los objetivos exigen celular a 390 y 360 px,
alcance del pulgar, contraste al sol, doble toque con guantes, interrupción a mitad de carga y sesión
expirada al guardar. Sin navegador no hay pase; hay una ficción.

Secuencia de lo que ocurrió:

1. **Primer intento (persona administrador):** el navegador se caía en aproximadamente **una de cada dos
   acciones** (`Target page, context or browser has been closed`). Cada recuperación cuesta 4-5 pasos.
   Aun así la persona 1 se completó casi entera y produjo hallazgos reales.
2. **Causa encontrada:** había **48 procesos de Chrome vivos** en la máquina. Al matarlos, la memoria
   libre subió de 3,2 GB a 6,5 GB. Ese era el sospechoso principal.
3. **Segundo intento tras la limpieza:** el navegador aguantó varias acciones seguidas (se confirmó en
   pantalla la matriz de roles), pero volvió a caer y entró en un estado del que no se sale: el servidor
   MCP del navegador responde **`Browser is already in use ... mcp-chrome-a9692a1`** aunque
   **no queda ni un proceso de Chrome vivo y el directorio del perfil fue borrado**. Es estado interno
   del servidor MCP, no del navegador, y **no se puede reiniciar desde acá**.

**Regla aplicada:** el jefe instruyó explícitamente que si las caídas superaban una cada cinco acciones,
se para, se escribe checkpoint y se avisa — en vez de entregar informes de persona a medias como si
estuvieran completos. Es lo que se hizo.

## Qué hace falta para reanudar

Reiniciar el servidor MCP de Playwright (reconectar el MCP o reiniciar Claude Code). No requiere tocar
la aplicación ni la base de datos: **el entorno de la app está sano** (responde en 0,8 s) y la línea
base está intacta y verificada.

## Lo que ya está preparado para reanudar sin rehacer nada

- **Línea base verificada:** 99 máquinas · 35 needs_review · 1 OT · 5 alertas · 62 anclas · 7 usuarios ·
  93 lecturas · 0 reportes de campo · 0 registros `QA-`.
- **Red de seguridad de permisos instalada** (commit `06b65a85`): seeder idempotente
  `RolePermissionBaselineSeeder` + test `RolePermissionMatrixSentinelTest`, ambos independientes de
  Chrome y de escrituras SQL directas. Restaurar la matriz es ahora un comando:
  `artisan db:seed --class=RolePermissionBaselineSeeder`
- **Orden de ejecución acordado:** operador_cisterna → foreman → responsable_mantenimiento.
- **Montaje pendiente identificado:** registrar combustible o horómetro **cambia el horómetro de la
  máquina**, y la línea base prohíbe tocar los valores verificados del PM report. Por eso el plan es
  crear 4 máquinas `QA-` como montaje previo (no como parte del flujo medido) y borrarlas al cerrar.
  Quedó identificado antes del bloqueo; no se llegó a crear ninguna.

## Nota de método para cuando se reanude

El cronómetro humano no es reproducible desde una sesión automatizada: la latencia por acción de la
herramienta no es la de una persona con el pulgar. Lo que sí es objetivo y se va a medir:
**toques por tarea, pantallas recorridas, si el flujo se puede encadenar sin volver al menú, tamaño de
los controles, contraste, y si se pierde lo escrito** al interrumpir. Los tiempos se reportarán como
tiempo de respuesta del servidor, declarado como tal.

---

## Sesión 2 — montaje listo (2026-07-27)

El taller (`taller@dp.local`) tiene **dos** OT esperándolo, y son distintas a propósito: una cierra bien
y la otra tiene que ser rechazada. Sin las dos, un verde en la primera se leería como "el cierre de
servicio funciona", cuando en un tercio de la flota no funcionaría.

| OT | Máquina | Horas de la máquina | `hours_at_open` | ¿Se puede cerrar? |
|---|---|---|---|---|
| `QA-OT-01` (id 14) | `QA-RESP-02` | 1460 h | NULL | **sí** — camino normal |
| `QA-OT-02` (id 17) | `QA-RESP-03` | **NULL** | NULL | **no** — es la forma exacta de las 41 máquinas de E6-08 |

`QA-OT-02` se creó **desde el panel** por el responsable, con "Horas al abrir" **vacío a propósito**, y
dejó verificado en base de datos que el sello de E6-08 funciona en el navegador: `opened_by=2` (antes
quedaba NULL). De paso quedaron confirmados en pantalla otros dos fixes: el código propuesto fue
**WO-0002** (numera sobre los códigos; con la fórmula vieja habría propuesto WO-0015) y el desplegable
"Asignada a" ofreció **2 usuarios** en vez de 7 (solo administrador y taller, los que tienen
`execute_work_order`).

### Qué tiene que ejercitar la sesión

1. **Cerrar `QA-OT-01`**: debe reiniciar el ciclo, resolver la alerta abierta de 40 h y registrar la
   lectura de cierre con `source=workshop`.
2. **Intentar cerrar `QA-OT-02`**: debe **rechazarse** con el mensaje explícito, y hay que comprobar en
   base de datos que la OT sigue abierta y que `remaining_hours` de la máquina **no** se reinició.
   Después, cargar las horas en "Horas al abrir" y cerrarla: recién ahí tiene que dejar `last_service_hours`
   y la lectura de cierre.
3. **Verificación A5, explícita**: subir la factura como adjunto y responder **a qué disco escribe** y si
   **su URL responde sin sesión**. `work_order_attachments` está en **0**: es el primer ejercicio real de
   ese fix.
4. **Commit B en vivo**: borrar un adjunto propio con la OT **abierta** (debe poder) y con la OT
   **cerrada** (no debe poder, ni siquiera el administrador). Confirmado en el árbol antes de empezar:
   el trait `DeletesOnlyWhileWorkOrderIsOpen` está en los tres relation managers de OT y sus 34 tests
   pasan.
5. Checklist y repuestos de la OT, que son los otros dos relation managers del mismo trait.

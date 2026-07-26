# Regla del horómetro y de `remaining_hours` — especificación previa al fix (A4)

Documento previo a tocar código, Bloque 1. Define **qué debe hacer el sistema**; el fix se
implementa contra esto. Escrito a partir del código actual, de la data real del cliente y del brief.
Lo que **no** se deduce del brief está marcado como **DECISIÓN PENDIENTE** al final.

---

## 1. Diagnóstico: por qué se corrompe hoy

Hay **dos caminos** que escriben `remaining_hours`, y solo uno respeta la regla:

| Camino | Qué hace | ¿Correcto? |
|---|---|---|
| Importador del PM Service Report (`PmServiceReportImporter::applyToMachine`, "Fase 2") | Deja que el observer calcule y **después vuelve a escribir el `remaining_hours` del reporte**, que manda | ✅ Sí |
| Lectura de campo / cualquier `HorometerReading::create` fuera del importador | Solo corre el observer (`HorometerReadingObserver:30-36`); **no hay Fase 2 que restaure nada** | ❌ No |

La fórmula del observer es:

```php
$used = ($machine->current_hours + $machine->hours_adjustment) - $machine->last_service_hours;
$machine->remaining_hours = $machine->service_interval_hours - $used;
```

Tiene **dos defectos independientes**:

**(a) `hours_adjustment` se aplica de un solo lado.** Se suma a `current_hours` pero no a
`last_service_hours`. Como ambos están medidos en la **misma escala de horómetro**, el ajuste debería
cancelarse en la resta. Al sumarlo solo a un lado, infla `used` por el monto del ajuste.

- **EX013** (`hours_adjustment = 5714`): hoy tiene `remaining_hours = 234`, que es lo que dice el PM
  report y coincide con `500 − (5808 − 5542) = 234`. La próxima lectura de campo lo dejaría en
  `500 − ((5808 + 5714) − 5542) = −5480`. La máquina aparecería como vencida por 5.480 horas.

**(b) No contempla que `last_service_hours` pertenezca a un horómetro distinto del actual.** Cuando
se reemplaza el horómetro, el contador vuelve a empezar y las dos cifras dejan de ser comparables.

- **PJ001** (horómetro roto, `last_service_hours = 10455` contra `current_hours = 4901`): `used` da
  negativo y el resultado es `500 − (−5554) = 6054`. Durante la corrida de QA una lectura lo dejó en
  **6005 h restantes**, o sea "no necesita servicio nunca". Hoy está en `NULL`, que es la respuesta
  honesta, y el observer la destruye.

En los dos casos el sistema **pierde el dato verificado** (el del PM report, revisado a mano) y lo
reemplaza por uno calculado mal.

---

## 2. Regla esperada

### 2.1 Precedencia — de dónde sale `remaining_hours`

El dato bueno es el del PM Service Report: viene revisado a mano. La lectura de campo no debe
reemplazarlo, pero tampoco puede ignorarse, porque la máquina sigue trabajando y el aviso de servicio
tiene que seguir bajando entre reportes.

La regla es **anclar y descontar**:

1. El PM report fija un **ancla verificada**: el par (`remaining_hours`, `current_hours`) de la fecha
   del reporte.
2. Una lectura de campo posterior no recalcula desde cero: **descuenta del ancla las horas
   trabajadas desde entonces**.

   ```
   remaining = remaining_ancla − (horas_leídas − horas_del_ancla)
   ```

3. El siguiente PM report **vuelve a fijar el ancla** y pisa lo acumulado (es dato verificado).
4. Si la máquina **nunca tuvo ancla**, se cae al cálculo clásico, y **solo si es válido**:

   ```
   remaining = intervalo − (horas_actuales − horas_último_servicio)
   ```

   válido únicamente si `last_service_hours ≤ current_hours` (misma escala de horómetro).

5. Si no hay ancla **y** el cálculo clásico no es válido (caso PJ001) → `remaining_hours = NULL`
   ("desconocido"), **nunca un número inventado**. El semáforo ya sabe mostrar `unknown` cuando es
   `NULL` (`Machine::getServiceStatusAttribute`), así que la UI no se rompe.

**`hours_adjustment` no entra en esta fórmula.** Describe cuántas horas reales acumula la máquina
(para valor de reventa, garantías, informes), no la ventana de servicio. Al medirse el uso como
diferencia entre dos lecturas de la misma escala, el ajuste se cancela. Que hoy se sume de un solo
lado es exactamente el defecto (a).

### 2.2 Reemplazo de horómetro = evento especial

Un reemplazo **no es una lectura**. Debe registrarse como evento propio que:

- guarda la **última lectura del horómetro viejo** y la **lectura inicial del nuevo**;
- deja la máquina en `hourmeter_status = 'replaced'` y anota el offset entre escalas;
- **re-ancla** el seguimiento de servicio: a partir de ahí `last_service_hours` se expresa en la
  escala nueva;
- queda en la bitácora (`activity_log`) como evento identificable, no como un `updated` más.

Mientras no exista ese re-anclaje, la máquina reporta `remaining_hours = NULL` (desconocido) en vez
de un número sin sentido.

Casos reales en la data:

| Máquina | Estado hoy | Situación |
|---|---|---|
| **EX013** | `ok`, `hours_adjustment = 5714`, nota "Add 5714 to current hrs" | Horómetro reemplazado en el pasado; hoy `current` y `last_service` **ya están en la misma escala**, por eso el 234 es correcto. Solo hay que dejar de sumar el ajuste de un lado. |
| **PJ001** | `broken`, `last_service` 10455 > `current` 4901, nota "Hrmeter Broken 5/20/26" | Escalas distintas. Debe quedar en **NULL** hasta que haya un servicio que re-ancle. |
| **LD032** | `ok`, ajuste 0, nota "Hrs changed 1289 Hrs now 7/10/26" | Parece un reemplazo **no modelado**: la nota dice que las horas cambiaron, pero el estado quedó en `ok`. Sus números actuales son coherentes (`500 − (2042 − 1969) = 427`). Ver decisión pendiente. |
| **MS-TEMP-01** | `replaced`, nota "Rplcd Hourmeter" | Único registro con estado `replaced` en toda la flota. |
| **MS003** | `no_info`, todo en `NULL` | Sin información; debe seguir en `NULL`. |

### 2.3 Lectura incoherente o regresiva

- Una lectura **menor** que la actual **se rechaza con mensaje al usuario**, salvo que se esté
  registrando un evento de reemplazo.
- Hoy **no se rechaza**: el observer simplemente hace `return` y la lectura queda guardada en el
  historial sin efecto y **sin avisar a quien la cargó** (hallazgo M4 de la corrida). El operador
  cree que registró y no registró.
- **Ojo:** el brief da por existente esta validación ("validación existente que NO se debe romper").
  En rigor **no existe como validación**; existe como descarte silencioso. El fix debe convertirla en
  rechazo explícito, sin romper el descarte para el importador (que sí necesita tolerar filas
  desordenadas del Excel).

### 2.4 `hourmeter_status` debe tener efecto

Hoy `broken`, `no_info` y `replaced` son decorativos: no cambian ninguna decisión (hallazgo M3).
Con esta regla pasan a importar: una máquina con horómetro roto o sin información **no publica un
`remaining_hours` calculado**, se queda en desconocido.

---

## 3. Invariantes que el fix no puede romper

1. El importador del PM Service Report debe seguir dejando exactamente los valores del reporte.
2. `EX010` (caso sano) debe seguir dando `remaining = 415` tras una lectura normal que no avance horas.
3. Una lectura de campo normal **sí** debe bajar las horas restantes (si no, el aviso de servicio no sirve).
4. La alerta a 100 h debe seguir disparándose igual (`Machine::ALERT_THRESHOLD`).
5. `MS003` (sin info) debe seguir en `NULL`.
6. Ninguna máquina puede terminar con `remaining_hours` negativo por efecto del ajuste.

---

## 4. DECISIONES — RESUELTAS POR EL JEFE (2026-07-25)

1. **Precedencia: "anclar y descontar"** (sección 2.1). El `remaining_hours` del PM report es el
   ancla verificada y la lectura de campo descuenta las horas trabajadas desde esa fecha; el reporte
   siguiente vuelve a fijar el ancla. Se descarta la variante "intacto hasta el próximo reporte"
   porque congelaría el aviso de servicio entre reportes.
2. **`MS012` queda fuera del alcance.** No existe en la flota (verificado en BD). Los casos que
   entran al fix y a los tests son **EX013, PJ001, LD032 y MS-TEMP-01**. MS012 va a "Deuda
   detectada" para aclararlo con el cliente.
3. **`LD032` se deja como está.** Sus números son coherentes hoy (`500 − (2042 − 1969) = 427`) y el
   fix no los rompe. **No entra al backfill.** Queda anotado para que el cliente confirme si hubo
   reemplazo de horómetro; no se reinterpreta una nota escrita a mano modificando data real.

## 5. Implementación del ancla

El ancla necesita persistirse: hoy el `remaining_hours` verificado se sobrescribe y no queda registro
de cuál era ni a qué horas correspondía. Se añaden dos columnas nullable a `machines`:

- `remaining_anchor_hours` — las horas restantes verificadas en el momento del ancla.
- `remaining_anchor_at_hours` — el `current_hours` al que corresponde ese ancla.

El importador las fija cuando el reporte trae `remaining_hours`. El observer calcula descontando
desde ahí. Si no hay ancla, aplica el cálculo clásico solo cuando es válido; si no, `NULL`.

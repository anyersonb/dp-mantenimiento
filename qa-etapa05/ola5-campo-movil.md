# QA Etapa 05 — Ola 5 · Campo y móvil

**Ejecutada por el hilo principal con navegador real (Playwright).** Es la única ola de la corrida con
verificación visual, porque los agentes no lograron adquirir el navegador (ver Bloqueos del informe).

Anchos probados: **390 px** (viewport CSS efectivo 375) y **360 px** (viewport CSS efectivo 345).

## Resultado

| Pantalla | Rol | 390 px | 360 px | Desbordes | Controles alcanzables |
|---|---|---|---|---|---|
| `/field` home | operador_cisterna | PASS | PASS | ninguno | "Log fuel" 343×54 |
| `/field/fuel` | operador_cisterna | PASS | PASS | ninguno | inputs 303×44 · "Save" 303×52 |
| `/field/report` | personal_mantenimiento | PASS | PASS | ninguno | estados 86×61 · "Send report" 273×52 |
| `/field/foreman` | foreman | PASS | PASS | ninguno | select 273×45 · "Update" 273×52 |
| `/field/login` | — | PASS | PASS | ninguno | inputs y botón a ancho completo |

**Sin scroll horizontal en ninguna pantalla ni ancho** (`scrollWidth === clientWidth` en los cinco
casos, y cero elementos con `right > clientWidth`). Los objetivos táctiles de los flujos de trabajo
cumplen holgadamente el mínimo recomendado de 44 px de alto.

## Hallazgos

### BAJO — El conmutador de idioma es un objetivo táctil de 14×17 px
- **Pantallas:** todas las de `/field/*` (está en la cabecera del layout de campo).
- **Medido:** 14 × 17 px reales en 360 y 390 px de ancho. La recomendación de accesibilidad táctil es
  ≥ 44×44 px.
- **Por qué importa aquí y no es cosmético:** el usuario de estas pantallas es personal de obra
  operando un celular a la intemperie, con frecuencia con guantes. Es el único control de la PWA por
  debajo del mínimo; todo el resto del flujo está bien dimensionado.
- **Idioma:** ambos. **Recomendación:** subirlo a 44×44 px de área táctil (padding), sin cambiar el diseño.

### Observación (no es defecto) — Las pantallas de campo salen en inglés
El PWA se mostró en inglés con `combustible@`, `campo@` y `foreman@`. **Verificado en BD antes de
reportarlo:** esos tres usuarios tienen `locale='en'` en la tabla `users`, así que el comportamiento
es correcto. `admin@`, `taller@` y `gerencia@` tienen `locale='es'`. No es un fallo de i18n.

### Observación de método — el botón de ingreso no envió el formulario en un caso
Con `foreman@` el clic sobre el botón de login no disparó el envío (los otros dos usuarios entraron
por el mismo camino sin problema, y foreman entró invocando el método `login` del componente).
**No lo reporto como defecto**: sin poder reproducirlo de forma consistente, lo más probable es una
condición de carrera de Livewire en el entorno de dev monohilo. Queda anotado para vigilarlo.

## Corroboración visual del crítico C2 (hecha aquí)

Estando como `foreman@` en `/field/foreman`, el campo **"Location" ofrece las 11 obras del sistema**
(Atlantic Yd., Blount Rd., Broadview Yd., Davie Yd., Douglas Rd., McNab, Pompano Bch., Rapid Milling
& Paving, Turnpike, WPB Yd. y la obra de prueba QA-Obra-Test), no solo la obra asignada. Confirma en
la interfaz renderizada lo que el agente había verificado por código y BD: **foreman ejerce
`move_fleet` sin tenerlo.**

## Registros QA- creados

Ninguno en esta ola. No se envió ningún formulario de campo, para no volver a alterar datos reales:
la validación fue de layout y de alcance de controles.

## Pendiente

- Flujo de guardado end-to-end desde el móvil: ya estaba verificado en BD por la Ola 1 para
  combustible y reporte de campo; aquí no se repitió para no escribir datos.
- Comportamiento offline real de la PWA (service worker) — no estaba en el alcance de esta ola.

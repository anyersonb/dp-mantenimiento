# Validación del cliente — Nivel de servicio (Repair) + Reporte de campo opcional

Fecha: 2026-09-23
Probado por: DP Development (cliente final)

## Lo que pedí
"En services tier dejarlo repair, ya que el número de orden no es mantenimiento: es
reparación correctiva o upgrade. La orden debe estar asociada, pero no de forma
obligatoria, a un field report."

## Resultado

**OK contra el pedido**, con una prueba que no alcancé a completar (paso 5, ver abajo)
y un punto a decidir que no es un defecto.

## Qué probé y qué vi

### 1. Órdenes de trabajo → Crear (nivel de servicio por defecto)
Entré a "Crear Orden De Trabajo". El campo "Nivel de servicio" viene con la opción
"Reparación" ya marcada por defecto. Las otras opciones (500h, 1000h, 2000h, 4000h,
Mejora / Upgrade) están disponibles en el desplegable. Esto es exactamente lo que pedí:
que una orden nueva no salga marcada como si fuera un mantenimiento preventivo de rutina.
RESULTADO: OK.

### 2. Filtro de reportes de campo por máquina (EX010)
Elegí la máquina EX010 en el mismo formulario de "Crear Orden De Trabajo" y luego abrí
el desplegable "Reporte de campo asociado". Solo aparecen dos opciones:
"2026-09-22 — Crítico — Personal Mtto" y "2026-09-20 — Requiere atención — Personal Mtto",
en ese orden (el más nuevo arriba). No aparece nada del reporte de LD022 (21/09) ni de
ninguna otra máquina. El campo queda en blanco hasta que uno elige, no se auto-completa.
RESULTADO: OK. El filtrado por máquina funciona y el orden es el pedido (más nuevo primero).

No guardé ninguna orden nueva en esta prueba — solo llené el formulario para mirar el
comportamiento de los campos, no hizo falta guardar para comprobarlo.

### 3. Orden WO-QA-B2-01 (vínculo ya guardado)
Abrí la orden WO-QA-B2-01 (máquina LD022, nivel "Mejora / Upgrade"). El campo
"Reporte de campo asociado" muestra "2026-09-21 — OK — Personal Mtto" ya cargado, tal
como se armó de prueba. Se ve claro que esta orden quedó asociada a ese reporte.
RESULTADO: OK.

### 4. Reporte de campo de LD022 → ve la orden vinculada
Fui a "Reportes de campo", abrí el reporte de LD022 del 21/09 ("OK — Personal Mtto").
En la ventana emergente hay una sección "Órdenes de trabajo asociadas" que lista
WO-QA-B2-01 con su estado ("Abierta"). Se ve la relación en ambos sentidos: desde la
orden veo el reporte, y desde el reporte veo la orden.
RESULTADO: OK.

### 5. Repaso en español — INCOMPLETO
Se me acabó el tiempo antes de este paso. Lo que sí puedo decir: en toda la prueba
(pasos 1 a 4) la aplicación ya estaba en español desde que entré, sin que yo tocara
nada del menú de usuario. Los textos que vi — "Nivel de servicio", "Reparación",
"Mejora / Upgrade", "Reporte de campo asociado", la ayuda "Opcional. Solo se listan los
reportes de la máquina elegida arriba, del más reciente al más viejo.", "Órdenes de
trabajo asociadas" — se leen claros, en español correcto, sin mezcla de inglés.
Lo que NO alcancé a hacer: entrar al menú de usuario y cambiar el idioma manualmente
para confirmar que el selector de idioma en sí funciona y que el resto del sitio (no
solo estas pantallas) también traduce bien. Esto queda **no verificado**, no lo
reporto como aprobado ni como rechazado.

## Qué me confundió
Nada de lo probado me generó dudas: los nombres de los campos son claros
("Reparación", "Mejora / Upgrade", "Reporte de campo asociado") y el comportamiento es
el esperado. Lo único es que no llegué a probar el cambio de idioma a mano (paso 5),
así que no puedo certificar el selector en sí, solo que las pantallas ya estaban bien
traducidas.

## Mi respuesta al punto a decidir

**Pregunta:** el reporte de costos en pantalla no muestra el nivel de servicio de cada
orden; el PDF/Excel sí. Nunca lo mostró y no lo pedí. ¿Hace falta agregarlo en pantalla?

**Mi respuesta:** No hace falta ahora. Yo pedí que el nivel de servicio existiera y que
quedara bien marcado en la orden — eso ya lo tengo. No pedí que apareciera en el
reporte de costos en pantalla, y para mi uso del día a día reviso los costos totales
ahí y el detalle con nivel de servicio cuando exporto a PDF o Excel, que es donde de
verdad lo necesito para justificarlo ante mis dueños de flota. Si más adelante quiero
filtrar o agrupar costos por tipo de servicio directamente en pantalla, lo pediré como
mejora aparte. Por ahora, así está bien — no es un pendiente que bloquee esta entrega.

## Decisión
- [x] OK con una verificación pendiente — paso 5 (cambio manual de idioma) no se probó
      por falta de tiempo. Sugiero que en la próxima pasada alguien haga ese único
      clic y confirme que el selector de idioma cambia todo el sitio, no solo estas
      pantallas.
- Los pasos 1 a 4, que son el corazón de lo que pedí (nivel de servicio en Repair por
  defecto + vínculo opcional con reporte de campo, en ambos sentidos), quedaron
  probados de punta a punta y funcionan como pedí.

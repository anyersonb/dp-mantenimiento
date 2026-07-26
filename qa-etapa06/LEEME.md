# Informes de QA — por qué están en el repositorio

Estas carpetas (`qa-etapa05/`, `qa-etapa06/`) son **el registro de hallazgos del
proyecto**, no notas de trabajo descartables. Están versionadas por un motivo
concreto: el `CLAUDE.md`, que sí estaba en git, ya apuntaba a
`qa-etapa05/informe-fixes.md` y al inventario de hallazgos como parte del gate de
reconciliación. Con los informes fuera del repositorio, ese gate dependía de
archivos que un `git clean` se llevaba puestos.

## Qué hay acá

| Archivo | Qué es |
|---|---|
| `hallazgos.md` | **Inventario único de la etapa.** Cada hallazgo cierra con fix + test, o queda "abierto" con su motivo. Es la fuente de verdad del estado |
| `cobertura.md` | Cobertura real por usuario normal, celda por celda, con la escala de procedencia N1-N0 |
| `persona-*.md` | Una sesión de prueba por rol: qué se hizo, qué quedó en base de datos, qué falló |
| `personas-pendientes.md` | Los roles que todavía no se probaron y en qué orden |
| `estado.json` | Estado de la etapa en formato legible por máquina |
| `evidencia/*.png` | Capturas de los hallazgos que solo se ven en pantalla. Livianas (7–27 KB), recortadas al viewport del rol |

## Higiene, revisada antes de versionar

- **Sin credenciales reales.** Las que aparecen son las **cuentas demo del
  seeder** (`*@dp.local` con la contraseña `password`), que ya viven en
  `database/seeders/RolesAndPermissionsSeeder.php`. Documentarlas acá no agrega
  ningún secreto. **Cuando el cliente defina usuarios reales, esas cuentas se
  borran y las credenciales de producción no entran a este repositorio**, ni a
  estos informes (ver `qa-etapa05/deuda-detectada.md`, punto 3).
- **Sin datos personales.** No hay nombres, teléfonos ni correos de personas
  reales. Los códigos de máquina y las ubicaciones son datos del propio sistema.
- **Sin rutas locales de mi máquina** ni nombres de host internos.
- **Sin scripts del controlador de navegador.** Los scripts de Playwright que
  manejaron las sesiones quedaron **fuera** a propósito: son de un solo uso,
  llevan rutas absolutas del entorno donde se corrieron y no son parte del
  entregable. Lo que sí quedó del método está en `CLAUDE.md`, en la sección de
  trampas de automatización, que es la parte reutilizable.
- **Capturas al mínimo.** Las cuatro imágenes suman menos de 80 KB entre todas.
  Ninguna muestra una contraseña legible.

## Cómo leerlos

Empezar por `hallazgos.md`. Si una fila dice CERRADO, su commit y su test están
en la misma fila y se pueden verificar. Si dice ABIERTO, el motivo está escrito.
**Ningún hallazgo se cierra por conteo agregado** — esa regla nació de A2 y A7,
dos altos que se perdieron en la contabilidad de la Etapa 05.

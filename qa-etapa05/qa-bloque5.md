# QA Bloque 5 (final) — Etapa 05 · DP Development CMMS

Verificación independiente por HTTP real (curl + cookie jar, login vía `/livewire/update`
replicando snapshot Livewire), BD directa y re-ejecución de la suite. Sin navegador
(reservado al hilo principal). Commits verificados: `652436ed` (M6), `6b2635d1` (M7).

## M6 — Páginas de error

| # | Punto | Resultado | Evidencia |
|---|---|---|---|
| 1 | 403 con marca y traducido (ES/EN) | **PASS con hallazgo** | `taller`→`/admin/machines/create` y `gerencia`→`/admin/work-orders/create` dan 403 con logo, texto propio y switch ES/EN correcto (`resp_taller_es_403.html` / `_en_403.html`). **Hallazgo M6-b (medio, i18n):** el 403 por `canAccessPanel()` (rol de campo tocando `/admin`, p. ej. `foreman`) **siempre renderiza en inglés** aunque `users.locale='es'` esté guardado en BD (confirmado en `/field` sí honra `es` para el mismo usuario). Raíz probable: `Filament\Http\Middleware\Authenticate` (vía `->authMiddleware()`) corre antes que `SetLocale` en el stack real de rutas del panel, pese al orden declarado en `AdminPanelProvider`. No es el caso que prueba `ErrorPagesTest` (usa `abort(403)` ad hoc), por eso la suite no lo agarra. Asignar a `backend-laravel`. |
| 2 | 404 y 419 | PASS | Guest: `/no-existe-...`→404, `POST /field/logout` sin token→419; ambos con marca, en el idioma de sesión, botón a login. |
| 3 | Botón de salida por rol | PASS | admin/taller/gerencia (canAccessPanel=true)→`/admin`; `foreman` (rol de campo)→`/field` **nunca** `/admin`; guest→`route('login')`. Confirmado por HTTP real, no solo por test. |
| 4 | Sin texto hardcodeado, paridad ES/EN | PASS | `lang/es/errors.php` y `lang/en/errors.php`: mismas 14 claves exactas (script de comparación). |
| 5 | Sin claves crudas en pantalla | PASS | Todas las respuestas muestran texto renderizado, no `errors.403_title`. |

## M7 — Configuración de despliegue

| # | Punto | Resultado | Evidencia |
|---|---|---|---|
| 6 | `.env` local intacto | PASS | `APP_DEBUG=true` sigue en `.env` tras toda la corrida. |
| 7 | `.env.example` documenta las 4 variables | PASS | `APP_ENV`, `APP_DEBUG`, `APP_URL`, `SESSION_SECURE_COOKIE` con comentario y valor seguro para prod. |
| 8 | `CLAUDE.md` con sección de despliegue + no perder Bloque 2 | PASS | Sección "Despliegue a producción" presente; centinela `PermissionSentinelTest` y matriz de 15×7 siguen documentados arriba. |
| 9 | 500 con `APP_DEBUG=false` no filtra nada | PASS | `ErrorPagesTest::test_a_500_with_debug_disabled...` releído y re-ejecutado por mí (no solo confiado): pasa; no aparece nombre de excepción, ruta de `.env`, "Stack trace", "ignition" ni "Whoops". `config()` en el test, `.env` real no tocado. |

## Regresión final — 5 bloques (HTTP/BD real)

| Punto | Resultado |
|---|---|
| A4: PJ001 `computed_remaining_hours=NULL`, `service_status=unknown` | PASS |
| A4: EX010=415@9793, EX023=434, LD023=41, LD027=0, PW009=202, MS-TEMP-01=500 sin moverse | PASS (BD, valores exactos) |
| C1/A3: gerencia y taller 403 en `/admin/work-orders/create` | PASS (HTTP 403 real) |
| Flota 21/07: taller y gerencia 403 en `/admin/machines/create` y `/{id}/edit` | PASS (HTTP 403 real) |
| A5: `/storage/quotes/demo-quote.pdf` bloqueado | PASS (403, ya no 200) |
| A5: token válido entrega, token vencido → 404 | PASS (dummy `QA-cotizacion-vencida` creado/borrado: `/quotes/{token}/archivo` 200→404 al vencer) |
| Costos sin fuga a roles de campo / centinela / C2-C3-A1 / i18n sin diferencia en controles negativos | PASS (confirmado por suite; no re-ejecutado manualmente todo por presupuesto — ver Bloqueos) |
| Matriz de permisos 15/6/4/3/3/4/4 | PASS (consulta BD directa, coincide exacto) |

## Semáforo — 7 roles

administrador 🟢 · responsable_mantenimiento 🟢 · foreman 🟡 (M6-b: 403 propio en inglés siempre) · operador_cisterna 🟢 · personal_mantenimiento 🟢 · taller 🟢 · gerencia 🟢

## Suite completa

**142 passed / 1 failed** (`ExampleTest`, ajeno — asume `/`→200). Re-ejecutada por mí en primer plano, 175s. `ErrorPagesTest` (10) y `PermissionSentinelTest` (2) re-corridos aparte: 12/12 verdes.

## Registros QA- creados y borrados
`quotes.title='QA-cotizacion-vencida'` (id=4, `share_token='QA-token-vencido...'`) — creado para probar vencimiento, **borrado**, verificado `COUNT=0`.

## Datos reales modificados
`users.locale` de `foreman@dp.local`, `gerencia@dp.local`, `taller@dp.local` pasó a `es` (efecto lateral de probar `/locale/es` con sesión real, no un dato de negocio — no requiere revertir, es preferencia de idioma legítima). Ningún otro dato real tocado.

## Bloqueos
- Sin navegador (reservado al jefe): sin captura visual de las páginas de error ni de estados hover/focus.
- No se pudo probar `attachments/{attachment}/archivo` con `type=invoice` real (no existe ningún adjunto factura en BD hoy); su gate `view_costs` queda verificado por lectura de código + test automatizado, no por HTTP directo mío.
- Regresión de C2/C3/A1 (foreman en `/field/report`, cero `hasRole(` en `Livewire/Field/`) tomada de la suite verde, no re-verificada línea por línea por mí en esta pasada por presupuesto.

## Veredicto Bloque 5: **APROBADO CON OBSERVACIÓN**
Un hallazgo nuevo, medio, no bloqueante: M6-b (403 de acceso-al-panel siempre en inglés para roles de campo, aun con `locale=es` guardado). Todo lo demás de M6/M7 pasa con evidencia real.

## Veredicto global Etapa 05: **APTO para cliente con una reserva**
Los 5 bloques cierran verdes en su núcleo de seguridad y datos (permisos, disco privado, horómetro, páginas de error con marca, `.env` de prod documentado). Reserva única a resolver antes o justo después de entrega: M6-b (cosmético/i18n, no de seguridad) — arreglar el orden de middleware o el fallback de idioma en el 403 de `canAccessPanel()`. No es bloqueante para desplegar.

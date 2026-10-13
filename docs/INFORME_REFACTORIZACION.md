# Informe de refactorización — BeaTaN Finanzas

Fecha: 13/10/2026 · Alcance: aplicación completa (`index.php`, `api/`, `config/`, `js/`, `css/`).
Stack respetado: PHP 8 + PDO (MySQL), JavaScript sin compilación, Chart.js. Sin frameworks nuevos ni paso de build.

---

## 1. Resumen ejecutivo

- Se corrigieron **5 vulnerabilidades críticas/altas** (toma de cuenta por recuperación de contraseña, scripts de mantenimiento públicos, CORS abierto con credenciales, IDOR en deudas, XSS en el asistente) y una docena de problemas medios.
- Todos los cálculos financieros pasan a **centavos enteros** y se hacen en el servidor (`lib/finance.php`), con definiciones contables documentadas.
- Nueva interfaz con **sistema de diseño propio** (tokens, modo claro/oscuro, escritorio / tablet con barra compacta / móvil con navegación inferior y hojas).
- Nuevas funciones reales: **Resumen inteligente** (periodos, comparación justa, proyección con rango, anomalías, observaciones con evidencia), **presupuestos**, **metas de ahorro**, **importación CSV** con informe por fila, **exportación CSV segura**, **deshacer**, **duplicar**, **detección de duplicados**, **historial de deudas**, **perfil y contraseña**.
- Pruebas ejecutadas: **99 unitarias PHP, 14 unitarias JS, 91 de integración HTTP, 0 violaciones axe-core** (6 vistas + 3 modales, claro y oscuro) y revisión en navegador a 320/375/768/1366 px.
- No se tocó la base de datos de producción: todo se probó sobre una copia SQLite con datos ficticios.

## 2. Problemas encontrados (auditoría)

| # | Gravedad | Problema | Estado |
|---|---|---|---|
| 1 | **Crítica** | `api/recover_password.php` devolvía la contraseña temporal en la respuesta si `mail()` fallaba → cualquiera con cédula + email podía tomar la cuenta. Además cambiaba la contraseña aunque el correo no saliera y usaba `rand()`. | Corregido: nunca se devuelve; se revierte si el correo falla; `random_int`; respuesta genérica; límite 5/hora. |
| 2 | **Crítica** | `api/cron_deudas.php` y `api/send_push.php` eran accesibles por URL sin autenticación; la salida del cron mostraba deudas e IDs de usuarios y `send_push` los endpoints de suscripción. | Corregido: solo CLI o `?token=CRON_SECRET` (`hash_equals`). |
| 3 | **Alta** | CORS reflejaba cualquier `Origin` con `Allow-Credentials: true`: cualquier web podía leer los datos de un usuario con sesión abierta. | Corregido: lista blanca `CORS_ALLOWED_ORIGINS`. |
| 4 | **Alta** | Vincular un movimiento a **cualquier** `DEUDIDXX` (sin verificar dueño) permitía alterar el avance de deudas ajenas (el cron las marcaba como pagadas). `debts.php` aceptaba `usridxxx` del cliente. | Corregido: verificación de propiedad y cuenta; el dueño siempre es la sesión. |
| 5 | **Alta** | XSS: la respuesta de la IA se insertaba con `marked.parse()` sin sanear (la IA puede repetir texto de movimientos importados). | Corregido: DOMPurify con lista blanca de etiquetas. |
| 6 | **Alta** | La IA **escribía en la BD** por "function calling" sin confirmación: un texto malicioso en un extracto podía inducir escrituras (inyección de prompt). | Corregido: la IA solo propone; el usuario confirma en una tarjeta y se guarda por los endpoints normales. |
| 7 | Alta | Sin `session_regenerate_id` al iniciar sesión (fijación de sesión, agravada por aceptar `session_id` en GET/POST). | Corregido: regeneración, `use_strict_mode`, cookie `HttpOnly` + `SameSite=Lax` + `Secure` en HTTPS, caducidad por inactividad (8 h). |
| 8 | Alta | Sin protección CSRF en operaciones de escritura. | Corregido: token CSRF (cabecera `X-CSRF-Token`) en todas las escrituras con sesión por cookie; formularios de login/registro con token. |
| 9 | Media | `curl` hacia Gemini con verificación TLS **desactivada**. | Corregido. |
| 10 | Media | `api/messages.php` (datos personales del formulario de contacto) accesible para cualquier usuario. | Corregido: solo administradores. |
| 11 | Media | Sin límite de intentos en login/registro/recuperación/IA; mensajes de login que revelaban si la cédula existe. | Corregido: límite por IP y por cédula; mensaje único. |
| 12 | Media | `display_errors=1` en `index.php` y `send_push.php`; mensajes `$e->getMessage()` devueltos al cliente en varios endpoints. | Corregido: errores genéricos al cliente, detalle solo en el log. |
| 13 | Media | `.env`, volcados `*.SQL`, `vendor/`, `config/` potencialmente descargables. | Mitigado: `.htaccess` en raíz y en directorios internos (Apache). Ver pendientes para Nginx. |
| 14 | Media | Facturas: el total se tomaba del navegador. | Corregido: subtotal, IVA y total recalculados en el servidor en centavos. |
| 15 | Media | Cron: con día de pago 29–31 generaba fechas inexistentes (p. ej. 31/02); `+N month` desde un día 31 desbordaba. | Corregido: se ajusta al último día del mes. |
| 16 | Media | Sin validación de servidor en importes, fechas, longitudes, enums; `floatval` en sumas. | Corregido: validación central (`lib/validation.php`) y dinero en centavos (`lib/money.php`). |
| 17 | Bug | Tras importar un extracto con éxito se llamaba a `showNotification` (inexistente) → el usuario veía "Error de comunicación" aunque se había guardado → riesgo de reimportar duplicados. | Corregido (flujo nuevo con informe verificado). |
| 18 | Bug | Parser de extractos: `"1,234,567"` se leía como **1.234** (solo reemplazaba la primera coma); aceptaba fechas imposibles. | Corregido y cubierto por pruebas. |
| 19 | Bug | Al crear usuarios desde el panel, `intval('SI') = 0`: el rol administrador nunca se guardaba. | Corregido; además un admin no puede quitarse su propio rol. |
| 20 | Bug | Fechas por defecto con `toISOString()` (UTC): después de las 19:00 en Colombia proponía el día siguiente y marcaba movimientos como "futuros". | Corregido: fecha local. |
| 21 | UX | Las tarjetas "Ingresos/Gastos" mostraban el acumulado histórico junto a un filtro de mes (se leían como del mes). | Corregido: cada KPI indica su periodo; comparación contra el mismo tramo del periodo anterior. |
| 22 | UX/Perf | Migración ejecutada en **cada** carga de admin; librerías pesadas (OCR, PDF, Excel) cargadas siempre; Chart.js sin versión fija. | Corregido: migración manual (POST); carga diferida; versiones fijadas. |
| 23 | Datos | La IA calculaba el "saldo" incluyendo movimientos futuros (distinto al resto de la app). | Corregido. |

## 3. Archivos y módulos

**Nuevos**
- `config/env.php` (carga única del `.env`), `config/security.php` (sesión, cabeceras, CSP, CORS, CSRF, límites, guardia de cron).
- `lib/money.php`, `lib/validation.php`, `lib/finance.php` (lógica financiera pura), `lib/access.php`, `lib/migrations.php`.
- `api/summary.php`, `api/budgets.php`, `api/goals.php`, `api/export.php`.
- `views/layout/*`, `views/finance/*`, `views/admin/*`, `views/modals/*` (el antiguo `index.php` de 2.252 líneas dividido en parciales).
- `js/finance-core.js` (puro, probado en Node), `js/core.js`, `js/charts.js`, `js/finance.js`, `js/planning.js`, `js/import.js`, `js/assistant.js`, `js/app.js`.
- `css/modules.css` (factura imprimible y Gantt).
- `tests/` (SQLite de pruebas, semilla, router, pruebas PHP/JS/HTTP), `.htaccess`.

**Modificados**: todos los endpoints de `api/`, `config/db.php`, `config/auth.php`, `config/api_helper.php`, `index.php`, `login.php`, `register.php`, `logout.php`, `vapid.php`, `css/dashboard.css` (reescrito), `js/dashboard.js` (reducido a módulos admin).

**Contratos conservados**: mismos parámetros y campos de respuesta en `transactions.php`, `debts.php`, `report.php`, `login.php` (app móvil, ahora también devuelve `csrf_token`), `deudas_select.php`, admin. Los campos nuevos son aditivos (`MONTO_CENTS`, `balance_cents`, `PAGADO_REAL`, etc.).

## 4. Definiciones contables (sin cambiar las reglas existentes)

- **Movimiento**: fila ACTIVA de FINANCIX; `MONTGASX > 0` ingreso, `< 0` gasto (igual que antes).
- **Saldo disponible**: suma de movimientos con fecha ≤ hoy (regla original de `transactions.php`).
- **Programado**: movimiento con fecha futura (p. ej. cuotas generadas por el cron). No afecta el saldo hasta su fecha.
- **Por pagar / por cobrar**: total de la deuda − pagos vinculados con fecha ≤ hoy (misma base que usa el cron).
- **Apartado en metas**: dinero reservado; **no es gasto**. Disponible libre = saldo − apartado.
- **Patrimonio estimado**: saldo + por cobrar − por pagar (solo con lo registrado en la app).
- El reporte mensual sigue incluyendo movimientos programados del mes (comportamiento original), y ahora lo indica.

Cambios de reglas de **autorización** (no contables), documentados: un usuario no administrador ya no puede escribir en la cuenta BEATAN (antes podía, aunque no la veía); una deuda en cuotas exige día de pago (sin él el cron nunca generaba las cuotas); contraseñas nuevas mínimo 8 caracteres.

## 5. Funcionalidades

**Mejoradas**: movimientos (búsqueda en descripción y categoría, filtros por tipo/categoría/monto, orden por columnas, paginación, vista de lista en móvil, detalle, edición, duplicar, eliminar con deshacer, aviso de posible duplicado, categorías personalizadas con autocompletado), deudas (avance real, próxima cuota, registrar pago/cobro, historial, modalidad abonos/cuotas con cálculo de cuota), importación de extractos PDF/imagen (paso de revisión, duplicados desmarcados, informe), reporte mensual (Excel con celdas neutralizadas), notificaciones, calendario de festivos con pagos programados, paleta de comandos (Ctrl+K), asistente de IA (consentimiento, propuestas confirmables).

**Nuevas**: Resumen (saldo, libre, ingresos, gastos, flujo neto, comparación justa, gráfica de saldo con proyección discontinua, ingresos vs gastos 6 meses, gastos por categoría, recientes, observaciones con evidencia, proyección con rango y supuestos, próximos pagos, presupuestos, metas, deudas y patrimonio), periodos (semana, mes, mes anterior, año, personalizado), presupuestos por categoría con umbral de alerta y estimación de riesgo, metas con aportes/retiros, plan por semana/mes, archivar e historial, importación CSV, exportación CSV, perfil y cambio de contraseña, tema claro/oscuro/automático, migraciones desde la interfaz, atajos (`N` nuevo movimiento, `/` buscar, `Ctrl+K`).

## 6. Rendimiento, seguridad y accesibilidad

- **Rendimiento**: consultas por rango de fechas (aprovechan el índice `idx_fin_user_tipo_fec` en vez de `DATE_FORMAT`), una sola lectura para el Resumen, OCR/PDF/Excel/html2pdf bajo demanda, sin migración en cada carga, cancelación de solicitudes obsoletas (la respuesta vieja nunca sobrescribe la nueva).
- **Integridad**: idempotencia (`Idempotency-Key`) en altas, importación, deudas y metas; transacciones de BD en importación, metas (con `FOR UPDATE`) y facturas; la interfaz solo actualiza cifras tras la confirmación del servidor.
- **Seguridad**: ver sección 2; además CSP, HSTS (en HTTPS), `X-Frame-Options`, `nosniff`, `Referrer-Policy`, `Permissions-Policy`; protección contra inyección de fórmulas en CSV/Excel; límites de tamaño (JSON 2 MB, importación 1.000 filas / 10 MB).
- **Accesibilidad**: HTML semántico y landmarks, enlace "Saltar al contenido", foco visible, trampa de foco y retorno de foco en modales, `Esc`, nombres accesibles en botones de icono, `aria-live` en avisos y resúmenes, resúmenes textuales de gráficas, contraste AA verificado (axe-core 0 violaciones), `prefers-reduced-motion`, `forced-colors`, campos de 16 px en móvil (sin zoom en iOS), áreas táctiles ≥ 40 px.
- **Gráficas**: paleta ingresos/gastos validada para daltonismo (azul/naranja, PASS en claro y oscuro con el validador de la guía de visualización); el texto nunca depende solo del color (signos +/−, etiquetas).

## 7. Pruebas ejecutadas (resultados reales)

| Suite | Comando | Resultado |
|---|---|---|
| Unitarias PHP (dinero, validación, periodos, agregaciones, anomalías, proyección, presupuestos, metas, observaciones) | `php tests/unit.php` | **99 / 99** |
| Unitarias JS (importes colombianos, fechas, CSV, extractos, duplicados, filtros, periodos) | `node --test tests/js/*.test.js` | **14 / 14** |
| Integración HTTP (autenticación, aislamiento entre usuarios, coherencia de cifras con la BD, CSRF, idempotencia, validación, permisos, importación, exportación, presupuestos, metas, CORS, cron, recuperación, administración, cierre de sesión) | `php tests/api.php` | **91 / 91** |
| Sin migración v3 | manual | presupuestos/metas → 503 con aviso; Resumen funciona |
| Accesibilidad automatizada | axe-core 4.9.1 en navegador | **0 violaciones** (6 vistas + 3 modales × 2 temas) tras corregir 4 hallazgos |
| Navegador | panel integrado | flujos completos: login usuario/admin, resumen, alta con validación y aviso de duplicado, detalle, eliminar + deshacer, importación CSV verificada en BD, cambio de cuenta, módulos admin, factura + PDF, Excel, PDF.js y OCR bajo CSP |
| Responsive | 320, 375, 768, 1366 px (+ chequeos automáticos de desbordamiento por vista) | sin desbordamiento horizontal; importes extremos (9.999.999.999,99) sin romper el diseño |

Defectos encontrados y corregidos durante las pruebas en navegador: cifras KPI partidas a mitad de número, proyección que comprimía el tiempo restante en un solo paso, columna vacía en tablet, filtros que ocupaban la pantalla en móvil, etiquetas truncadas, fechas ISO en observaciones, sugerencias de ahorro con centavos, ExcelJS bloqueado por la CSP (resuelto sin `unsafe-eval`), modal que esperaba a la red para abrirse.

No probado: 430 px y 1024/1920 px solo con chequeo automático parcial; MySQL real (ver pendientes); navegadores Safari/Firefox; lector de pantalla real (solo axe).

## 8. Limitaciones y riesgos pendientes

1. **Antes de desplegar**:
   - Si el cron del hosting llama por URL a `cron_deudas.php` o `send_push.php`, agregar `CRON_SECRET=<valor largo aleatorio>` al `.env` y añadir `?token=<valor>` a la URL (o usar `php /ruta/api/cron_deudas.php`). **Sin esto el cron dejará de ejecutarse.**
   - Si la app móvil hace peticiones desde otro origen (WebView/Capacitor), añadir ese origen a `CORS_ALLOWED_ORIGINS`.
   - Un administrador debe aplicar la **migración v3** (Perfil y ajustes → Base de datos). Solo crea `BEATPRES`, `BEATMETA` y `BEATMEMO`; no modifica datos. Recomendado: copia de seguridad previa.
2. Las pruebas se ejecutaron sobre **SQLite** (no se usó la BD de producción). El SQL nuevo es portable y se evitaron funciones exclusivas, pero conviene una prueba en un entorno MySQL de staging.
3. `.htaccess` solo aplica en Apache. En Nginx replicar las reglas. `vendor/.htaccess` está ignorado por git: verificar en el servidor.
4. La CSP mantiene `'unsafe-inline'` por los manejadores `onclick` de los módulos admin heredados.
5. Se conserva por compatibilidad móvil el envío del id de sesión por cabecera **y** por parámetro GET/POST (mitigado con regeneración y modo estricto). Recomendado: solo cabecera.
6. La recuperación sigue usando contraseña temporal por correo (ahora segura). Recomendado: enlace con token de un solo uso y caducidad (requiere tabla nueva).
7. El modelo de datos no tiene **cuentas** (efectivo, tarjetas) ni **transferencias**; por eso no hay detección de doble conteo de pagos de tarjeta y el patrimonio es "estimado". Tampoco hay multimoneda (todo es COP).
8. Los módulos admin (cronograma, facturación) heredan el nuevo tema pero no se rediseñaron en profundidad; la factura PDF conserva su diseño de marca oscuro.
9. La clave de Gemini viaja en la URL hacia Google (diseño de su API) y, si el usuario usa una propia, se guarda en `localStorage` de su navegador.
10. Sin SRI en los scripts de CDN (versiones fijadas). El limitador de solicitudes es por servidor (archivos temporales).

## 9. Ejecución

Producción (sin cambios de infraestructura): subir los archivos; PHP ≥ 7.4 (probado en 8.1) con `pdo_mysql`, `curl`, `mbstring`; `composer install` para `vendor/` (web push). Variables del `.env`:

```
DB_TYPE=mysql
DB_HOST=… DB_NAME=… DB_USER=… DB_PASSWORD=…
VAPID_PUBLIC_KEY=… VAPID_PRIVATE_KEY=…
GEMINI_API_KEY=…            # opcional
CORS_ALLOWED_ORIGINS=…      # opcional, separados por coma
CRON_SECRET=…               # obligatorio si el cron se invoca por URL
APP_DEBUG=0
```

Entorno local de pruebas (no toca la BD real):

```bash
php -d extension=pdo_sqlite tests/setup_db.php
php -d extension=pdo_sqlite -S localhost:8091 tests/router.php
```

Abrir `http://localhost:8091` (usuarios de prueba en `tests/seed_users.php`). Pruebas:

```bash
php tests/unit.php
node --test tests/js/*.test.js
php -d extension=pdo_sqlite tests/api.php http://localhost:8091
```

(`tests/api.php` espera la base recién creada.)

## 10. Recomendaciones priorizadas

1. Configurar `CRON_SECRET`, `CORS_ALLOWED_ORIGINS` y aplicar la migración v3 (bloqueante).
2. Probar en staging con MySQL una copia de la base real.
3. Recuperación de contraseña por token de un solo uso.
4. Retirar los `onclick` inline de los módulos admin y eliminar `'unsafe-inline'` de la CSP; añadir SRI o servir las librerías desde el propio servidor.
5. Aceptar el id de sesión solo por cabecera `X-Session-ID`.
6. Modelo de cuentas y transferencias (evitar doble conteo de tarjetas, saldo por cuenta).
7. Ejecutar las pruebas en CI (GitHub Actions) en cada cambio.
8. Rediseño profundo de cronograma y facturación con los componentes nuevos.

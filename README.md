# BeaTaN · Finanzas

Panel financiero personal y de empresa (PHP + MySQL, JavaScript sin compilación).

- Informe técnico de la refactorización: [`docs/INFORME_REFACTORIZACION.md`](docs/INFORME_REFACTORIZACION.md)

## Requisitos

PHP ≥ 7.4 (probado en 8.1) con `pdo_mysql`, `curl` y `mbstring`. `composer install` para las notificaciones push.

## Configuración (`.env`)

```
DB_TYPE=mysql
DB_HOST=
DB_NAME=
DB_USER=
DB_PASSWORD=
VAPID_PUBLIC_KEY=
VAPID_PRIVATE_KEY=
GEMINI_API_KEY=          # opcional: asistente de IA
CORS_ALLOWED_ORIGINS=    # opcional: orígenes externos permitidos, separados por coma
CRON_SECRET=             # necesario si el cron se invoca por URL (?token=...)
APP_DEBUG=0
```

Tras desplegar, un administrador debe aplicar las migraciones desde **Perfil y ajustes → Base de datos** (solo crean tablas nuevas).

## Tareas programadas

```bash
php api/cron_deudas.php
php api/send_push.php
```

Por URL: `https://…/api/cron_deudas.php?token=CRON_SECRET`.

## Desarrollo y pruebas (base SQLite local, nunca la real)

```bash
php -d extension=pdo_sqlite tests/setup_db.php
php -d extension=pdo_sqlite -S localhost:8091 tests/router.php
php tests/unit.php
node --test tests/js/*.test.js
php -d extension=pdo_sqlite tests/api.php http://localhost:8091
```

<?php
// dashboard/tests/router.php
// Router para el servidor embebido de PHP en el entorno de PRUEBAS:
//   php -d extension=pdo_sqlite -S localhost:8091 tests/router.php
// Carga tests/test.env (SQLite) y emula las reglas de .htaccess.

putenv('BEATAN_ENV_FILE=' . __DIR__ . '/test.env');

$path = rawurldecode(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) ?? '/');
$blocked = '#(^|/)\.|\.(sql|log|lock|md|ini|sh|bak)$|^/(config|lib|vendor|tests|docs)(/|$)|^/composer\.json$#i';
if (preg_match($blocked, $path)) {
  http_response_code(403);
  echo 'Forbidden';
  return true;
}
if ($path === '/' || $path === '') {
  $_SERVER['SCRIPT_NAME'] = '/index.php';
  require __DIR__ . '/../index.php';
  return true;
}
return false;

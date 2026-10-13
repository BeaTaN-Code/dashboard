<?php
// dashboard/config/env.php
// Carga única y cacheada de variables de entorno (.env).

if (!function_exists('parse_env')) {
  function parse_env($path)
  {
    $env = [];
    if (!is_readable($path))
      return $env;
    $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
      $line = trim($line);
      if ($line === '' || $line[0] === '#')
        continue;
      if (strpos($line, '=') === false)
        continue;
      list($name, $value) = array_map('trim', explode('=', $line, 2));
      $value = trim($value, " \t\n\r\0\x0B\"'");
      $env[$name] = $value;
    }
    return $env;
  }
}

/**
 * Devuelve el arreglo de variables de entorno.
 * BEATAN_ENV_FILE (variable de entorno del proceso) permite apuntar a otro archivo,
 * por ejemplo el entorno de pruebas local, sin tocar el .env real.
 */
function app_env(): array
{
  static $cache = null;
  if ($cache !== null)
    return $cache;

  $override = getenv('BEATAN_ENV_FILE');
  $paths = $override ? [$override] : [
    __DIR__ . '/../../.env',
    __DIR__ . '/../.env',
  ];

  $cache = [];
  foreach ($paths as $path) {
    if (is_readable($path)) {
      $cache = parse_env($path);
      break;
    }
  }
  return $cache;
}

function env_value(string $key, $default = null)
{
  $env = app_env();
  if (array_key_exists($key, $env) && $env[$key] !== '')
    return $env[$key];
  $fromProcess = getenv($key);
  return ($fromProcess !== false && $fromProcess !== '') ? $fromProcess : $default;
}

function app_is_debug(): bool
{
  return in_array(strtolower((string) env_value('APP_DEBUG', '0')), ['1', 'true', 'yes'], true);
}

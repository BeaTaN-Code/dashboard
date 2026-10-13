<?php
// dashboard/config/db.php
// Conexión a base de datos usando .env y utilidades de sesión.
date_default_timezone_set('America/Bogota');

require_once __DIR__ . '/env.php';

// Helper para obtener cabeceras si no existe (ej. en FastCGI/CGI)
if (!function_exists('getallheaders')) {
  function getallheaders()
  {
    $headers = [];
    foreach ($_SERVER as $name => $value) {
      if (substr($name, 0, 5) == 'HTTP_') {
        $headers[str_replace(' ', '-', ucwords(strtolower(str_replace('_', ' ', substr($name, 5)))))] = $value;
      }
    }
    return $headers;
  }
}

function db_driver(): string
{
  $type = strtolower((string) env_value('DB_TYPE', 'mysql'));
  if ($type === 'pgsql' || $type === 'postgres')
    return 'pgsql';
  if ($type === 'sqlite')
    return 'sqlite';
  return 'mysql';
}

function getDbConnection()
{
  static $pdo = null;
  if ($pdo instanceof PDO)
    return $pdo;

  $driver = db_driver();
  $options = [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
  ];

  try {
    if ($driver === 'sqlite') {
      // Solo para el entorno de pruebas local (tests/). Nunca en producción.
      $path = (string) env_value('DB_PATH', '');
      if ($path !== '' && !preg_match('#^([A-Za-z]:)?[\\\\/]#', $path)) {
        $path = __DIR__ . '/../' . $path; // relativo a la raíz del proyecto
      }
      $pdo = new PDO('sqlite:' . $path, null, null, $options);
      $pdo->exec('PRAGMA foreign_keys = ON; PRAGMA busy_timeout = 3000;');
      db_register_sqlite_compat($pdo);
      return $pdo;
    }

    $host = env_value('DB_HOST', '127.0.0.1');
    $port = env_value('DB_PORT', $driver === 'pgsql' ? '5432' : '3306');
    $name = env_value('DB_NAME', '');
    $dsn = $driver === 'pgsql'
      ? "pgsql:host={$host};port={$port};dbname={$name}"
      : "mysql:host={$host};port={$port};dbname={$name};charset=utf8mb4";

    $pdo = new PDO($dsn, env_value('DB_USER', ''), env_value('DB_PASSWORD', ''), $options);

    if ($driver === 'mysql') {
      $pdo->exec("SET time_zone = '-05:00'");
    }
    return $pdo;
  } catch (Exception $e) {
    error_log('DB connection error: ' . $e->getMessage());
    $pdo = null;
    return null;
  }
}

/**
 * Funciones equivalentes a MySQL para poder ejecutar el código en SQLite durante las pruebas.
 */
function db_register_sqlite_compat(PDO $pdo): void
{
  $pdo->sqliteCreateFunction('CURDATE', fn() => date('Y-m-d'), 0);
  $pdo->sqliteCreateFunction('CURTIME', fn() => date('H:i:s'), 0);
  $pdo->sqliteCreateFunction('NOW', fn() => date('Y-m-d H:i:s'), 0);
  $pdo->sqliteCreateFunction('YEAR', fn($d) => $d ? (int) substr($d, 0, 4) : null, 1);
  $pdo->sqliteCreateFunction('MONTH', fn($d) => $d ? (int) substr($d, 5, 2) : null, 1);
  $pdo->sqliteCreateFunction('DATE_FORMAT', function ($d, $fmt) {
    if (!$d)
      return null;
    $ts = strtotime($d);
    return strtr($fmt, ['%Y' => date('Y', $ts), '%m' => date('m', $ts), '%d' => date('d', $ts)]);
  }, 2);
}

/** Sufijo de bloqueo de fila; SQLite no soporta FOR UPDATE (bloquea la BD completa). */
function db_for_update(): string
{
  return db_driver() === 'sqlite' ? '' : ' FOR UPDATE';
}

/** Indica si la excepción corresponde a una tabla inexistente (migración pendiente). */
function db_is_missing_table(Throwable $e): bool
{
  $msg = $e->getMessage();
  return strpos($msg, '42S02') !== false || stripos($msg, 'no such table') !== false
    || stripos($msg, "doesn't exist") !== false;
}

// Función para verificar sesión activa (páginas HTML)
function checkSession()
{
  if (session_status() === PHP_SESSION_NONE) {
    session_start();
  }

  if (!isset($_SESSION['user_id']) || !isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    header('Location: login.php');
    exit;
  }
}

// Función para obtener datos del usuario actual
function getCurrentUser()
{
  if (session_status() === PHP_SESSION_NONE) {
    session_start();
  }

  return [
    'id' => $_SESSION['user_id'] ?? null,
    'USRIDXXX' => $_SESSION['user_id'] ?? null,
    'name' => $_SESSION['user_name'] ?? null,
    'email' => $_SESSION['user_email'] ?? null,
    'is_admin' => $_SESSION['is_admin'] ?? false
  ];
}

<?php
// dashboard/config/db.php
// Configuración de conexión a base de datos usando .env

function parse_env($path)
{
  $env = [];
  if (!file_exists($path))
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

function getDbConnection()
{
  // Buscar .env en múltiples ubicaciones posibles
  $envPaths = [
    __DIR__ . '/../../.env',
    __DIR__ . '/../.env',
    '/.env'
  ];

  $env = [];
  foreach ($envPaths as $path) {
    if (file_exists($path)) {
      $env = parse_env($path);
      break;
    }
  }

  $dbType = isset($env['DB_TYPE']) ? strtolower($env['DB_TYPE']) : 'mysql';
  $dbHost = $env['DB_HOST'] ?? '127.0.0.1';
  $dbPort = $env['DB_PORT'] ?? ($dbType === 'postgres' ? '5432' : '3306');
  $dbName = $env['DB_NAME'] ?? '';
  $dbUser = $env['DB_USER'] ?? '';
  $dbPass = $env['DB_PASSWORD'] ?? '';

  try {
    if ($dbType === 'postgres' || $dbType === 'pgsql') {
      $dsn = "pgsql:host={$dbHost};port={$dbPort};dbname={$dbName}";
    } else {
      $dsn = "mysql:host={$dbHost};port={$dbPort};dbname={$dbName};charset=utf8mb4";
    }

    $pdo = new PDO($dsn, $dbUser, $dbPass, [
      PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
      PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);

    if ($dbType === 'mysql') {
      $pdo->exec("SET time_zone = '-10:00'");
    }

    return $pdo;
  } catch (Exception $e) {
    error_log('DB connection error: ' . $e->getMessage());
    return null;
  }
}

// Función para verificar sesión activa
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
    'name' => $_SESSION['user_name'] ?? null,
    'email' => $_SESSION['user_email'] ?? null,
    'is_admin' => $_SESSION['is_admin'] ?? false
  ];
}

// Zona horaria para Colombia
date_default_timezone_set('America/Bogota');
?>
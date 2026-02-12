<?php
// dashboard/config/db.php
// Configuracion de conexion a base de datos usando .env
date_default_timezone_set('America/Bogota');

// ===============================================
// SECURE SESSION CONFIGURATION
// ===============================================
function initSecureSession()
{
  if (session_status() === PHP_SESSION_NONE) {
    $isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
      || (!empty($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https');

    ini_set('session.cookie_httponly', 1);
    ini_set('session.cookie_samesite', 'Strict');
    ini_set('session.use_strict_mode', 1);
    ini_set('session.use_only_cookies', 1);
    ini_set('session.cookie_lifetime', 0);
    ini_set('session.gc_maxlifetime', 3600);

    if ($isHttps) {
      ini_set('session.cookie_secure', 1);
    }

    session_start();
  }
}

// ===============================================
// SECURITY HEADERS
// ===============================================
function setSecurityHeaders()
{
  header('X-Content-Type-Options: nosniff');
  header('X-Frame-Options: DENY');
  header('X-XSS-Protection: 1; mode=block');
  header('Referrer-Policy: strict-origin-when-cross-origin');
  header('Permissions-Policy: camera=(), microphone=(), geolocation=()');
  header("Content-Security-Policy: default-src 'self'; script-src 'self' 'unsafe-inline' cdn.jsdelivr.net; style-src 'self' 'unsafe-inline' cdn.jsdelivr.net fonts.googleapis.com; font-src 'self' fonts.gstatic.com cdn.jsdelivr.net; img-src 'self' data:; connect-src 'self';");
}

// ===============================================
// CSRF PROTECTION
// ===============================================
function generateCsrfToken()
{
  if (session_status() === PHP_SESSION_NONE) {
    initSecureSession();
  }
  if (empty($_SESSION['csrf_token']) || empty($_SESSION['csrf_token_time']) || (time() - $_SESSION['csrf_token_time']) > 3600) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    $_SESSION['csrf_token_time'] = time();
  }
  return $_SESSION['csrf_token'];
}

function validateCsrfToken($token)
{
  if (session_status() === PHP_SESSION_NONE) {
    initSecureSession();
  }
  if (empty($_SESSION['csrf_token']) || empty($token)) {
    return false;
  }
  if ((time() - ($_SESSION['csrf_token_time'] ?? 0)) > 3600) {
    unset($_SESSION['csrf_token'], $_SESSION['csrf_token_time']);
    return false;
  }
  return hash_equals($_SESSION['csrf_token'], $token);
}

function getCsrfTokenFromRequest()
{
  return $_SERVER['HTTP_X_CSRF_TOKEN']
    ?? $_POST['csrf_token']
    ?? '';
}

// ===============================================
// RATE LIMITING (file-based)
// ===============================================
function checkRateLimit($identifier, $maxAttempts = 5, $windowSeconds = 900)
{
  $rateLimitDir = sys_get_temp_dir() . '/beatan_rate_limit';
  if (!is_dir($rateLimitDir)) {
    @mkdir($rateLimitDir, 0700, true);
  }

  $file = $rateLimitDir . '/' . md5($identifier) . '.json';
  $data = ['attempts' => [], 'blocked_until' => 0];

  if (file_exists($file)) {
    $content = @file_get_contents($file);
    if ($content) {
      $data = json_decode($content, true) ?: $data;
    }
  }

  $now = time();

  // Check if currently blocked
  if ($data['blocked_until'] > $now) {
    $remaining = $data['blocked_until'] - $now;
    return ['allowed' => false, 'remaining' => $remaining, 'message' => "Demasiados intentos. Intenta de nuevo en " . ceil($remaining / 60) . " minuto(s)."];
  }

  // Clean old attempts outside window
  $data['attempts'] = array_filter($data['attempts'], function ($t) use ($now, $windowSeconds) {
    return ($now - $t) < $windowSeconds;
  });

  if (count($data['attempts']) >= $maxAttempts) {
    $data['blocked_until'] = $now + $windowSeconds;
    $data['attempts'] = [];
    @file_put_contents($file, json_encode($data), LOCK_EX);
    return ['allowed' => false, 'remaining' => $windowSeconds, 'message' => "Demasiados intentos. Intenta de nuevo en " . ceil($windowSeconds / 60) . " minuto(s)."];
  }

  return ['allowed' => true];
}

function recordRateLimitAttempt($identifier)
{
  $rateLimitDir = sys_get_temp_dir() . '/beatan_rate_limit';
  if (!is_dir($rateLimitDir)) {
    @mkdir($rateLimitDir, 0700, true);
  }

  $file = $rateLimitDir . '/' . md5($identifier) . '.json';
  $data = ['attempts' => [], 'blocked_until' => 0];

  if (file_exists($file)) {
    $content = @file_get_contents($file);
    if ($content) {
      $data = json_decode($content, true) ?: $data;
    }
  }

  $data['attempts'][] = time();
  @file_put_contents($file, json_encode($data), LOCK_EX);
}

function clearRateLimit($identifier)
{
  $rateLimitDir = sys_get_temp_dir() . '/beatan_rate_limit';
  $file = $rateLimitDir . '/' . md5($identifier) . '.json';
  if (file_exists($file)) {
    @unlink($file);
  }
}

// ===============================================
// ENV PARSER
// ===============================================
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

// ===============================================
// DATABASE CONNECTION
// ===============================================
function getDbConnection()
{
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
      PDO::ATTR_EMULATE_PREPARES => false,
    ]);

    if ($dbType === 'mysql') {
      $pdo->exec("SET time_zone = '-05:00'");
    }

    return $pdo;
  } catch (Exception $e) {
    error_log('DB connection error: ' . $e->getMessage());
    return null;
  }
}

// ===============================================
// SESSION CHECK
// ===============================================
function checkSession()
{
  initSecureSession();

  if (!isset($_SESSION['user_id']) || !isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    header('Location: login.php');
    exit;
  }

  // Session timeout: 1 hour of inactivity
  if (isset($_SESSION['last_activity']) && (time() - $_SESSION['last_activity']) > 3600) {
    session_unset();
    session_destroy();
    header('Location: login.php?expired=1');
    exit;
  }
  $_SESSION['last_activity'] = time();
}

function getCurrentUser()
{
  if (session_status() === PHP_SESSION_NONE) {
    initSecureSession();
  }

  return [
    'id' => $_SESSION['user_id'] ?? null,
    'name' => $_SESSION['user_name'] ?? null,
    'email' => $_SESSION['user_email'] ?? null,
    'is_admin' => $_SESSION['is_admin'] ?? false
  ];
}

// ===============================================
// INPUT SANITIZATION HELPERS
// ===============================================
function sanitizeString($input)
{
  return htmlspecialchars(trim($input), ENT_QUOTES, 'UTF-8');
}

function sanitizeInt($input)
{
  return intval($input);
}

function sanitizeFloat($input)
{
  return floatval($input);
}

function validateDateFormat($date)
{
  $d = DateTime::createFromFormat('Y-m-d', $date);
  return $d && $d->format('Y-m-d') === $date;
}
?>

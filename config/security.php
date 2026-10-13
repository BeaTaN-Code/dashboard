<?php
// dashboard/config/security.php
// Sesión segura, cabeceras de seguridad, CORS con lista blanca, CSRF y límites de solicitudes.

require_once __DIR__ . '/env.php';

function app_is_https(): bool
{
  if (!empty($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off')
    return true;
  if (($_SERVER['SERVER_PORT'] ?? '') == 443)
    return true;
  return strtolower($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https';
}

function app_client_ip(): string
{
  return (string) ($_SERVER['REMOTE_ADDR'] ?? 'cli');
}

/**
 * Cabeceras de seguridad comunes. La CSP permite 'unsafe-inline' porque la interfaz
 * todavía usa manejadores inline; aun así restringe orígenes de scripts y conexiones.
 */
function app_send_security_headers(bool $isApi): void
{
  if (headers_sent())
    return;
  header('X-Content-Type-Options: nosniff');
  header('Referrer-Policy: strict-origin-when-cross-origin');
  header('X-Frame-Options: DENY');
  header('Permissions-Policy: camera=(), microphone=(), geolocation=(), payment=()');
  if (app_is_https()) {
    header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
  }
  if ($isApi) {
    header("Content-Security-Policy: default-src 'none'; frame-ancestors 'none'");
    header('Cache-Control: no-store');
  } else {
    $cdn = 'https://cdn.jsdelivr.net https://cdnjs.cloudflare.com';
    header("Content-Security-Policy: default-src 'self'; "
      . "script-src 'self' 'unsafe-inline' 'wasm-unsafe-eval' blob: $cdn; "
      . "style-src 'self' 'unsafe-inline' https://fonts.googleapis.com $cdn; "
      . "font-src 'self' data: https://fonts.gstatic.com $cdn; "
      . "img-src 'self' data: blob:; "
      . "connect-src 'self' blob: data: https://fonts.googleapis.com https://fonts.gstatic.com https://generativelanguage.googleapis.com https://tessdata.projectnaptha.com $cdn; "
      . "worker-src 'self' blob: $cdn; "
      . "object-src 'none'; base-uri 'self'; form-action 'self'; frame-ancestors 'none'");
    header('Cache-Control: no-store, private');
  }
}

/** Orígenes permitidos para CORS: CORS_ALLOWED_ORIGINS=https://app.ejemplo.com,http://localhost:8100 */
function app_allowed_origins(): array
{
  $raw = (string) env_value('CORS_ALLOWED_ORIGINS', '');
  return array_values(array_filter(array_map('trim', explode(',', $raw))));
}

function app_is_same_origin(string $origin): bool
{
  $host = $_SERVER['HTTP_HOST'] ?? '';
  $parsed = parse_url($origin);
  if (!$parsed || empty($parsed['host']))
    return false;
  $originHost = $parsed['host'] . (isset($parsed['port']) ? ':' . $parsed['port'] : '');
  return strcasecmp($originHost, $host) === 0;
}

/**
 * Antes se reflejaba cualquier Origin con credenciales (cualquier web podía leer los datos
 * financieros de un usuario con sesión iniciada). Ahora solo se aceptan orígenes de la lista.
 */
function app_handle_cors(): void
{
  $origin = $_SERVER['HTTP_ORIGIN'] ?? '';
  $allowed = $origin !== '' && in_array($origin, app_allowed_origins(), true);

  if ($allowed) {
    header("Access-Control-Allow-Origin: $origin");
    header('Vary: Origin');
    header('Access-Control-Allow-Credentials: true');
    header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With, X-Session-ID, X-CSRF-Token, Idempotency-Key');
    header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
  }

  if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') {
    http_response_code($allowed || ($origin !== '' && app_is_same_origin($origin)) ? 204 : 403);
    exit;
  }
}

/** true cuando la sesión llegó explícitamente (cliente móvil) y no por cookie del navegador. */
function app_session_from_header(?bool $set = null): bool
{
  static $value = false;
  if ($set !== null)
    $value = $set;
  return $value;
}

function app_start_session(): void
{
  if (session_status() === PHP_SESSION_ACTIVE)
    return;

  ini_set('session.use_strict_mode', '1');
  ini_set('session.use_only_cookies', '1');
  session_set_cookie_params([
    'lifetime' => 0,
    'path' => '/',
    'secure' => app_is_https(),
    'httponly' => true,
    'samesite' => 'Lax',
  ]);

  // Compatibilidad con la app móvil: envía el id de sesión obtenido en api/login.php.
  $headers = array_change_key_case(getallheaders(), CASE_LOWER);
  $explicit = $headers['x-session-id'] ?? $_GET['session_id'] ?? $_POST['session_id'] ?? null;
  if (is_string($explicit) && preg_match('/^[A-Za-z0-9,-]{20,128}$/', $explicit)) {
    session_id($explicit);
    app_session_from_header(true);
  }

  session_start();

  // Caducidad por inactividad (8 horas).
  $now = time();
  if (isset($_SESSION['last_seen']) && ($now - (int) $_SESSION['last_seen']) > 8 * 3600) {
    $_SESSION = [];
    session_regenerate_id(true);
  }
  $_SESSION['last_seen'] = $now;
}

function app_csrf_token(): string
{
  if (session_status() !== PHP_SESSION_ACTIVE)
    app_start_session();
  if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
  }
  return $_SESSION['csrf_token'];
}

function app_csrf_valid(?string $token): bool
{
  return is_string($token) && !empty($_SESSION['csrf_token']) && hash_equals($_SESSION['csrf_token'], $token);
}

/**
 * Exige el token CSRF en operaciones que modifican datos cuando la sesión viaja en cookie.
 * Las sesiones enviadas explícitamente (cabecera X-Session-ID) no son vulnerables a CSRF.
 */
function app_require_csrf_for_unsafe_methods(): void
{
  $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
  if (in_array($method, ['GET', 'HEAD', 'OPTIONS'], true))
    return;
  if (app_session_from_header())
    return;
  if (empty($_SESSION['logged_in']))
    return; // el endpoint responderá 401
  $headers = array_change_key_case(getallheaders(), CASE_LOWER);
  if (!app_csrf_valid($headers['x-csrf-token'] ?? null)) {
    api_response(false, 'La sesión de seguridad expiró. Recarga la página e inténtalo de nuevo.', 419);
  }
}

/**
 * Límite simple de solicitudes por IP basado en archivos (válido en hosting compartido).
 * Devuelve true si la solicitud está permitida.
 */
function rate_limit_hit(string $bucket, int $max, int $windowSeconds): bool
{
  $dir = rtrim(sys_get_temp_dir(), '/\\') . DIRECTORY_SEPARATOR . 'beatan_rl';
  if (!is_dir($dir) && !@mkdir($dir, 0700, true) && !is_dir($dir))
    return true; // si no se puede limitar, no bloquear al usuario legítimo

  $file = $dir . DIRECTORY_SEPARATOR . hash('sha256', $bucket . '|' . app_client_ip()) . '.json';
  $fh = @fopen($file, 'c+');
  if (!$fh)
    return true;

  $allowed = true;
  if (flock($fh, LOCK_EX)) {
    $now = time();
    $data = json_decode(stream_get_contents($fh) ?: '[]', true);
    $hits = array_values(array_filter(is_array($data) ? $data : [], fn($t) => is_int($t) && $t > $now - $windowSeconds));
    if (count($hits) >= $max) {
      $allowed = false;
    } else {
      $hits[] = $now;
    }
    ftruncate($fh, 0);
    rewind($fh);
    fwrite($fh, json_encode($hits));
    fflush($fh);
    flock($fh, LOCK_UN);
  }
  fclose($fh);
  return $allowed;
}

function rate_limit_or_fail(string $bucket, int $max, int $windowSeconds): void
{
  if (!rate_limit_hit($bucket, $max, $windowSeconds)) {
    header('Retry-After: ' . $windowSeconds);
    api_response(false, 'Demasiados intentos. Espera unos minutos e inténtalo de nuevo.', 429);
  }
}

/** Bloquea el acceso HTTP a scripts de mantenimiento salvo CLI o token CRON_SECRET. */
function app_require_cli_or_cron_token(): void
{
  if (PHP_SAPI === 'cli')
    return;
  $secret = (string) env_value('CRON_SECRET', '');
  $headers = array_change_key_case(getallheaders(), CASE_LOWER);
  $given = $headers['x-cron-token'] ?? $_GET['token'] ?? '';
  if ($secret === '' || !is_string($given) || !hash_equals($secret, $given)) {
    http_response_code(403);
    header('Content-Type: text/plain; charset=utf-8');
    echo "Acceso denegado.\n";
    exit;
  }
  header('Content-Type: text/plain; charset=utf-8');
}

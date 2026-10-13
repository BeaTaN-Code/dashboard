<?php
// dashboard/config/api_helper.php
// Helper centralizado para respuestas API, arranque seguro y utilidades comunes.

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/security.php';

if (!function_exists('api_response')) {
  /**
   * Emite una respuesta JSON estructurada y finaliza la ejecución.
   */
  function api_response(bool $success, $dataOrError = null, int $statusCode = 200, array $extra = []): void
  {
    if (!headers_sent()) {
      header('Content-Type: application/json; charset=utf-8');
      http_response_code($statusCode);
    }

    $response = ['success' => $success];

    if ($success) {
      $response['data'] = $dataOrError;
    } else {
      $response['error'] = is_string($dataOrError) ? $dataOrError : 'Ocurrió un error inesperado';
    }

    if (!empty($extra)) {
      $response = array_merge($response, $extra);
    }

    echo json_encode($response, JSON_UNESCAPED_UNICODE);
    exit;
  }
}

/** Respuesta JSON libre (para endpoints con contrato histórico propio). */
function api_json(array $payload, int $statusCode = 200): void
{
  if (!headers_sent()) {
    header('Content-Type: application/json; charset=utf-8');
    http_response_code($statusCode);
  }
  echo json_encode($payload, JSON_UNESCAPED_UNICODE);
  exit;
}

/**
 * Arranque común de todos los endpoints de /api:
 * cabeceras de seguridad, CORS restringido, sesión segura y verificación CSRF.
 */
function api_bootstrap(bool $csrf = true): void
{
  app_send_security_headers(true);
  header('Content-Type: application/json; charset=utf-8');
  app_handle_cors();
  app_start_session();

  set_exception_handler(function (Throwable $e) {
    error_log('Unhandled API error: ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
    api_response(false, 'Ocurrió un error inesperado. Inténtalo de nuevo.', 500);
  });

  if ($csrf) {
    app_require_csrf_for_unsafe_methods();
  }
}

if (!function_exists('api_require_auth')) {
  /**
   * Verifica que exista una sesión activa. Si no, retorna error 401.
   */
  function api_require_auth(): array
  {
    if (session_status() === PHP_SESSION_NONE) {
      app_start_session();
    }

    if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true || empty($_SESSION['user_id'])) {
      api_response(false, 'No autorizado - Inicie sesión para continuar', 401);
    }

    return [
      'user_id' => (string) $_SESSION['user_id'],
      'user_name' => $_SESSION['user_name'] ?? 'Usuario',
      'is_admin' => ($_SESSION['is_admin'] ?? false) === true
    ];
  }
}

function api_require_admin(): array
{
  $auth = api_require_auth();
  if (!$auth['is_admin']) {
    api_response(false, 'Acceso denegado. Se requiere rol de administrador', 403);
  }
  return $auth;
}

function api_db(): PDO
{
  $pdo = getDbConnection();
  if (!$pdo) {
    api_response(false, 'Error de conexión a la base de datos', 500);
  }
  return $pdo;
}

if (!function_exists('api_get_json_input')) {
  /**
   * Decodifica el cuerpo de la petición JSON entrante de forma segura (máx. 2 MB).
   */
  function api_get_json_input(int $maxBytes = 2097152): array
  {
    static $cache = null;
    if ($cache !== null)
      return $cache;
    $raw = file_get_contents('php://input', false, null, 0, $maxBytes + 1);
    if ($raw === false || $raw === '') {
      return $cache = [];
    }
    if (strlen($raw) > $maxBytes) {
      api_response(false, 'La solicitud es demasiado grande', 413);
    }
    $data = json_decode($raw, true);
    return $cache = (is_array($data) ? $data : []);
  }
}

/**
 * Idempotencia por sesión: si el cliente repite una operación con la misma cabecera
 * Idempotency-Key (doble clic, reintento por red), se devuelve la respuesta original.
 */
function api_idempotency_key(): ?string
{
  $headers = array_change_key_case(getallheaders(), CASE_LOWER);
  $key = $headers['idempotency-key'] ?? null;
  return (is_string($key) && preg_match('/^[A-Za-z0-9_-]{8,80}$/', $key)) ? $key : null;
}

function api_idempotent_replay(string $scope): void
{
  $key = api_idempotency_key();
  if (!$key)
    return;
  $store = $_SESSION['idem'] ?? [];
  $id = $scope . ':' . $key;
  if (isset($store[$id]) && (time() - $store[$id]['t']) < 900) {
    header('Idempotent-Replay: true');
    api_json($store[$id]['r'], $store[$id]['s']);
  }
}

function api_idempotent_success(string $scope, array $payload, int $status = 200): void
{
  $key = api_idempotency_key();
  if ($key) {
    $store = array_filter($_SESSION['idem'] ?? [], fn($v) => (time() - $v['t']) < 900);
    $store[$scope . ':' . $key] = ['t' => time(), 'r' => $payload, 's' => $status];
    $_SESSION['idem'] = array_slice($store, -50, null, true);
  }
  api_json($payload, $status);
}

/** Registra en log sin datos personales/financieros sensibles. */
function api_log_error(string $context, Throwable $e): void
{
  error_log($context . ': ' . get_class($e) . ' ' . $e->getMessage());
}

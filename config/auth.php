<?php
// dashboard/config/auth.php
// Funciones de autenticación

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/security.php';

const PASSWORD_MIN_LENGTH = 8;
const LOGIN_GENERIC_ERROR = 'Cédula o contraseña incorrecta';

function loginUser($cedula, $password)
{
  // Límite de intentos por IP (30 cada 15 minutos, tolera oficinas tras NAT) y por cédula (8 cada 15 minutos)
  if (!rate_limit_hit('login', 30, 900) || !rate_limit_hit('login:' . strtolower((string) $cedula), 8, 900)) {
    return ['success' => false, 'error' => 'Demasiados intentos. Espera 15 minutos e inténtalo de nuevo.', 'status' => 429];
  }

  $pdo = getDbConnection();
  if (!$pdo) {
    return ['success' => false, 'error' => 'Error de conexión a la base de datos'];
  }

  try {
    $stmt = $pdo->prepare("SELECT * FROM BEATUSRS WHERE USRIDXXX = :cedula AND REGESTXX = 'ACTIVO'");
    $stmt->execute([':cedula' => $cedula]);
    $user = $stmt->fetch();

    // Mismo mensaje para usuario inexistente y contraseña errónea (evita enumerar cédulas).
    if (!$user || !password_verify($password, (string) $user['USRPASXX'])) {
      return ['success' => false, 'error' => LOGIN_GENERIC_ERROR, 'status' => 401];
    }

    if (password_needs_rehash($user['USRPASXX'], PASSWORD_DEFAULT)) {
      $up = $pdo->prepare('UPDATE BEATUSRS SET USRPASXX = :p WHERE USRIDXXX = :id');
      $up->execute([':p' => password_hash($password, PASSWORD_DEFAULT), ':id' => $user['USRIDXXX']]);
    }

    if (session_status() === PHP_SESSION_NONE) {
      app_start_session();
    }
    // Nuevo identificador de sesión tras autenticarse: impide la fijación de sesión.
    session_regenerate_id(true);
    $_SESSION = ['last_seen' => time()];

    $_SESSION['user_id'] = (string) $user['USRIDXXX'];
    $_SESSION['user_name'] = $user['USRNMEXX'];
    $_SESSION['user_email'] = $user['USRMAILX'];
    $_SESSION['is_admin'] = ($user['ISADMINX'] === 'SI');
    $_SESSION['logged_in'] = true;
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));

    unset($user['USRPASXX']);
    return ['success' => true, 'user' => $user];

  } catch (Exception $e) {
    error_log('Login error: ' . $e->getMessage());
    return ['success' => false, 'error' => 'Error al iniciar sesión'];
  }
}

function validateRegistration(array $data): ?string
{
  if (empty($data['cedula']) || empty($data['nombre']) || empty($data['email']) || empty($data['password'])) {
    return 'Por favor complete todos los campos obligatorios';
  }
  if (!preg_match('/^[0-9A-Za-z-]{4,15}$/', $data['cedula'])) {
    return 'La cédula solo puede tener números o letras (entre 4 y 15 caracteres)';
  }
  if (mb_strlen($data['nombre']) > 50) {
    return 'El nombre no puede superar 50 caracteres';
  }
  if (!filter_var($data['email'], FILTER_VALIDATE_EMAIL) || strlen($data['email']) > 150) {
    return 'Por favor ingrese un email válido';
  }
  if (strlen($data['password']) < PASSWORD_MIN_LENGTH) {
    return 'La contraseña debe tener al menos ' . PASSWORD_MIN_LENGTH . ' caracteres';
  }
  if (strlen($data['password']) > 200) {
    return 'La contraseña es demasiado larga';
  }
  return null;
}

function registerUser($data)
{
  if (!rate_limit_hit('register', 5, 3600)) {
    return ['success' => false, 'error' => 'Demasiados registros desde esta conexión. Inténtalo más tarde.'];
  }

  $pdo = getDbConnection();
  if (!$pdo) {
    return ['success' => false, 'error' => 'Error de conexión a la base de datos'];
  }

  try {
    $stmt = $pdo->prepare("SELECT USRIDXXX FROM BEATUSRS WHERE USRIDXXX = :cedula");
    $stmt->execute([':cedula' => $data['cedula']]);
    if ($stmt->fetch()) {
      return ['success' => false, 'error' => 'Ya existe un usuario con esa cédula'];
    }

    $stmt = $pdo->prepare("SELECT USRIDXXX FROM BEATUSRS WHERE USRMAILX = :email");
    $stmt->execute([':email' => $data['email']]);
    if ($stmt->fetch()) {
      return ['success' => false, 'error' => 'Ya existe un usuario con ese email'];
    }

    $hashedPassword = password_hash($data['password'], PASSWORD_DEFAULT);

    $stmt = $pdo->prepare("
            INSERT INTO BEATUSRS (USRIDXXX, USRPASXX, USRNMEXX, USRMAILX, USRCELUL, ISADMINX, REGUSRXX, REGFECXX, REGHORXX, REGESTXX)
            VALUES (:cedula, :password, :nombre, :email, :celular, 'NO', :cedula2, :d, :h, 'ACTIVO')
        ");
    $stmt->execute([
      ':cedula' => $data['cedula'],
      ':password' => $hashedPassword,
      ':nombre' => $data['nombre'],
      ':email' => $data['email'],
      ':celular' => mb_substr($data['celular'] ?? '', 0, 20),
      ':cedula2' => $data['cedula'],
      ':d' => date('Y-m-d'),
      ':h' => date('H:i:s'),
    ]);

    return ['success' => true, 'message' => 'Usuario registrado exitosamente'];

  } catch (Exception $e) {
    error_log('Register error: ' . $e->getMessage());
    return ['success' => false, 'error' => 'Error al registrar usuario. Inténtalo de nuevo.'];
  }
}

function logoutUser()
{
  if (session_status() === PHP_SESSION_NONE) {
    app_start_session();
  }

  $_SESSION = [];

  if (ini_get("session.use_cookies")) {
    $params = session_get_cookie_params();
    setcookie(session_name(), '', [
      'expires' => time() - 42000,
      'path' => $params['path'],
      'domain' => $params['domain'],
      'secure' => $params['secure'],
      'httponly' => $params['httponly'],
      'samesite' => $params['samesite'] ?? 'Lax',
    ]);
  }

  session_destroy();
}

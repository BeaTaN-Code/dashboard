<?php
// dashboard/config/auth.php
// Funciones de autenticación

require_once __DIR__ . '/db.php';

function loginUser($cedula, $password)
{
  $pdo = getDbConnection();
  if (!$pdo) {
    return ['success' => false, 'error' => 'Error de conexión a la base de datos'];
  }

  try {
    $stmt = $pdo->prepare("SELECT * FROM BEATUSRS WHERE USRIDXXX = :cedula AND REGESTXX = 'ACTIVO'");
    $stmt->execute([':cedula' => $cedula]);
    $user = $stmt->fetch();

    if (!$user) {
      return ['success' => false, 'error' => 'Usuario no encontrado o inactivo'];
    }

    // Verificar contraseña (hash)
    if (!password_verify($password, $user['USRPASXX'])) {
      return ['success' => false, 'error' => 'Contraseña incorrecta'];
    }

    // Iniciar sesión
    if (session_status() === PHP_SESSION_NONE) {
      session_start();
    }

    $_SESSION['user_id'] = $user['USRIDXXX'];
    $_SESSION['user_name'] = $user['USRNMEXX'];
    $_SESSION['user_email'] = $user['USRMAILX'];
    $_SESSION['is_admin'] = ($user['ISADMINX'] === 'SI');
    $_SESSION['logged_in'] = true;

    return ['success' => true, 'user' => $user];

  } catch (Exception $e) {
    error_log('Login error: ' . $e->getMessage());
    return ['success' => false, 'error' => 'Error al iniciar sesión'];
  }
}

function registerUser($data)
{
  $pdo = getDbConnection();
  if (!$pdo) {
    return ['success' => false, 'error' => 'Error de conexión a la base de datos'];
  }

  try {
    // Verificar si el usuario ya existe
    $stmt = $pdo->prepare("SELECT USRIDXXX FROM BEATUSRS WHERE USRIDXXX = :cedula");
    $stmt->execute([':cedula' => $data['cedula']]);

    if ($stmt->fetch()) {
      return ['success' => false, 'error' => 'Ya existe un usuario con esa cédula'];
    }

    // Verificar email duplicado
    $stmt = $pdo->prepare("SELECT USRIDXXX FROM BEATUSRS WHERE USRMAILX = :email");
    $stmt->execute([':email' => $data['email']]);

    if ($stmt->fetch()) {
      return ['success' => false, 'error' => 'Ya existe un usuario con ese email'];
    }

    // Hash de la contraseña
    $hashedPassword = password_hash($data['password'], PASSWORD_DEFAULT);

    // Insertar usuario
    $stmt = $pdo->prepare("
            INSERT INTO BEATUSRS (USRIDXXX, USRPASXX, USRNMEXX, USRMAILX, USRCELUL, ISADMINX, REGUSRXX, REGFECXX, REGHORXX, REGESTXX)
            VALUES (:cedula, :password, :nombre, :email, :celular, 'NO', :cedula, CURDATE(), CURTIME(), 'ACTIVO')
        ");

    $stmt->execute([
      ':cedula' => $data['cedula'],
      ':password' => $hashedPassword,
      ':nombre' => $data['nombre'],
      ':email' => $data['email'],
      ':celular' => $data['celular'] ?? ''
    ]);

    return ['success' => true, 'message' => 'Usuario registrado exitosamente'];

  } catch (Exception $e) {
    error_log('Register error: ' . $e->getMessage());
    return ['success' => false, 'error' => 'Error al registrar usuario: ' . $e->getMessage()];
  }
}

function logoutUser()
{
  if (session_status() === PHP_SESSION_NONE) {
    session_start();
  }

  $_SESSION = [];

  if (ini_get("session.use_cookies")) {
    $params = session_get_cookie_params();
    setcookie(
      session_name(),
      '',
      time() - 42000,
      $params["path"],
      $params["domain"],
      $params["secure"],
      $params["httponly"]
    );
  }

  session_destroy();
}
?>
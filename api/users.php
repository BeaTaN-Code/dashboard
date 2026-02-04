<?php
// dashboard/api/users.php
// API para gestionar BEATUSRS (solo admin)

header('Content-Type: application/json');
session_start();
require_once __DIR__ . '/../config/db.php';

// Verificar autenticacion
if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
  http_response_code(401);
  echo json_encode(['success' => false, 'error' => 'No autorizado']);
  exit;
}

// Verificar que sea admin
if (!isset($_SESSION['is_admin']) || $_SESSION['is_admin'] !== true) {
  http_response_code(403);
  echo json_encode(['success' => false, 'error' => 'Acceso denegado. Se requiere rol de administrador']);
  exit;
}

$pdo = getDbConnection();
if (!$pdo) {
  http_response_code(500);
  echo json_encode(['success' => false, 'error' => 'Error de conexion a la base de datos']);
  exit;
}

$method = $_SERVER['REQUEST_METHOD'];
$adminId = $_SESSION['user_id'];

switch ($method) {
  case 'GET':
    getUser($pdo);
    break;
  case 'POST':
    createUser($pdo, $adminId);
    break;
  case 'PUT':
    updateUser($pdo, $adminId);
    break;
  case 'DELETE':
    toggleUserStatus($pdo, $adminId);
    break;
  default:
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Metodo no permitido']);
}

function getUser($pdo)
{
  $id = intval($_GET['id'] ?? 0);

  if ($id <= 0) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'ID invalido']);
    return;
  }

  try {
    $stmt = $pdo->prepare("SELECT USRIDXXX, USRNMEXX, USRMAILX, USRCELUL, ISADMINX, REGESTXX FROM BEATUSRS WHERE USRIDXXX = :id");
    $stmt->execute([':id' => $id]);
    $user = $stmt->fetch();

    if (!$user) {
      http_response_code(404);
      echo json_encode(['success' => false, 'error' => 'Usuario no encontrado']);
      return;
    }

    echo json_encode([
      'success' => true,
      'data' => $user
    ]);

  } catch (Exception $e) {
    error_log('Get user error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Error al obtener usuario']);
  }
}

function createUser($pdo, $adminId)
{
  $input = json_decode(file_get_contents('php://input'), true);

  $cedula = trim($input['cedula'] ?? '');
  $nombre = trim($input['nombre'] ?? '');
  $email = trim($input['email'] ?? '');
  $celular = trim($input['celular'] ?? '');
  $password = $input['password'] ?? '';
  $isAdmin = intval($input['admin'] ?? 0);

  // Validaciones
  if (empty($cedula) || empty($nombre) || empty($email) || empty($password)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Nombre, email y contrasena son requeridos']);
    return;
  }

  if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Email invalido']);
    return;
  }

  if (strlen($password) < 6) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'La contrasena debe tener al menos 6 caracteres']);
    return;
  }

  try {
    // Verificar que el email no exista
    $stmt = $pdo->prepare("SELECT USRIDXXX FROM BEATUSRS WHERE USRMAILX = :email");
    $stmt->execute([':email' => $email]);

    if ($stmt->fetch()) {
      http_response_code(400);
      echo json_encode(['success' => false, 'error' => 'El email ya esta registrado']);
      return;
    }

    // Hash de la contrasena
    $hashedPassword = password_hash($password, PASSWORD_DEFAULT);

    $stmt = $pdo->prepare("
            INSERT INTO BEATUSRS (USRIDXXX, USRNMEXX, USRMAILX, USRCELUL, USRPASXX, ISADMINX, REGUSRXX, REGFECXX, REGHORXX, REGESTXX)
            VALUES (:cedula, :nombre, :email, :celular, :password, :isAdmin, :adminId, CURDATE(), CURTIME(), 'ACTIVO')
        ");

    $stmt->execute([
      ':cedula' => $cedula,
      ':nombre' => $nombre,
      ':email' => $email,
      ':celular' => $celular,
      ':password' => $hashedPassword,
      ':isAdmin' => $isAdmin,
      ':adminId' => $adminId
    ]);

    $newId = $pdo->lastInsertId();

    echo json_encode([
      'success' => true,
      'message' => 'Usuario creado exitosamente',
      'id' => $newId
    ]);

  } catch (Exception $e) {
    error_log('Create user error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Error al crear usuario']);
  }
}

function updateUser($pdo, $adminId)
{
  $input = json_decode(file_get_contents('php://input'), true);

  $id = intval($input['id'] ?? 0);
  $nombre = trim($input['nombre'] ?? '');
  $email = trim($input['email'] ?? '');
  $celular = trim($input['celular'] ?? '');
  $password = $input['password'] ?? '';
  $isAdmin = trim($input['admin'] ?? '');

  if ($id <= 0) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'ID invalido']);
    return;
  }

  // Validaciones
  if (empty($nombre) || empty($email)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Nombre y email son requeridos']);
    return;
  }

  if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Email invalido']);
    return;
  }

  try {
    // Verificar que el usuario existe
    $stmt = $pdo->prepare("SELECT USRIDXXX FROM BEATUSRS WHERE USRIDXXX = :id");
    $stmt->execute([':id' => $id]);

    if (!$stmt->fetch()) {
      http_response_code(404);
      echo json_encode(['success' => false, 'error' => 'Usuario no encontrado']);
      return;
    }

    // Verificar que el email no pertenezca a otro usuario
    $stmt = $pdo->prepare("SELECT USRIDXXX FROM BEATUSRS WHERE USRMAILX = :email AND USRIDXXX != :id");
    $stmt->execute([':email' => $email, ':id' => $id]);

    if ($stmt->fetch()) {
      http_response_code(400);
      echo json_encode(['success' => false, 'error' => 'El email ya esta en uso por otro usuario']);
      return;
    }

    // Actualizar usuario
    if (!empty($password)) {
      if (strlen($password) < 6) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'La contrasena debe tener al menos 6 caracteres']);
        return;
      }

      $hashedPassword = password_hash($password, PASSWORD_DEFAULT);

      $stmt = $pdo->prepare("
                UPDATE BEATUSRS 
                SET USRNMEXX = :nombre, 
                    USRMAILX = :email, 
                    USRCELUL = :celular,
                    USRPASXX = :password,
                    ISADMINX = :isAdmin,
                    REGUSRMX = :adminId,
                    REGFECMX = CURDATE(),
                    REGHORMX = CURTIME()
                WHERE USRIDXXX = :id
            ");

      $stmt->execute([
        ':nombre' => $nombre,
        ':email' => $email,
        ':celular' => $celular,
        ':password' => $hashedPassword,
        ':isAdmin' => $isAdmin,
        ':adminId' => $adminId,
        ':id' => $id
      ]);
    } else {
      $stmt = $pdo->prepare("
                UPDATE BEATUSRS 
                SET USRNMEXX = :nombre, 
                    USRMAILX = :email, 
                    USRCELUL = :celular,
                    ISADMINX = :isAdmin,
                    REGUSRMX = :adminId,
                    REGFECMX = CURDATE(),
                    REGHORMX = CURTIME()
                WHERE USRIDXXX = :id
            ");

      $stmt->execute([
        ':nombre' => $nombre,
        ':email' => $email,
        ':celular' => $celular,
        ':isAdmin' => $isAdmin,
        ':adminId' => $adminId,
        ':id' => $id
      ]);
    }

    echo json_encode([
      'success' => true,
      'message' => 'Usuario actualizado exitosamente'
    ]);

  } catch (Exception $e) {
    error_log('Update user error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Error al actualizar usuario']);
  }
}

function toggleUserStatus($pdo, $adminId)
{
  $id = intval($_GET['id'] ?? 0);
  $newStatus = $_GET['status'] ?? '';

  if ($id <= 0) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'ID invalido']);
    return;
  }

  if (!in_array($newStatus, ['ACTIVO', 'INACTIVO'])) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Estado invalido']);
    return;
  }

  // No permitir desactivarse a si mismo
  if ($id == $adminId) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'No puede cambiar su propio estado']);
    return;
  }

  try {
    $stmt = $pdo->prepare("
            UPDATE BEATUSRS 
            SET REGESTXX = :status,
                REGUSRMX = :adminId,
                REGFECMX = CURDATE(),
                REGHORMX = CURTIME()
            WHERE USRIDXXX = :id
        ");

    $stmt->execute([
      ':status' => $newStatus,
      ':adminId' => $adminId,
      ':id' => $id
    ]);

    $action = $newStatus === 'ACTIVO' ? 'activado' : 'inactivado';

    echo json_encode([
      'success' => true,
      'message' => "Usuario $action exitosamente"
    ]);

  } catch (Exception $e) {
    error_log('Toggle user status error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Error al cambiar estado del usuario']);
  }
}
?>

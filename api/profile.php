<?php
// dashboard/api/profile.php
// API para gestionar el perfil del usuario logueado en BeaTaN Nexus

header('Content-Type: application/json');
session_start();
require_once __DIR__ . '/../config/db.php';

if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
  http_response_code(401);
  echo json_encode(['success' => false, 'error' => 'No autorizado']);
  exit;
}

$pdo = getDbConnection();
if (!$pdo) {
  http_response_code(500);
  echo json_encode(['success' => false, 'error' => 'Error de conexión a la base de datos']);
  exit;
}

$userId = $_SESSION['user_id'];
$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'GET') {
  try {
    $stmt = $pdo->prepare("SELECT USRIDXXX, USRNMEXX, USRMAILX, USRCELUL FROM BEATUSRS WHERE USRIDXXX = ?");
    $stmt->execute([$userId]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($user) {
      echo json_encode([
        'success' => true,
        'data' => [
          'cedula' => $user['USRIDXXX'],
          'nombre' => $user['USRNMEXX'],
          'email' => $user['USRMAILX'],
          'celular' => $user['USRCELUL']
        ]
      ]);
    } else {
      http_response_code(404);
      echo json_encode(['success' => false, 'error' => 'Usuario no encontrado']);
    }
  } catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
  }
  exit;
}

if ($method === 'PUT') {
  $input = json_decode(file_get_contents('php://input'), true);
  $nombre = trim($input['nombre'] ?? '');
  $email = trim($input['email'] ?? '');
  $celular = trim($input['celular'] ?? '');
  $oldPassword = $input['old_password'] ?? '';
  $newPassword = $input['new_password'] ?? '';
  
  if (empty($nombre) || empty($email)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Nombre y Email son obligatorios']);
    exit;
  }
  
  try {
    // Si quiere cambiar contraseña, validar antigua
    if (!empty($newPassword)) {
      if (strlen($newPassword) < 6) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'La nueva contraseña debe tener al menos 6 caracteres']);
        exit;
      }
      
      $stmt = $pdo->prepare("SELECT USRPASXX FROM BEATUSRS WHERE USRIDXXX = ?");
      $stmt->execute([$userId]);
      $user = $stmt->fetch();
      
      if (!$user || !password_verify($oldPassword, $user['USRPASXX'])) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'La contraseña actual es incorrecta']);
        exit;
      }
      
      $newHash = password_hash($newPassword, PASSWORD_DEFAULT);
      $stmt = $pdo->prepare("UPDATE BEATUSRS SET USRNMEXX = ?, USRMAILX = ?, USRCELUL = ?, USRPASXX = ? WHERE USRIDXXX = ?");
      $stmt->execute([$nombre, $email, $celular, $newHash, $userId]);
    } else {
      $stmt = $pdo->prepare("UPDATE BEATUSRS SET USRNMEXX = ?, USRMAILX = ?, USRCELUL = ? WHERE USRIDXXX = ?");
      $stmt->execute([$nombre, $email, $celular, $userId]);
    }
    
    // Actualizar variables de sesión
    $_SESSION['user_name'] = $nombre;
    $_SESSION['user_email'] = $email;
    
    echo json_encode(['success' => true, 'message' => 'Perfil actualizado correctamente']);
  } catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
  }
  exit;
}
?>

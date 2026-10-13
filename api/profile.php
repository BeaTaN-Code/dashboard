<?php
// dashboard/api/profile.php
// API para gestionar el perfil del usuario logueado en BeaTaN Nexus

require_once __DIR__ . '/../config/api_helper.php';
api_bootstrap();

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
    api_log_error(basename(__FILE__), $e);
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'No se pudo completar la operación']);
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
  if (!filter_var($email, FILTER_VALIDATE_EMAIL) || mb_strlen($nombre) > 50 || mb_strlen($celular) > 20) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Revisa el email, el nombre (máx. 50) y el celular (máx. 20)']);
    exit;
  }
  
  try {
    $dup = $pdo->prepare("SELECT USRIDXXX FROM BEATUSRS WHERE USRMAILX = ? AND USRIDXXX <> ?");
    $dup->execute([$email, $userId]);
    if ($dup->fetch()) {
      http_response_code(400);
      echo json_encode(['success' => false, 'error' => 'Ese email ya está en uso por otra cuenta']);
      exit;
    }

    // Si quiere cambiar contraseña, validar antigua
    if (!empty($newPassword)) {
      if (strlen($newPassword) < 8) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'La nueva contraseña debe tener al menos 8 caracteres']);
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
      session_regenerate_id(true);
    } else {
      $stmt = $pdo->prepare("UPDATE BEATUSRS SET USRNMEXX = ?, USRMAILX = ?, USRCELUL = ? WHERE USRIDXXX = ?");
      $stmt->execute([$nombre, $email, $celular, $userId]);
    }
    
    // Actualizar variables de sesión
    $_SESSION['user_name'] = $nombre;
    $_SESSION['user_email'] = $email;
    
    echo json_encode(['success' => true, 'message' => 'Perfil actualizado correctamente']);
  } catch (Exception $e) {
    api_log_error(basename(__FILE__), $e);
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'No se pudo completar la operación']);
  }
  exit;
}
?>

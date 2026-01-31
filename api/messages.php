<?php
// dashboard/api/messages.php
// API para gestionar mensajes del formulario

header('Content-Type: application/json');
session_start();
require_once __DIR__ . '/../config/db.php';

// Verificar autenticación
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

$method = $_SERVER['REQUEST_METHOD'];
$userId = $_SESSION['user_id'];

switch ($method) {
  case 'GET':
    getMessage($pdo);
    break;
  case 'PUT':
    updateMessageStatus($pdo, $userId);
    break;
  default:
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Método no permitido']);
}

function getMessage($pdo)
{
  $id = intval($_GET['id'] ?? 0);

  if ($id <= 0) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'ID inválido']);
    return;
  }

  try {
    $stmt = $pdo->prepare("SELECT * FROM FORMULARIO WHERE ID = :id AND REGESTXX = 'ACTIVO'");
    $stmt->execute([':id' => $id]);
    $message = $stmt->fetch();

    if (!$message) {
      http_response_code(404);
      echo json_encode(['success' => false, 'error' => 'Mensaje no encontrado']);
      return;
    }

    echo json_encode([
      'success' => true,
      'data' => $message
    ]);

  } catch (Exception $e) {
    error_log('Get message error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Error al obtener mensaje']);
  }
}

function updateMessageStatus($pdo, $userId)
{
  $input = json_decode(file_get_contents('php://input'), true);

  $id = intval($input['id'] ?? 0);
  $estado = $input['estado'] ?? '';

  $validStates = ['PENDIENTE', 'LEIDO', 'RESPONDIDO', 'CERRADO'];

  if ($id <= 0 || !in_array($estado, $validStates)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Datos inválidos']);
    return;
  }

  try {
    $stmt = $pdo->prepare("
            UPDATE FORMULARIO 
            SET ESTADO = :estado, 
                USUMOD = :userId,
                REGESTAMP = CURRENT_TIMESTAMP
            WHERE ID = :id
        ");

    $stmt->execute([
      ':estado' => $estado,
      ':userId' => $userId,
      ':id' => $id
    ]);

    echo json_encode([
      'success' => true,
      'message' => 'Estado actualizado exitosamente'
    ]);

  } catch (Exception $e) {
    error_log('Update message status error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Error al actualizar estado']);
  }
}
?>
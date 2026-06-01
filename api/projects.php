<?php
// dashboard/api/projects.php
// API para gestionar proyectos de BeaTaN (solo admin)

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
  echo json_encode(['success' => false, 'error' => 'Acceso denegado. Se requiere administrador']);
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
    getProjects($pdo);
    break;
  case 'POST':
    createProject($pdo, $adminId);
    break;
  case 'PUT':
    updateProject($pdo, $adminId);
    break;
  case 'DELETE':
    deleteProject($pdo, $adminId);
    break;
  default:
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Metodo no permitido']);
}

function getProjects($pdo) {
  try {
    $stmt = $pdo->query("SELECT * FROM BEATPROY WHERE REGESTXX = 'ACTIVO' ORDER BY PROYIDXX DESC");
    $projects = $stmt->fetchAll();
    echo json_encode(['success' => true, 'data' => $projects]);
  } catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Error al obtener proyectos: ' . $e->getMessage()]);
  }
}

function createProject($pdo, $adminId) {
  $input = json_decode(file_get_contents('php://input'), true);
  $nombre = trim($input['nombre'] ?? '');
  $descripcion = trim($input['descripcion'] ?? '');
  $estado = trim($input['estado'] ?? 'PLANIFICACION');

  if (empty($nombre)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'El nombre del proyecto es requerido']);
    return;
  }

  try {
    $stmt = $pdo->prepare("
      INSERT INTO BEATPROY (PROYNOMX, PROYDESX, PROYESTX, REGUSRXX, REGFECXX, REGHORXX, REGUSRMX, REGFECMX, REGHORMX, REGESTXX, REGSTAMP)
      VALUES (:nombre, :descripcion, :estado, :adminId, CURDATE(), CURTIME(), :adminId, CURDATE(), CURTIME(), 'ACTIVO', CURRENT_TIMESTAMP)
    ");
    $stmt->execute([
      ':nombre' => $nombre,
      ':descripcion' => $descripcion,
      ':estado' => $estado,
      ':adminId' => $adminId
    ]);
    echo json_encode(['success' => true, 'message' => 'Proyecto creado exitosamente', 'id' => $pdo->lastInsertId()]);
  } catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Error al crear proyecto: ' . $e->getMessage()]);
  }
}

function updateProject($pdo, $adminId) {
  $input = json_decode(file_get_contents('php://input'), true);
  $id = intval($input['id'] ?? 0);
  $nombre = trim($input['nombre'] ?? '');
  $descripcion = trim($input['descripcion'] ?? '');
  $estado = trim($input['estado'] ?? '');

  if ($id <= 0 || empty($nombre)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'ID y nombre son requeridos']);
    return;
  }

  try {
    $stmt = $pdo->prepare("
      UPDATE BEATPROY 
      SET PROYNOMX = :nombre, 
          PROYDESX = :descripcion, 
          PROYESTX = :estado, 
          REGUSRMX = :adminId, 
          REGFECMX = CURDATE(), 
          REGHORMX = CURTIME()
      WHERE PROYIDXX = :id AND REGESTXX = 'ACTIVO'
    ");
    $stmt->execute([
      ':nombre' => $nombre,
      ':descripcion' => $descripcion,
      ':estado' => $estado,
      ':adminId' => $adminId,
      ':id' => $id
    ]);
    echo json_encode(['success' => true, 'message' => 'Proyecto actualizado exitosamente']);
  } catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Error al actualizar proyecto: ' . $e->getMessage()]);
  }
}

function deleteProject($pdo, $adminId) {
  $id = intval($_GET['id'] ?? 0);

  if ($id <= 0) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'ID de proyecto inválido']);
    return;
  }

  try {
    $stmt = $pdo->prepare("
      UPDATE BEATPROY 
      SET REGESTXX = 'INACTIVO', 
          REGUSRMX = :adminId, 
          REGFECMX = CURDATE(), 
          REGHORMX = CURTIME()
      WHERE PROYIDXX = :id
    ");
    $stmt->execute([
      ':adminId' => $adminId,
      ':id' => $id
    ]);
    echo json_encode(['success' => true, 'message' => 'Proyecto eliminado exitosamente']);
  } catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Error al eliminar proyecto: ' . $e->getMessage()]);
  }
}

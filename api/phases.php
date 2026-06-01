<?php
// dashboard/api/phases.php
// API para gestionar las fases e hitos de proyectos (solo admin)

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
    getPhases($pdo);
    break;
  case 'POST':
    createPhase($pdo, $adminId);
    break;
  case 'PUT':
    updatePhase($pdo, $adminId);
    break;
  case 'DELETE':
    deletePhase($pdo, $adminId);
    break;
  default:
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Metodo no permitido']);
}

function getPhases($pdo) {
  $projectId = intval($_GET['proyidxx'] ?? 0);

  if ($projectId <= 0) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'ID de proyecto requerido']);
    return;
  }

  try {
    $stmt = $pdo->prepare("
      SELECT * FROM BEATFASE 
      WHERE PROYIDXX = :projectId AND REGESTXX = 'ACTIVO' 
      ORDER BY FASEFECI ASC, FASEIDXX ASC
    ");
    $stmt->execute([':projectId' => $projectId]);
    $phases = $stmt->fetchAll();
    echo json_encode(['success' => true, 'data' => $phases]);
  } catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Error al obtener fases: ' . $e->getMessage()]);
  }
}

function createPhase($pdo, $adminId) {
  $input = json_decode(file_get_contents('php://input'), true);

  $projectId = intval($input['proyidxx'] ?? 0);
  $nombre    = trim($input['fasenomx'] ?? '');
  $fechaI    = trim($input['fasefeci'] ?? '');
  $fechaT    = trim($input['fasefect'] ?? '');
  $esHito    = trim($input['fasehito'] ?? 'NO');

  if ($projectId <= 0 || empty($nombre) || empty($fechaI) || empty($fechaT)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Proyecto, nombre, fecha inicio y término son requeridos']);
    return;
  }

  if (!in_array($esHito, ['SI', 'NO'])) {
    $esHito = 'NO';
  }

  try {
    $stmt = $pdo->prepare("
      INSERT INTO BEATFASE (
        PROYIDXX, FASENOMX, FASEFECI, FASEFECT, FASEHITO, 
        REGUSRXX, REGFECXX, REGHORXX, 
        REGUSRMX, REGFECMX, REGHORMX, 
        REGESTXX, REGSTAMP
      ) VALUES (
        :projectId, :nombre, :fechaI, :fechaT, :esHito, 
        :adminId, CURDATE(), CURTIME(), 
        :adminId, CURDATE(), CURTIME(), 
        'ACTIVO', CURRENT_TIMESTAMP
      )
    ");
    $stmt->execute([
      ':projectId' => $projectId,
      ':nombre'    => $nombre,
      ':fechaI'    => $fechaI,
      ':fechaT'    => $fechaT,
      ':esHito'    => $esHito,
      ':adminId'   => $adminId
    ]);
    echo json_encode(['success' => true, 'message' => 'Fase creada exitosamente', 'id' => $pdo->lastInsertId()]);
  } catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Error al crear fase: ' . $e->getMessage()]);
  }
}

function updatePhase($pdo, $adminId) {
  $input = json_decode(file_get_contents('php://input'), true);

  $id     = intval($input['id'] ?? 0);
  $nombre = trim($input['fasenomx'] ?? '');
  $fechaI = trim($input['fasefeci'] ?? '');
  $fechaT = trim($input['fasefect'] ?? '');
  $esHito = trim($input['fasehito'] ?? 'NO');

  if ($id <= 0 || empty($nombre) || empty($fechaI) || empty($fechaT)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'ID, nombre, fecha inicio y término son requeridos']);
    return;
  }

  if (!in_array($esHito, ['SI', 'NO'])) {
    $esHito = 'NO';
  }

  try {
    $stmt = $pdo->prepare("
      UPDATE BEATFASE 
      SET FASENOMX = :nombre, 
          FASEFECI = :fechaI, 
          FASEFECT = :fechaT, 
          FASEHITO = :esHito, 
          REGUSRMX = :adminId, 
          REGFECMX = CURDATE(), 
          REGHORMX = CURTIME()
      WHERE FASEIDXX = :id AND REGESTXX = 'ACTIVO'
    ");
    $stmt->execute([
      ':nombre'  => $nombre,
      ':fechaI'  => $fechaI,
      ':fechaT'  => $fechaT,
      ':esHito'  => $esHito,
      ':adminId' => $adminId,
      ':id'      => $id
    ]);
    echo json_encode(['success' => true, 'message' => 'Fase actualizada exitosamente']);
  } catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Error al actualizar fase: ' . $e->getMessage()]);
  }
}

function deletePhase($pdo, $adminId) {
  $id = intval($_GET['id'] ?? 0);

  if ($id <= 0) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'ID de fase inválido']);
    return;
  }

  try {
    $stmt = $pdo->prepare("
      UPDATE BEATFASE 
      SET REGESTXX = 'INACTIVO', 
          REGUSRMX = :adminId, 
          REGFECMX = CURDATE(), 
          REGHORMX = CURTIME()
      WHERE FASEIDXX = :id
    ");
    $stmt->execute([
      ':adminId' => $adminId,
      ':id'      => $id
    ]);
    echo json_encode(['success' => true, 'message' => 'Fase eliminada exitosamente']);
  } catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Error al eliminar fase: ' . $e->getMessage()]);
  }
}

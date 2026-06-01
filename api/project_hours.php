<?php
// dashboard/api/project_hours.php
// API para gestionar el cronograma de horas de proyectos (solo admin)

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
    getHoursLogs($pdo);
    break;
  case 'POST':
    createHoursLog($pdo, $adminId);
    break;
  case 'DELETE':
    deleteHoursLog($pdo, $adminId);
    break;
  default:
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Metodo no permitido']);
}

function getHoursLogs($pdo) {
  $projectId = intval($_GET['proyidxx'] ?? 0);
  $userId = trim($_GET['usridxxx'] ?? '');
  $startDate = trim($_GET['start_date'] ?? '');
  $endDate = trim($_GET['end_date'] ?? '');

  try {
    $sql = "
      SELECT h.*, p.PROYNOMX, u.USRNMEXX 
      FROM BEATHORA h
      INNER JOIN BEATPROY p ON h.PROYIDXX = p.PROYIDXX
      INNER JOIN BEATUSRS u ON h.USRIDXXX = u.USRIDXXX
      WHERE h.REGESTXX = 'ACTIVO'
    ";
    
    $params = [];

    if ($projectId > 0) {
      $sql .= " AND h.PROYIDXX = :projectId";
      $params[':projectId'] = $projectId;
    }
    if (!empty($userId)) {
      $sql .= " AND h.USRIDXXX = :userId";
      $params[':userId'] = $userId;
    }
    if (!empty($startDate)) {
      $sql .= " AND h.HORAFECX >= :startDate";
      $params[':startDate'] = $startDate;
    }
    if (!empty($endDate)) {
      $sql .= " AND h.HORAFECX <= :endDate";
      $params[':endDate'] = $endDate;
    }

    $sql .= " ORDER BY h.HORAFECX DESC, h.HORAIDXX DESC";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $logs = $stmt->fetchAll();

    echo json_encode(['success' => true, 'data' => $logs]);
  } catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Error al obtener horas: ' . $e->getMessage()]);
  }
}

function createHoursLog($pdo, $adminId) {
  $input = json_decode(file_get_contents('php://input'), true);
  
  $projectId   = intval($input['proyidxx'] ?? 0);
  $userId      = trim($input['usridxxx'] ?? '');
  $hours       = floatval($input['horadedx'] ?? 0);
  $date        = trim($input['horafecx'] ?? '');
  $description = trim($input['horadesx'] ?? '');

  if ($projectId <= 0 || empty($userId) || $hours <= 0 || empty($date)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Proyecto, desarrollador, horas y fecha son requeridos']);
    return;
  }

  try {
    $stmt = $pdo->prepare("
      INSERT INTO BEATHORA (PROYIDXX, USRIDXXX, HORADEDX, HORAFECX, HORADESX, REGUSRXX, REGFECXX, REGHORXX, REGUSRMX, REGFECMX, REGHORMX, REGESTXX, REGSTAMP)
      VALUES (:projectId, :userId, :hours, :date, :description, :adminId, CURDATE(), CURTIME(), :adminId, CURDATE(), CURTIME(), 'ACTIVO', CURRENT_TIMESTAMP)
    ");
    $stmt->execute([
      ':projectId' => $projectId,
      ':userId' => $userId,
      ':hours' => $hours,
      ':date' => $date,
      ':description' => $description,
      ':adminId' => $adminId
    ]);
    echo json_encode(['success' => true, 'message' => 'Horas registradas exitosamente', 'id' => $pdo->lastInsertId()]);
  } catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Error al registrar horas: ' . $e->getMessage()]);
  }
}

function deleteHoursLog($pdo, $adminId) {
  $id = intval($_GET['id'] ?? 0);

  if ($id <= 0) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'ID de registro de horas inválido']);
    return;
  }

  try {
    $stmt = $pdo->prepare("
      UPDATE BEATHORA 
      SET REGESTXX = 'INACTIVO', 
          REGUSRMX = :adminId, 
          REGFECMX = CURDATE(), 
          REGHORMX = CURTIME()
      WHERE HORAIDXX = :id
    ");
    $stmt->execute([
      ':adminId' => $adminId,
      ':id' => $id
    ]);
    echo json_encode(['success' => true, 'message' => 'Registro de horas eliminado exitosamente']);
  } catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Error al eliminar horas: ' . $e->getMessage()]);
  }
}

<?php
// dashboard/api/debts.php
// API para gestionar deudas

header('Content-Type: application/json');
date_default_timezone_set('America/Bogota');
session_start();
require_once __DIR__ . '/../config/db.php';

// Verificar autenticacion
if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
  http_response_code(401);
  echo json_encode(['success' => false, 'error' => 'No autorizado']);
  exit;
}

$pdo = getDbConnection();
if (!$pdo) {
  http_response_code(500);
  echo json_encode(['success' => false, 'error' => 'Error de conexion a la base de datos']);
  exit;
}

$method = $_SERVER['REQUEST_METHOD'];
$userId = $_SESSION['user_id'];
$isAdmin = $_SESSION['is_admin'] ?? false;

switch ($method) {
  case 'GET':
    getDebts($pdo, $userId, $isAdmin);
    break;
  case 'POST':
    addDebt($pdo, $userId);
    break;
  case 'PUT':
    updateDebt($pdo, $userId, $isAdmin);
    break;
  case 'DELETE':
    deleteDebt($pdo, $userId, $isAdmin);
    break;
  default:
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Metodo no permitido']);
}

/* ========================= GET ========================= */
function getDebts($pdo, $userId, $isAdmin)
{
  $tipperxx = $_GET['tipperxx'] ?? ''; // BEATAN o PERSONAL
  $tipdeudx = $_GET['tipdeudx'] ?? ''; // A FAVOR / EN CONTRA

  try {
    $sql = "SELECT * FROM DEUDASIX WHERE REGESTXX = 'ACTIVO'";
    $params = [];

    // Solo ver propias si no es admin o no es BEATAN
    if ($tipperxx !== 'BEATAN' || !$isAdmin) {
      $sql .= " AND USRIDXXX = :userId";
      $params[':userId'] = $userId;
    }

    if (!empty($tipperxx)) {
      $sql .= " AND TIPPERXX = :tipperxx";
      $params[':tipperxx'] = $tipperxx;
    }

    if (!empty($tipdeudx)) {
      $sql .= " AND TIPDEUDX = :tipdeudx";
      $params[':tipdeudx'] = $tipdeudx;
    }

    $sql .= " ORDER BY DEUDIDXX DESC";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $debts = $stmt->fetchAll();

    echo json_encode([
      'success' => true,
      'data' => $debts
    ]);

  } catch (Exception $e) {
    error_log('Get debts error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Error al obtener deudas']);
  }
}

/* ========================= POST ========================= */
function addDebt($pdo, $userId)
{
  $input = json_decode(file_get_contents('php://input'), true);

  $usridxxx = $input['usridxxx'] ?? $userId;
  $tipdeudx = $input['tipdeudx'] ?? '';
  $tipperxx = $input['tipperxx'] ?? '';
  $desdeudx = $input['desdeudx'] ?? '';
  $numcuotx = $input['numcuotx'] ?? null;
  $feccuotx = $input['feccuotx'] ?? null;
  $moncuotx = $input['moncuotx'] ?? null;
  $mondeudx = floatval($input['mondeudx'] ?? 0);

  if (empty($tipdeudx) || empty($tipperxx) || $mondeudx <= 0) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Datos incompletos']);
    return;
  }

  try {
    $stmt = $pdo->prepare("
      INSERT INTO DEUDASIX
      (USRIDXXX, TIPDEUDX, TIPPERXX, DESDEUDX, NUMCUOTX, FECCUOTX, MONCUOTX,
       MONDEUDX, REGUSRXX, REGFECXX, REGHORXX, REGUSRMX, REGFECMX, REGHORMX, 
       REGESTXX, REGSTAMP)
      VALUES
      (:usridxxx, :tipdeudx, :tipperxx, :desdeudx, :numcuotx, :feccuotx, :moncuotx,
       :mondeudx, :userId, CURDATE(), CURTIME(), :userId, CURDATE(), CURTIME(), 
       'ACTIVO', NOW())
    ");

    $stmt->execute([
      ':usridxxx' => $usridxxx,
      ':tipdeudx' => $tipdeudx,
      ':tipperxx' => $tipperxx,
      ':desdeudx' => $desdeudx,
      ':numcuotx' => $numcuotx,
      ':feccuotx' => $feccuotx,
      ':moncuotx' => $moncuotx,
      ':mondeudx' => $mondeudx,
      ':userId' => $userId
    ]);

    echo json_encode([
      'success' => true,
      'message' => 'Deuda registrada exitosamente',
      'id' => $pdo->lastInsertId()
    ]);

  } catch (Exception $e) {
    error_log('Add debt error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Error al registrar deuda']);
  }
}

/* ========================= PUT ========================= */
function updateDebt($pdo, $userId, $isAdmin)
{
  $input = json_decode(file_get_contents('php://input'), true);

  $id = intval($input['id'] ?? 0);
  if ($id <= 0) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'ID invalido']);
    return;
  }

  $stmt = $pdo->prepare("SELECT * FROM DEUDASIX WHERE DEUDIDXX = :id");
  $stmt->execute([':id' => $id]);
  $debt = $stmt->fetch();

  if (!$debt) {
    http_response_code(404);
    echo json_encode(['success' => false, 'error' => 'Deuda no encontrada']);
    return;
  }

  $canEdit = ($debt['USRIDXXX'] == $userId) || ($isAdmin && $debt['TIPPERXX'] === 'BEATAN');

  if (!$canEdit) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'No autorizado para editar']);
    return;
  }

  $stmt = $pdo->prepare("
    UPDATE DEUDASIX SET
      TIPDEUDX = :tipdeudx,
      MONDEUDX = :mondeudx,
      DESDEUDX = :desdeudx,
      NUMCUOTX = :numcuotx,
      FECCUOTX = :feccuotx,
      MONCUOTX = :moncuotx,
      REGUSRMX = :userId,
      REGFECMX = CURDATE(),
      REGHORMX = CURTIME(),
      REGSTAMP = NOW()
    WHERE DEUDIDXX = :id
  ");

  $stmt->execute([
    ':tipdeudx' => $input['tipdeudx'],
    ':mondeudx' => $input['mondeudx'],
    ':desdeudx' => $input['desdeudx'],
    ':numcuotx' => $input['numcuotx'],
    ':feccuotx' => $input['feccuotx'],
    ':moncuotx' => $input['moncuotx'],
    ':userId' => $userId,
    ':id' => $id
  ]);

  echo json_encode(['success' => true, 'message' => 'Deuda actualizada']);
}

/* ========================= DELETE ========================= */
function deleteDebt($pdo, $userId, $isAdmin)
{
  $id = intval($_GET['id'] ?? 0);

  if ($id <= 0) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'ID invalido']);
    return;
  }

  $stmt = $pdo->prepare("SELECT * FROM DEUDASIX WHERE DEUDIDXX = :id");
  $stmt->execute([':id' => $id]);
  $debt = $stmt->fetch();

  if (!$debt) {
    http_response_code(404);
    echo json_encode(['success' => false, 'error' => 'Deuda no encontrada']);
    return;
  }

  $canDelete = ($debt['USRIDXXX'] == $userId) || ($isAdmin && $debt['TIPPERXX'] === 'BEATAN');

  if (!$canDelete) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'No autorizado para eliminar']);
    return;
  }

  $stmt = $pdo->prepare("
    UPDATE DEUDASIX SET
      REGESTXX = 'INACTIVO',
      REGUSRMX = :userId,
      REGFECMX = CURDATE(),
      REGHORMX = CURTIME(),
      REGSTAMP = NOW()
    WHERE DEUDIDXX = :id
  ");

  $stmt->execute([
    ':userId' => $userId,
    ':id' => $id
  ]);

  echo json_encode(['success' => true, 'message' => 'Deuda eliminada']);
}
?>
<?php
// dashboard/api/transactions.php
// API para gestionar transacciones financieras

header('Content-Type: application/json');
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
    getTransactions($pdo, $userId, $isAdmin);
    break;
  case 'POST':
    addTransaction($pdo, $userId);
    break;
  case 'PUT':
    updateTransaction($pdo, $userId, $isAdmin);
    break;
  case 'DELETE':
    deleteTransaction($pdo, $userId, $isAdmin);
    break;
  default:
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Metodo no permitido']);
}

function getTransactions($pdo, $userId, $isAdmin)
{
  $tipgasxx = $_GET['tipgasxx'] ?? '';
  $tipo = $_GET['tipo'] ?? '';
  $month = $_GET['month'] ?? '';

  // Fecha actual para filtrar balances
  $today = date('Y-m-d');

  try {
    // Query base - solo transacciones activas
    $sql = "SELECT * FROM FINANCIX WHERE REGESTXX = 'ACTIVO'";
    $params = [];

    // Solo filtrar por usuario si NO es BEATAN o si no es admin
    if ($tipgasxx !== 'BEATAN' || !$isAdmin) {
      $sql .= " AND USRIDXXX = :userId";
      $params[':userId'] = $userId;
    }

    // Filtrar por tipo de cuenta (BEATAN o PERSONAL)
    if (!empty($tipgasxx)) {
      $sql .= " AND TIPGASXX = :tipgasxx";
      $params[':tipgasxx'] = $tipgasxx;
    }

    // Filtrar por tipo de movimiento (INGRESO o GASTO)
    if (!empty($tipo)) {
      if ($tipo === 'INGRESO') {
        $sql .= " AND MONTGASX > 0";
      } else {
        $sql .= " AND MONTGASX < 0";
      }
    }

    // Filtrar por mes si se especifica
    if (!empty($month)) {
      $sql .= " AND DATE_FORMAT(FINCFECX, '%Y-%m') = :month";
      $params[':month'] = $month;
    }

    $sql .= " ORDER BY FINCFECX DESC, FINCTIDX DESC";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $transactions = $stmt->fetchAll();

    // Calcular balances (solo transacciones hasta el dia actual)
    $balanceSql = "SELECT 
            COALESCE(SUM(CASE WHEN MONTGASX > 0 AND FINCFECX <= :today THEN MONTGASX ELSE 0 END), 0) as income,
            COALESCE(SUM(CASE WHEN MONTGASX < 0 AND FINCFECX <= :today2 THEN ABS(MONTGASX) ELSE 0 END), 0) as expense
            FROM FINANCIX 
            WHERE REGESTXX = 'ACTIVO'";

    $balanceParams = [
      ':today' => $today,
      ':today2' => $today
    ];

    // Solo filtrar por usuario si NO es BEATAN o si no es admin
    if ($tipgasxx !== 'BEATAN' || !$isAdmin) {
      $balanceSql .= " AND USRIDXXX = :userId";
      $balanceParams[':userId'] = $userId;
    }

    if (!empty($tipgasxx)) {
      $balanceSql .= " AND TIPGASXX = :tipgasxx";
      $balanceParams[':tipgasxx'] = $tipgasxx;
    }

    $stmt = $pdo->prepare($balanceSql);
    $stmt->execute($balanceParams);
    $balance = $stmt->fetch();

    $income = floatval($balance['income'] ?? 0);
    $expense = floatval($balance['expense'] ?? 0);
    $total = $income - $expense;

    echo json_encode([
      'success' => true,
      'data' => $transactions,
      'balance' => [
        'total' => $total,
        'income' => $income,
        'expense' => $expense
      ]
    ]);

  } catch (Exception $e) {
    error_log('Get transactions error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Error al obtener transacciones']);
  }
}

function addTransaction($pdo, $userId)
{
  $input = json_decode(file_get_contents('php://input'), true);

  $tipgasxx = $input['tipgasxx'] ?? '';
  $tipo = $input['tipo'] ?? '';
  $monto = floatval($input['monto'] ?? 0);
  $categoria = $input['categoria'] ?? '';
  $descripcion = $input['descripcion'] ?? '';
  $fecha = $input['fecha'] ?? date('Y-m-d');

  // Validaciones
  if (empty($tipgasxx) || empty($tipo) || $monto <= 0 || empty($categoria) || empty($fecha)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Datos incompletos']);
    return;
  }

  // Si es gasto, el monto debe ser negativo
  if ($tipo === 'GASTO') {
    $monto = -abs($monto);
  } else {
    $monto = abs($monto);
  }

  try {
    $stmt = $pdo->prepare("
            INSERT INTO FINANCIX (USRIDXXX, TIPGASXX, MONTGASX, CATGASXX, DESGASXX, FINCFECX, REGUSRXX, REGFECXX, REGHORXX, REGESTXX)
            VALUES (:userId, :tipgasxx, :monto, :categoria, :descripcion, :fecha, :userId, CURDATE(), CURTIME(), 'ACTIVO')
        ");

    $stmt->execute([
      ':userId' => $userId,
      ':tipgasxx' => $tipgasxx,
      ':monto' => $monto,
      ':categoria' => $categoria,
      ':descripcion' => $descripcion,
      ':fecha' => $fecha
    ]);

    $newId = $pdo->lastInsertId();

    echo json_encode([
      'success' => true,
      'message' => 'Transaccion agregada exitosamente',
      'id' => $newId
    ]);

  } catch (Exception $e) {
    error_log('Add transaction error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Error al agregar transaccion']);
  }
}

function updateTransaction($pdo, $userId, $isAdmin)
{
  $input = json_decode(file_get_contents('php://input'), true);

  $id = intval($input['id'] ?? 0);
  $tipo = $input['tipo'] ?? '';
  $monto = floatval($input['monto'] ?? 0);
  $categoria = $input['categoria'] ?? '';
  $descripcion = $input['descripcion'] ?? '';
  $fecha = $input['fecha'] ?? '';

  if ($id <= 0) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'ID invalido']);
    return;
  }

  // Si es gasto, el monto debe ser negativo
  if ($tipo === 'GASTO') {
    $monto = -abs($monto);
  } else {
    $monto = abs($monto);
  }

  try {
    // Verificar que la transaccion pertenece al usuario o es admin con BEATAN
    $stmt = $pdo->prepare("SELECT FINCTIDX, TIPGASXX, USRIDXXX FROM FINANCIX WHERE FINCTIDX = :id");
    $stmt->execute([':id' => $id]);
    $transaction = $stmt->fetch();

    if (!$transaction) {
      http_response_code(404);
      echo json_encode(['success' => false, 'error' => 'Transaccion no encontrada']);
      return;
    }

    // Permitir edicion si es el dueno o si es admin y es BEATAN
    $canEdit = ($transaction['USRIDXXX'] == $userId) || ($isAdmin && $transaction['TIPGASXX'] === 'BEATAN');

    if (!$canEdit) {
      http_response_code(403);
      echo json_encode(['success' => false, 'error' => 'No tiene permiso para editar esta transaccion']);
      return;
    }

    $stmt = $pdo->prepare("
            UPDATE FINANCIX 
            SET MONTGASX = :monto, 
                CATGASXX = :categoria, 
                DESGASXX = :descripcion, 
                FINCFECX = :fecha,
                REGUSRMX = :userId,
                REGFECMX = CURDATE(),
                REGHORMX = CURTIME()
            WHERE FINCTIDX = :id
        ");

    $stmt->execute([
      ':monto' => $monto,
      ':categoria' => $categoria,
      ':descripcion' => $descripcion,
      ':fecha' => $fecha,
      ':userId' => $userId,
      ':id' => $id
    ]);

    echo json_encode([
      'success' => true,
      'message' => 'Transaccion actualizada exitosamente'
    ]);

  } catch (Exception $e) {
    error_log('Update transaction error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Error al actualizar transaccion']);
  }
}

function deleteTransaction($pdo, $userId, $isAdmin)
{
  $id = intval($_GET['id'] ?? 0);

  if ($id <= 0) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'ID invalido']);
    return;
  }

  try {
    // Verificar que la transaccion pertenece al usuario o es admin con BEATAN
    $stmt = $pdo->prepare("SELECT FINCTIDX, TIPGASXX, USRIDXXX FROM FINANCIX WHERE FINCTIDX = :id");
    $stmt->execute([':id' => $id]);
    $transaction = $stmt->fetch();

    if (!$transaction) {
      http_response_code(404);
      echo json_encode(['success' => false, 'error' => 'Transaccion no encontrada']);
      return;
    }

    // Permitir eliminacion si es el dueno o si es admin y es BEATAN
    $canDelete = ($transaction['USRIDXXX'] == $userId) || ($isAdmin && $transaction['TIPGASXX'] === 'BEATAN');

    if (!$canDelete) {
      http_response_code(403);
      echo json_encode(['success' => false, 'error' => 'No tiene permiso para eliminar esta transaccion']);
      return;
    }

    // Soft delete - cambiar estado a INACTIVO
    $stmt = $pdo->prepare("
            UPDATE FINANCIX 
            SET REGESTXX = 'INACTIVO',
                REGUSRMX = :userId,
                REGFECMX = CURDATE(),
                REGHORMX = CURTIME()
            WHERE FINCTIDX = :id
        ");

    $stmt->execute([
      ':userId' => $userId,
      ':id' => $id
    ]);

    echo json_encode([
      'success' => true,
      'message' => 'Transaccion eliminada exitosamente'
    ]);

  } catch (Exception $e) {
    error_log('Delete transaction error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Error al eliminar transaccion']);
  }
}
?>
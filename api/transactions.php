<?php
// dashboard/api/transactions.php
// API para gestionar transacciones financieras

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
    getTransactions($pdo, $userId);
    break;
  case 'POST':
    addTransaction($pdo, $userId);
    break;
  case 'PUT':
    updateTransaction($pdo, $userId);
    break;
  case 'DELETE':
    deleteTransaction($pdo, $userId);
    break;
  default:
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Método no permitido']);
}

function getTransactions($pdo, $userId)
{
  $tipgasxx = $_GET['tipgasxx'] ?? '';
  $tipo = $_GET['tipo'] ?? '';
  $month = $_GET['month'] ?? '';

  // Fecha actual para filtrar balances
  $today = date('Y-m-d');

  try {
    // Query base - solo transacciones activas
    $sql = "SELECT * FROM FINANCIX WHERE USRIDXXX = :userId AND REGESTXX = 'ACTIVO'";
    $params = [':userId' => $userId];

    // Filtrar por tipo de cuenta (BEATAN o PERSONAL)
    if (!empty($tipgasxx)) {
      $sql .= " AND TIPGASXX = :tipgasxx";
      $params[':tipgasxx'] = $tipgasxx;
    }

    // Filtrar por tipo de movimiento (INGRESO o GASTO)
    if (!empty($tipo)) {
      $sql .= " AND CATGASXX IN (SELECT CATGASXX FROM FINANCIX WHERE ";
      if ($tipo === 'INGRESO') {
        $sql = "SELECT * FROM FINANCIX WHERE USRIDXXX = :userId AND REGESTXX = 'ACTIVO'";
        if (!empty($tipgasxx)) {
          $sql .= " AND TIPGASXX = :tipgasxx";
        }
        $sql .= " AND MONTGASX > 0";
      } else {
        $sql = "SELECT * FROM FINANCIX WHERE USRIDXXX = :userId AND REGESTXX = 'ACTIVO'";
        if (!empty($tipgasxx)) {
          $sql .= " AND TIPGASXX = :tipgasxx";
        }
        $sql .= " AND MONTGASX < 0";
      }
    }

    // Re-construir query con filtros correctos
    $sql = "SELECT * FROM FINANCIX WHERE USRIDXXX = :userId AND REGESTXX = 'ACTIVO'";
    $params = [':userId' => $userId];

    if (!empty($tipgasxx)) {
      $sql .= " AND TIPGASXX = :tipgasxx";
      $params[':tipgasxx'] = $tipgasxx;
    }

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

    // Calcular balances (solo transacciones hasta el día actual)
    $balanceSql = "SELECT 
            COALESCE(SUM(CASE WHEN MONTGASX > 0 AND FINCFECX <= :today THEN MONTGASX ELSE 0 END), 0) as income,
            COALESCE(SUM(CASE WHEN MONTGASX < 0 AND FINCFECX <= :today2 THEN ABS(MONTGASX) ELSE 0 END), 0) as expense
            FROM FINANCIX 
            WHERE USRIDXXX = :userId AND REGESTXX = 'ACTIVO'";

    $balanceParams = [
      ':userId' => $userId,
      ':today' => $today,
      ':today2' => $today
    ];

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
      'message' => 'Transacción agregada exitosamente',
      'id' => $newId
    ]);

  } catch (Exception $e) {
    error_log('Add transaction error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Error al agregar transacción']);
  }
}

function updateTransaction($pdo, $userId)
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
    echo json_encode(['success' => false, 'error' => 'ID inválido']);
    return;
  }

  // Si es gasto, el monto debe ser negativo
  if ($tipo === 'GASTO') {
    $monto = -abs($monto);
  } else {
    $monto = abs($monto);
  }

  try {
    // Verificar que la transacción pertenece al usuario
    $stmt = $pdo->prepare("SELECT FINCTIDX FROM FINANCIX WHERE FINCTIDX = :id AND USRIDXXX = :userId");
    $stmt->execute([':id' => $id, ':userId' => $userId]);

    if (!$stmt->fetch()) {
      http_response_code(403);
      echo json_encode(['success' => false, 'error' => 'No tiene permiso para editar esta transacción']);
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
            WHERE FINCTIDX = :id AND USRIDXXX = :userId2
        ");

    $stmt->execute([
      ':monto' => $monto,
      ':categoria' => $categoria,
      ':descripcion' => $descripcion,
      ':fecha' => $fecha,
      ':userId' => $userId,
      ':id' => $id,
      ':userId2' => $userId
    ]);

    echo json_encode([
      'success' => true,
      'message' => 'Transacción actualizada exitosamente'
    ]);

  } catch (Exception $e) {
    error_log('Update transaction error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Error al actualizar transacción']);
  }
}

function deleteTransaction($pdo, $userId)
{
  $id = intval($_GET['id'] ?? 0);

  if ($id <= 0) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'ID inválido']);
    return;
  }

  try {
    // Verificar que la transacción pertenece al usuario
    $stmt = $pdo->prepare("SELECT FINCTIDX FROM FINANCIX WHERE FINCTIDX = :id AND USRIDXXX = :userId");
    $stmt->execute([':id' => $id, ':userId' => $userId]);

    if (!$stmt->fetch()) {
      http_response_code(403);
      echo json_encode(['success' => false, 'error' => 'No tiene permiso para eliminar esta transacción']);
      return;
    }

    // Soft delete - cambiar estado a INACTIVO
    $stmt = $pdo->prepare("
            UPDATE FINANCIX 
            SET REGESTXX = 'INACTIVO',
                REGUSRMX = :userId,
                REGFECMX = CURDATE(),
                REGHORMX = CURTIME()
            WHERE FINCTIDX = :id AND USRIDXXX = :userId2
        ");

    $stmt->execute([
      ':userId' => $userId,
      ':id' => $id,
      ':userId2' => $userId
    ]);

    echo json_encode([
      'success' => true,
      'message' => 'Transacción eliminada exitosamente'
    ]);

  } catch (Exception $e) {
    error_log('Delete transaction error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Error al eliminar transacción']);
  }
}
?>
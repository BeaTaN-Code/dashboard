<?php
// dashboard/api/notifications.php
// API para gestionar notificaciones push

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

if ($method !== 'GET') {
  http_response_code(405);
  echo json_encode(['success' => false, 'error' => 'Metodo no permitido']);
  exit;
}

$notifications = [];
$today = date('Y-m-d');
$nextWeek = date('Y-m-d', strtotime('+7 days'));

try {
  // 1. Obtener gastos proximos (para todos los usuarios)
  $stmt = $pdo->prepare("
        SELECT FINCTIDX, CATGASXX, DESGASXX, MONTGASX, FINCFECX 
        FROM FINANCIX 
        WHERE REGESTXX = 'ACTIVO' 
        AND MONTGASX < 0 
        AND FINCFECX BETWEEN :today AND :nextWeek
        AND USRIDXXX = :userId
        ORDER BY FINCFECX ASC
        LIMIT 10
    ");
  $stmt->execute([':today' => $today, ':nextWeek' => $nextWeek, ':userId' => $userId]);
  $upcomingExpenses = $stmt->fetchAll();

  foreach ($upcomingExpenses as $expense) {
    $daysUntil = (strtotime($expense['FINCFECX']) - strtotime($today)) / 86400;
    $urgency = $daysUntil <= 2 ? 'urgent' : 'warning';

    $notifications[] = [
      'id' => 'expense_' . $expense['FINCTIDX'],
      'type' => 'expense',
      'urgency' => $urgency,
      'title' => 'Gasto Proximo',
      'message' => htmlspecialchars($expense['CATGASXX']) . ': $' . number_format(abs($expense['MONTGASX']), 2),
      'detail' => htmlspecialchars($expense['DESGASXX'] ?? ''),
      'date' => date('d/m/Y', strtotime($expense['FINCFECX'])),
      'daysUntil' => $daysUntil,
      'icon' => 'bi-cash-coin'
    ];
  }

  // 2. Obtener mensajes nuevos (solo para admin)
  if ($isAdmin) {
    $stmt = $pdo->query("
            SELECT ID, NOMBRE, ASUNTO, HORCREA 
            FROM FORMULARIO 
            WHERE REGESTXX = 'ACTIVO' AND ESTADO = 'PENDIENTE'
            ORDER BY HORCREA DESC
            LIMIT 5
        ");
    $newMessages = $stmt->fetchAll();

    foreach ($newMessages as $message) {
      $notifications[] = [
        'id' => 'message_' . $message['ID'],
        'type' => 'message',
        'urgency' => 'info',
        'title' => 'Nuevo Mensaje',
        'message' => htmlspecialchars($message['NOMBRE']),
        'detail' => htmlspecialchars($message['ASUNTO'] ?? 'Sin asunto'),
        'date' => date('d/m/Y H:i', strtotime($message['HORCREA'])),
        'icon' => 'bi-envelope'
      ];
    }
  }

  // 3. Verificar balance negativo
  $stmt = $pdo->prepare("
        SELECT 
            COALESCE(SUM(CASE WHEN MONTGASX > 0 AND FINCFECX <= :today THEN MONTGASX ELSE 0 END), 0) -
            COALESCE(SUM(CASE WHEN MONTGASX < 0 AND FINCFECX <= :today2 THEN ABS(MONTGASX) ELSE 0 END), 0) as balance
        FROM FINANCIX 
        WHERE USRIDXXX = :userId AND REGESTXX = 'ACTIVO' AND TIPGASXX = 'PERSONAL'
    ");
  $stmt->execute([':today' => $today, ':today2' => $today, ':userId' => $userId]);
  $balance = $stmt->fetch();

  if ($balance && $balance['balance'] < 0) {
    $notifications[] = [
      'id' => 'balance_negative',
      'type' => 'alert',
      'urgency' => 'danger',
      'title' => 'Balance Negativo',
      'message' => 'Tu balance personal es negativo',
      'detail' => 'Balance actual: $' . number_format($balance['balance'], 2),
      'date' => date('d/m/Y'),
      'icon' => 'bi-exclamation-triangle'
    ];
  }

  // Ordenar por urgencia
  usort($notifications, function ($a, $b) {
    $order = ['danger' => 0, 'urgent' => 1, 'warning' => 2, 'info' => 3];
    return ($order[$a['urgency']] ?? 4) - ($order[$b['urgency']] ?? 4);
  });

  echo json_encode([
    'success' => true,
    'data' => $notifications,
    'count' => count($notifications)
  ]);

} catch (Exception $e) {
  error_log('Get notifications error: ' . $e->getMessage());
  http_response_code(500);
  echo json_encode(['success' => false, 'error' => 'Error al obtener notificaciones']);
}
?>
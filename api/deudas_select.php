<?php
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

$stmt = $pdo->prepare("
  SELECT DISTINCT
    D.DEUDIDXX,
    D.DESDEUDX,
    D.USRIDXXX
  FROM DEUDASIX D
  WHERE D.REGESTXX = 'ACTIVO'
    AND D.NUMCUOTX IS NULL
    AND D.USRIDXXX = :usrid
  ORDER BY D.DEUDIDXX ASC
");

$stmt->bindParam(':usrid', $userId, PDO::PARAM_INT);
$stmt->execute();

$data = $stmt->fetchAll(PDO::FETCH_ASSOC);

echo json_encode([
  'success' => true,
  'data' => $data
]);
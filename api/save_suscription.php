<?php 
session_start();
require_once __DIR__ . '/../config/db.php';
$raw = file_get_contents("php://input");
$data = json_decode($raw, true);

if (!$data) {
  http_response_code(400);
  echo "No JSON recibido";
  exit;
}

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

$endpoint = $data['endpoint'];
$p256dh   = $data['keys']['p256dh'];
$auth     = $data['keys']['auth'];

/* 🔍 Buscar si ya existe */
$check = $pdo->prepare("
  SELECT id 
  FROM push_subscriptions 
  WHERE endpoint = ? 
    AND p256dh = ? 
    AND auth = ?
  LIMIT 1
");
$check->execute([$endpoint, $p256dh, $auth]);

if ($check->fetch()) {
  echo "YA_EXISTE";
  exit;
}

/* ✅ Insertar si no existe */
$stmt = $pdo->prepare("
  INSERT INTO push_subscriptions (endpoint, p256dh, auth)
  VALUES (?, ?, ?)
");

$stmt->execute([$endpoint, $p256dh, $auth]);

echo "INSERTADO";
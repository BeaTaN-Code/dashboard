<?php 
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

$stmt = $pdo->prepare("INSERT INTO push_subscriptions (endpoint, p256dh, auth) VALUES (?,?,?)");

$stmt->execute([
  $data['endpoint'],
  $data['keys']['p256dh'],
  $data['keys']['auth']
]);
<?php
// dashboard/api/save_suscription.php
// Guarda la suscripción Web Push del navegador para el usuario de la sesión.

require_once __DIR__ . '/../config/api_helper.php';
api_bootstrap();
$auth = api_require_auth();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
  api_response(false, 'Método no permitido', 405);
}

$data = api_get_json_input(16384);
$endpoint = $data['endpoint'] ?? '';
$p256dh = $data['keys']['p256dh'] ?? '';
$authKey = $data['keys']['auth'] ?? '';

// Solo endpoints HTTPS de servicios push y claves en base64url razonables
if (!is_string($endpoint) || strlen($endpoint) > 1000 || !preg_match('#^https://[^\s]+$#', $endpoint)
  || !is_string($p256dh) || !preg_match('/^[A-Za-z0-9_-]{20,200}={0,2}$/', $p256dh)
  || !is_string($authKey) || !preg_match('/^[A-Za-z0-9_-]{8,100}={0,2}$/', $authKey)) {
  api_response(false, 'Suscripción inválida', 400);
}

$pdo = api_db();
try {
  $check = $pdo->prepare("SELECT id FROM push_subscriptions WHERE endpoint = ? AND p256dh = ? AND auth = ? AND USRIDXXX = ? LIMIT 1");
  $check->execute([$endpoint, $p256dh, $authKey, $auth['user_id']]);
  if ($check->fetch()) {
    api_response(true, ['status' => 'YA_EXISTE']);
  }

  $stmt = $pdo->prepare("INSERT INTO push_subscriptions (USRIDXXX, endpoint, p256dh, auth) VALUES (?, ?, ?, ?)");
  $stmt->execute([$auth['user_id'], $endpoint, $p256dh, $authKey]);
  api_response(true, ['status' => 'INSERTADO']);
} catch (PDOException $e) {
  api_log_error('save_suscription', $e);
  api_response(false, 'No se pudo guardar la suscripción', 500);
}

<?php
require 'vendor/autoload.php';
use Minishlink\WebPush\WebPush;
use Minishlink\WebPush\Subscription;

header('Content-Type: application/json');
session_start();
require_once __DIR__ . '/../config/db.php';

// Verificar autenticacion
if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
  http_response_code(401);
  echo json_encode(['success' => false, 'error' => 'No autorizado']);
  exit;
}

$envPaths = [
  __DIR__ . '/../../.env',
  __DIR__ . '/../.env',
  $_SERVER['DOCUMENT_ROOT'] . '/.env'
];

$env = [];
foreach ($envPaths as $path) {
  if (file_exists($path)) {
    $env = parse_env($path);
    break;
  }
}

$pdo = getDbConnection();
if (!$pdo) {
  http_response_code(500);
  echo json_encode(['success' => false, 'error' => 'Error de conexion a la base de datos']);
  exit;
}

$subs = $pdo->query("SELECT * FROM push_subscriptions")->fetchAll();

$auth = [
  'VAPID' => [
    'subject' => 'mailto:beatancode@gmail.com',
    'publicKey' => $env['VAPID_PUBLIC_KEY'],
    'privateKey' => $env['VAPID_PRIVATE_KEY'],
  ],
];

$webPush = new WebPush($auth);

$payload = json_encode([
  "title" => "⚠️ Gastos próximos",
  "body" => "Tienes gastos por vencer",
]);

foreach ($subs as $s) {
  $sub = Subscription::create([
    "endpoint" => $s['endpoint'],
    "keys" => [
      "p256dh" => $s['p256dh'],
      "auth" => $s['auth']
    ]
  ]);

  $webPush->queueNotification($sub, $payload);
}

$webPush->flush();

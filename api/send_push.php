<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);
require '../vendor/autoload.php';
use Minishlink\WebPush\WebPush;
use Minishlink\WebPush\Subscription;

header('Content-Type: application/json');
session_start();
require_once __DIR__ . '/../config/db.php';

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

$reports = $webPush->flush();

foreach ($reports as $report) {
  echo "Endpoint: {$report->getRequest()->getUri()}\n";
  if ($report->isSuccess()) {
    echo "✅ Enviado correctamente\n";
  } else {
    echo "❌ Error: {$report->getReason()}\n";
  }
}

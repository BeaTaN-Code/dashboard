<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require __DIR__ . '/../vendor/autoload.php';

use Minishlink\WebPush\WebPush;
use Minishlink\WebPush\Subscription;

require_once __DIR__ . '/../config/db.php';

/* =========================
   CARGAR .ENV
========================= */
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

if (!isset($env['VAPID_PUBLIC_KEY'], $env['VAPID_PRIVATE_KEY'])) {
  die("❌ VAPID keys no encontradas en .env");
}

/* =========================
   CONEXIÓN BD
========================= */
$pdo = getDbConnection();
if (!$pdo) {
  die("❌ Error de conexión a BD");
}

/* =========================
   TRAER SUSCRIPCIONES
========================= */
$subs = $pdo->query("SELECT * FROM push_subscriptions")->fetchAll(PDO::FETCH_ASSOC);

if (count($subs) === 0) {
  echo "❌ No hay usuarios suscritos\n";
  exit;
}

/* =========================
   CONFIG VAPID
========================= */
$auth = [
  'VAPID' => [
    'subject' => 'mailto:beatancode@gmail.com',
    'publicKey' => $env['VAPID_PUBLIC_KEY'],
    'privateKey' => $env['VAPID_PRIVATE_KEY'],
  ],
];

$webPush = new WebPush($auth);

$tomorrow = date('Y-m-d', strtotime('+1 day'));

/* =========================
   PROCESAR POR USUARIO
========================= */
$stmt = $pdo->prepare("
  SELECT CATGASXX, DESGASXX, MONTGASX
  FROM FINANCIX
  WHERE REGESTXX = 'ACTIVO'
    AND MONTGASX < 0
    AND FINCFECX = ?
    AND USRIDXXX = ?
");

foreach ($subs as $s) {

  // buscar gastos SOLO de ese usuario
  $stmt->execute([$tomorrow, $s['USRIDXXX']]);
  $gastos = $stmt->fetchAll(PDO::FETCH_ASSOC);

  if (count($gastos) === 0) {
    continue; // este usuario no tiene gastos mañana
  }

  /* =========================
     ARMAR MENSAJE
  ========================= */
  $detalle = "";
  $total = 0;

  foreach ($gastos as $g) {
    $monto = number_format(abs($g['MONTGASX']), 0, ',', '.');
    $detalle .= "• {$g['CATGASXX']}: {$g['DESGASXX']} ($$monto)\n";
    $total += abs($g['MONTGASX']);
  }

  $totalFmt = number_format($total, 0, ',', '.');

  $payload = json_encode([
    "title" => "⚠️ Gastos mañana",
    "body"  => "Tienes ".count($gastos)." gasto(s):\n".$detalle."\nTotal: $$totalFmt",
    "url"   => "/dashboard"
  ]);

  /* =========================
     CREAR SUBSCRIPCIÓN
  ========================= */
  $sub = Subscription::create([
    "endpoint" => $s['endpoint'],
    "keys" => [
      "p256dh" => $s['p256dh'],
      "auth" => $s['auth']
    ]
  ]);

  $webPush->queueNotification($sub, $payload);
}

/* =========================
   ENVIAR
========================= */
$reports = $webPush->flush();

/* =========================
   REPORTE
========================= */
foreach ($reports as $report) {
  echo "Endpoint: {$report->getRequest()->getUri()}\n";
  if ($report->isSuccess()) {
    echo "✅ Enviado correctamente\n";
  } else {
    echo "❌ Error: {$report->getReason()}\n";
  }
}
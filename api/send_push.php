<?php
// dashboard/api/send_push.php
// Envía recordatorios push de gastos de mañana. Solo CLI o ?token=CRON_SECRET
// (antes cualquiera podía dispararlo por URL y ver los endpoints de los suscriptores).

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/security.php';
app_require_cli_or_cron_token();

require __DIR__ . '/../vendor/autoload.php';

use Minishlink\WebPush\WebPush;
use Minishlink\WebPush\Subscription;

$env = app_env();

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
$ok = 0;
$fail = 0;
foreach ($reports as $report) {
  if ($report->isSuccess()) {
    $ok++;
  } else {
    $fail++;
    error_log('send_push: ' . $report->getReason());
  }
}
echo "Notificaciones enviadas: $ok, fallidas: $fail" . PHP_EOL;


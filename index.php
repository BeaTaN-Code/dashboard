<?php
// dashboard/index.php — Aplicación principal (shell + vistas).
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/config/security.php';

ini_set('display_errors', app_is_debug() ? '1' : '0');
app_send_security_headers(false);
app_start_session();
checkSession();
$user = getCurrentUser();
$csrfToken = app_csrf_token();
define('APP_PAGE', true);

$pdo = getDbConnection();
if (!$pdo) {
  http_response_code(503);
  echo '<!doctype html><meta charset="utf-8"><title>Servicio no disponible</title><p style="font-family:sans-serif;padding:2rem">No pudimos conectar con la base de datos. Inténtalo de nuevo en unos minutos.</p>';
  exit;
}

// Datos de los módulos de administración (solo administradores)
$formStats = ['total' => 0, 'pendiente' => 0, 'leido' => 0, 'respondido' => 0];
$formData = [];
$usersData = [];
$pendingMessages = 0;
if ($user['is_admin']) {
  try {
    $stmt = $pdo->query("SELECT ESTADO, COUNT(*) AS count FROM FORMULARIO WHERE REGESTXX = 'ACTIVO' GROUP BY ESTADO");
    while ($row = $stmt->fetch()) {
      $formStats[strtolower($row['ESTADO'])] = (int) $row['count'];
      $formStats['total'] += (int) $row['count'];
    }
    $pendingMessages = $formStats['pendiente'];
    $formData = $pdo->query("SELECT * FROM FORMULARIO WHERE REGESTXX = 'ACTIVO' ORDER BY HORCREA DESC LIMIT 100")->fetchAll();
  } catch (Exception $e) {
    error_log('Form data error: ' . $e->getMessage());
  }
  try {
    $usersData = $pdo->query("SELECT USRIDXXX, USRNMEXX, USRMAILX, USRCELUL, ISADMINX, REGESTXX, REGFECXX FROM BEATUSRS ORDER BY REGFECXX DESC")->fetchAll();
  } catch (Exception $e) {
    error_log('Users data error: ' . $e->getMessage());
  }
}

$avatarCandidate = 'img/Equipo/' . preg_replace('/[^0-9A-Za-z-]/', '', (string) $user['id']) . '.png';
$avatarPath = is_file(__DIR__ . '/' . $avatarCandidate) ? $avatarCandidate : null;

$appConfig = [
  'isAdmin' => (bool) $user['is_admin'],
  'userId' => (string) $user['id'],
  'userName' => (string) ($user['name'] ?? ''),
  'csrf' => $csrfToken,
  'today' => date('Y-m-d'),
  'vapidPublicKey' => (string) env_value('VAPID_PUBLIC_KEY', ''),
];
$asset = fn(string $path) => $path . '?v=' . (@filemtime(__DIR__ . '/' . $path) ?: '1');
?>
<!DOCTYPE html>
<html lang="es-CO">

<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
  <meta name="color-scheme" content="light dark">
  <meta name="theme-color" content="#0a7c95">
  <title>BeaTaN · Finanzas</title>
  <link rel="icon" type="image/png" href="img/Logo.png">
  <script>
    // Aplica el tema antes de pintar para evitar parpadeos (preferencia local del navegador).
    (function () {
      try {
        var t = localStorage.getItem('beatan_theme');
        if (t === 'light' || t === 'dark') document.documentElement.setAttribute('data-theme', t);
      } catch (e) { }
    })();
  </script>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Geist:wght@400;500;600;700&family=Geist+Mono:wght@400;600&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
  <link rel="stylesheet" href="<?= $asset('css/dashboard.css') ?>">
  <link rel="stylesheet" href="<?= $asset('css/modules.css') ?>">
  <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js" defer></script>
  <script src="https://cdn.jsdelivr.net/npm/marked@12.0.2/marked.min.js" defer></script>
  <script src="https://cdnjs.cloudflare.com/ajax/libs/dompurify/3.1.6/purify.min.js" defer></script>
  <script>window.APP_CONFIG = <?= json_encode($appConfig, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
    window.isAdmin = window.APP_CONFIG.isAdmin;
    window.CURRENT_USER_ID = window.APP_CONFIG.userId;</script>
</head>

<body>
  <a class="skip-link" href="#main">Saltar al contenido</a>
  <div class="app-shell">
    <?php require __DIR__ . '/views/layout/sidebar.php'; ?>

    <div class="app-main">
      <?php require __DIR__ . '/views/layout/topbar.php'; ?>

      <main class="content" id="main" tabindex="-1">
        <?php require __DIR__ . '/views/finance/overview.php'; ?>
        <?php require __DIR__ . '/views/finance/sections.php'; ?>
        <?php if ($user['is_admin']): ?>
          <?php require __DIR__ . '/views/admin/messages.php'; ?>
          <?php require __DIR__ . '/views/admin/users.php'; ?>
          <?php require __DIR__ . '/views/admin/cronograma.php'; ?>
          <?php require __DIR__ . '/views/admin/facturacion.php'; ?>
        <?php endif; ?>
      </main>
    </div>
  </div>

  <?php require __DIR__ . '/views/modals/finance.php'; ?>
  <?php if ($user['is_admin']) require __DIR__ . '/views/modals/admin.php'; ?>
  <?php require __DIR__ . '/views/layout/widgets.php'; ?>

  <script src="<?= $asset('js/finance-core.js') ?>" defer></script>
  <script src="<?= $asset('js/core.js') ?>" defer></script>
  <script src="<?= $asset('js/charts.js') ?>" defer></script>
  <script src="<?= $asset('js/finance.js') ?>" defer></script>
  <script src="<?= $asset('js/planning.js') ?>" defer></script>
  <script src="<?= $asset('js/import.js') ?>" defer></script>
  <script src="<?= $asset('js/assistant.js') ?>" defer></script>
  <script src="<?= $asset('js/dashboard.js') ?>" defer></script>
  <script src="<?= $asset('js/app.js') ?>" defer></script>
</body>

</html>

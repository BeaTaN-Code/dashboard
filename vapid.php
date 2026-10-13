<?php
// Genera un par de claves VAPID. Solo por línea de comandos: php vapid.php
if (PHP_SAPI !== 'cli') {
  http_response_code(404);
  exit;
}
require __DIR__ . '/vendor/autoload.php';

use Minishlink\WebPush\VAPID;

print_r(VAPID::createVapidKeys());

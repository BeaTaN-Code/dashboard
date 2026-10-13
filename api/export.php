<?php
// dashboard/api/export.php
// Exportación de movimientos a CSV (UTF-8 con BOM, separador ";" para Excel en español).
// Protege contra inyección de fórmulas: las celdas que empiezan por = + - @ tab o CR se prefijan con '.

require_once __DIR__ . '/../config/api_helper.php';
require_once __DIR__ . '/../lib/finance.php';
require_once __DIR__ . '/../lib/access.php';

api_bootstrap();
$auth = api_require_auth();
if ($_SERVER['REQUEST_METHOD'] !== 'GET')
  api_response(false, 'Método no permitido', 405);
rate_limit_or_fail('export', 30, 300);

$pdo = api_db();
$account = require_account($auth, $_GET['tipgasxx'] ?? null);
$from = valid_date($_GET['from'] ?? '');
$to = valid_date($_GET['to'] ?? '');
if (!$from || !$to || $from > $to) {
  api_response(false, 'Indica un rango de fechas válido', 422);
}

function csv_safe_cell($value): string
{
  $s = (string) $value;
  if ($s !== '' && preg_match('/^[=+\-@\t\r]/', $s)) {
    $s = "'" . $s;
  }
  return $s;
}

function csv_line(array $cells): string
{
  return implode(';', array_map(function ($c) {
    $s = csv_safe_cell($c);
    return '"' . str_replace('"', '""', $s) . '"';
  }, $cells)) . "\r\n";
}

$rows = fin_fetch_rows($pdo, fin_scope($account, $auth['is_admin'], $auth['user_id']), $from, $to);
$today = date('Y-m-d');

$filename = sprintf('movimientos_%s_%s_a_%s.csv', strtolower($account), $from, $to);
header_remove('Content-Type');
header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Cache-Control: no-store');

echo "\xEF\xBB\xBF";
echo csv_line(['Fecha', 'Tipo', 'Categoría', 'Descripción', 'Monto', 'Estado']);
foreach ($rows as $r) {
  // El monto se exporta como número sin signo de moneda; los gastos en negativo.
  $amount = cents_to_decimal($r['cents']);
  echo implode(';', [
    '"' . $r['date'] . '"',
    '"' . ($r['cents'] >= 0 ? 'Ingreso' : 'Gasto') . '"',
    '"' . str_replace('"', '""', csv_safe_cell($r['cat'])) . '"',
    '"' . str_replace('"', '""', csv_safe_cell($r['desc'])) . '"',
    $amount, // número: no es texto del usuario, no requiere protección
    '"' . ($r['date'] <= $today ? 'Realizado' : 'Programado') . '"',
  ]) . "\r\n";
}
exit;

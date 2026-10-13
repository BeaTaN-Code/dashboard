<?php
// dashboard/api/report.php
// API para generar reportes mensuales financieros (contrato histórico conservado).

require_once __DIR__ . '/../config/api_helper.php';
require_once __DIR__ . '/../lib/finance.php';
require_once __DIR__ . '/../lib/access.php';

api_bootstrap();
$auth = api_require_auth();
$pdo = api_db();

$tipgasxx = valid_enum($_GET['tipgasxx'] ?? 'PERSONAL', ACCOUNTS) ?? 'PERSONAL';
$month = valid_month($_GET['month'] ?? '') ?? date('Y-m');

// Seguridad: usuarios normales solo pueden ver PERSONAL
if ($tipgasxx === 'BEATAN' && !$auth['is_admin']) {
  api_response(false, 'No autorizado', 403);
}

[$from, $to] = month_bounds($month);
$prevMonth = date('Y-m', strtotime($from . ' -1 month'));
[$prevFrom, $prevTo] = month_bounds($prevMonth);
$scope = fin_scope($tipgasxx, $auth['is_admin'], $auth['user_id']);
[$where, $params] = $scope;

try {
  $rows = fin_fetch_rows($pdo, $scope, $from, $to);
  $prevRows = fin_fetch_rows($pdo, $scope, $prevFrom, $prevTo);
  $t = fin_totals($rows);
  $p = fin_totals($prevRows);

  // Detalle por categoría
  $cats = [];
  foreach ($rows as $r) {
    $c = $r['cat'];
    if (!isset($cats[$c]))
      $cats[$c] = ['CATGASXX' => $c, 'ingresos' => 0, 'gastos' => 0, 'movimientos' => 0];
    if ($r['cents'] > 0)
      $cats[$c]['ingresos'] += $r['cents'];
    else
      $cats[$c]['gastos'] += -$r['cents'];
    $cats[$c]['movimientos']++;
  }
  usort($cats, fn($a, $b) => [$b['gastos'], $b['ingresos']] <=> [$a['gastos'], $a['ingresos']]);
  $categorias = array_map(fn($c) => [
    'CATGASXX' => $c['CATGASXX'],
    'ingresos' => cents_to_float($c['ingresos']),
    'gastos' => cents_to_float($c['gastos']),
    'movimientos' => $c['movimientos'],
  ], $cats);

  $movimientos = array_map(fn($r) => [
    'FINCTIDX' => $r['id'],
    'FINCFECX' => $r['date'],
    'CATGASXX' => $r['cat'],
    'DESGASXX' => $r['desc'],
    'MONTGASX' => cents_to_decimal($r['cents']),
  ], $rows);

  // Deudas
  $sqlDeudas = "SELECT DEUDIDXX, TIPDEUDX, DESDEUDX, MONDEUDX, MONCUOTX, PAGCUOTX, NUMCUOTX, FECCUOTX, ABONCUOT, REGESTXX, REGFECXX
    FROM DEUDASIX WHERE TIPPERXX = :tipperxx AND REGESTXX IN ('ACTIVO', 'PAGADO')";
  $paramsDeudas = [':tipperxx' => $tipgasxx];
  if (!($tipgasxx === 'BEATAN' && $auth['is_admin'])) {
    $sqlDeudas .= ' AND USRIDXXX = :userId';
    $paramsDeudas[':userId'] = $auth['user_id'];
  }
  $sqlDeudas .= ' ORDER BY REGESTXX ASC, DEUDIDXX DESC';
  $stmt = $pdo->prepare($sqlDeudas);
  $stmt->execute($paramsDeudas);
  $deudas = $stmt->fetchAll();

  $deudasActivas = array_values(array_filter($deudas, fn($d) => $d['REGESTXX'] === 'ACTIVO'));
  $deudasPagadas = array_values(array_filter($deudas, fn($d) => $d['REGESTXX'] === 'PAGADO'));
  $totalContra = 0;
  $totalFavor = 0;
  foreach ($deudasActivas as $d) {
    if ($d['TIPDEUDX'] === 'EN CONTRA')
      $totalContra += db_money_to_cents($d['MONDEUDX']);
    else
      $totalFavor += db_money_to_cents($d['MONDEUDX']);
  }

  api_json([
    'success' => true,
    'periodo' => ['month' => $month, 'prevMonth' => $prevMonth, 'tipgasxx' => $tipgasxx],
    'resumen' => [
      'ingresos' => cents_to_float($t['income']),
      'gastos' => cents_to_float($t['expense']),
      'balance' => cents_to_float($t['net']),
      'movimientos' => $t['count'],
      'varIngresos' => pct_change($t['income'], $p['income']),
      'varGastos' => pct_change($t['expense'], $p['expense']),
      'varBalance' => pct_change($t['net'], $p['net']),
      'prevIngresos' => cents_to_float($p['income']),
      'prevGastos' => cents_to_float($p['expense']),
      'prevBalance' => cents_to_float($p['net']),
    ],
    'categorias' => $categorias,
    'movimientos' => $movimientos,
    'deudas' => [
      'activas' => $deudasActivas,
      'pagadas' => $deudasPagadas,
      'totalContra' => cents_to_float($totalContra),
      'totalFavor' => cents_to_float($totalFavor),
    ],
  ]);
} catch (PDOException $e) {
  api_log_error('Report error', $e);
  api_response(false, 'Error al generar el reporte', 500);
}

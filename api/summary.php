<?php
// dashboard/api/summary.php
// Visión general financiera de un periodo. Todas las cifras se devuelven en centavos (int)
// y se calculan en el servidor a partir de los movimientos guardados.

require_once __DIR__ . '/../config/api_helper.php';
require_once __DIR__ . '/../lib/finance.php';
require_once __DIR__ . '/../lib/access.php';

api_bootstrap();
$auth = api_require_auth();
if ($_SERVER['REQUEST_METHOD'] !== 'GET')
  api_response(false, 'Método no permitido', 405);

$pdo = api_db();
$account = require_account($auth, $_GET['tipgasxx'] ?? null);
$today = date('Y-m-d');

$period = fin_period((string) ($_GET['period'] ?? 'month'), $_GET['from'] ?? null, $_GET['to'] ?? null, $today);
if (!$period)
  api_response(false, 'El rango de fechas no es válido (máximo 3 años y la fecha inicial debe ser anterior a la final).', 422);

$scope = fin_scope($account, $auth['is_admin'], $auth['user_id']);

// Una sola lectura cubre: historial (180 días antes), periodo anterior, periodo actual y próximos 30 días.
$historyStart = min($period['prev_from'], add_days($period['from'], -180), date('Y-m-01', strtotime(date('Y-m-01', strtotime($today)) . ' -5 month')));
$horizon = max($period['to'], add_days($today, 30));
$rows = fin_fetch_rows($pdo, $scope, $historyStart, $horizon);

$realizedEnd = min($period['to'], $today);
$periodRealized = $period['from'] <= $today ? fin_filter_range($rows, $period['from'], $realizedEnd) : [];
$compareEnd = min($period['compare_to'], $today);
$prevRealized = $period['prev_from'] <= $compareEnd ? fin_filter_range($rows, $period['prev_from'], $compareEnd) : [];
$history = fin_filter_range($rows, add_days($period['from'], -180), add_days($period['from'], -1));

$totals = fin_totals($periodRealized);
$prevTotals = fin_totals($prevRealized);
$categories = fin_expense_by_category($periodRealized);
$balanceToday = fin_balance_until($pdo, $scope, $today);

// Serie para la gráfica: diaria hasta 92 días; mensual para periodos más largos.
$openingBalance = fin_balance_until($pdo, $scope, add_days($period['from'], -1));
if ($period['days_total'] <= 92) {
  $series = ['granularity' => 'day', 'points' => $period['from'] <= $today ? fin_daily_series($periodRealized, $period['from'], $realizedEnd, $openingBalance) : []];
} else {
  $points = [];
  $running = $openingBalance;
  $m = substr($period['from'], 0, 7);
  while ($m <= substr($realizedEnd, 0, 7) && $period['from'] <= $today) {
    [$s, $e] = month_bounds($m);
    $t = fin_totals(fin_filter_range($periodRealized, max($s, $period['from']), min($e, $realizedEnd)));
    $running += $t['net'];
    $points[] = ['date' => $m, 'income' => $t['income'], 'expense' => $t['expense'], 'balance' => $running];
    $m = date('Y-m', strtotime($m . '-01 +1 month'));
  }
  $series = ['granularity' => 'month', 'points' => $points];
}

// Próximos movimientos programados (30 días)
$upcomingRows = fin_filter_range($rows, add_days($today, 1), add_days($today, 30));
$upcomingItems = array_map(fn($r) => [
  'id' => $r['id'], 'date' => $r['date'], 'cents' => $r['cents'], 'category' => $r['cat'],
  'description' => $r['desc'], 'debt_id' => $r['debt'], 'days' => days_between($today, $r['date']),
], $upcomingRows);
$upcomingTotals = fin_totals($upcomingRows);
$upcoming = [
  'days' => 30,
  'items' => array_slice($upcomingItems, 0, 25),
  'count' => count($upcomingItems),
  'expense' => $upcomingTotals['expense'],
  'income' => $upcomingTotals['income'],
];

// Deudas, metas y presupuestos (los dos últimos pueden no existir si falta la migración v3)
$debts = fin_debts($pdo, $account, $auth['is_admin'], $auth['user_id'], $today);

$goals = ['available' => true, 'saved' => 0, 'target' => 0, 'count' => 0, 'items' => []];
try {
  $gSql = "SELECT G.METAIDXX, G.METANOMX, G.METAOBJX, G.METAFECX, COALESCE(SUM(M.MEMOMONX), 0) AS AHORRADO
    FROM BEATMETA G LEFT JOIN BEATMEMO M ON M.METAIDXX = G.METAIDXX AND M.REGESTXX = 'ACTIVO'
    WHERE G.REGESTXX = 'ACTIVO' AND G.METAESTX = 'ACTIVA' AND G.TIPGASXX = :acc";
  $gParams = [':acc' => $account];
  if ($account !== 'BEATAN') {
    $gSql .= ' AND G.USRIDXXX = :uid';
    $gParams[':uid'] = $auth['user_id'];
  }
  $gSql .= ' GROUP BY G.METAIDXX, G.METANOMX, G.METAOBJX, G.METAFECX ORDER BY G.METAFECX IS NULL, G.METAFECX ASC';
  $st = $pdo->prepare($gSql);
  $st->execute($gParams);
  foreach ($st->fetchAll() as $g) {
    $target = db_money_to_cents($g['METAOBJX']);
    $saved = db_money_to_cents($g['AHORRADO']);
    $goals['saved'] += $saved;
    $goals['target'] += $target;
    $goals['count']++;
    $goals['items'][] = ['id' => (int) $g['METAIDXX'], 'name' => $g['METANOMX'], 'target' => $target, 'saved' => $saved,
      'target_date' => $g['METAFECX'], 'plan' => fin_goal_plan($target, $saved, $g['METAFECX'], $today)];
  }
  $goals['items'] = array_slice($goals['items'], 0, 4);
} catch (PDOException $e) {
  if (!db_is_missing_table($e))
    throw $e;
  $goals['available'] = false;
}

$budgets = ['available' => true, 'items' => []];
try {
  $bSql = "SELECT PRESIDXX, CATGASXX, PRESMONX, PRESALRX FROM BEATPRES WHERE REGESTXX = 'ACTIVO' AND TIPGASXX = :acc";
  $bParams = [':acc' => $account];
  if ($account !== 'BEATAN') {
    $bSql .= ' AND USRIDXXX = :uid';
    $bParams[':uid'] = $auth['user_id'];
  }
  $st = $pdo->prepare($bSql);
  $st->execute($bParams);
  [$mFrom, $mTo] = month_bounds(substr($today, 0, 7));
  $monthRows = fin_filter_range($rows, $mFrom, $mTo);
  $daysTotal = (int) date('t', strtotime($today));
  $daysElapsed = (int) date('j', strtotime($today));
  foreach ($st->fetchAll() as $b) {
    $key = mb_strtolower($b['CATGASXX']);
    $spent = 0;
    $scheduled = 0;
    foreach ($monthRows as $r) {
      if ($r['cents'] >= 0 || mb_strtolower($r['cat']) !== $key)
        continue;
      if ($r['date'] <= $today)
        $spent -= $r['cents'];
      else
        $scheduled -= $r['cents'];
    }
    $limit = db_money_to_cents($b['PRESMONX']);
    $budgets['items'][] = ['id' => (int) $b['PRESIDXX'], 'category' => $b['CATGASXX'], 'limit' => $limit, 'spent' => $spent, 'scheduled' => $scheduled]
      + fin_budget_status($limit, $spent, $scheduled, (int) $b['PRESALRX'], $daysElapsed, $daysTotal);
  }
  usort($budgets['items'], fn($a, $b) => ($b['pct'] ?? 0) <=> ($a['pct'] ?? 0));
} catch (PDOException $e) {
  if (!db_is_missing_table($e))
    throw $e;
  $budgets['available'] = false;
}

// Proyección (solo si el periodo está en curso)
$projection = ['available' => false, 'reason' => 'La proyección solo aplica a periodos en curso.', 'assumptions' => []];
if ($period['in_progress']) {
  $scheduledNet = fin_totals(fin_filter_range($rows, add_days($today, 1), $period['to']))['net'];
  $variableSpent = 0;
  foreach ($periodRealized as $r) {
    if ($r['cents'] < 0 && $r['debt'] === null)
      $variableSpent -= $r['cents'];
  }
  // El historial "mismos días restantes" solo tiene sentido para periodos mensuales.
  $historical = in_array($period['preset'], ['month'], true)
    ? fin_historical_remaining($rows, $today, (int) date('j', strtotime($today)) + 1, 3)
    : [];
  $projection = fin_projection($balanceToday, $scheduledNet, $period['days_elapsed'], $period['days_total'] - $period['days_elapsed'], $historical, $variableSpent);
}

$unusual = fin_unusual_expenses($periodRealized, $history);
$duplicates = fin_possible_duplicates(fin_filter_range($rows, $period['from'], $period['to']));
$increases = fin_category_increases($periodRealized, $prevRealized);

$insights = fin_insights([
  'today' => $today,
  'period' => $period,
  'totals' => $totals,
  'previous_totals' => $prevTotals,
  'categories' => $categories,
  'increases' => $increases,
  'budgets' => $budgets['items'],
  'upcoming' => ['items' => array_filter($upcomingItems, fn($u) => $u['cents'] < 0 && $u['days'] <= 7), 'expense' => array_sum(array_map(fn($u) => $u['cents'] < 0 && $u['days'] <= 7 ? -$u['cents'] : 0, $upcomingItems)), 'days' => 7],
  'projection' => $projection,
  'unusual' => $unusual,
  'duplicates' => $duplicates,
]);

// Movimientos recientes realizados
[$where, $params] = $scope;
$st = $pdo->prepare("SELECT FINCTIDX, FINCFECX, MONTGASX, CATGASXX, DESGASXX, DEUDIDXX FROM FINANCIX
  WHERE $where AND FINCFECX <= :today ORDER BY FINCFECX DESC, FINCTIDX DESC LIMIT 6");
$st->execute($params + [':today' => $today]);
$recent = array_map(fn($r) => ['id' => $r['id'], 'date' => $r['date'], 'cents' => $r['cents'], 'category' => $r['cat'], 'description' => $r['desc']],
  fin_normalize_rows($st->fetchAll()));

api_response(true, [
  'account' => $account,
  'today' => $today,
  'period' => $period,
  'balance' => [
    'today' => $balanceToday,
    'goals_reserved' => $goals['saved'],
    'free' => $balanceToday - $goals['saved'],
    'net_worth' => $balanceToday + $debts['receivable'] - $debts['payable'],
  ],
  'totals' => $totals,
  'previous' => $prevTotals + ['from' => $period['prev_from'], 'to' => $compareEnd],
  'variation' => [
    'income' => pct_change($totals['income'], $prevTotals['income']),
    'expense' => pct_change($totals['expense'], $prevTotals['expense']),
    'net' => pct_change($totals['net'], $prevTotals['net']),
  ],
  'categories' => $categories,
  'series' => $series,
  'monthly' => fin_monthly_series($rows, substr($today, 0, 7), 6, $today),
  'upcoming' => $upcoming,
  'debts' => $debts,
  'goals' => $goals,
  'budgets' => $budgets,
  'projection' => $projection,
  'anomalies' => ['unusual' => $unusual, 'duplicates' => $duplicates, 'increases' => $increases],
  'insights' => $insights,
  'recent' => $recent,
]);

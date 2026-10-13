<?php
// dashboard/lib/finance.php
// Lógica financiera pura (sin E/S) + consultas de apoyo.
//
// Definiciones contables usadas en toda la aplicación:
//  - Movimiento: fila ACTIVA de FINANCIX. MONTGASX > 0 es ingreso, < 0 es gasto.
//  - Saldo disponible: suma de movimientos con fecha <= hoy (los futuros son "programados").
//  - Ingresos/Gastos del periodo: movimientos realizados (fecha <= hoy) dentro del periodo.
//  - Programado: movimiento con fecha futura (p. ej. cuotas de deuda generadas por el cron).
//  - Por pagar / por cobrar: saldo pendiente de deudas activas EN CONTRA / A FAVOR,
//    calculado como monto total − abonos/cuotas vinculados con fecha <= hoy.
//  - Apartado en metas: dinero reservado para metas de ahorro. NO es un gasto: sigue dentro
//    del saldo, por eso "disponible libre" = saldo − apartado.
//  - Patrimonio estimado: saldo + por cobrar − por pagar.

require_once __DIR__ . '/money.php';
require_once __DIR__ . '/validation.php';

const PERIOD_PRESETS = ['week', 'month', 'prev_month', 'year', 'last30', 'custom'];

function days_between(string $from, string $to): int
{
  return (int) round((strtotime($to . ' 12:00:00') - strtotime($from . ' 12:00:00')) / 86400);
}

function add_days(string $date, int $days): string
{
  return date('Y-m-d', strtotime(($days >= 0 ? '+' : '') . $days . ' days', strtotime($date . ' 12:00:00')));
}

/**
 * Resuelve un periodo. Devuelve null si el periodo personalizado es inválido.
 * Incluye el periodo anterior equivalente para comparaciones.
 */
function fin_period(string $preset, ?string $from, ?string $to, string $today): ?array
{
  $preset = in_array($preset, PERIOD_PRESETS, true) ? $preset : 'month';
  $label = '';

  switch ($preset) {
    case 'week':
      $dow = (int) date('N', strtotime($today));
      $start = add_days($today, -($dow - 1));
      $end = add_days($start, 6);
      $prevStart = add_days($start, -7);
      $prevEnd = add_days($start, -1);
      $label = 'esta semana';
      break;
    case 'prev_month':
      $start = date('Y-m-01', strtotime(date('Y-m-01', strtotime($today)) . ' -1 month'));
      $end = date('Y-m-t', strtotime($start));
      $prevStart = date('Y-m-01', strtotime($start . ' -1 month'));
      $prevEnd = date('Y-m-t', strtotime($prevStart));
      $label = 'el mes anterior';
      break;
    case 'year':
      $y = (int) substr($today, 0, 4);
      $start = "$y-01-01";
      $end = "$y-12-31";
      $prevStart = ($y - 1) . '-01-01';
      $prevEnd = ($y - 1) . '-12-31';
      $label = 'este año';
      break;
    case 'last30':
      $start = add_days($today, -29);
      $end = $today;
      $prevStart = add_days($start, -30);
      $prevEnd = add_days($start, -1);
      $label = 'los últimos 30 días';
      break;
    case 'custom':
      $start = valid_date($from ?? '');
      $end = valid_date($to ?? '');
      if (!$start || !$end || $start > $end || days_between($start, $end) > 366 * 3)
        return null;
      $len = days_between($start, $end) + 1;
      $prevEnd = add_days($start, -1);
      $prevStart = add_days($start, -$len);
      $label = 'el periodo seleccionado';
      break;
    default: // month
      $start = date('Y-m-01', strtotime($today));
      $end = date('Y-m-t', strtotime($today));
      $prevStart = date('Y-m-01', strtotime($start . ' -1 month'));
      $prevEnd = date('Y-m-t', strtotime($prevStart));
      $label = 'este mes';
  }

  // Si el periodo está en curso, se compara contra el mismo tramo del periodo anterior
  // (p. ej. días 1-13 de este mes vs. días 1-13 del mes pasado), no contra el mes completo.
  $inProgress = $start <= $today && $today < $end;
  $compareEnd = $prevEnd;
  if ($inProgress) {
    $elapsed = days_between($start, $today);
    $compareEnd = min($prevEnd, add_days($prevStart, $elapsed));
  }

  return [
    'preset' => $preset,
    'from' => $start,
    'to' => $end,
    'label' => $label,
    'prev_from' => $prevStart,
    'prev_to' => $prevEnd,
    'compare_to' => $compareEnd,
    'in_progress' => $inProgress,
    'days_total' => days_between($start, $end) + 1,
    'days_elapsed' => $start > $today ? 0 : min(days_between($start, $today) + 1, days_between($start, $end) + 1),
  ];
}

/** Normaliza filas de FINANCIX a una estructura compacta en centavos. */
function fin_normalize_rows(array $rows): array
{
  return array_map(fn($r) => [
    'id' => (int) $r['FINCTIDX'],
    'date' => substr((string) $r['FINCFECX'], 0, 10),
    'cents' => db_money_to_cents($r['MONTGASX']),
    'cat' => (string) ($r['CATGASXX'] ?? ''),
    'desc' => (string) ($r['DESGASXX'] ?? ''),
    'debt' => isset($r['DEUDIDXX']) && $r['DEUDIDXX'] !== null && $r['DEUDIDXX'] !== '' ? (int) $r['DEUDIDXX'] : null,
  ], $rows);
}

function fin_filter_range(array $rows, string $from, string $to): array
{
  return array_values(array_filter($rows, fn($r) => $r['date'] >= $from && $r['date'] <= $to));
}

/** Totales de ingresos/gastos en centavos (gasto expresado en positivo). */
function fin_totals(array $rows): array
{
  $income = 0;
  $expense = 0;
  foreach ($rows as $r) {
    if ($r['cents'] > 0)
      $income += $r['cents'];
    elseif ($r['cents'] < 0)
      $expense += -$r['cents'];
  }
  return ['income' => $income, 'expense' => $expense, 'net' => $income - $expense, 'count' => count($rows)];
}

/** Gasto por categoría ordenado de mayor a menor, con participación porcentual. */
function fin_expense_by_category(array $rows): array
{
  $by = [];
  foreach ($rows as $r) {
    if ($r['cents'] >= 0)
      continue;
    $cat = $r['cat'] !== '' ? $r['cat'] : 'Sin categoría';
    $by[$cat] = ($by[$cat] ?? 0) + (-$r['cents']);
  }
  arsort($by);
  $total = array_sum($by);
  $out = [];
  foreach ($by as $cat => $cents) {
    $out[] = ['category' => $cat, 'cents' => $cents, 'share' => $total > 0 ? round($cents * 100 / $total, 1) : 0.0];
  }
  return $out;
}

/** Serie diaria (ingresos, gastos, saldo acumulado) para graficar un periodo. */
function fin_daily_series(array $rows, string $from, string $to, int $openingBalance): array
{
  $byDay = [];
  foreach ($rows as $r) {
    $d = $r['date'];
    if (!isset($byDay[$d]))
      $byDay[$d] = ['income' => 0, 'expense' => 0];
    if ($r['cents'] > 0)
      $byDay[$d]['income'] += $r['cents'];
    else
      $byDay[$d]['expense'] += -$r['cents'];
  }
  $series = [];
  $running = $openingBalance;
  $days = days_between($from, $to);
  for ($i = 0; $i <= $days; $i++) {
    $d = add_days($from, $i);
    $inc = $byDay[$d]['income'] ?? 0;
    $exp = $byDay[$d]['expense'] ?? 0;
    $running += $inc - $exp;
    $series[] = ['date' => $d, 'income' => $inc, 'expense' => $exp, 'balance' => $running];
  }
  return $series;
}

/** Serie mensual de los últimos N meses hasta $month (YYYY-MM) inclusive, solo realizados. */
function fin_monthly_series(array $rows, string $month, int $months, string $today): array
{
  $out = [];
  for ($i = $months - 1; $i >= 0; $i--) {
    $m = date('Y-m', strtotime($month . "-01 -$i month"));
    [$s, $e] = month_bounds($m);
    $e = min($e, $today);
    $t = $s <= $e ? fin_totals(fin_filter_range($rows, $s, $e)) : ['income' => 0, 'expense' => 0, 'net' => 0];
    $out[] = ['month' => $m, 'income' => $t['income'], 'expense' => $t['expense'], 'net' => $t['net']];
  }
  return $out;
}

function fin_median(array $values): float
{
  sort($values);
  $n = count($values);
  if ($n === 0)
    return 0.0;
  $mid = intdiv($n, 2);
  return $n % 2 ? (float) $values[$mid] : ($values[$mid - 1] + $values[$mid]) / 2;
}

function fin_percentile(array $values, float $p): float
{
  sort($values);
  $n = count($values);
  if ($n === 0)
    return 0.0;
  $idx = ($n - 1) * $p;
  $lo = (int) floor($idx);
  $hi = (int) ceil($idx);
  return $values[$lo] + ($values[$hi] - $values[$lo]) * ($idx - $lo);
}

/**
 * Gastos inusuales: un gasto del periodo que supera 2,5 veces la mediana y el percentil 90
 * del historial de su categoría (mínimo 5 movimientos previos para tener referencia).
 */
function fin_unusual_expenses(array $periodRows, array $historyRows): array
{
  $hist = [];
  foreach ($historyRows as $r) {
    if ($r['cents'] < 0 && $r['debt'] === null)
      $hist[mb_strtolower($r['cat'])][] = -$r['cents'];
  }
  $out = [];
  foreach ($periodRows as $r) {
    if ($r['cents'] >= 0 || $r['debt'] !== null)
      continue;
    $values = $hist[mb_strtolower($r['cat'])] ?? [];
    if (count($values) < 5)
      continue;
    $amount = -$r['cents'];
    $median = fin_median($values);
    $p90 = fin_percentile($values, 0.9);
    if ($median > 0 && $amount >= 2.5 * $median && $amount > $p90) {
      $out[] = [
        'id' => $r['id'],
        'date' => $r['date'],
        'category' => $r['cat'],
        'description' => $r['desc'],
        'cents' => $amount,
        'median_cents' => (int) round($median),
        'samples' => count($values),
        'ratio' => round($amount / $median, 1),
      ];
    }
  }
  usort($out, fn($a, $b) => $b['ratio'] <=> $a['ratio']);
  return $out;
}

/** Posibles duplicados: misma fecha, mismo monto y misma descripción (sin distinguir mayúsculas). */
function fin_possible_duplicates(array $rows): array
{
  $groups = [];
  foreach ($rows as $r) {
    $key = $r['date'] . '|' . $r['cents'] . '|' . mb_strtolower(trim($r['desc']));
    $groups[$key][] = $r;
  }
  $out = [];
  foreach ($groups as $g) {
    if (count($g) < 2)
      continue;
    $out[] = [
      'date' => $g[0]['date'],
      'cents' => $g[0]['cents'],
      'description' => $g[0]['desc'],
      'category' => $g[0]['cat'],
      'ids' => array_map(fn($r) => $r['id'], $g),
      'count' => count($g),
    ];
  }
  return $out;
}

/**
 * Categorías cuyo gasto creció de forma relevante frente al mismo tramo del periodo anterior:
 * +25 % o más y una diferencia de al menos el 5 % del gasto total actual.
 */
function fin_category_increases(array $currentRows, array $previousRows): array
{
  $cur = [];
  foreach (fin_expense_by_category($currentRows) as $c)
    $cur[$c['category']] = $c['cents'];
  $prev = [];
  foreach (fin_expense_by_category($previousRows) as $c)
    $prev[$c['category']] = $c['cents'];
  $total = array_sum($cur);
  $out = [];
  foreach ($cur as $cat => $cents) {
    $before = $prev[$cat] ?? 0;
    if ($before <= 0)
      continue;
    $diff = $cents - $before;
    $pct = pct_change($cents, $before);
    if ($pct !== null && $pct >= 25 && $total > 0 && $diff >= $total * 0.05) {
      $out[] = ['category' => $cat, 'cents' => $cents, 'previous_cents' => $before, 'pct' => $pct];
    }
  }
  usort($out, fn($a, $b) => $b['pct'] <=> $a['pct']);
  return $out;
}

/**
 * Proyección del saldo al final del periodo.
 *  - Parte del saldo real de hoy y suma los movimientos ya programados hasta el cierre.
 *  - Resta el gasto variable esperado para los días que faltan. Se estima con lo que gastaste
 *    en ESOS MISMOS días de los meses anteriores (mediana); así los gastos fijos de inicio de
 *    mes (arriendo, etc.) no se cuentan dos veces. El rango usa el mínimo y el máximo.
 *  - Sin historial, usa el ritmo diario del periodo actual (requiere al menos 7 días) y un
 *    margen de ±25 %, con confianza baja.
 *  - No supone ingresos adicionales no registrados (supuesto conservador).
 * $historicalRemaining: gasto variable (centavos, positivo) de los días equivalentes en meses previos.
 */
function fin_projection(int $balanceToday, int $scheduledNet, int $daysElapsed, int $daysRemaining, array $historicalRemaining, int $variableSpentSoFar): array
{
  if ($daysRemaining <= 0) {
    return ['available' => false, 'reason' => 'El periodo ya terminó: las cifras son reales, no proyectadas.', 'assumptions' => []];
  }

  $base = $balanceToday + $scheduledNet;
  $hist = array_values(array_filter($historicalRemaining, fn($v) => is_int($v) || is_float($v)));

  if (count($hist) > 0) {
    $central = fin_median($hist);
    $minSpend = min($hist);
    $maxSpend = max($hist);
    $method = 'historial';
    $confidence = count($hist) >= 3 ? 'media' : 'baja';
    $assumptions = [
      'Parte del saldo real registrado hasta hoy.',
      'Incluye los movimientos ya programados (por ejemplo, cuotas de deudas) hasta el cierre.',
      'Estima el gasto de los días que faltan con lo que gastaste en esos mismos días de los últimos ' . count($hist) . ' mes(es).',
      'No supone ingresos extra que no estén registrados.',
    ];
  } elseif ($daysElapsed >= 7) {
    $central = $variableSpentSoFar / $daysElapsed * $daysRemaining;
    $minSpend = $central * 0.75;
    $maxSpend = $central * 1.25;
    $method = 'ritmo';
    $confidence = 'baja';
    $assumptions = [
      'Parte del saldo real registrado hasta hoy.',
      'Incluye los movimientos ya programados hasta el cierre.',
      'Sin historial previo: supone que mantienes tu ritmo de gasto diario actual (margen de ±25 %).',
      'No supone ingresos extra que no estén registrados.',
    ];
  } else {
    return [
      'available' => false,
      'reason' => 'Aún no hay suficientes datos: se necesitan al menos 7 días de movimientos o un mes de historial.',
      'assumptions' => [],
    ];
  }

  return [
    'available' => true,
    'method' => $method,
    'projected' => (int) round($base - $central),
    'low' => (int) round($base - $maxSpend),
    'high' => (int) round($base - $minSpend),
    'expected_spend' => (int) round($central),
    'daily_rate' => (int) round($central / $daysRemaining),
    'days_remaining' => $daysRemaining,
    'scheduled_net' => $scheduledNet,
    'confidence' => $confidence,
    'assumptions' => $assumptions,
  ];
}

/**
 * Gasto variable (sin cuotas de deuda) realizado en los días [$fromDay..fin de mes] de cada uno
 * de los $months meses anteriores a $today. Solo incluye meses con movimientos registrados.
 */
function fin_historical_remaining(array $rows, string $today, int $fromDay, int $months = 3): array
{
  $out = [];
  for ($i = 1; $i <= $months; $i++) {
    $ms = date('Y-m-01', strtotime(date('Y-m-01', strtotime($today)) . " -$i month"));
    $me = date('Y-m-t', strtotime($ms));
    $monthRows = fin_filter_range($rows, $ms, $me);
    if (count($monthRows) === 0)
      continue;
    $startDay = min($fromDay, (int) date('t', strtotime($ms)) + 1);
    $start = add_days($ms, $startDay - 1);
    $spent = 0;
    foreach ($monthRows as $r) {
      if ($r['date'] >= $start && $r['cents'] < 0 && $r['debt'] === null)
        $spent -= $r['cents'];
    }
    $out[] = $spent;
  }
  return $out;
}

/**
 * Estado de un presupuesto mensual. $spent incluye lo realizado; $scheduled lo programado.
 * Nunca calcula porcentajes con límite cero.
 */
function fin_budget_status(int $limit, int $spent, int $scheduled, int $alertPct, int $daysElapsed, int $daysTotal): array
{
  if ($limit <= 0) {
    return ['status' => 'sin_limite', 'pct' => null, 'remaining' => null, 'forecast' => null];
  }
  $pct = round($spent * 100 / $limit, 1);
  $remaining = $limit - $spent;

  $variableSoFar = max(0, $spent);
  $forecast = $daysElapsed > 0 && $daysElapsed < $daysTotal
    ? (int) round($variableSoFar / $daysElapsed * $daysTotal) + $scheduled
    : $spent + $scheduled;

  if ($spent > $limit)
    $status = 'excedido';
  elseif ($pct >= $alertPct)
    $status = 'alerta';
  elseif ($daysElapsed >= 5 && $forecast > $limit)
    $status = 'riesgo';
  else
    $status = 'ok';

  return ['status' => $status, 'pct' => $pct, 'remaining' => $remaining, 'forecast' => $forecast];
}

/** Ahorro sugerido por semana/mes para llegar a una meta en la fecha deseada. */
function fin_goal_plan(int $target, int $saved, ?string $targetDate, string $today): array
{
  $missing = max(0, $target - $saved);
  $pct = $target > 0 ? min(100.0, round($saved * 100 / $target, 1)) : 0.0;
  $plan = ['missing' => $missing, 'pct' => $pct, 'per_week' => null, 'per_month' => null, 'days_left' => null, 'overdue' => false];
  if ($missing === 0 || !$targetDate)
    return $plan;
  $days = days_between($today, $targetDate);
  $plan['days_left'] = $days;
  if ($days <= 0) {
    $plan['overdue'] = true;
    return $plan;
  }
  // Redondeado hacia arriba al peso entero: sugerir centavos no aporta y confunde.
  $plan['per_week'] = (int) (ceil($missing / max(1, $days / 7) / 100) * 100);
  $plan['per_month'] = (int) (ceil($missing / max(1, $days / 30.4375) / 100) * 100);
  return $plan;
}

/**
 * Observaciones en lenguaje natural derivadas SOLO de datos verificables.
 * Cada observación incluye la evidencia numérica que la respalda.
 */
function fin_insights(array $ctx): array
{
  $out = [];
  $fmt = fn(int $c) => cents_format_cop($c);
  $fd = fn(string $d) => date('d/m/Y', strtotime($d));
  $t = $ctx['totals'];
  $label = $ctx['period']['label'];

  if ($t['count'] === 0) {
    return [[
      'kind' => 'empty', 'severity' => 'info',
      'title' => 'Aún no hay movimientos en ' . $label,
      'detail' => 'Registra tus ingresos y gastos para recibir un análisis automático.',
      'evidence' => null,
    ]];
  }

  $out[] = [
    'kind' => 'summary', 'severity' => $t['net'] >= 0 ? 'positive' : 'warning',
    'title' => $t['net'] >= 0
      ? 'En ' . $label . ' ingresaste más de lo que gastaste'
      : 'En ' . $label . ' gastaste más de lo que ingresaste',
    'detail' => 'Ingresos ' . $fmt($t['income']) . ' · Gastos ' . $fmt($t['expense']) . ' · Flujo neto ' . $fmt($t['net']) . '.',
    'evidence' => $t['count'] . ' movimientos realizados entre el ' . $fd($ctx['period']['from']) . ' y el ' . $fd(min($ctx['period']['to'], $ctx['today'])) . '.',
  ];

  if (!empty($ctx['categories'])) {
    $top = $ctx['categories'][0];
    $out[] = [
      'kind' => 'top_category', 'severity' => 'info',
      'title' => 'Tu mayor gasto fue ' . $top['category'],
      'detail' => $fmt($top['cents']) . ', el ' . str_replace('.', ',', (string) $top['share']) . ' % de tus gastos.',
      'evidence' => 'Suma de gastos realizados en la categoría ' . $top['category'] . '.',
    ];
  }

  $prev = $ctx['previous_totals'];
  $chg = pct_change($t['expense'], $prev['expense']);
  if ($chg !== null && abs($chg) >= 10) {
    $out[] = [
      'kind' => 'expense_change', 'severity' => $chg > 0 ? 'warning' : 'positive',
      'title' => $chg > 0 ? 'Tus gastos aumentaron frente al periodo anterior' : 'Tus gastos bajaron frente al periodo anterior',
      'detail' => 'Ahora ' . $fmt($t['expense']) . ' vs. ' . $fmt($prev['expense']) . ' (' . ($chg > 0 ? '+' : '') . str_replace('.', ',', (string) $chg) . ' %).',
      'evidence' => $ctx['period']['in_progress']
        ? 'Comparado con el mismo número de días del periodo anterior.'
        : 'Comparado con el periodo anterior completo.',
    ];
  }

  foreach (array_slice($ctx['increases'], 0, 2) as $inc) {
    $out[] = [
      'kind' => 'category_increase', 'severity' => 'warning',
      'title' => 'Tu gasto en ' . $inc['category'] . ' aumentó',
      'detail' => $fmt($inc['cents']) . ' frente a ' . $fmt($inc['previous_cents']) . ' (+' . str_replace('.', ',', (string) $inc['pct']) . ' %).',
      'evidence' => 'Mismo tramo del periodo anterior.',
    ];
  }

  foreach ($ctx['budgets'] as $b) {
    if (in_array($b['status'], ['excedido', 'riesgo', 'alerta'], true)) {
      $titles = [
        'excedido' => 'Superaste el presupuesto de ' . $b['category'],
        'alerta' => 'Estás cerca del límite en ' . $b['category'],
        'riesgo' => 'Si mantienes este ritmo, podrías superar el presupuesto de ' . $b['category'],
      ];
      $out[] = [
        'kind' => 'budget', 'severity' => $b['status'] === 'excedido' ? 'danger' : 'warning',
        'title' => $titles[$b['status']],
        'detail' => 'Llevas ' . $fmt($b['spent']) . ' de ' . $fmt($b['limit']) . ($b['status'] === 'riesgo' ? '; estimación al cierre: ' . $fmt($b['forecast']) . '.' : '.'),
        'evidence' => $b['status'] === 'riesgo' ? 'Estimación basada en el ritmo diario de este mes; no es una certeza.' : 'Gasto realizado este mes en la categoría.',
      ];
    }
  }

  $up = $ctx['upcoming'];
  if (count($up['items']) > 0) {
    $n = count($up['items']);
    $out[] = [
      'kind' => 'upcoming', 'severity' => 'info',
      'title' => $n === 1 ? 'Tienes 1 pago próximo' : "Tienes $n pagos próximos",
      'detail' => 'Suman ' . $fmt($up['expense']) . ' en los próximos ' . $up['days'] . ' días.',
      'evidence' => 'Gastos con fecha futura ya registrados.',
    ];
  }

  $proj = $ctx['projection'];
  if (!empty($proj['available']) && $proj['projected'] < 0) {
    $out[] = [
      'kind' => 'projection', 'severity' => 'danger',
      'title' => 'Tu saldo podría quedar en negativo al cierre',
      'detail' => 'Proyección: ' . $fmt($proj['projected']) . ' (rango ' . $fmt($proj['low']) . ' a ' . $fmt($proj['high']) . ').',
      'evidence' => 'Supone un gasto de ' . $fmt($proj['expected_spend']) . ' en los ' . $proj['days_remaining'] . ' días que faltan. Es una estimación, no una certeza.',
    ];
  }

  foreach (array_slice($ctx['unusual'], 0, 2) as $u) {
    $out[] = [
      'kind' => 'unusual', 'severity' => 'warning',
      'title' => 'Gasto inusual en ' . $u['category'],
      'detail' => $fmt($u['cents']) . ' el ' . $fd($u['date']) . ($u['description'] !== '' ? ' (' . $u['description'] . ')' : '') . '.',
      'evidence' => 'Es ' . str_replace('.', ',', (string) $u['ratio']) . ' veces tu gasto típico en esta categoría (mediana ' . $fmt($u['median_cents']) . ' en ' . $u['samples'] . ' movimientos).',
    ];
  }

  if (count($ctx['duplicates']) > 0) {
    $d = $ctx['duplicates'][0];
    $out[] = [
      'kind' => 'duplicates', 'severity' => 'warning',
      'title' => count($ctx['duplicates']) === 1 ? 'Hay un posible movimiento duplicado' : 'Hay ' . count($ctx['duplicates']) . ' posibles movimientos duplicados',
      'detail' => $d['count'] . ' movimientos de ' . $fmt(abs($d['cents'])) . ' el ' . $fd($d['date']) . ($d['description'] !== '' ? ' («' . $d['description'] . '»)' : '') . '.',
      'evidence' => 'Misma fecha, monto y descripción. Revísalos: pueden ser legítimos.',
    ];
  }

  return $out;
}

/* ============================ Consultas ============================ */

/**
 * Filtro de alcance: PERSONAL siempre por usuario; BEATAN (cuenta de la empresa) es
 * compartida entre administradores, como en el resto de la aplicación.
 */
function fin_scope(string $account, bool $isAdmin, string $userId, string $alias = ''): array
{
  $p = $alias !== '' ? $alias . '.' : '';
  $sql = "{$p}REGESTXX = 'ACTIVO' AND {$p}TIPGASXX = :scope_acc";
  $params = [':scope_acc' => $account];
  if (!($account === 'BEATAN' && $isAdmin)) {
    $sql .= " AND {$p}USRIDXXX = :scope_user";
    $params[':scope_user'] = $userId;
  }
  return [$sql, $params];
}

function fin_fetch_rows(PDO $pdo, array $scope, string $from, string $to): array
{
  [$where, $params] = $scope;
  $stmt = $pdo->prepare("SELECT FINCTIDX, FINCFECX, MONTGASX, CATGASXX, DESGASXX, DEUDIDXX
    FROM FINANCIX WHERE $where AND FINCFECX BETWEEN :f AND :t ORDER BY FINCFECX ASC, FINCTIDX ASC");
  $stmt->execute($params + [':f' => $from, ':t' => $to]);
  return fin_normalize_rows($stmt->fetchAll());
}

function fin_balance_until(PDO $pdo, array $scope, string $date): int
{
  [$where, $params] = $scope;
  $stmt = $pdo->prepare("SELECT COALESCE(SUM(MONTGASX), 0) FROM FINANCIX WHERE $where AND FINCFECX <= :d");
  $stmt->execute($params + [':d' => $date]);
  return db_money_to_cents($stmt->fetchColumn());
}

/** Deudas activas con saldo pendiente calculado a partir de los movimientos vinculados. */
function fin_debts(PDO $pdo, string $account, bool $isAdmin, string $userId, string $today): array
{
  $sql = "SELECT D.DEUDIDXX, D.TIPDEUDX, D.DESDEUDX, D.MONDEUDX, D.MONCUOTX, D.NUMCUOTX, D.FECCUOTX,
            COALESCE(SUM(CASE WHEN F.FINCFECX <= :today THEN ABS(F.MONTGASX) ELSE 0 END), 0) AS PAGADO,
            MIN(CASE WHEN F.FINCFECX > :today2 THEN F.FINCFECX END) AS PROXIMO
          FROM DEUDASIX D
          LEFT JOIN FINANCIX F ON F.DEUDIDXX = D.DEUDIDXX AND F.REGESTXX = 'ACTIVO'
          WHERE D.REGESTXX = 'ACTIVO' AND D.TIPPERXX = :acc";
  $params = [':today' => $today, ':today2' => $today, ':acc' => $account];
  if (!($account === 'BEATAN' && $isAdmin)) {
    $sql .= ' AND D.USRIDXXX = :uid';
    $params[':uid'] = $userId;
  }
  $sql .= ' GROUP BY D.DEUDIDXX, D.TIPDEUDX, D.DESDEUDX, D.MONDEUDX, D.MONCUOTX, D.NUMCUOTX, D.FECCUOTX';
  $stmt = $pdo->prepare($sql);
  $stmt->execute($params);

  $items = [];
  $payable = 0;
  $receivable = 0;
  foreach ($stmt->fetchAll() as $d) {
    $total = db_money_to_cents($d['MONDEUDX']);
    $paid = db_money_to_cents($d['PAGADO']);
    $remaining = max(0, $total - $paid);
    if ($d['TIPDEUDX'] === 'EN CONTRA')
      $payable += $remaining;
    else
      $receivable += $remaining;
    $items[] = [
      'id' => (int) $d['DEUDIDXX'],
      'type' => $d['TIPDEUDX'],
      'description' => $d['DESDEUDX'],
      'total' => $total,
      'paid' => min($paid, $total),
      'remaining' => $remaining,
      'pct' => $total > 0 ? min(100.0, round($paid * 100 / $total, 1)) : 0.0,
      'next_date' => $d['PROXIMO'],
    ];
  }
  usort($items, fn($a, $b) => $b['remaining'] <=> $a['remaining']);
  return ['items' => $items, 'payable' => $payable, 'receivable' => $receivable];
}

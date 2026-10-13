<?php
// dashboard/tests/unit.php
// Pruebas unitarias de la lógica financiera pura. Uso: php tests/unit.php

if (PHP_SAPI !== 'cli') {
  http_response_code(404);
  exit;
}
date_default_timezone_set('America/Bogota');
require_once __DIR__ . '/../lib/finance.php';

$passed = 0;
$failed = 0;
function check(string $name, $actual, $expected): void
{
  global $passed, $failed;
  if ($actual === $expected) {
    $passed++;
  } else {
    $failed++;
    echo "  FALLO: $name\n    esperado: " . var_export($expected, true) . "\n    obtenido: " . var_export($actual, true) . "\n";
  }
}
function section(string $t): void
{
  echo "• $t\n";
}

section('Dinero (centavos exactos)');
check('string entero', money_to_cents('50000'), 5000000);
check('string con 1 decimal', money_to_cents('50000.5'), 5000050);
check('string con 2 decimales', money_to_cents('0.01'), 1);
check('float 0.1+0.2 sin error binario', money_to_cents(0.1 + 0.2), 30);
check('float 19.99', money_to_cents(19.99), 1999);
check('negativo', money_to_cents('-1234.56'), -123456);
check('cero', money_to_cents('0'), 0);
check('3 decimales rechazado', money_to_cents('1.234'), null);
check('texto rechazado', money_to_cents('abc'), null);
check('formato con comas rechazado', money_to_cents('1,000'), null);
check('máximo DECIMAL(12,2)', money_to_cents('9999999999.99'), 999999999999);
check('excede DECIMAL(12,2)', money_to_cents('10000000000'), null);
check('NaN rechazado', money_to_cents(NAN), null);
check('INF rechazado', money_to_cents(INF), null);
check('null rechazado', money_to_cents(null), null);
check('BD decimal string', db_money_to_cents('1234.50'), 123450);
check('BD negativo', db_money_to_cents('-0.05'), -5);
check('BD null', db_money_to_cents(null), 0);
check('BD float sqlite', db_money_to_cents(1234.5), 123450);
check('a decimal', cents_to_decimal(123456), '1234.56');
check('a decimal negativo', cents_to_decimal(-5), '-0.05');
check('a decimal cero', cents_to_decimal(0), '0.00');
check('suma exacta de 0.10 x 10', array_sum(array_fill(0, 10, money_to_cents('0.10'))), 100);
check('formato COP', cents_format_cop(123456789), '$1.234.568');
check('formato COP negativo', cents_format_cop(-50000), '-$500');
check('variación con base cero => null', pct_change(1000, 0), null);
check('variación +50%', pct_change(150, 100), 50.0);
check('variación con base negativa', pct_change(-50, -100), 50.0);

section('Validación');
check('fecha válida', valid_date('2024-02-29'), '2024-02-29');
check('29 feb año no bisiesto', valid_date('2025-02-29'), null);
check('31 abril', valid_date('2025-04-31'), null);
check('formato incorrecto', valid_date('29/02/2024'), null);
check('mes válido', valid_month('2025-12'), '2025-12');
check('mes 13', valid_month('2025-13'), null);
check('límites febrero bisiesto', month_bounds('2024-02'), ['2024-02-01', '2024-02-29']);
check('límites febrero', month_bounds('2025-02'), ['2025-02-01', '2025-02-28']);
check('límites diciembre', month_bounds('2025-12'), ['2025-12-01', '2025-12-31']);
check('texto limpio recortado', clean_text("  hola\x07  mundo  ", 8), 'hola mun');
check('enum', valid_enum('gasto', ['INGRESO', 'GASTO']), 'GASTO');
[$tx, $err] = validate_transaction_input(['tipgasxx' => 'PERSONAL', 'tipo' => 'GASTO', 'monto' => '15000.50', 'categoria' => 'Comida', 'fecha' => '2025-01-31']);
check('movimiento válido sin errores', $err, []);
check('gasto con signo negativo', $tx['cents'], -1500050);
[$tx, $err] = validate_transaction_input(['tipgasxx' => 'OTRA', 'tipo' => 'X', 'monto' => '0', 'categoria' => '', 'fecha' => '2025-02-30']);
check('movimiento inválido reporta 5 errores', count($err), 5);
[$tx, $err] = validate_transaction_input(['tipgasxx' => 'PERSONAL', 'tipo' => 'INGRESO', 'monto' => '-500', 'categoria' => 'x', 'fecha' => '2025-01-01']);
check('monto negativo como ingreso se normaliza a positivo', $tx['cents'], 50000);
[$tx, $err] = validate_transaction_input(['tipgasxx' => 'PERSONAL', 'tipo' => 'INGRESO', 'monto' => '5', 'categoria' => 'x', 'fecha' => '2025-01-01', 'deudidxx' => 'abc']);
check('deuda inválida', in_array('Deuda asociada inválida', $err, true), true);

section('Periodos');
$p = fin_period('month', null, null, '2025-03-13');
check('mes actual desde', $p['from'], '2025-03-01');
check('mes actual hasta', $p['to'], '2025-03-31');
check('mes anterior desde', $p['prev_from'], '2025-02-01');
check('comparación mismo tramo (13 días)', $p['compare_to'], '2025-02-13');
check('días transcurridos', $p['days_elapsed'], 13);
check('en curso', $p['in_progress'], true);
$p = fin_period('month', null, null, '2025-03-31');
check('mes: último día ya no está en curso', $p['in_progress'], false);
$p = fin_period('month', null, null, '2025-03-30');
check('tramo comparación no supera fin de febrero', $p['compare_to'], '2025-02-28');
$p = fin_period('prev_month', null, null, '2025-01-15');
check('mes anterior cruzando año', [$p['from'], $p['to']], ['2024-12-01', '2024-12-31']);
$p = fin_period('week', null, null, '2025-03-13'); // jueves
check('semana lunes-domingo', [$p['from'], $p['to']], ['2025-03-10', '2025-03-16']);
$p = fin_period('custom', '2025-01-10', '2025-01-19', '2025-03-13');
check('personalizado previo de igual longitud', [$p['prev_from'], $p['prev_to']], ['2024-12-31', '2025-01-09']);
check('personalizado invertido inválido', fin_period('custom', '2025-02-01', '2025-01-01', '2025-03-13'), null);
check('personalizado fecha inválida', fin_period('custom', '2025-02-30', '2025-03-01', '2025-03-13'), null);
check('preset desconocido => mes', fin_period('xyz', null, null, '2025-03-13')['preset'], 'month');

section('Agregaciones');
$rows = fin_normalize_rows([
  ['FINCTIDX' => 1, 'FINCFECX' => '2025-03-01', 'MONTGASX' => '1000000.00', 'CATGASXX' => 'Salario', 'DESGASXX' => 'Nómina', 'DEUDIDXX' => null],
  ['FINCTIDX' => 2, 'FINCFECX' => '2025-03-02', 'MONTGASX' => '-200000.10', 'CATGASXX' => 'Comida', 'DESGASXX' => 'Mercado', 'DEUDIDXX' => null],
  ['FINCTIDX' => 3, 'FINCFECX' => '2025-03-02', 'MONTGASX' => '-100000.20', 'CATGASXX' => 'Transporte', 'DESGASXX' => 'Taxi', 'DEUDIDXX' => null],
  ['FINCTIDX' => 4, 'FINCFECX' => '2025-03-05', 'MONTGASX' => '-300000.00', 'CATGASXX' => 'Comida', 'DESGASXX' => 'Restaurante', 'DEUDIDXX' => '7'],
]);
$t = fin_totals($rows);
check('ingresos', $t['income'], 100000000);
check('gastos exactos', $t['expense'], 60000030);
check('neto', $t['net'], 39999970);
$cats = fin_expense_by_category($rows);
check('categoría principal', $cats[0]['category'], 'Comida');
check('participación', $cats[0]['share'], 83.3);
check('totales vacíos', fin_totals([]), ['income' => 0, 'expense' => 0, 'net' => 0, 'count' => 0]);
$series = fin_daily_series($rows, '2025-03-01', '2025-03-05', 500);
check('serie diaria longitud', count($series), 5);
check('saldo final serie = apertura + neto', end($series)['balance'], 500 + 39999970);

section('Anomalías');
$hist = [];
foreach ([10000, 12000, 9000, 11000, 10500, 9800] as $i => $v)
  $hist[] = ['id' => 100 + $i, 'date' => '2025-01-0' . ($i + 1), 'cents' => -$v * 100, 'cat' => 'Comida', 'desc' => 'x', 'debt' => null];
$cur = [
  ['id' => 1, 'date' => '2025-03-03', 'cents' => -5000000, 'cat' => 'Comida', 'desc' => 'Cena', 'debt' => null],
  ['id' => 2, 'date' => '2025-03-04', 'cents' => -1100000, 'cat' => 'Comida', 'desc' => 'Normal', 'debt' => null],
];
$u = fin_unusual_expenses($cur, $hist);
check('detecta solo el gasto inusual', array_column($u, 'id'), [1]);
check('sin historial suficiente no marca', fin_unusual_expenses($cur, array_slice($hist, 0, 3)), []);
$dups = fin_possible_duplicates([
  ['id' => 1, 'date' => '2025-03-02', 'cents' => -4590000, 'cat' => 'Ent', 'desc' => 'Streaming', 'debt' => null],
  ['id' => 2, 'date' => '2025-03-02', 'cents' => -4590000, 'cat' => 'Ent', 'desc' => 'streaming ', 'debt' => null],
  ['id' => 3, 'date' => '2025-03-03', 'cents' => -4590000, 'cat' => 'Ent', 'desc' => 'Streaming', 'debt' => null],
]);
check('duplicado mismo día/monto/descripción', count($dups), 1);
check('ids del duplicado', $dups[0]['ids'], [1, 2]);
$inc = fin_category_increases(
  [['id' => 1, 'date' => 'x', 'cents' => -200000, 'cat' => 'Transporte', 'desc' => '', 'debt' => null], ['id' => 2, 'date' => 'x', 'cents' => -800000, 'cat' => 'Comida', 'desc' => '', 'debt' => null]],
  [['id' => 3, 'date' => 'x', 'cents' => -100000, 'cat' => 'Transporte', 'desc' => '', 'debt' => null], ['id' => 4, 'date' => 'x', 'cents' => -790000, 'cat' => 'Comida', 'desc' => '', 'debt' => null]]
);
check('aumento de categoría', array_column($inc, 'category'), ['Transporte']);

section('Proyección');
$pr = fin_projection(1000000, -200000, 10, 20, [500000, 300000, 400000], 900000);
check('proyección disponible con historial', $pr['available'], true);
check('método historial', $pr['method'], 'historial');
check('central = base − mediana del historial', $pr['projected'], 1000000 - 200000 - 400000);
check('rango bajo usa el mayor gasto histórico', $pr['low'], 1000000 - 200000 - 500000);
check('rango alto usa el menor gasto histórico', $pr['high'], 1000000 - 200000 - 300000);
check('confianza media con 3 meses', $pr['confidence'], 'media');
$pr = fin_projection(1000000, 0, 10, 20, [], 300000);
check('sin historial usa ritmo actual', [$pr['method'], $pr['projected']], ['ritmo', 1000000 - 600000]);
check('ritmo ±25 %', [$pr['low'], $pr['high']], [1000000 - 750000, 1000000 - 450000]);
check('sin datos suficientes', fin_projection(100, 0, 3, 27, [], 0)['available'], false);
check('periodo terminado', fin_projection(100, 0, 30, 0, [1], 0)['available'], false);
$hrows = [
  ['id' => 1, 'date' => '2025-02-02', 'cents' => -100000, 'cat' => 'Vivienda', 'desc' => '', 'debt' => null],
  ['id' => 2, 'date' => '2025-02-20', 'cents' => -5000, 'cat' => 'Comida', 'desc' => '', 'debt' => null],
  ['id' => 3, 'date' => '2025-02-25', 'cents' => -7000, 'cat' => 'DEUDA', 'desc' => '', 'debt' => 9],
  ['id' => 4, 'date' => '2025-01-28', 'cents' => -3000, 'cat' => 'Comida', 'desc' => '', 'debt' => null],
];
check('historial de días restantes excluye fijos tempranos y cuotas', fin_historical_remaining($hrows, '2025-03-14', 15, 3), [5000, 3000]);
check('día 31 en febrero no desborda', fin_historical_remaining($hrows, '2025-03-30', 31, 1), [0]);

section('Presupuestos');
check('límite cero sin porcentaje', fin_budget_status(0, 1000, 0, 80, 10, 30)['pct'], null);
check('excedido', fin_budget_status(100000, 120000, 0, 80, 10, 30)['status'], 'excedido');
check('alerta 80 %', fin_budget_status(100000, 85000, 0, 80, 20, 30)['status'], 'alerta');
check('riesgo por ritmo', fin_budget_status(100000, 50000, 0, 80, 10, 30)['status'], 'riesgo');
check('ok', fin_budget_status(100000, 10000, 0, 80, 10, 30)['status'], 'ok');
check('restante', fin_budget_status(100000, 30000, 0, 80, 10, 30)['remaining'], 70000);

section('Metas');
$g = fin_goal_plan(1200000, 200000, '2025-12-31', '2025-01-01');
check('faltante', $g['missing'], 1000000);
check('por mes (≈12 meses, redondeado al peso)', $g['per_month'], (int) (ceil(1000000 / (364 / 30.4375) / 100) * 100));
check('sugerencia sin centavos', $g['per_month'] % 100, 0);
check('meta vencida', fin_goal_plan(100, 0, '2024-01-01', '2025-01-01')['overdue'], true);
check('meta cumplida', fin_goal_plan(100, 150, '2026-01-01', '2025-01-01')['pct'], 100.0);
check('objetivo cero sin división', fin_goal_plan(0, 0, null, '2025-01-01')['pct'], 0.0);

section('Observaciones');
$ctx = [
  'today' => '2025-03-13',
  'period' => fin_period('month', null, null, '2025-03-13'),
  'totals' => ['income' => 0, 'expense' => 0, 'net' => 0, 'count' => 0],
  'previous_totals' => ['income' => 0, 'expense' => 0, 'net' => 0, 'count' => 0],
  'categories' => [], 'increases' => [], 'budgets' => [],
  'upcoming' => ['items' => [], 'expense' => 0, 'days' => 7],
  'projection' => ['available' => false], 'unusual' => [], 'duplicates' => [],
];
check('sin datos => estado vacío orientativo', fin_insights($ctx)[0]['kind'], 'empty');
$ctx['totals'] = ['income' => 100000, 'expense' => 150000, 'net' => -50000, 'count' => 3];
$ctx['previous_totals'] = ['income' => 100000, 'expense' => 100000, 'net' => 0, 'count' => 2];
$kinds = array_column(fin_insights($ctx), 'kind');
check('resumen y cambio de gasto', $kinds, ['summary', 'expense_change']);
foreach (fin_insights($ctx) as $i)
  check('toda observación trae evidencia: ' . $i['kind'], is_string($i['evidence']) && $i['evidence'] !== '', true);

echo "\nResultado: $passed pruebas correctas, $failed fallidas\n";
exit($failed === 0 ? 0 : 1);

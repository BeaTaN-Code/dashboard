<?php
// dashboard/api/budgets.php
// Presupuestos mensuales por categoría (tabla BEATPRES, migración v3).

require_once __DIR__ . '/../config/api_helper.php';
require_once __DIR__ . '/../lib/finance.php';
require_once __DIR__ . '/../lib/access.php';

api_bootstrap();
$auth = api_require_auth();
$pdo = api_db();

try {
  switch ($_SERVER['REQUEST_METHOD']) {
    case 'GET':
      listBudgets($pdo, $auth);
      break;
    case 'POST':
      createBudget($pdo, $auth);
      break;
    case 'PUT':
      updateBudget($pdo, $auth);
      break;
    case 'DELETE':
      deleteBudget($pdo, $auth);
      break;
    default:
      api_response(false, 'Método no permitido', 405);
  }
} catch (PDOException $e) {
  if (db_is_missing_table($e)) {
    api_response(false, 'El módulo de presupuestos aún no está activado. Un administrador debe aplicar la migración v3 desde Ajustes.', 503, ['migration_required' => true]);
  }
  api_log_error('Budgets error', $e);
  api_response(false, 'No se pudo procesar el presupuesto', 500);
}

function budgetScopeSql(string $account, array $auth): array
{
  $sql = "REGESTXX = 'ACTIVO' AND TIPGASXX = :acc";
  $params = [':acc' => $account];
  if ($account !== 'BEATAN') {
    $sql .= ' AND USRIDXXX = :uid';
    $params[':uid'] = $auth['user_id'];
  }
  return [$sql, $params];
}

function listBudgets(PDO $pdo, array $auth): void
{
  $account = require_account($auth, $_GET['tipgasxx'] ?? null);
  $today = date('Y-m-d');
  $month = valid_month($_GET['month'] ?? '') ?? date('Y-m');
  [$from, $to] = month_bounds($month);

  [$where, $params] = budgetScopeSql($account, $auth);
  $stmt = $pdo->prepare("SELECT PRESIDXX, CATGASXX, PRESMONX, PRESALRX FROM BEATPRES WHERE $where ORDER BY CATGASXX ASC");
  $stmt->execute($params);
  $budgets = $stmt->fetchAll();

  $rows = fin_fetch_rows($pdo, fin_scope($account, $auth['is_admin'], $auth['user_id']), $from, $to);
  $spentByCat = [];
  $scheduledByCat = [];
  $names = [];
  foreach ($rows as $r) {
    if ($r['cents'] >= 0)
      continue;
    $key = mb_strtolower($r['cat']);
    $names[$key] = $r['cat'];
    if ($r['date'] <= $today)
      $spentByCat[$key] = ($spentByCat[$key] ?? 0) - $r['cents'];
    else
      $scheduledByCat[$key] = ($scheduledByCat[$key] ?? 0) - $r['cents'];
  }

  $daysTotal = (int) date('t', strtotime($from));
  $daysElapsed = $today < $from ? 0 : ($today > $to ? $daysTotal : (int) date('j', strtotime($today)));

  $out = [];
  $budgeted = [];
  $totals = ['limit' => 0, 'spent' => 0];
  foreach ($budgets as $b) {
    $key = mb_strtolower($b['CATGASXX']);
    $budgeted[$key] = true;
    $limit = db_money_to_cents($b['PRESMONX']);
    $spent = $spentByCat[$key] ?? 0;
    $scheduled = $scheduledByCat[$key] ?? 0;
    $status = fin_budget_status($limit, $spent, $scheduled, (int) $b['PRESALRX'], $daysElapsed, $daysTotal);
    $totals['limit'] += $limit;
    $totals['spent'] += $spent;
    $out[] = [
      'id' => (int) $b['PRESIDXX'],
      'category' => $b['CATGASXX'],
      'limit' => $limit,
      'alert_pct' => (int) $b['PRESALRX'],
      'spent' => $spent,
      'scheduled' => $scheduled,
    ] + $status;
  }

  $unbudgeted = [];
  foreach ($spentByCat as $key => $cents) {
    if (!isset($budgeted[$key]))
      $unbudgeted[] = ['category' => $names[$key], 'spent' => $cents];
  }
  usort($unbudgeted, fn($a, $b) => $b['spent'] <=> $a['spent']);

  api_response(true, [
    'month' => $month,
    'days_elapsed' => $daysElapsed,
    'days_total' => $daysTotal,
    'budgets' => $out,
    'totals' => $totals,
    'unbudgeted' => $unbudgeted,
  ]);
}

function readBudgetInput(array $in): array
{
  $errors = [];
  $cat = clean_text($in['categoria'] ?? '', 30);
  if ($cat === '')
    $errors[] = 'Elige una categoría';
  $cents = money_to_cents($in['monto'] ?? null);
  if ($cents === null || $cents <= 0)
    $errors[] = 'El límite debe ser mayor que cero';
  $alert = valid_int_range($in['alerta'] ?? 80, 10, 100);
  if ($alert === null)
    $errors[] = 'El umbral de alerta debe estar entre 10 % y 100 %';
  return [$cat, $cents, $alert, $errors];
}

function createBudget(PDO $pdo, array $auth): void
{
  $in = api_get_json_input();
  $account = require_account($auth, $in['tipgasxx'] ?? null);
  [$cat, $cents, $alert, $errors] = readBudgetInput($in);
  if ($errors)
    api_response(false, implode('. ', $errors), 422, ['errors' => $errors]);

  [$where, $params] = budgetScopeSql($account, $auth);
  $pdo->beginTransaction();
  $dup = $pdo->prepare("SELECT PRESIDXX FROM BEATPRES WHERE $where AND LOWER(CATGASXX) = LOWER(:cat)" . db_for_update());
  $dup->execute($params + [':cat' => $cat]);
  if ($dup->fetch()) {
    $pdo->rollBack();
    api_response(false, 'Ya existe un presupuesto para esa categoría. Edítalo en lugar de crear otro.', 409);
  }
  [$d, $h] = audit_now();
  $stmt = $pdo->prepare("INSERT INTO BEATPRES (USRIDXXX, TIPGASXX, CATGASXX, PRESMONX, PRESALRX, REGUSRXX, REGFECXX, REGHORXX, REGESTXX)
    VALUES (:uid, :acc, :cat, :monto, :alerta, :uid2, :d, :h, 'ACTIVO')");
  $stmt->execute([':uid' => $auth['user_id'], ':acc' => $account, ':cat' => $cat, ':monto' => cents_to_decimal($cents),
    ':alerta' => $alert, ':uid2' => $auth['user_id'], ':d' => $d, ':h' => $h]);
  $id = (int) $pdo->lastInsertId();
  $pdo->commit();
  api_response(true, ['id' => $id], 201, ['message' => 'Presupuesto creado']);
}

function loadOwnBudget(PDO $pdo, array $auth, int $id): array
{
  $stmt = $pdo->prepare("SELECT * FROM BEATPRES WHERE PRESIDXX = :id AND REGESTXX = 'ACTIVO'");
  $stmt->execute([':id' => $id]);
  $b = $stmt->fetch();
  if (!$b)
    api_response(false, 'Presupuesto no encontrado', 404);
  if (!can_modify_record($auth, (string) $b['USRIDXXX'], (string) $b['TIPGASXX']))
    api_response(false, 'No tienes permiso sobre este presupuesto', 403);
  return $b;
}

function updateBudget(PDO $pdo, array $auth): void
{
  $in = api_get_json_input();
  $id = valid_int_range($in['id'] ?? null, 1, PHP_INT_MAX);
  if (!$id)
    api_response(false, 'ID inválido', 400);
  $b = loadOwnBudget($pdo, $auth, $id);
  [$cat, $cents, $alert, $errors] = readBudgetInput($in + ['categoria' => $b['CATGASXX']]);
  if ($errors)
    api_response(false, implode('. ', $errors), 422, ['errors' => $errors]);
  [$d, $h] = audit_now();
  $stmt = $pdo->prepare("UPDATE BEATPRES SET CATGASXX = :cat, PRESMONX = :monto, PRESALRX = :alerta,
      REGUSRMX = :uid, REGFECMX = :d, REGHORMX = :h WHERE PRESIDXX = :id");
  $stmt->execute([':cat' => $cat, ':monto' => cents_to_decimal($cents), ':alerta' => $alert, ':uid' => $auth['user_id'], ':d' => $d, ':h' => $h, ':id' => $id]);
  api_response(true, ['id' => $id], 200, ['message' => 'Presupuesto actualizado']);
}

function deleteBudget(PDO $pdo, array $auth): void
{
  $id = valid_int_range($_GET['id'] ?? null, 1, PHP_INT_MAX);
  if (!$id)
    api_response(false, 'ID inválido', 400);
  loadOwnBudget($pdo, $auth, $id);
  [$d, $h] = audit_now();
  $stmt = $pdo->prepare("UPDATE BEATPRES SET REGESTXX = 'INACTIVO', REGUSRMX = :uid, REGFECMX = :d, REGHORMX = :h WHERE PRESIDXX = :id");
  $stmt->execute([':uid' => $auth['user_id'], ':d' => $d, ':h' => $h, ':id' => $id]);
  api_response(true, ['id' => $id], 200, ['message' => 'Presupuesto eliminado']);
}

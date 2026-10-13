<?php
// dashboard/api/goals.php
// Metas de ahorro (BEATMETA) y sus aportes/retiros (BEATMEMO). Migración v3.
// Un aporte NO es un gasto: es dinero apartado dentro del saldo disponible.

require_once __DIR__ . '/../config/api_helper.php';
require_once __DIR__ . '/../lib/finance.php';
require_once __DIR__ . '/../lib/access.php';

api_bootstrap();
$auth = api_require_auth();
$pdo = api_db();

try {
  switch ($_SERVER['REQUEST_METHOD']) {
    case 'GET':
      isset($_GET['id']) ? getGoal($pdo, $auth) : listGoals($pdo, $auth);
      break;
    case 'POST':
      $in = api_get_json_input();
      ($in['action'] ?? 'create') === 'movement' ? addMovement($pdo, $auth, $in) : createGoal($pdo, $auth, $in);
      break;
    case 'PUT':
      updateGoal($pdo, $auth);
      break;
    case 'DELETE':
      deleteGoal($pdo, $auth);
      break;
    default:
      api_response(false, 'Método no permitido', 405);
  }
} catch (PDOException $e) {
  if ($pdo->inTransaction())
    $pdo->rollBack();
  if (db_is_missing_table($e)) {
    api_response(false, 'El módulo de metas aún no está activado. Un administrador debe aplicar la migración v3 desde Ajustes.', 503, ['migration_required' => true]);
  }
  api_log_error('Goals error', $e);
  api_response(false, 'No se pudo procesar la meta', 500);
}

function goalsScope(string $account, array $auth): array
{
  $sql = "G.REGESTXX = 'ACTIVO' AND G.TIPGASXX = :acc";
  $params = [':acc' => $account];
  if ($account !== 'BEATAN') {
    $sql .= ' AND G.USRIDXXX = :uid';
    $params[':uid'] = $auth['user_id'];
  }
  return [$sql, $params];
}

function shapeGoal(array $g, string $today): array
{
  $target = db_money_to_cents($g['METAOBJX']);
  $saved = db_money_to_cents($g['AHORRADO'] ?? 0);
  return [
    'id' => (int) $g['METAIDXX'],
    'name' => $g['METANOMX'],
    'target' => $target,
    'saved' => $saved,
    'target_date' => $g['METAFECX'],
    'state' => $g['METAESTX'],
    'completed' => $saved >= $target && $target > 0,
    'created' => $g['REGFECXX'],
    'plan' => fin_goal_plan($target, $saved, $g['METAFECX'], $today),
  ];
}

function listGoals(PDO $pdo, array $auth): void
{
  $account = require_account($auth, $_GET['tipgasxx'] ?? null);
  [$where, $params] = goalsScope($account, $auth);
  if (empty($_GET['include_archived']))
    $where .= " AND G.METAESTX = 'ACTIVA'";
  $stmt = $pdo->prepare("SELECT G.*, COALESCE(SUM(M.MEMOMONX), 0) AS AHORRADO
    FROM BEATMETA G LEFT JOIN BEATMEMO M ON M.METAIDXX = G.METAIDXX AND M.REGESTXX = 'ACTIVO'
    WHERE $where
    GROUP BY G.METAIDXX, G.USRIDXXX, G.TIPGASXX, G.METANOMX, G.METAOBJX, G.METAFECX, G.METAESTX,
             G.REGUSRXX, G.REGFECXX, G.REGHORXX, G.REGUSRMX, G.REGFECMX, G.REGHORMX, G.REGESTXX, G.REGSTAMP
    ORDER BY G.METAESTX ASC, G.METAFECX IS NULL, G.METAFECX ASC, G.METAIDXX DESC");
  $stmt->execute($params);
  $today = date('Y-m-d');
  $goals = array_map(fn($g) => shapeGoal($g, $today), $stmt->fetchAll());
  $active = array_filter($goals, fn($g) => $g['state'] === 'ACTIVA');
  api_response(true, [
    'goals' => $goals,
    'totals' => [
      'saved' => array_sum(array_map(fn($g) => $g['saved'], $active)),
      'target' => array_sum(array_map(fn($g) => $g['target'], $active)),
    ],
  ]);
}

function loadGoal(PDO $pdo, array $auth, int $id, bool $lock = false): array
{
  $stmt = $pdo->prepare("SELECT * FROM BEATMETA WHERE METAIDXX = :id AND REGESTXX = 'ACTIVO'" . ($lock ? db_for_update() : ''));
  $stmt->execute([':id' => $id]);
  $g = $stmt->fetch();
  if (!$g)
    api_response(false, 'Meta no encontrada', 404);
  if ($g['TIPGASXX'] === 'BEATAN' && !$auth['is_admin'])
    api_response(false, 'No tienes permiso sobre esta meta', 403);
  if (!can_modify_record($auth, (string) $g['USRIDXXX'], (string) $g['TIPGASXX']))
    api_response(false, 'No tienes permiso sobre esta meta', 403);
  return $g;
}

function goalSaved(PDO $pdo, int $id): int
{
  $stmt = $pdo->prepare("SELECT COALESCE(SUM(MEMOMONX), 0) FROM BEATMEMO WHERE METAIDXX = :id AND REGESTXX = 'ACTIVO'");
  $stmt->execute([':id' => $id]);
  return db_money_to_cents($stmt->fetchColumn());
}

function getGoal(PDO $pdo, array $auth): void
{
  $id = valid_int_range($_GET['id'] ?? null, 1, PHP_INT_MAX);
  if (!$id)
    api_response(false, 'ID inválido', 400);
  $g = loadGoal($pdo, $auth, $id);
  $g['AHORRADO'] = cents_to_decimal(goalSaved($pdo, $id));
  $stmt = $pdo->prepare("SELECT MEMOIDXX, MEMOMONX, MEMOFECX, MEMODESX FROM BEATMEMO
    WHERE METAIDXX = :id AND REGESTXX = 'ACTIVO' ORDER BY MEMOFECX DESC, MEMOIDXX DESC LIMIT 200");
  $stmt->execute([':id' => $id]);
  $movs = array_map(fn($m) => [
    'id' => (int) $m['MEMOIDXX'],
    'cents' => db_money_to_cents($m['MEMOMONX']),
    'date' => $m['MEMOFECX'],
    'description' => $m['MEMODESX'],
  ], $stmt->fetchAll());
  api_response(true, ['goal' => shapeGoal($g, date('Y-m-d')), 'movements' => $movs]);
}

function readGoalInput(array $in, bool $partial = false): array
{
  $errors = [];
  $name = clean_text($in['nombre'] ?? '', 80);
  if ($name === '')
    $errors[] = 'Ponle un nombre a la meta';
  $cents = money_to_cents($in['objetivo'] ?? null);
  if ($cents === null || $cents <= 0)
    $errors[] = 'El objetivo debe ser mayor que cero';
  $date = null;
  if (!empty($in['fecha'])) {
    $date = valid_date($in['fecha']);
    if (!$date)
      $errors[] = 'La fecha deseada no es válida';
  }
  return [$name, $cents, $date, $errors];
}

function createGoal(PDO $pdo, array $auth, array $in): void
{
  api_idempotent_replay('goal_create');
  $account = require_account($auth, $in['tipgasxx'] ?? null);
  [$name, $cents, $date, $errors] = readGoalInput($in);
  if ($date && $date < date('Y-m-d'))
    $errors[] = 'La fecha deseada debe ser futura';
  if ($errors)
    api_response(false, implode('. ', $errors), 422, ['errors' => $errors]);
  [$d, $h] = audit_now();
  $stmt = $pdo->prepare("INSERT INTO BEATMETA (USRIDXXX, TIPGASXX, METANOMX, METAOBJX, METAFECX, METAESTX, REGUSRXX, REGFECXX, REGHORXX, REGESTXX)
    VALUES (:uid, :acc, :name, :obj, :fecha, 'ACTIVA', :uid2, :d, :h, 'ACTIVO')");
  $stmt->execute([':uid' => $auth['user_id'], ':acc' => $account, ':name' => $name, ':obj' => cents_to_decimal($cents),
    ':fecha' => $date, ':uid2' => $auth['user_id'], ':d' => $d, ':h' => $h]);
  api_idempotent_success('goal_create', ['success' => true, 'data' => ['id' => (int) $pdo->lastInsertId()], 'message' => 'Meta creada'], 201);
}

function addMovement(PDO $pdo, array $auth, array $in): void
{
  api_idempotent_replay('goal_movement');
  $id = valid_int_range($in['meta_id'] ?? null, 1, PHP_INT_MAX);
  $tipo = valid_enum($in['tipo'] ?? '', ['APORTE', 'RETIRO']);
  $cents = money_to_cents($in['monto'] ?? null);
  $date = valid_date($in['fecha'] ?? date('Y-m-d'));
  $errors = [];
  if (!$id)
    $errors[] = 'Meta inválida';
  if (!$tipo)
    $errors[] = 'Indica si es un aporte o un retiro';
  if ($cents === null || $cents <= 0)
    $errors[] = 'El monto debe ser mayor que cero';
  if (!$date)
    $errors[] = 'La fecha no es válida';
  elseif ($date > date('Y-m-d'))
    $errors[] = 'Los aportes y retiros no pueden tener fecha futura';
  if ($errors)
    api_response(false, implode('. ', $errors), 422, ['errors' => $errors]);

  $pdo->beginTransaction();
  $goal = loadGoal($pdo, $auth, $id, true); // bloquea la meta: evita retiros concurrentes que dejen saldo negativo
  if ($goal['METAESTX'] !== 'ACTIVA') {
    $pdo->rollBack();
    api_response(false, 'La meta está archivada. Reactívala para registrar movimientos.', 409);
  }
  $saved = goalSaved($pdo, $id);
  if ($tipo === 'RETIRO' && $cents > $saved) {
    $pdo->rollBack();
    api_response(false, 'No puedes retirar más de lo ahorrado (' . cents_format_cop($saved) . ').', 422);
  }
  $signed = $tipo === 'RETIRO' ? -$cents : $cents;
  [$d, $h] = audit_now();
  $stmt = $pdo->prepare("INSERT INTO BEATMEMO (METAIDXX, USRIDXXX, MEMOMONX, MEMOFECX, MEMODESX, REGUSRXX, REGFECXX, REGHORXX, REGESTXX)
    VALUES (:meta, :uid, :monto, :fecha, :descr, :uid2, :d, :h, 'ACTIVO')");
  $stmt->execute([':meta' => $id, ':uid' => $auth['user_id'], ':monto' => cents_to_decimal($signed), ':fecha' => $date,
    ':descr' => clean_text($in['descripcion'] ?? '', 150), ':uid2' => $auth['user_id'], ':d' => $d, ':h' => $h]);
  $pdo->commit();

  api_idempotent_success('goal_movement', [
    'success' => true,
    'data' => ['saved' => $saved + $signed],
    'message' => $tipo === 'APORTE' ? 'Aporte registrado' : 'Retiro registrado',
  ], 201);
}

function updateGoal(PDO $pdo, array $auth): void
{
  $in = api_get_json_input();
  $id = valid_int_range($in['id'] ?? null, 1, PHP_INT_MAX);
  if (!$id)
    api_response(false, 'ID inválido', 400);
  $g = loadGoal($pdo, $auth, $id);
  [$d, $h] = audit_now();

  // Solo cambio de estado (archivar / reactivar)
  if (isset($in['estado']) && !isset($in['nombre'])) {
    $state = valid_enum($in['estado'], ['ACTIVA', 'ARCHIVADA']);
    if (!$state)
      api_response(false, 'Estado inválido', 422);
    $stmt = $pdo->prepare("UPDATE BEATMETA SET METAESTX = :s, REGUSRMX = :uid, REGFECMX = :d, REGHORMX = :h WHERE METAIDXX = :id");
    $stmt->execute([':s' => $state, ':uid' => $auth['user_id'], ':d' => $d, ':h' => $h, ':id' => $id]);
    api_response(true, ['id' => $id], 200, ['message' => $state === 'ARCHIVADA' ? 'Meta archivada' : 'Meta reactivada']);
  }

  [$name, $cents, $date, $errors] = readGoalInput($in);
  if ($errors)
    api_response(false, implode('. ', $errors), 422, ['errors' => $errors]);
  $stmt = $pdo->prepare("UPDATE BEATMETA SET METANOMX = :name, METAOBJX = :obj, METAFECX = :fecha,
      REGUSRMX = :uid, REGFECMX = :d, REGHORMX = :h WHERE METAIDXX = :id");
  $stmt->execute([':name' => $name, ':obj' => cents_to_decimal($cents), ':fecha' => $date, ':uid' => $auth['user_id'], ':d' => $d, ':h' => $h, ':id' => $id]);
  api_response(true, ['id' => $id], 200, ['message' => 'Meta actualizada']);
}

function deleteGoal(PDO $pdo, array $auth): void
{
  $id = valid_int_range($_GET['id'] ?? null, 1, PHP_INT_MAX);
  if (!$id)
    api_response(false, 'ID inválido', 400);
  loadGoal($pdo, $auth, $id);
  [$d, $h] = audit_now();
  $pdo->beginTransaction();
  $pdo->prepare("UPDATE BEATMETA SET REGESTXX = 'INACTIVO', REGUSRMX = :uid, REGFECMX = :d, REGHORMX = :h WHERE METAIDXX = :id")
    ->execute([':uid' => $auth['user_id'], ':d' => $d, ':h' => $h, ':id' => $id]);
  $pdo->prepare("UPDATE BEATMEMO SET REGESTXX = 'INACTIVO', REGUSRMX = :uid, REGFECMX = :d, REGHORMX = :h WHERE METAIDXX = :id")
    ->execute([':uid' => $auth['user_id'], ':d' => $d, ':h' => $h, ':id' => $id]);
  $pdo->commit();
  api_response(true, ['id' => $id], 200, ['message' => 'Meta eliminada']);
}

<?php
// dashboard/api/transactions.php
// API para gestionar transacciones financieras.
// Contrato histórico conservado: GET devuelve {data, balance:{total,income,expense}}; POST acepta
// movimientos individuales y {action:'bulk_import', items:[...]}; PUT edita; DELETE ?id= borra (lógico).

require_once __DIR__ . '/../config/api_helper.php';
require_once __DIR__ . '/../lib/finance.php';
require_once __DIR__ . '/../lib/access.php';

api_bootstrap();
$auth = api_require_auth();
$pdo = api_db();

const BULK_MAX_ITEMS = 1000;

try {
  switch ($_SERVER['REQUEST_METHOD']) {
    case 'GET':
      getTransactions($pdo, $auth);
      break;
    case 'POST':
      $input = api_get_json_input();
      $action = $input['action'] ?? '';
      if ($action === 'bulk_import')
        bulkImport($pdo, $auth, $input);
      elseif ($action === 'restore')
        restoreTransaction($pdo, $auth, $input);
      else
        addTransaction($pdo, $auth, $input);
      break;
    case 'PUT':
      updateTransaction($pdo, $auth);
      break;
    case 'DELETE':
      deleteTransaction($pdo, $auth);
      break;
    default:
      api_response(false, 'Método no permitido', 405);
  }
} catch (PDOException $e) {
  if ($pdo->inTransaction())
    $pdo->rollBack();
  api_log_error('Transactions error', $e);
  api_response(false, 'No se pudo completar la operación con los movimientos', 500);
}

function getTransactions(PDO $pdo, array $auth): void
{
  $tipgasxx = valid_enum($_GET['tipgasxx'] ?? '', ACCOUNTS) ?? '';
  $tipo = valid_enum($_GET['tipo'] ?? '', ['INGRESO', 'GASTO']) ?? '';
  $month = valid_month($_GET['month'] ?? '') ?? '';
  $from = valid_date($_GET['from'] ?? '') ?? '';
  $to = valid_date($_GET['to'] ?? '') ?? '';
  $categoria = clean_text($_GET['categoria'] ?? '', 30);
  $search = clean_text($_GET['search'] ?? '', 100);
  $today = date('Y-m-d');

  // Alcance: solo los propios, salvo administradores sobre la cuenta BEATAN (compartida).
  $where = "F.REGESTXX = 'ACTIVO'";
  $params = [];
  if (!($tipgasxx === 'BEATAN' && $auth['is_admin'])) {
    $where .= ' AND F.USRIDXXX = :userId';
    $params[':userId'] = $auth['user_id'];
  }
  if ($tipgasxx !== '') {
    $where .= ' AND F.TIPGASXX = :tipgasxx';
    $params[':tipgasxx'] = $tipgasxx;
  }
  $scopeWhere = $where;
  $scopeParams = $params;

  if ($tipo === 'INGRESO')
    $where .= ' AND F.MONTGASX > 0';
  elseif ($tipo === 'GASTO')
    $where .= ' AND F.MONTGASX < 0';

  // Rango de fechas (usa el índice en vez de DATE_FORMAT). 'month' se mantiene por compatibilidad.
  if ($month !== '') {
    [$from, $to] = month_bounds($month);
  }
  if ($from !== '') {
    $where .= ' AND F.FINCFECX >= :from';
    $params[':from'] = $from;
  }
  if ($to !== '') {
    $where .= ' AND F.FINCFECX <= :to';
    $params[':to'] = $to;
  }
  if ($categoria !== '') {
    $where .= ' AND F.CATGASXX = :categoria';
    $params[':categoria'] = $categoria;
  }
  if ($search !== '') {
    $where .= " AND (F.DESGASXX LIKE :search ESCAPE '!' OR F.CATGASXX LIKE :search2 ESCAPE '!')";
    $like = '%' . str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $search) . '%';
    $params[':search'] = $like;
    $params[':search2'] = $like;
  }

  $stmt = $pdo->prepare("SELECT F.*, D.DESDEUDX FROM FINANCIX F
    LEFT JOIN DEUDASIX D ON D.DEUDIDXX = F.DEUDIDXX
    WHERE $where ORDER BY F.FINCFECX ASC, F.FINCTIDX ASC");
  $stmt->execute($params);
  $transactions = $stmt->fetchAll();
  foreach ($transactions as &$t) {
    $t['MONTO_CENTS'] = db_money_to_cents($t['MONTGASX']);
  }
  unset($t);

  // Balance histórico hasta hoy (regla original: los movimientos futuros no cuentan).
  $balStmt = $pdo->prepare("SELECT
      COALESCE(SUM(CASE WHEN F.MONTGASX > 0 THEN F.MONTGASX ELSE 0 END), 0) AS income,
      COALESCE(SUM(CASE WHEN F.MONTGASX < 0 THEN -F.MONTGASX ELSE 0 END), 0) AS expense
    FROM FINANCIX F WHERE $scopeWhere AND F.FINCFECX <= :today");
  $balStmt->execute($scopeParams + [':today' => $today]);
  $bal = $balStmt->fetch();
  $income = db_money_to_cents($bal['income']);
  $expense = db_money_to_cents($bal['expense']);

  // Totales del filtro actual (separando lo realizado de lo programado)
  $sums = ['realized' => ['income' => 0, 'expense' => 0], 'scheduled' => ['income' => 0, 'expense' => 0]];
  foreach ($transactions as $t) {
    $bucket = $t['FINCFECX'] <= $today ? 'realized' : 'scheduled';
    $c = $t['MONTO_CENTS'];
    if ($c > 0)
      $sums[$bucket]['income'] += $c;
    else
      $sums[$bucket]['expense'] += -$c;
  }

  // Categorías usadas por el usuario en la cuenta (para autocompletar)
  $catStmt = $pdo->prepare("SELECT DISTINCT F.CATGASXX FROM FINANCIX F WHERE $scopeWhere AND F.CATGASXX IS NOT NULL AND F.CATGASXX <> '' ORDER BY F.CATGASXX");
  $catStmt->execute($scopeParams);

  api_json([
    'success' => true,
    'data' => $transactions,
    'balance' => [
      'total' => cents_to_float($income - $expense),
      'income' => cents_to_float($income),
      'expense' => cents_to_float($expense),
    ],
    'balance_cents' => ['total' => $income - $expense, 'income' => $income, 'expense' => $expense],
    'filtered_cents' => $sums + ['count' => count($transactions)],
    'categories' => array_column($catStmt->fetchAll(), 'CATGASXX'),
    'today' => $today,
  ]);
}

function assertCanWriteAccount(array $auth, ?string $account): void
{
  if ($account === 'BEATAN' && !$auth['is_admin']) {
    api_response(false, 'Solo los administradores pueden registrar movimientos de la empresa', 403);
  }
}

function addTransaction(PDO $pdo, array $auth, array $input): void
{
  api_idempotent_replay('tx_add');
  [$tx, $errors] = validate_transaction_input($input);
  if ($errors) {
    api_response(false, implode('. ', $errors), 422, ['errors' => $errors]);
  }
  assertCanWriteAccount($auth, $tx['tipgasxx']);
  if ($tx['deudidxx'] !== null && !debt_belongs_to($pdo, $tx['deudidxx'], $auth, $tx['tipgasxx'])) {
    api_response(false, 'La deuda asociada no existe o no te pertenece', 422);
  }

  [$d, $h] = audit_now();
  $stmt = $pdo->prepare("INSERT INTO FINANCIX (USRIDXXX, DEUDIDXX, TIPGASXX, MONTGASX, CATGASXX, DESGASXX, FINCFECX, REGUSRXX, REGFECXX, REGHORXX, REGESTXX, REGSTAMP)
    VALUES (:userId, :deudidxx, :tipgasxx, :monto, :categoria, :descripcion, :fecha, :userId2, :d, :h, 'ACTIVO', :stamp)");
  $stmt->execute([
    ':userId' => $auth['user_id'],
    ':deudidxx' => $tx['deudidxx'],
    ':tipgasxx' => $tx['tipgasxx'],
    ':monto' => cents_to_decimal($tx['cents']),
    ':categoria' => $tx['categoria'],
    ':descripcion' => $tx['descripcion'],
    ':fecha' => $tx['fecha'],
    ':userId2' => $auth['user_id'],
    ':d' => $d,
    ':h' => $h,
    ':stamp' => "$d $h",
  ]);

  api_idempotent_success('tx_add', [
    'success' => true,
    'message' => 'Transacción agregada exitosamente',
    'id' => (int) $pdo->lastInsertId(),
  ]);
}

/**
 * Importación masiva con informe por fila. Las filas inválidas se rechazan (con motivo) sin
 * bloquear las válidas; todo lo aceptado se guarda en una única transacción de BD.
 * Los posibles duplicados (misma fecha y monto ya existentes) se informan pero se respetan,
 * porque el usuario los marcó explícitamente en la vista previa.
 */
function bulkImport(PDO $pdo, array $auth, array $input): void
{
  api_idempotent_replay('tx_bulk');
  $items = $input['items'] ?? null;
  if (!is_array($items) || count($items) === 0) {
    api_response(false, 'No hay movimientos para importar', 422);
  }
  if (count($items) > BULK_MAX_ITEMS) {
    api_response(false, 'Máximo ' . BULK_MAX_ITEMS . ' movimientos por importación', 413);
  }

  $accepted = [];
  $rejected = [];
  foreach (array_values($items) as $i => $item) {
    if (!is_array($item)) {
      $rejected[] = ['index' => $i, 'reasons' => ['Formato inválido']];
      continue;
    }
    $cents = money_to_cents($item['monto'] ?? null);
    $tipo = $item['tipo'] ?? ($cents !== null && $cents < 0 ? 'GASTO' : 'INGRESO');
    [$tx, $errors] = validate_transaction_input([
      'tipgasxx' => $item['tipgasxx'] ?? 'PERSONAL',
      'tipo' => $tipo,
      'monto' => $cents !== null ? cents_to_decimal(abs($cents)) : null,
      'categoria' => ($item['categoria'] ?? '') !== '' ? $item['categoria'] : 'Varios',
      'descripcion' => ($item['descripcion'] ?? '') !== '' ? $item['descripcion'] : 'Importado de extracto',
      'fecha' => $item['fecha'] ?? '',
    ]);
    if (!$errors && $tx['tipgasxx'] === 'BEATAN' && !$auth['is_admin'])
      $errors[] = 'Sin permiso para la cuenta de la empresa';
    if ($errors)
      $rejected[] = ['index' => $i, 'reasons' => $errors];
    else
      $accepted[] = $tx + ['index' => $i];
  }

  if (count($accepted) === 0) {
    api_response(false, 'Ninguna fila es válida para importar', 422, ['count' => 0, 'rejected' => $rejected]);
  }

  $dupStmt = $pdo->prepare("SELECT COUNT(*) FROM FINANCIX WHERE REGESTXX = 'ACTIVO' AND USRIDXXX = :u AND TIPGASXX = :a AND FINCFECX = :f AND MONTGASX = :m");
  $insert = $pdo->prepare("INSERT INTO FINANCIX (USRIDXXX, DEUDIDXX, TIPGASXX, MONTGASX, CATGASXX, DESGASXX, FINCFECX, REGUSRXX, REGFECXX, REGHORXX, REGESTXX, REGSTAMP)
    VALUES (:userId, NULL, :tipgasxx, :monto, :categoria, :descripcion, :fecha, :userId2, :d, :h, 'ACTIVO', :stamp)");
  [$d, $h] = audit_now();

  $possibleDuplicates = 0;
  $pdo->beginTransaction();
  foreach ($accepted as $tx) {
    $dupStmt->execute([':u' => $auth['user_id'], ':a' => $tx['tipgasxx'], ':f' => $tx['fecha'], ':m' => cents_to_decimal($tx['cents'])]);
    if ((int) $dupStmt->fetchColumn() > 0)
      $possibleDuplicates++;
    $insert->execute([
      ':userId' => $auth['user_id'],
      ':tipgasxx' => $tx['tipgasxx'],
      ':monto' => cents_to_decimal($tx['cents']),
      ':categoria' => $tx['categoria'],
      ':descripcion' => $tx['descripcion'],
      ':fecha' => $tx['fecha'],
      ':userId2' => $auth['user_id'],
      ':d' => $d,
      ':h' => $h,
      ':stamp' => "$d $h",
    ]);
  }
  $pdo->commit();

  $count = count($accepted);
  api_idempotent_success('tx_bulk', [
    'success' => true,
    'message' => "Se importaron $count movimiento(s)" . (count($rejected) ? ' y se rechazaron ' . count($rejected) : ''),
    'count' => $count,
    'rejected' => $rejected,
    'possible_duplicates' => $possibleDuplicates,
  ]);
}

function loadTransactionForWrite(PDO $pdo, array $auth, int $id, string $state = 'ACTIVO'): array
{
  $stmt = $pdo->prepare('SELECT FINCTIDX, TIPGASXX, USRIDXXX, REGESTXX FROM FINANCIX WHERE FINCTIDX = :id');
  $stmt->execute([':id' => $id]);
  $t = $stmt->fetch();
  if (!$t || $t['REGESTXX'] !== $state) {
    api_response(false, 'Transacción no encontrada', 404);
  }
  if (!can_modify_record($auth, (string) $t['USRIDXXX'], (string) $t['TIPGASXX'])) {
    api_response(false, 'No tiene permiso para modificar esta transacción', 403);
  }
  return $t;
}

function updateTransaction(PDO $pdo, array $auth): void
{
  $input = api_get_json_input();
  $id = valid_int_range($input['id'] ?? null, 1, PHP_INT_MAX);
  if (!$id) {
    api_response(false, 'ID inválido', 400);
  }
  $current = loadTransactionForWrite($pdo, $auth, $id);

  [$tx, $errors] = validate_transaction_input($input + ['tipgasxx' => $current['TIPGASXX']], false);
  if ($errors) {
    api_response(false, implode('. ', $errors), 422, ['errors' => $errors]);
  }
  if ($tx['deudidxx'] !== null && !debt_belongs_to($pdo, $tx['deudidxx'], $auth, $current['TIPGASXX'])) {
    api_response(false, 'La deuda asociada no existe o no te pertenece', 422);
  }

  [$d, $h] = audit_now();
  $stmt = $pdo->prepare("UPDATE FINANCIX SET DEUDIDXX = :deudidxx, MONTGASX = :monto, CATGASXX = :categoria,
      DESGASXX = :descripcion, FINCFECX = :fecha, REGUSRMX = :userId, REGFECMX = :d, REGHORMX = :h, REGSTAMP = :stamp
    WHERE FINCTIDX = :id");
  $stmt->execute([
    ':deudidxx' => $tx['deudidxx'],
    ':monto' => cents_to_decimal($tx['cents']),
    ':categoria' => $tx['categoria'],
    ':descripcion' => $tx['descripcion'],
    ':fecha' => $tx['fecha'],
    ':userId' => $auth['user_id'],
    ':d' => $d,
    ':h' => $h,
    ':stamp' => "$d $h",
    ':id' => $id,
  ]);

  api_json(['success' => true, 'message' => 'Transacción actualizada exitosamente']);
}

function deleteTransaction(PDO $pdo, array $auth): void
{
  $id = valid_int_range($_GET['id'] ?? null, 1, PHP_INT_MAX);
  if (!$id) {
    api_response(false, 'ID inválido', 400);
  }
  loadTransactionForWrite($pdo, $auth, $id);
  setTransactionState($pdo, $auth, $id, 'INACTIVO');
  api_json(['success' => true, 'message' => 'Transacción eliminada', 'id' => $id, 'undo' => true]);
}

/** Deshacer un borrado lógico reciente. */
function restoreTransaction(PDO $pdo, array $auth, array $input): void
{
  $id = valid_int_range($input['id'] ?? null, 1, PHP_INT_MAX);
  if (!$id) {
    api_response(false, 'ID inválido', 400);
  }
  loadTransactionForWrite($pdo, $auth, $id, 'INACTIVO');
  setTransactionState($pdo, $auth, $id, 'ACTIVO');
  api_json(['success' => true, 'message' => 'Movimiento restaurado', 'id' => $id]);
}

function setTransactionState(PDO $pdo, array $auth, int $id, string $state): void
{
  [$d, $h] = audit_now();
  $stmt = $pdo->prepare('UPDATE FINANCIX SET REGESTXX = :s, REGUSRMX = :userId, REGFECMX = :d, REGHORMX = :h, REGSTAMP = :stamp WHERE FINCTIDX = :id');
  $stmt->execute([':s' => $state, ':userId' => $auth['user_id'], ':d' => $d, ':h' => $h, ':stamp' => "$d $h", ':id' => $id]);
}

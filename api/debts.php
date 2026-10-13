<?php
// dashboard/api/debts.php
// API para gestionar deudas (contrato histórico conservado).

require_once __DIR__ . '/../config/api_helper.php';
require_once __DIR__ . '/../lib/finance.php';
require_once __DIR__ . '/../lib/access.php';

api_bootstrap();
$auth = api_require_auth();
$pdo = api_db();

try {
  switch ($_SERVER['REQUEST_METHOD']) {
    case 'GET':
      getDebts($pdo, $auth);
      break;
    case 'POST':
      addDebt($pdo, $auth);
      break;
    case 'PUT':
      updateDebt($pdo, $auth);
      break;
    case 'DELETE':
      deleteDebt($pdo, $auth);
      break;
    default:
      api_response(false, 'Método no permitido', 405);
  }
} catch (PDOException $e) {
  api_log_error('Debts error', $e);
  api_response(false, 'No se pudo completar la operación con las deudas', 500);
}

/* ========================= GET ========================= */
function getDebts(PDO $pdo, array $auth): void
{
  $tipperxx = valid_enum($_GET['tipperxx'] ?? '', ACCOUNTS) ?? '';
  $tipdeudx = valid_enum($_GET['tipdeudx'] ?? '', DEBT_TYPES) ?? '';
  $regestxx = strtoupper((string) ($_GET['regestxx'] ?? 'ACTIVO'));
  $search = clean_text($_GET['search'] ?? '', 100);
  $today = date('Y-m-d');

  $sql = "SELECT D.*, COALESCE(P.PAGADO, 0) AS PAGADO_REAL, P.PROXIMO AS PROXIMO_PAGO, COALESCE(P.MOVS, 0) AS MOVS_VINCULADOS
    FROM DEUDASIX D
    LEFT JOIN (
      SELECT DEUDIDXX,
             SUM(CASE WHEN FINCFECX <= :today THEN ABS(MONTGASX) ELSE 0 END) AS PAGADO,
             MIN(CASE WHEN FINCFECX > :today2 THEN FINCFECX END) AS PROXIMO,
             COUNT(*) AS MOVS
      FROM FINANCIX WHERE REGESTXX = 'ACTIVO' AND DEUDIDXX IS NOT NULL GROUP BY DEUDIDXX
    ) P ON P.DEUDIDXX = D.DEUDIDXX
    WHERE 1=1";
  $params = [':today' => $today, ':today2' => $today];

  if (in_array($regestxx, ['ACTIVO', 'PAGADO', 'INACTIVO'], true)) {
    $sql .= ' AND D.REGESTXX = :regestxx';
    $params[':regestxx'] = $regestxx;
  }
  if (!($tipperxx === 'BEATAN' && $auth['is_admin'])) {
    $sql .= ' AND D.USRIDXXX = :userId';
    $params[':userId'] = $auth['user_id'];
  }
  if ($tipperxx !== '') {
    $sql .= ' AND D.TIPPERXX = :tipperxx';
    $params[':tipperxx'] = $tipperxx;
  }
  if ($tipdeudx !== '') {
    $sql .= ' AND D.TIPDEUDX = :tipdeudx';
    $params[':tipdeudx'] = $tipdeudx;
  }
  if ($search !== '') {
    $sql .= " AND D.DESDEUDX LIKE :search ESCAPE '!'";
    $params[':search'] = '%' . str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $search) . '%';
  }
  $sql .= ' ORDER BY D.REGESTXX ASC, D.DEUDIDXX DESC';

  $stmt = $pdo->prepare($sql);
  $stmt->execute($params);
  $debts = $stmt->fetchAll();
  foreach ($debts as &$d) {
    $total = db_money_to_cents($d['MONDEUDX']);
    $paid = db_money_to_cents($d['PAGADO_REAL']);
    $d['TOTAL_CENTS'] = $total;
    $d['PAGADO_CENTS'] = min($paid, $total);
    $d['RESTANTE_CENTS'] = max(0, $total - $paid);
  }
  unset($d);

  api_json(['success' => true, 'data' => $debts]);
}

/** Valida los campos editables de una deuda. */
function readDebtInput(array $in): array
{
  $errors = [];
  $tipdeudx = valid_enum($in['tipdeudx'] ?? '', DEBT_TYPES);
  if (!$tipdeudx)
    $errors[] = 'Indica si la deuda es a favor o en contra';
  $desc = clean_text($in['desdeudx'] ?? '', 150);
  if ($desc === '')
    $errors[] = 'Describe la deuda (por ejemplo, a quién se le debe)';
  $total = money_to_cents($in['mondeudx'] ?? null);
  if ($total === null || $total <= 0)
    $errors[] = 'El monto total debe ser mayor que cero';

  $num = null;
  $day = null;
  $cuota = null;
  $rawNum = $in['numcuotx'] ?? null;
  if ($rawNum !== null && $rawNum !== '' && $rawNum !== 0 && $rawNum !== '0') {
    $num = valid_int_range($rawNum, 1, 600);
    if ($num === null)
      $errors[] = 'El número de cuotas debe estar entre 1 y 600';
  }
  $rawDay = $in['feccuotx'] ?? null;
  if ($rawDay !== null && $rawDay !== '') {
    $day = valid_int_range($rawDay, 1, 31);
    if ($day === null)
      $errors[] = 'El día de pago debe estar entre 1 y 31';
  }
  $rawCuota = $in['moncuotx'] ?? null;
  if ($rawCuota !== null && $rawCuota !== '' && $rawCuota !== 0 && $rawCuota !== '0') {
    $cuota = money_to_cents($rawCuota);
    if ($cuota === null || $cuota <= 0)
      $errors[] = 'El valor de la cuota no es válido';
  }
  if ($num !== null) {
    // El cron solo genera cuotas cuando existen número, valor y día de pago.
    if ($day === null)
      $errors[] = 'Indica el día de pago de las cuotas';
    if ($cuota === null && $total !== null && $total > 0)
      $cuota = (int) ceil($total / $num);
  }

  return [[
    'tipdeudx' => $tipdeudx,
    'desdeudx' => $desc,
    'mondeudx' => $total,
    'numcuotx' => $num,
    'feccuotx' => $day,
    'moncuotx' => $cuota,
  ], $errors];
}

/* ========================= POST ========================= */
function addDebt(PDO $pdo, array $auth): void
{
  api_idempotent_replay('debt_add');
  $in = api_get_json_input();
  $tipperxx = valid_enum($in['tipperxx'] ?? '', ACCOUNTS);
  if (!$tipperxx)
    api_response(false, 'Tipo de cuenta inválido', 422);
  if ($tipperxx === 'BEATAN' && !$auth['is_admin'])
    api_response(false, 'Solo los administradores pueden registrar deudas de la empresa', 403);
  [$d, $errors] = readDebtInput($in);
  if ($errors)
    api_response(false, implode('. ', $errors), 422, ['errors' => $errors]);

  [$fd, $fh] = audit_now();
  // El dueño es siempre el usuario de la sesión (antes se aceptaba 'usridxxx' desde el cliente).
  $stmt = $pdo->prepare("INSERT INTO DEUDASIX
      (USRIDXXX, TIPDEUDX, TIPPERXX, DESDEUDX, NUMCUOTX, FECCUOTX, MONCUOTX, MONDEUDX,
       REGUSRXX, REGFECXX, REGHORXX, REGUSRMX, REGFECMX, REGHORMX, REGESTXX, REGSTAMP)
    VALUES (:uid, :tipdeudx, :tipperxx, :desdeudx, :numcuotx, :feccuotx, :moncuotx, :mondeudx,
       :uid2, :d, :h, :uid3, :d2, :h2, 'ACTIVO', :stamp)");
  $stmt->execute([
    ':uid' => $auth['user_id'],
    ':tipdeudx' => $d['tipdeudx'],
    ':tipperxx' => $tipperxx,
    ':desdeudx' => $d['desdeudx'],
    ':numcuotx' => $d['numcuotx'],
    ':feccuotx' => $d['feccuotx'],
    ':moncuotx' => $d['moncuotx'] !== null ? cents_to_decimal($d['moncuotx']) : null,
    ':mondeudx' => cents_to_decimal($d['mondeudx']),
    ':uid2' => $auth['user_id'], ':d' => $fd, ':h' => $fh,
    ':uid3' => $auth['user_id'], ':d2' => $fd, ':h2' => $fh, ':stamp' => "$fd $fh",
  ]);

  api_idempotent_success('debt_add', [
    'success' => true,
    'message' => 'Deuda registrada exitosamente',
    'id' => (int) $pdo->lastInsertId(),
  ]);
}

function loadDebtForWrite(PDO $pdo, array $auth, int $id): array
{
  $stmt = $pdo->prepare('SELECT * FROM DEUDASIX WHERE DEUDIDXX = :id');
  $stmt->execute([':id' => $id]);
  $debt = $stmt->fetch();
  if (!$debt)
    api_response(false, 'Deuda no encontrada', 404);
  if (!can_modify_record($auth, (string) $debt['USRIDXXX'], (string) $debt['TIPPERXX']))
    api_response(false, 'No autorizado para modificar esta deuda', 403);
  return $debt;
}

/* ========================= PUT ========================= */
function updateDebt(PDO $pdo, array $auth): void
{
  $in = api_get_json_input();
  $id = valid_int_range($in['id'] ?? null, 1, PHP_INT_MAX);
  if (!$id)
    api_response(false, 'ID inválido', 400);
  loadDebtForWrite($pdo, $auth, $id);
  [$fd, $fh] = audit_now();

  // Modo: solo cambio de estado (ej: marcar PAGADO)
  if (isset($in['regestxx']) && !isset($in['tipdeudx'])) {
    $newState = valid_enum($in['regestxx'], ['ACTIVO', 'PAGADO', 'INACTIVO']);
    if (!$newState)
      api_response(false, 'Estado inválido', 422);
    $stmt = $pdo->prepare('UPDATE DEUDASIX SET REGESTXX = :s, REGUSRMX = :uid, REGFECMX = :d, REGHORMX = :h, REGSTAMP = :stamp WHERE DEUDIDXX = :id');
    $stmt->execute([':s' => $newState, ':uid' => $auth['user_id'], ':d' => $fd, ':h' => $fh, ':stamp' => "$fd $fh", ':id' => $id]);
    api_json(['success' => true, 'message' => 'Estado actualizado a ' . $newState]);
  }

  [$d, $errors] = readDebtInput($in);
  if ($errors)
    api_response(false, implode('. ', $errors), 422, ['errors' => $errors]);

  $stmt = $pdo->prepare('UPDATE DEUDASIX SET TIPDEUDX = :tipdeudx, MONDEUDX = :mondeudx, DESDEUDX = :desdeudx,
      NUMCUOTX = :numcuotx, FECCUOTX = :feccuotx, MONCUOTX = :moncuotx,
      REGUSRMX = :uid, REGFECMX = :d, REGHORMX = :h, REGSTAMP = :stamp
    WHERE DEUDIDXX = :id');
  $stmt->execute([
    ':tipdeudx' => $d['tipdeudx'],
    ':mondeudx' => cents_to_decimal($d['mondeudx']),
    ':desdeudx' => $d['desdeudx'],
    ':numcuotx' => $d['numcuotx'],
    ':feccuotx' => $d['feccuotx'],
    ':moncuotx' => $d['moncuotx'] !== null ? cents_to_decimal($d['moncuotx']) : null,
    ':uid' => $auth['user_id'], ':d' => $fd, ':h' => $fh, ':stamp' => "$fd $fh",
    ':id' => $id,
  ]);

  api_json(['success' => true, 'message' => 'Deuda actualizada']);
}

/* ========================= DELETE ========================= */
function deleteDebt(PDO $pdo, array $auth): void
{
  $id = valid_int_range($_GET['id'] ?? null, 1, PHP_INT_MAX);
  if (!$id)
    api_response(false, 'ID inválido', 400);
  loadDebtForWrite($pdo, $auth, $id);
  [$fd, $fh] = audit_now();
  $stmt = $pdo->prepare("UPDATE DEUDASIX SET REGESTXX = 'INACTIVO', REGUSRMX = :uid, REGFECMX = :d, REGHORMX = :h, REGSTAMP = :stamp WHERE DEUDIDXX = :id");
  $stmt->execute([':uid' => $auth['user_id'], ':d' => $fd, ':h' => $fh, ':stamp' => "$fd $fh", ':id' => $id]);
  api_json(['success' => true, 'message' => 'Deuda eliminada']);
}

<?php
// dashboard/api/deudas_select.php
// Deudas activas "de abonos libres" (sin cuotas) que pueden vincularse a un movimiento.

require_once __DIR__ . '/../config/api_helper.php';
require_once __DIR__ . '/../lib/validation.php';

api_bootstrap();
$auth = api_require_auth();
$pdo = api_db();

$sql = "SELECT D.DEUDIDXX, D.DESDEUDX, D.USRIDXXX, D.TIPDEUDX, D.TIPPERXX
  FROM DEUDASIX D
  WHERE D.REGESTXX = 'ACTIVO' AND D.NUMCUOTX IS NULL";
$params = [];
$account = valid_enum($_GET['tipperxx'] ?? '', ACCOUNTS);
if ($account === 'BEATAN' && $auth['is_admin']) {
  $sql .= " AND D.TIPPERXX = 'BEATAN'";
} else {
  $sql .= ' AND D.USRIDXXX = :usrid';
  $params[':usrid'] = $auth['user_id'];
  if ($account) {
    $sql .= ' AND D.TIPPERXX = :acc';
    $params[':acc'] = $account;
  }
}
$sql .= ' ORDER BY D.DEUDIDXX ASC';

$stmt = $pdo->prepare($sql);
$stmt->execute($params);

api_json(['success' => true, 'data' => $stmt->fetchAll()]);

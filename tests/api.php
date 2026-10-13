<?php
// dashboard/tests/api.php
// Pruebas de integración HTTP contra el servidor de PRUEBAS (SQLite).
// 1) php -d extension=pdo_sqlite tests/setup_db.php
// 2) php -d extension=pdo_sqlite -S localhost:8092 tests/router.php
// 3) php -d extension=pdo_sqlite tests/api.php http://localhost:8092

if (PHP_SAPI !== 'cli') {
  http_response_code(404);
  exit;
}
date_default_timezone_set('America/Bogota');
require_once __DIR__ . '/seed_users.php';

$BASE = rtrim($argv[1] ?? 'http://localhost:8092', '/');
$passed = 0;
$failed = 0;

function ok(string $name, bool $cond, string $info = ''): void
{
  global $passed, $failed;
  if ($cond) {
    $passed++;
  } else {
    $failed++;
    echo "  FALLO: $name" . ($info !== '' ? " — $info" : '') . "\n";
  }
}

final class Client
{
  public string $jar;
  public ?string $csrf = null;
  public ?string $sid = null;
  public function __construct(public string $base)
  {
    $this->jar = tempnam(sys_get_temp_dir(), 'jar');
  }
  public function req(string $method, string $path, $body = null, array $headers = []): array
  {
    $ch = curl_init($this->base . '/' . ltrim($path, '/'));
    $h = [];
    foreach ($headers as $k => $v)
      $h[] = "$k: $v";
    if ($body !== null) {
      $h[] = 'Content-Type: application/json';
      curl_setopt($ch, CURLOPT_POSTFIELDS, is_string($body) ? $body : json_encode($body));
    }
    curl_setopt_array($ch, [
      CURLOPT_CUSTOMREQUEST => $method,
      CURLOPT_RETURNTRANSFER => true,
      CURLOPT_HEADER => true,
      CURLOPT_COOKIEJAR => $this->jar,
      CURLOPT_COOKIEFILE => $this->jar,
      CURLOPT_HTTPHEADER => $h,
      CURLOPT_TIMEOUT => 30,
    ]);
    $raw = curl_exec($ch);
    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $hs = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    curl_close($ch);
    $head = substr((string) $raw, 0, $hs);
    $text = substr((string) $raw, $hs);
    return ['status' => $status, 'headers' => strtolower($head), 'text' => $text, 'json' => json_decode($text, true)];
  }
  public function login(array $user): array
  {
    $r = $this->req('POST', 'api/login.php', ['cedula' => $user['cedula'], 'password' => $user['password']]);
    $this->csrf = $r['json']['csrf_token'] ?? null;
    $this->sid = $r['json']['session_id'] ?? null;
    return $r;
  }
  public function write(string $method, string $path, $body = null, array $headers = []): array
  {
    return $this->req($method, $path, $body, $headers + ['X-CSRF-Token' => (string) $this->csrf]);
  }
}

$pdo = new PDO('sqlite:' . __DIR__ . '/tmp/test.sqlite', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
$today = date('Y-m-d');
$U = TEST_USERS;

echo "• Autenticación\n";
$anon = new Client($BASE);
ok('sin sesión => 401', $anon->req('GET', 'api/transactions.php?tipgasxx=PERSONAL')['status'] === 401);
$bad = $anon->req('POST', 'api/login.php', ['cedula' => $U['user']['cedula'], 'password' => 'incorrecta']);
$ghost = $anon->req('POST', 'api/login.php', ['cedula' => '999999', 'password' => 'incorrecta']);
ok('contraseña errónea => 401', $bad['status'] === 401);
ok('mismo mensaje para usuario inexistente (sin enumeración)', ($bad['json']['error'] ?? 'a') === ($ghost['json']['error'] ?? 'b'));
ok('cabeceras de seguridad', str_contains($bad['headers'], 'x-content-type-options: nosniff') && str_contains($bad['headers'], 'x-frame-options: deny'));

$user = new Client($BASE);
$lr = $user->login($U['user']);
ok('login correcto', $lr['status'] === 200 && !empty($user->csrf) && !empty($user->sid), $lr['text']);
ok('login no devuelve el hash de contraseña', !str_contains($lr['text'], 'USRPASXX') && !str_contains($lr['text'], '$2y$'));
ok('cookie de sesión HttpOnly + SameSite', (bool) preg_match('/set-cookie: [^\n]*httponly[^\n]*samesite=lax/i', $lr['headers']));

$admin = new Client($BASE);
$admin->login($U['admin']);
$other = new Client($BASE);
$other->login($U['other']);

echo "• Aislamiento de datos entre usuarios\n";
$r = $user->req('GET', 'api/transactions.php?tipgasxx=PERSONAL');
ok('lista propia', $r['status'] === 200 && count($r['json']['data']) > 20);
ok('no incluye datos de otro usuario', !str_contains($r['text'], 'otro usuario'));
$r = $user->req('GET', 'api/transactions.php?tipgasxx=BEATAN');
ok('usuario normal no ve movimientos de la empresa', $r['status'] === 200 && count($r['json']['data']) === 0);
$r = $user->req('GET', 'api/debts.php?tipperxx=PERSONAL&regestxx=ALL');
ok('deudas propias sin las de otro usuario', $r['status'] === 200 && !str_contains($r['text'], 'otro usuario'));
ok('resumen de la empresa prohibido a usuario normal', $user->req('GET', 'api/summary.php?tipgasxx=BEATAN')['status'] === 403);
ok('reporte de la empresa prohibido a usuario normal', $user->req('GET', 'api/report.php?tipgasxx=BEATAN')['status'] === 403);
ok('mensajes de contacto prohibidos a usuario normal', $user->req('GET', 'api/messages.php?id=1')['status'] === 403);
ok('gestión de usuarios prohibida', $user->req('GET', 'api/users.php')['status'] === 403);
$r = $admin->req('GET', 'api/transactions.php?tipgasxx=BEATAN');
ok('admin ve la cuenta de la empresa', $r['status'] === 200 && count($r['json']['data']) >= 12);

echo "• Resumen y coherencia de cifras\n";
$r = $user->req('GET', 'api/summary.php?tipgasxx=PERSONAL&period=month');
ok('resumen 200', $r['status'] === 200, $r['text']);
$s = $r['json']['data'] ?? [];
$expected = $pdo->query("SELECT ROUND(SUM(MONTGASX) * 100) AS c FROM FINANCIX WHERE USRIDXXX = '{$U['user']['cedula']}' AND TIPGASXX = 'PERSONAL' AND REGESTXX = 'ACTIVO' AND FINCFECX <= '$today'")->fetch()['c'];
ok('saldo de hoy = suma en BD', ($s['balance']['today'] ?? null) === (int) $expected, json_encode($s['balance'] ?? null) . " vs $expected");
$monthFrom = date('Y-m-01');
$exp = $pdo->query("SELECT ROUND(SUM(-MONTGASX) * 100) AS c FROM FINANCIX WHERE USRIDXXX = '{$U['user']['cedula']}' AND TIPGASXX = 'PERSONAL' AND REGESTXX = 'ACTIVO' AND MONTGASX < 0 AND FINCFECX BETWEEN '$monthFrom' AND '$today'")->fetch()['c'];
ok('gastos del mes = suma en BD (solo realizados)', ($s['totals']['expense'] ?? null) === (int) $exp, ($s['totals']['expense'] ?? 'null') . " vs $exp");
ok('patrimonio = saldo + por cobrar − por pagar', $s['balance']['net_worth'] === $s['balance']['today'] + $s['debts']['receivable'] - $s['debts']['payable']);
ok('disponible libre = saldo − apartado en metas', $s['balance']['free'] === $s['balance']['today'] - $s['goals']['saved']);
ok('próximos pagos incluidos', ($s['upcoming']['count'] ?? 0) >= 2);
ok('observaciones con evidencia', count($s['insights']) > 0 && count(array_filter($s['insights'], fn($i) => empty($i['evidence']))) === 0);
ok('detecta duplicado sembrado', (int) date('j') < 3 || count($s['anomalies']['duplicates']) >= 1);
ok('detecta gasto inusual sembrado', (int) date('j') < 3 || count(array_filter($s['anomalies']['unusual'], fn($u) => $u['description'] === 'Cena aniversario')) === 1);
$deuda = array_values(array_filter($s['debts']['items'], fn($d) => $d['description'] === 'Préstamo a Camila'))[0] ?? null;
ok('saldo pendiente de deuda con abono', $deuda && $deuda['paid'] === 20000000 && $deuda['remaining'] === 30000000);
ok('periodo personalizado inválido => 422', $user->req('GET', 'api/summary.php?period=custom&from=2025-05-01&to=2025-04-01')['status'] === 422);
foreach (['week', 'prev_month', 'year', 'last30'] as $preset) {
  ok("periodo $preset", $user->req('GET', "api/summary.php?period=$preset")['status'] === 200);
}

echo "• CSRF e idempotencia\n";
$payload = ['tipgasxx' => 'PERSONAL', 'tipo' => 'GASTO', 'monto' => '12345.67', 'categoria' => 'Prueba', 'descripcion' => 'Movimiento de prueba API', 'fecha' => $today];
ok('POST sin token CSRF => 419', $user->req('POST', 'api/transactions.php', $payload)['status'] === 419);
ok('POST con token CSRF de otra sesión => 419', $user->req('POST', 'api/transactions.php', $payload, ['X-CSRF-Token' => (string) $other->csrf])['status'] === 419);
$k = 'test-key-' . bin2hex(random_bytes(4));
$r1 = $user->write('POST', 'api/transactions.php', $payload, ['Idempotency-Key' => $k]);
$r2 = $user->write('POST', 'api/transactions.php', $payload, ['Idempotency-Key' => $k]);
ok('alta correcta', $r1['status'] === 200 && !empty($r1['json']['id']), $r1['text']);
ok('reintento devuelve la misma respuesta', ($r2['json']['id'] ?? null) === ($r1['json']['id'] ?? 'x') && str_contains($r2['headers'], 'idempotent-replay: true'));
$count = $pdo->query("SELECT COUNT(*) AS c FROM FINANCIX WHERE DESGASXX = 'Movimiento de prueba API'")->fetch()['c'];
ok('no se duplicó en BD', (int) $count === 1);
$stored = $pdo->query("SELECT MONTGASX FROM FINANCIX WHERE FINCTIDX = " . (int) $r1['json']['id'])->fetch()['MONTGASX'];
ok('monto guardado exacto y con signo', (string) $stored === '-12345.67', (string) $stored);
$mobile = new Client($BASE);
$m = $mobile->req('POST', 'api/transactions.php', $payload + ['descripcion' => 'Desde app móvil'], ['X-Session-ID' => (string) $user->sid]);
ok('cliente móvil con X-Session-ID no requiere CSRF', $m['status'] === 200, $m['text']);

echo "• Validación y autorización de escritura\n";
$r = $user->write('POST', 'api/transactions.php', ['tipgasxx' => 'PERSONAL', 'tipo' => 'GASTO', 'monto' => '0', 'categoria' => '', 'fecha' => '2025-02-30']);
ok('datos inválidos => 422 con errores', $r['status'] === 422 && count($r['json']['errors'] ?? []) >= 3);
ok('monto con 3 decimales => 422', $user->write('POST', 'api/transactions.php', ['monto' => '10.123'] + $payload)['status'] === 422);
ok('monto gigante => 422', $user->write('POST', 'api/transactions.php', ['monto' => '99999999999'] + $payload)['status'] === 422);
ok('usuario normal no escribe en la empresa', $user->write('POST', 'api/transactions.php', ['tipgasxx' => 'BEATAN'] + $payload)['status'] === 403);
$otherDebt = $pdo->query("SELECT DEUDIDXX FROM DEUDASIX WHERE USRIDXXX = '{$U['other']['cedula']}'")->fetch()['DEUDIDXX'];
ok('no se puede vincular la deuda de otro usuario', $user->write('POST', 'api/transactions.php', ['deudidxx' => $otherDebt] + $payload)['status'] === 422);
$otherTx = $pdo->query("SELECT FINCTIDX FROM FINANCIX WHERE USRIDXXX = '{$U['other']['cedula']}' LIMIT 1")->fetch()['FINCTIDX'];
ok('no se puede editar movimiento ajeno', $user->write('PUT', 'api/transactions.php', ['id' => $otherTx] + $payload)['status'] === 403);
ok('no se puede borrar movimiento ajeno', $user->write('DELETE', "api/transactions.php?id=$otherTx")['status'] === 403);
$id = (int) $r1['json']['id'];
$r = $user->write('PUT', 'api/transactions.php', ['id' => $id, 'tipo' => 'INGRESO', 'monto' => '500', 'categoria' => 'Prueba', 'fecha' => $today, 'descripcion' => 'Editado']);
ok('edición propia', $r['status'] === 200);
ok('borrado lógico', $user->write('DELETE', "api/transactions.php?id=$id")['status'] === 200);
ok('estado INACTIVO en BD', $pdo->query("SELECT REGESTXX FROM FINANCIX WHERE FINCTIDX = $id")->fetch()['REGESTXX'] === 'INACTIVO');
ok('deshacer (restaurar)', $user->write('POST', 'api/transactions.php', ['action' => 'restore', 'id' => $id])['status'] === 200);
ok('restaurado en BD', $pdo->query("SELECT REGESTXX FROM FINANCIX WHERE FINCTIDX = $id")->fetch()['REGESTXX'] === 'ACTIVO');
ok('deuda: el dueño es la sesión aunque se envíe usridxxx', (function () use ($user, $pdo, $U) {
  $r = $user->write('POST', 'api/debts.php', ['usridxxx' => $U['other']['cedula'], 'tipdeudx' => 'EN CONTRA', 'tipperxx' => 'PERSONAL', 'desdeudx' => 'Prueba dueño', 'mondeudx' => '1000']);
  $owner = $pdo->query("SELECT USRIDXXX FROM DEUDASIX WHERE DESDEUDX = 'Prueba dueño'")->fetch()['USRIDXXX'] ?? null;
  return $r['status'] === 200 && $owner === $U['user']['cedula'];
})());
ok('deuda en cuotas sin día de pago => 422', $user->write('POST', 'api/debts.php', ['tipdeudx' => 'EN CONTRA', 'tipperxx' => 'PERSONAL', 'desdeudx' => 'x', 'mondeudx' => '1000', 'numcuotx' => 3])['status'] === 422);
ok('día de pago 32 => 422', $user->write('POST', 'api/debts.php', ['tipdeudx' => 'EN CONTRA', 'tipperxx' => 'PERSONAL', 'desdeudx' => 'x', 'mondeudx' => '1000', 'numcuotx' => 3, 'feccuotx' => 32])['status'] === 422);

echo "• Importación masiva\n";
$r = $user->write('POST', 'api/transactions.php', ['action' => 'bulk_import', 'items' => [
  ['tipgasxx' => 'PERSONAL', 'monto' => -25000, 'categoria' => 'Alimentacion', 'descripcion' => 'Import A', 'fecha' => $today, 'tipo' => 'GASTO'],
  ['tipgasxx' => 'PERSONAL', 'monto' => 80000, 'categoria' => '', 'descripcion' => 'Import B', 'fecha' => $today, 'tipo' => 'INGRESO'],
  ['tipgasxx' => 'PERSONAL', 'monto' => 1000, 'categoria' => 'X', 'descripcion' => 'Import C', 'fecha' => '2025-13-01', 'tipo' => 'INGRESO'],
]]);
ok('acepta válidas y rechaza inválidas con motivo', $r['status'] === 200 && $r['json']['count'] === 2 && count($r['json']['rejected']) === 1 && $r['json']['rejected'][0]['index'] === 2, $r['text']);
ok('categoría vacía usa "Varios"', $pdo->query("SELECT CATGASXX FROM FINANCIX WHERE DESGASXX = 'Import B'")->fetch()['CATGASXX'] === 'Varios');
$r = $user->write('POST', 'api/transactions.php', ['action' => 'bulk_import', 'items' => [['monto' => -25000, 'descripcion' => 'Import A', 'fecha' => $today, 'tipo' => 'GASTO']]]);
ok('informa posibles duplicados', ($r['json']['possible_duplicates'] ?? 0) === 1);
ok('lote demasiado grande => 413', $user->write('POST', 'api/transactions.php', ['action' => 'bulk_import', 'items' => array_fill(0, 1001, ['monto' => 1, 'fecha' => $today])])['status'] === 413);
ok('importar a la empresa como usuario => rechazado', $user->write('POST', 'api/transactions.php', ['action' => 'bulk_import', 'items' => [['tipgasxx' => 'BEATAN', 'monto' => 1, 'fecha' => $today, 'tipo' => 'INGRESO']]])['status'] === 422);

echo "• Exportación CSV\n";
$user->write('POST', 'api/transactions.php', ['descripcion' => '=HYPERLINK("http://malicioso.test","clic")', 'categoria' => '+SUM(A1)'] + $payload);
$r = $user->req('GET', "api/export.php?tipgasxx=PERSONAL&from=$monthFrom&to=$today");
ok('exporta CSV', $r['status'] === 200 && str_contains($r['headers'], 'text/csv'));
ok('neutraliza fórmulas en descripción', str_contains($r['text'], "\"'=HYPERLINK"));
ok('neutraliza fórmulas en categoría', str_contains($r['text'], "\"'+SUM(A1)\""));
ok('no exporta datos de otro usuario', !str_contains($r['text'], 'otro usuario'));
ok('rango inválido => 422', $user->req('GET', 'api/export.php?from=2025-02-01&to=2025-01-01')['status'] === 422);

echo "• Presupuestos y metas\n";
$r = $user->req('GET', 'api/budgets.php?tipgasxx=PERSONAL');
ok('lista presupuestos', $r['status'] === 200 && count($r['json']['data']['budgets']) === 3);
ok('presupuesto duplicado => 409', $user->write('POST', 'api/budgets.php', ['tipgasxx' => 'PERSONAL', 'categoria' => 'alimentacion', 'monto' => '1000'])['status'] === 409);
ok('límite cero => 422', $user->write('POST', 'api/budgets.php', ['tipgasxx' => 'PERSONAL', 'categoria' => 'Salud', 'monto' => '0'])['status'] === 422);
ok('crear presupuesto', $user->write('POST', 'api/budgets.php', ['tipgasxx' => 'PERSONAL', 'categoria' => 'Salud', 'monto' => '150000', 'alerta' => 75])['status'] === 201);
$bid = $pdo->query("SELECT PRESIDXX FROM BEATPRES WHERE USRIDXXX = '{$U['user']['cedula']}' LIMIT 1")->fetch()['PRESIDXX'];
ok('otro usuario no edita mi presupuesto', $other->write('PUT', 'api/budgets.php', ['id' => $bid, 'monto' => '1'])['status'] === 403);
$r = $user->req('GET', 'api/goals.php?tipgasxx=PERSONAL');
ok('lista metas', $r['status'] === 200 && count($r['json']['data']['goals']) === 2);
$gid = $r['json']['data']['goals'][0]['id'];
$saved = $r['json']['data']['goals'][0]['saved'];
ok('retiro mayor que lo ahorrado => 422', $user->write('POST', 'api/goals.php', ['action' => 'movement', 'meta_id' => $gid, 'tipo' => 'RETIRO', 'monto' => (string) ($saved / 100 + 1)])['status'] === 422);
ok('aporte con fecha futura => 422', $user->write('POST', 'api/goals.php', ['action' => 'movement', 'meta_id' => $gid, 'tipo' => 'APORTE', 'monto' => '1000', 'fecha' => date('Y-m-d', strtotime('+3 days'))])['status'] === 422);
$r = $user->write('POST', 'api/goals.php', ['action' => 'movement', 'meta_id' => $gid, 'tipo' => 'APORTE', 'monto' => '100000.50']);
ok('aporte registrado y saldo exacto', $r['status'] === 201 && $r['json']['data']['saved'] === $saved + 10000050, $r['text']);
ok('otro usuario no aporta a mi meta', $other->write('POST', 'api/goals.php', ['action' => 'movement', 'meta_id' => $gid, 'tipo' => 'APORTE', 'monto' => '1'])['status'] === 403);
ok('crear meta', $user->write('POST', 'api/goals.php', ['nombre' => 'Meta de prueba', 'objetivo' => '1000000', 'fecha' => date('Y-m-d', strtotime('+60 days'))])['status'] === 201);
ok('archivar meta', $user->write('PUT', 'api/goals.php', ['id' => $gid, 'estado' => 'ARCHIVADA'])['status'] === 200);
ok('meta archivada no acepta aportes', $user->write('POST', 'api/goals.php', ['action' => 'movement', 'meta_id' => $gid, 'tipo' => 'APORTE', 'monto' => '1'])['status'] === 409);

echo "• CORS, mantenimiento y recuperación\n";
$r = $anon->req('OPTIONS', 'api/transactions.php', null, ['Origin' => 'https://sitio-malicioso.test', 'Access-Control-Request-Method' => 'POST']);
ok('preflight de origen no permitido => 403 sin ACAO', $r['status'] === 403 && !str_contains($r['headers'], 'access-control-allow-origin'));
$r = $user->req('GET', 'api/transactions.php?tipgasxx=PERSONAL', null, ['Origin' => 'https://sitio-malicioso.test']);
ok('origen no permitido no recibe ACAO', !str_contains($r['headers'], 'access-control-allow-origin'));
$r = $anon->req('OPTIONS', 'api/transactions.php', null, ['Origin' => 'http://localhost:8100', 'Access-Control-Request-Method' => 'POST']);
ok('origen permitido => 204 con ACAO', $r['status'] === 204 && str_contains($r['headers'], 'access-control-allow-origin: http://localhost:8100'));
ok('cron sin token => 403', $anon->req('GET', 'api/cron_deudas.php')['status'] === 403);
ok('push sin token => 403', $anon->req('GET', 'api/send_push.php')['status'] === 403);
$r = $anon->req('GET', 'api/cron_deudas.php?token=test-cron-secret-0001');
ok('cron con token => 200', $r['status'] === 200 && str_contains($r['text'], 'CRON FINALIZADO'), substr($r['text'], -300));
$r = $anon->req('POST', 'api/recover_password.php', ['cedula' => $U['user']['cedula'], 'email' => $U['user']['email']]);
ok('recuperación nunca devuelve la contraseña temporal', !str_contains($r['text'], 'temp_password') && !preg_match('/[A-Za-z0-9]{12}"/', $r['text'] ?? ''));
ok('recuperación sin SMTP no cambia la contraseña', (new Client($BASE))->login($U['user'])['status'] === 200);
ok('.env bloqueado', $anon->req('GET', '.env')['status'] === 403);
ok('config/ bloqueado', $anon->req('GET', 'config/db.php')['status'] === 403);
ok('migración solo admin', $user->req('GET', 'api/migrate.php')['status'] === 403 && $admin->req('GET', 'api/migrate.php')['status'] === 200);

echo "• Administración\n";
$r = $admin->write('POST', 'api/users.php', ['cedula' => '4004', 'nombre' => 'Nuevo Admin', 'email' => 'nuevo@example.test', 'password' => 'ClaveSegura#1', 'admin' => 'SI']);
ok('crear usuario administrador guarda el rol', $r['status'] === 200 && $pdo->query("SELECT ISADMINX FROM BEATUSRS WHERE USRIDXXX = '4004'")->fetch()['ISADMINX'] === 'SI', $r['text']);
ok('admin no puede quitarse su propio rol', $admin->write('PUT', 'api/users.php', ['id' => $U['admin']['cedula'], 'nombre' => 'Admin', 'email' => $U['admin']['email'], 'admin' => 'NO'])['status'] === 400);
$r = $admin->req('GET', 'api/summary.php?tipgasxx=BEATAN');
ok('resumen de la empresa para admin', $r['status'] === 200 && $r['json']['data']['totals']['income'] > 0);

echo "• Cierre de sesión\n";
$user->req('GET', 'logout.php');
ok('tras cerrar sesión => 401', $user->req('GET', 'api/transactions.php?tipgasxx=PERSONAL')['status'] === 401);

echo "\nResultado: $passed pruebas correctas, $failed fallidas\n";
exit($failed === 0 ? 0 : 1);

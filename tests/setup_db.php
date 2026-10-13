<?php
// dashboard/tests/setup_db.php
// Crea una base SQLite de PRUEBAS con datos ficticios. Nunca usa la base de datos real.
// Uso: php -d extension=pdo_sqlite tests/setup_db.php [--without-v3]

if (PHP_SAPI !== 'cli') {
  http_response_code(404);
  exit;
}

date_default_timezone_set('America/Bogota');
require_once __DIR__ . '/seed_users.php';

$dir = __DIR__ . '/tmp';
if (!is_dir($dir))
  mkdir($dir, 0700, true);
$path = $dir . '/test.sqlite';
if (file_exists($path))
  unlink($path);

$pdo = new PDO('sqlite:' . $path, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$withV3 = !in_array('--without-v3', $argv, true);

$audit = "REGUSRXX TEXT, REGFECXX TEXT, REGHORXX TEXT, REGUSRMX TEXT, REGFECMX TEXT, REGHORMX TEXT, REGESTXX TEXT DEFAULT 'ACTIVO', REGSTAMP TEXT";
$schema = [
  "CREATE TABLE BEATUSRS (USRIDXXX TEXT PRIMARY KEY, USRPASXX TEXT, USRNMEXX TEXT, USRMAILX TEXT, USRCELUL TEXT, ISADMINX TEXT, $audit)",
  "CREATE TABLE DEUDASIX (DEUDIDXX INTEGER PRIMARY KEY AUTOINCREMENT, USRIDXXX TEXT NOT NULL, TIPDEUDX TEXT NOT NULL, TIPPERXX TEXT NOT NULL, DESDEUDX TEXT NOT NULL,
     PAGCUOTX INTEGER, NUMCUOTX INTEGER, ABONCUOT NUMERIC, FECCUOTX INTEGER, MONCUOTX NUMERIC, MONDEUDX NUMERIC, $audit)",
  "CREATE TABLE FINANCIX (FINCTIDX INTEGER PRIMARY KEY AUTOINCREMENT, USRIDXXX TEXT, DEUDIDXX INTEGER NULL REFERENCES DEUDASIX(DEUDIDXX), TIPGASXX TEXT, MONTGASX NUMERIC,
     CATGASXX TEXT, DESGASXX TEXT, EXTIDXXX TEXT, BANKLINK TEXT, FINCFECX TEXT, $audit)",
  "CREATE INDEX idx_fin_user_tipo_fec ON FINANCIX (USRIDXXX, TIPGASXX, REGESTXX, FINCFECX)",
  "CREATE TABLE FESTCOLX (FESTIDXX INTEGER PRIMARY KEY AUTOINCREMENT, FESTNOMX TEXT NOT NULL, FESTFECX TEXT NOT NULL, $audit)",
  "CREATE TABLE FORMULARIO (ID INTEGER PRIMARY KEY AUTOINCREMENT, NOMBRE TEXT, EMAIL TEXT, INDICATIVO TEXT, TELEFONO TEXT, DESPEMP TEXT, NOMEMP TEXT, ASUNTO TEXT,
     MENSAJE TEXT, ESTADO TEXT DEFAULT 'PENDIENTE', HORCREA TEXT DEFAULT CURRENT_TIMESTAMP, USUMOD TEXT, REGESTAMP TEXT, REGESTXX TEXT DEFAULT 'ACTIVO')",
  "CREATE TABLE BEATPROY (PROYIDXX INTEGER PRIMARY KEY AUTOINCREMENT, PROYNOMX TEXT NOT NULL, PROYDESX TEXT, PROYESTX TEXT DEFAULT 'PLANIFICACION', $audit)",
  "CREATE TABLE BEATHORA (HORAIDXX INTEGER PRIMARY KEY AUTOINCREMENT, PROYIDXX INTEGER NOT NULL, USRIDXXX TEXT NOT NULL, HORADEDX NUMERIC NOT NULL, HORAFECX TEXT NOT NULL, HORADESX TEXT, $audit)",
  "CREATE TABLE BEATFACT (FACTIDXX INTEGER PRIMARY KEY AUTOINCREMENT, FACTNUMX TEXT NOT NULL UNIQUE, PROYIDXX INTEGER, FACTFECX TEXT NOT NULL, FACTVENX TEXT NOT NULL,
     CLIEIDXX TEXT, CLIENOMX TEXT NOT NULL, CLIEMLXX TEXT, CLIEDIRX TEXT, CLIECELL TEXT, FACTSUBT NUMERIC NOT NULL, FACTIVAX NUMERIC DEFAULT 0, FACTDESC NUMERIC DEFAULT 0,
     FACTTOTA NUMERIC NOT NULL, FACTESTX TEXT DEFAULT 'BORRADOR', $audit)",
  "CREATE TABLE BEATFACD (FACDIDXX INTEGER PRIMARY KEY AUTOINCREMENT, FACTIDXX INTEGER NOT NULL, ITEMDESX TEXT NOT NULL, ITEMCANT NUMERIC NOT NULL, ITEMVALU NUMERIC NOT NULL, ITEMTOTA NUMERIC NOT NULL, $audit)",
  "CREATE TABLE BEATFASE (FASEIDXX INTEGER PRIMARY KEY AUTOINCREMENT, PROYIDXX INTEGER NOT NULL, FASENOMX TEXT NOT NULL, FASEFECI TEXT NOT NULL, FASEFECT TEXT NOT NULL, FASEHITO TEXT DEFAULT 'NO', $audit)",
  "CREATE TABLE push_subscriptions (id INTEGER PRIMARY KEY AUTOINCREMENT, USRIDXXX TEXT, endpoint TEXT, p256dh TEXT, auth TEXT)",
];
if ($withV3) {
  $schema[] = "CREATE TABLE BEATPRES (PRESIDXX INTEGER PRIMARY KEY AUTOINCREMENT, USRIDXXX TEXT NOT NULL, TIPGASXX TEXT NOT NULL, CATGASXX TEXT NOT NULL, PRESMONX NUMERIC NOT NULL, PRESALRX INTEGER NOT NULL DEFAULT 80, $audit)";
  $schema[] = "CREATE TABLE BEATMETA (METAIDXX INTEGER PRIMARY KEY AUTOINCREMENT, USRIDXXX TEXT NOT NULL, TIPGASXX TEXT NOT NULL DEFAULT 'PERSONAL', METANOMX TEXT NOT NULL, METAOBJX NUMERIC NOT NULL, METAFECX TEXT, METAESTX TEXT NOT NULL DEFAULT 'ACTIVA', $audit)";
  $schema[] = "CREATE TABLE BEATMEMO (MEMOIDXX INTEGER PRIMARY KEY AUTOINCREMENT, METAIDXX INTEGER NOT NULL REFERENCES BEATMETA(METAIDXX), USRIDXXX TEXT NOT NULL, MEMOMONX NUMERIC NOT NULL, MEMOFECX TEXT NOT NULL, MEMODESX TEXT, $audit)";
}
foreach ($schema as $sql)
  $pdo->exec($sql);

$today = date('Y-m-d');
$now = date('H:i:s');

// Usuarios de prueba (contraseñas en tests/seed_users.php)
$u = $pdo->prepare("INSERT INTO BEATUSRS (USRIDXXX, USRPASXX, USRNMEXX, USRMAILX, USRCELUL, ISADMINX, REGFECXX, REGHORXX, REGESTXX) VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'ACTIVO')");
foreach (TEST_USERS as $user) {
  $u->execute([$user['cedula'], password_hash($user['password'], PASSWORD_DEFAULT), $user['nombre'], $user['email'], '+57 300 0000000', $user['admin'] ? 'SI' : 'NO', $today, $now]);
}

$tx = $pdo->prepare("INSERT INTO FINANCIX (USRIDXXX, DEUDIDXX, TIPGASXX, MONTGASX, CATGASXX, DESGASXX, FINCFECX, REGUSRXX, REGFECXX, REGHORXX, REGESTXX) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'ACTIVO')");
$add = function (string $user, string $acc, float $amount, string $cat, string $desc, string $date, ?int $debt = null) use ($tx, $today, $now) {
  $tx->execute([$user, $debt, $acc, number_format($amount, 2, '.', ''), $cat, $desc, $date, $user, $today, $now]);
};

$monthStart = fn(int $back) => date('Y-m-01', strtotime(date('Y-m-01') . " -$back month"));
$day = fn(string $ms, int $d) => date('Y-m-d', strtotime($ms . ' +' . (min($d, (int) date('t', strtotime($ms))) - 1) . ' days'));

// --- Usuario regular 2002: 7 meses de historia personal (determinista) ---
$userId = TEST_USERS['user']['cedula'];
for ($m = 6; $m >= 0; $m--) {
  $ms = $monthStart($m);
  $limitDay = $m === 0 ? (int) date('j') : 31;
  $items = [
    [1, 4200000, 'Salario', 'Pago de nómina'],
    [2, -1350000, 'Vivienda', 'Arriendo apartamento'],
    [5, -185000, 'Servicios', 'Energía y agua'],
    [6, -95000, 'Servicios', 'Internet hogar'],
    [8, -210000 - $m * 7000, 'Alimentacion', 'Mercado quincenal'],
    [12, -64000, 'Transporte', 'Recarga transporte'],
    [15, 650000, 'Freelance', 'Proyecto diseño web'],
    [18, -120000 - ($m === 0 ? 90000 : 0), 'Entretenimiento', 'Cine y salidas'],
    [21, -198000, 'Alimentacion', 'Mercado quincenal'],
    [24, -58000 - ($m === 0 ? 60000 : 0), 'Transporte', 'Taxi y gasolina'],
    [27, -75000, 'Salud', 'Farmacia'],
  ];
  foreach ($items as [$d, $amt, $cat, $desc]) {
    if ($d > $limitDay && $m === 0)
      continue;
    $add($userId, 'PERSONAL', $amt, $cat, $desc, $day($ms, $d));
  }
}
// Gasto inusual y posible duplicado en el mes actual (para probar detección de anomalías)
if ((int) date('j') >= 3) {
  $add($userId, 'PERSONAL', -980000, 'Alimentacion', 'Cena aniversario', $day($monthStart(0), 3));
  $add($userId, 'PERSONAL', -45900, 'Entretenimiento', 'Suscripción streaming', $day($monthStart(0), 2));
  $add($userId, 'PERSONAL', -45900, 'Entretenimiento', 'Suscripción streaming', $day($monthStart(0), 2));
}

// Deudas del usuario 2002
$debt = $pdo->prepare("INSERT INTO DEUDASIX (USRIDXXX, TIPDEUDX, TIPPERXX, DESDEUDX, PAGCUOTX, NUMCUOTX, ABONCUOT, FECCUOTX, MONCUOTX, MONDEUDX, REGUSRXX, REGFECXX, REGHORXX, REGESTXX)
  VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'ACTIVO')");
$debt->execute([$userId, 'EN CONTRA', 'PERSONAL', 'Crédito portátil', 0, 6, 0, 10, 300000, 1800000, $userId, $monthStart(3), $now]);
$cuotasId = (int) $pdo->lastInsertId();
for ($i = 0; $i < 6; $i++) {
  $ms = date('Y-m-01', strtotime($monthStart(3) . " +$i month"));
  $add($userId, 'PERSONAL', -300000, 'DEUDA', 'Cuota #' . ($i + 1) . ' — Crédito portátil', $day($ms, 10), $cuotasId);
}
$debt->execute([$userId, 'A FAVOR', 'PERSONAL', 'Préstamo a Camila', null, null, 0, null, null, 500000, $userId, $monthStart(2), $now]);
$favorId = (int) $pdo->lastInsertId();
$add($userId, 'PERSONAL', 200000, 'Otros', 'Abono préstamo Camila', $day($monthStart(1), 20), $favorId);
$debt->execute([$userId, 'EN CONTRA', 'PERSONAL', 'Deuda con Andrés', null, null, 0, null, null, 350000, $userId, $monthStart(1), $now]);

// Próximos pagos programados
$add($userId, 'PERSONAL', -89000, 'Servicios', 'Plan celular', date('Y-m-d', strtotime('+3 days')));
$add($userId, 'PERSONAL', -240000, 'Educacion', 'Curso inglés', date('Y-m-d', strtotime('+12 days')));

// --- Otro usuario 3003 (para pruebas de aislamiento) ---
$otherId = TEST_USERS['other']['cedula'];
$add($otherId, 'PERSONAL', 9999000, 'Salario', 'Dato privado de otro usuario', $today);
$add($otherId, 'PERSONAL', -123000, 'Alimentacion', 'Gasto privado de otro usuario', $today);
$debt->execute([$otherId, 'EN CONTRA', 'PERSONAL', 'Deuda privada de otro usuario', null, null, 0, null, null, 700000, $otherId, $today, $now]);

// --- Cuenta de la empresa (admin 1001) ---
$adminId = TEST_USERS['admin']['cedula'];
for ($m = 3; $m >= 0; $m--) {
  $ms = $monthStart($m);
  $add($adminId, 'BEATAN', 12500000, 'Ventas', 'Desarrollo sitio web cliente', $day($ms, 4));
  $add($adminId, 'BEATAN', -3200000, 'Salarios', 'Nómina equipo', $day($ms, 5));
  $add($adminId, 'BEATAN', -420000, 'Infraestructura', 'Servidores y dominios', $day($ms, 7));
}
$add($adminId, 'PERSONAL', 3000000, 'Salario', 'Pago de nómina', $day($monthStart(0), 1));

if ($withV3) {
  $b = $pdo->prepare("INSERT INTO BEATPRES (USRIDXXX, TIPGASXX, CATGASXX, PRESMONX, PRESALRX, REGFECXX, REGESTXX) VALUES (?, 'PERSONAL', ?, ?, ?, ?, 'ACTIVO')");
  $b->execute([$userId, 'Alimentacion', 800000, 80, $today]);
  $b->execute([$userId, 'Entretenimiento', 250000, 80, $today]);
  $b->execute([$userId, 'Transporte', 300000, 90, $today]);

  $g = $pdo->prepare("INSERT INTO BEATMETA (USRIDXXX, TIPGASXX, METANOMX, METAOBJX, METAFECX, METAESTX, REGFECXX, REGESTXX) VALUES (?, 'PERSONAL', ?, ?, ?, 'ACTIVA', ?, 'ACTIVO')");
  $g->execute([$userId, 'Fondo de emergencia', 6000000, date('Y-m-d', strtotime('+10 months')), $today]);
  $goalId = (int) $pdo->lastInsertId();
  $g->execute([$userId, 'Viaje a Cartagena', 2500000, date('Y-m-d', strtotime('+5 months')), $today]);
  $goal2 = (int) $pdo->lastInsertId();
  $mv = $pdo->prepare("INSERT INTO BEATMEMO (METAIDXX, USRIDXXX, MEMOMONX, MEMOFECX, MEMODESX, REGFECXX, REGESTXX) VALUES (?, ?, ?, ?, ?, ?, 'ACTIVO')");
  $mv->execute([$goalId, $userId, 1200000, $monthStart(2), 'Aporte inicial', $today]);
  $mv->execute([$goalId, $userId, 400000, $monthStart(1), 'Aporte mensual', $today]);
  $mv->execute([$goal2, $userId, 350000, $monthStart(0), 'Primer aporte', $today]);
}

$pdo->exec("INSERT INTO FORMULARIO (NOMBRE, EMAIL, INDICATIVO, TELEFONO, DESPEMP, NOMEMP, ASUNTO, MENSAJE, ESTADO, USUMOD, REGESTXX)
  VALUES ('Cliente Prueba', 'cliente@example.test', '+57', '3000000000', 'Pyme', 'Empresa Prueba', 'Cotización', 'Mensaje de prueba', 'PENDIENTE', '', 'ACTIVO')");
$pdo->exec("INSERT INTO BEATPROY (PROYNOMX, PROYDESX, PROYESTX, REGESTXX) VALUES ('Proyecto Demo', 'Proyecto de prueba', 'DESARROLLO', 'ACTIVO')");

echo "Base de pruebas creada en $path" . ($withV3 ? '' : ' (sin tablas v3)') . PHP_EOL;

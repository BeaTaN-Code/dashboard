<?php
// dashboard/api/report.php
// API para generar reportes mensuales financieros

header('Content-Type: application/json');
date_default_timezone_set('America/Bogota');
session_start();
require_once __DIR__ . '/../config/db.php';

// Verificar autenticacion
if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
  http_response_code(401);
  echo json_encode(['success' => false, 'error' => 'No autorizado']);
  exit;
}

$pdo = getDbConnection();
if (!$pdo) {
  http_response_code(500);
  echo json_encode(['success' => false, 'error' => 'Error de conexion a la base de datos']);
  exit;
}

$userId  = $_SESSION['user_id'];
$isAdmin = $_SESSION['is_admin'] ?? false;

$tipgasxx = $_GET['tipgasxx'] ?? 'PERSONAL'; // PERSONAL | BEATAN
$month    = $_GET['month']    ?? date('Y-m'); // YYYY-MM

// Seguridad: usuarios normales solo pueden ver PERSONAL
if ($tipgasxx === 'BEATAN' && !$isAdmin) {
  http_response_code(403);
  echo json_encode(['success' => false, 'error' => 'No autorizado']);
  exit;
}

// Calcular mes anterior
$monthDate     = $month . '-01';
$prevMonthDate = date('Y-m', strtotime($monthDate . ' -1 month'));

try {

  // ─── 1. RESUMEN DEL MES ACTUAL ────────────────────────────────────────────
  $sqlResumen = "
    SELECT
      COALESCE(SUM(CASE WHEN MONTGASX > 0 THEN MONTGASX ELSE 0 END), 0)        AS ingresos,
      COALESCE(SUM(CASE WHEN MONTGASX < 0 THEN ABS(MONTGASX) ELSE 0 END), 0)   AS gastos,
      COUNT(*)                                                                    AS total_movimientos
    FROM FINANCIX
    WHERE REGESTXX = 'ACTIVO'
      AND DATE_FORMAT(FINCFECX, '%Y-%m') = :month
      AND TIPGASXX = :tipgasxx
  ";
  $paramsResumen = [':month' => $month, ':tipgasxx' => $tipgasxx];
  if ($tipgasxx !== 'BEATAN' || !$isAdmin) {
    $sqlResumen .= " AND USRIDXXX = :userId";
    $paramsResumen[':userId'] = $userId;
  }

  $stmt = $pdo->prepare($sqlResumen);
  $stmt->execute($paramsResumen);
  $resumen = $stmt->fetch();

  $ingresos = floatval($resumen['ingresos']);
  $gastos   = floatval($resumen['gastos']);
  $balance  = $ingresos - $gastos;

  // ─── 2. RESUMEN MES ANTERIOR (para % variacion) ───────────────────────────
  $sqlPrev = "
    SELECT
      COALESCE(SUM(CASE WHEN MONTGASX > 0 THEN MONTGASX ELSE 0 END), 0)      AS ingresos,
      COALESCE(SUM(CASE WHEN MONTGASX < 0 THEN ABS(MONTGASX) ELSE 0 END), 0) AS gastos
    FROM FINANCIX
    WHERE REGESTXX = 'ACTIVO'
      AND DATE_FORMAT(FINCFECX, '%Y-%m') = :month
      AND TIPGASXX = :tipgasxx
  ";
  $paramsPrev = [':month' => $prevMonthDate, ':tipgasxx' => $tipgasxx];
  if ($tipgasxx !== 'BEATAN' || !$isAdmin) {
    $sqlPrev .= " AND USRIDXXX = :userId";
    $paramsPrev[':userId'] = $userId;
  }

  $stmt = $pdo->prepare($sqlPrev);
  $stmt->execute($paramsPrev);
  $prevData = $stmt->fetch();

  $prevIngresos = floatval($prevData['ingresos']);
  $prevGastos   = floatval($prevData['gastos']);
  $prevBalance  = $prevIngresos - $prevGastos;

  // Calcular variaciones porcentuales
  $varIngresos = ($prevIngresos > 0) ? round((($ingresos - $prevIngresos) / $prevIngresos) * 100, 1) : null;
  $varGastos   = ($prevGastos   > 0) ? round((($gastos   - $prevGastos)   / $prevGastos)   * 100, 1) : null;
  $varBalance  = ($prevBalance  != 0) ? round((($balance  - $prevBalance)  / abs($prevBalance)) * 100, 1) : null;

  // ─── 3. DETALLE POR CATEGORIA ─────────────────────────────────────────────
  $sqlCat = "
    SELECT
      CATGASXX,
      COALESCE(SUM(CASE WHEN MONTGASX > 0 THEN MONTGASX ELSE 0 END), 0)      AS ingresos,
      COALESCE(SUM(CASE WHEN MONTGASX < 0 THEN ABS(MONTGASX) ELSE 0 END), 0) AS gastos,
      COUNT(*) AS movimientos
    FROM FINANCIX
    WHERE REGESTXX = 'ACTIVO'
      AND DATE_FORMAT(FINCFECX, '%Y-%m') = :month
      AND TIPGASXX = :tipgasxx
  ";
  $paramsCat = [':month' => $month, ':tipgasxx' => $tipgasxx];
  if ($tipgasxx !== 'BEATAN' || !$isAdmin) {
    $sqlCat .= " AND USRIDXXX = :userId";
    $paramsCat[':userId'] = $userId;
  }
  $sqlCat .= " GROUP BY CATGASXX ORDER BY gastos DESC, ingresos DESC";

  $stmt = $pdo->prepare($sqlCat);
  $stmt->execute($paramsCat);
  $categorias = $stmt->fetchAll();

  // ─── 4. DETALLE DE MOVIMIENTOS DEL MES ───────────────────────────────────
  $sqlMovs = "
    SELECT FINCTIDX, FINCFECX, CATGASXX, DESGASXX, MONTGASX
    FROM FINANCIX
    WHERE REGESTXX = 'ACTIVO'
      AND DATE_FORMAT(FINCFECX, '%Y-%m') = :month
      AND TIPGASXX = :tipgasxx
  ";
  $paramsMovs = [':month' => $month, ':tipgasxx' => $tipgasxx];
  if ($tipgasxx !== 'BEATAN' || !$isAdmin) {
    $sqlMovs .= " AND USRIDXXX = :userId";
    $paramsMovs[':userId'] = $userId;
  }
  $sqlMovs .= " ORDER BY FINCFECX ASC, FINCTIDX ASC";

  $stmt = $pdo->prepare($sqlMovs);
  $stmt->execute($paramsMovs);
  $movimientos = $stmt->fetchAll();

  // ─── 5. DEUDAS DEL USUARIO ────────────────────────────────────────────────
  $sqlDeudas = "
    SELECT DEUDIDXX, TIPDEUDX, DESDEUDX, MONDEUDX, MONCUOTX,
           PAGCUOTX, NUMCUOTX, FECCUOTX, ABONCUOT, REGESTXX, REGFECXX
    FROM DEUDASIX
    WHERE TIPPERXX = :tipperxx
      AND REGESTXX IN ('ACTIVO', 'PAGADO')
  ";
  $paramsDeudas = [':tipperxx' => $tipgasxx];
  if ($tipgasxx !== 'BEATAN' || !$isAdmin) {
    $sqlDeudas .= " AND USRIDXXX = :userId";
    $paramsDeudas[':userId'] = $userId;
  }
  $sqlDeudas .= " ORDER BY REGESTXX ASC, DEUDIDXX DESC";

  $stmt = $pdo->prepare($sqlDeudas);
  $stmt->execute($paramsDeudas);
  $deudas = $stmt->fetchAll();

  // Separar deudas activas y pagadas
  $deudasActivas = array_values(array_filter($deudas, fn($d) => $d['REGESTXX'] === 'ACTIVO'));
  $deudasPagadas = array_values(array_filter($deudas, fn($d) => $d['REGESTXX'] === 'PAGADO'));

  // Total deudas activas en contra
  $totalDeudaContra = array_sum(array_map(
    fn($d) => $d['TIPDEUDX'] === 'EN CONTRA' ? floatval($d['MONDEUDX']) : 0,
    $deudasActivas
  ));
  $totalDeudaFavor = array_sum(array_map(
    fn($d) => $d['TIPDEUDX'] === 'A FAVOR'   ? floatval($d['MONDEUDX']) : 0,
    $deudasActivas
  ));

  // ─── RESPUESTA COMPLETA ────────────────────────────────────────────────────
  echo json_encode([
    'success' => true,
    'periodo' => [
      'month'     => $month,
      'prevMonth' => $prevMonthDate,
      'tipgasxx'  => $tipgasxx
    ],
    'resumen' => [
      'ingresos'     => $ingresos,
      'gastos'       => $gastos,
      'balance'      => $balance,
      'movimientos'  => intval($resumen['total_movimientos']),
      'varIngresos'  => $varIngresos,
      'varGastos'    => $varGastos,
      'varBalance'   => $varBalance,
      'prevIngresos' => $prevIngresos,
      'prevGastos'   => $prevGastos,
      'prevBalance'  => $prevBalance
    ],
    'categorias'       => $categorias,
    'movimientos'      => $movimientos,
    'deudas' => [
      'activas'     => $deudasActivas,
      'pagadas'     => $deudasPagadas,
      'totalContra' => $totalDeudaContra,
      'totalFavor'  => $totalDeudaFavor,
    ]
  ]);

} catch (Exception $e) {
  error_log('Report error: ' . $e->getMessage());
  http_response_code(500);
  echo json_encode(['success' => false, 'error' => 'Error al generar el reporte']);
}
?>

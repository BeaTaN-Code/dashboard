<?php
require_once __DIR__ . '/../config/db.php';
$pdo = getDbConnection();

$hoy = new DateTime('today');

/*
========================================
1️⃣ GENERAR CUOTAS AUTOMÁTICAS (CON CUOTAS)
========================================
*/
$sql = "
SELECT 
  D.DEUDIDXX,
  D.DESDEUDX,
  D.USRIDXXX,
  D.NUMCUOTX,
  D.MONCUOTX,
  D.FECCUOTX,
  D.REGFECXX,
  COUNT(F.FINCTIDX) AS EXISTENTES
FROM DEUDASIX D
LEFT JOIN FINANCIX F 
  ON F.DEUDIDXX = D.DEUDIDXX
 AND F.FINCFECX <= CURDATE()
WHERE D.NUMCUOTX IS NOT NULL
  AND D.REGESTXX = 'ACTIVO'
GROUP BY 
  D.DEUDIDXX,
  D.USRIDXXX,
  D.NUMCUOTX,
  D.MONCUOTX,
  D.FECCUOTX,
  D.REGFECXX
";

$stmt = $pdo->query($sql);
$deudas = $stmt->fetchAll(PDO::FETCH_ASSOC);

foreach ($deudas as $d) {

  $inicio = new DateTime($d['REGFECXX']);
  $diff = $inicio->diff($hoy);
  $mesesTranscurridos = ($diff->y * 12) + $diff->m + 1;

  $maxCuotas = min($mesesTranscurridos, (int) $d['NUMCUOTX']);
  $existentes = (int) $d['EXISTENTES'];
  $faltantes = $maxCuotas - $existentes;

  if ($faltantes <= 0)
    continue;

  for ($i = $existentes; $i < $maxCuotas; $i++) {

    $fechaCuota = clone $inicio;
    $fechaCuota->modify("+$i month");

    $dia = str_pad($d['FECCUOTX'], 2, '0', STR_PAD_LEFT);
    $fechaFinal = $fechaCuota->format("Y-m") . "-$dia";

    if ($fechaFinal > date('Y-m-d'))
      continue;

    $insert = $pdo->prepare("
      INSERT INTO FINANCIX (
        USRIDXXX,
        DEUDIDXX,
        TIPGASXX,
        CATGASXX,
        MONTGASX,
        DESGASXX,
        FINCFECX,
        REGUSRXX,
        REGFECXX,
        REGHORXX,
        REGUSRMX,
        REGFECMX,
        REGHORMX,
        REGSTAMP,
        REGESTXX
      ) VALUES (
        :usr,
        :deuda,
        'PERSONAL',
        'OTROS',
        :monto,
        :desc,
        :fecha,
        10000,
        CURDATE(),
        CURTIME(),
        10000,
        CURDATE(),
        CURTIME(),
        NOW(),
        'ACTIVO'
      )
    ");

    $insert->execute([
      ':usr' => $d['USRIDXXX'],
      ':deuda' => $d['DEUDIDXX'],
      ':monto' => $d['MONCUOTX'] * -1,
      ':desc' => 'Cuota automática deuda: ' . $d['DESDEUDX'],
      ':fecha' => $fechaFinal
    ]);
  }
}

/*
=====================================
2️⃣ ACTUALIZAR CUOTAS PAGADAS
=====================================
*/
$sqlCuotas = "
SELECT 
  D.DEUDIDXX,
  D.NUMCUOTX,
  COUNT(F.FINCTIDX) AS PAGADAS
FROM DEUDASIX D
LEFT JOIN FINANCIX F 
  ON F.DEUDIDXX = D.DEUDIDXX
 AND F.FINCFECX <= CURDATE()
WHERE D.NUMCUOTX IS NOT NULL
  AND D.REGESTXX = 'ACTIVO'
GROUP BY D.DEUDIDXX, D.NUMCUOTX
";

$stmt = $pdo->query($sqlCuotas);
$deudasCuotas = $stmt->fetchAll(PDO::FETCH_ASSOC);

foreach ($deudasCuotas as $d) {
  $pagadas = (int) $d['PAGADAS'];
  $numcuot = (int) $d['NUMCUOTX'];
  $estado = ($pagadas >= $numcuot) ? 'PAGADO' : 'ACTIVO';

  $upd = $pdo->prepare("
    UPDATE DEUDASIX
    SET PAGCUOTX = :pagadas,
        REGESTXX = :estado,
        REGUSRMX = 10000,
        REGFECMX = CURDATE(),
        REGHORMX = CURTIME(),
        REGSTAMP = NOW()
    WHERE DEUDIDXX = :id
  ");

  $upd->execute([
    ':pagadas' => $pagadas,
    ':estado' => $estado,
    ':id' => $d['DEUDIDXX']
  ]);
}

/*
=====================================
3️⃣ DEUDAS SIN CUOTAS (ABONOS)
=====================================
*/
$sqlAbonos = "
SELECT 
  D.DEUDIDXX,
  D.MONDEUDX,
  IFNULL(SUM(ABS(F.MONTGASX)),0) AS TOTAL_ABONADO
FROM DEUDASIX D
LEFT JOIN FINANCIX F 
  ON F.DEUDIDXX = D.DEUDIDXX
 AND F.FINCFECX <= CURDATE()
WHERE D.NUMCUOTX IS NULL
  AND D.REGESTXX = 'ACTIVO'
GROUP BY D.DEUDIDXX, D.MONDEUDX
";

$stmt = $pdo->query($sqlAbonos);
$deudasAbono = $stmt->fetchAll(PDO::FETCH_ASSOC);

foreach ($deudasAbono as $d) {
  $abonado = (float) $d['TOTAL_ABONADO'];
  $monto = (float) $d['MONDEUDX'];
  $estado = ($abonado >= $monto) ? 'PAGADO' : 'ACTIVO';

  $upd = $pdo->prepare("
    UPDATE DEUDASIX
    SET ABONCUOT = :abonado,
        REGESTXX = :estado,
        REGUSRMX = 10000,
        REGFECMX = CURDATE(),
        REGHORMX = CURTIME(),
        REGSTAMP = NOW()
    WHERE DEUDIDXX = :id
  ");

  $upd->execute([
    ':abonado' => $abonado,
    ':estado' => $estado,
    ':id' => $d['DEUDIDXX']
  ]);
}

echo "CRON DE DEUDAS EJECUTADO OK\n";
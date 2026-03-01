<?php
require_once __DIR__ . '/../config/db.php';
$pdo = getDbConnection();

echo "=============================\n";
echo "INICIANDO CRON DE DEUDAS\n";
echo "=============================\n";

$hoy = new DateTime('today');
echo "Fecha HOY: " . $hoy->format('Y-m-d') . "\n";

/*
========================================
1️⃣ GENERAR CUOTAS AUTOMÁTICAS (CON CUOTAS)
========================================
*/
echo "\n[FASE 1] GENERAR CUOTAS AUTOMÁTICAS\n";

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

echo "Deudas encontradas: " . count($deudas) . "\n";

foreach ($deudas as $d) {
  echo "\n-----------------------------\n";
  echo "Procesando DEUDA ID: {$d['DEUDIDXX']}\n";
  print_r($d);

  $inicio = new DateTime($d['REGFECXX']);
  $diff = $inicio->diff($hoy);
  $mesesTranscurridos = ($diff->y * 12) + $diff->m + 1;

  echo "Fecha inicio: " . $inicio->format('Y-m-d') . "\n";
  echo "Meses transcurridos: $mesesTranscurridos\n";

  $maxCuotas = (int) $d['NUMCUOTX'];
  $existentes = (int) $d['EXISTENTES'];
  $faltantes = $maxCuotas - $existentes;

  echo "Cuotas máximas: $maxCuotas\n";
  echo "Cuotas existentes: $existentes\n";
  echo "Cuotas faltantes: $faltantes\n";

  if ($faltantes <= 0) {
    echo "❌ No se generan cuotas\n";
    continue;
  }

  for ($i = $existentes; $i < $maxCuotas; $i++) {

    $fechaCuota = clone $inicio;
    $fechaCuota->modify("+$i month");

    $dia = str_pad($d['FECCUOTX'], 2, '0', STR_PAD_LEFT);
    $fechaFinal = $fechaCuota->format("Y-m") . "-$dia";

    echo "Insertando cuota #".($i+1)." con fecha: $fechaFinal\n";

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

    echo "✔ Cuota insertada\n";
  }
}

/*
=====================================
2️⃣ ACTUALIZAR CUOTAS PAGADAS
=====================================
*/
echo "\n[FASE 2] ACTUALIZAR CUOTAS PAGADAS\n";

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

echo "Registros para actualizar: " . count($deudasCuotas) . "\n";

foreach ($deudasCuotas as $d) {
  print_r($d);

  $pagadas = (int) $d['PAGADAS'];
  $numcuot = (int) $d['NUMCUOTX'];
  $estado = ($pagadas >= $numcuot) ? 'PAGADO' : 'ACTIVO';

  echo "Pagadas: $pagadas / Total: $numcuot => Estado: $estado\n";

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

  echo "✔ Deuda actualizada\n";
}

/*
=====================================
3️⃣ DEUDAS SIN CUOTAS (ABONOS)
=====================================
*/
echo "\n[FASE 3] DEUDAS SIN CUOTAS (ABONOS)\n";

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

echo "Deudas sin cuotas: " . count($deudasAbono) . "\n";

foreach ($deudasAbono as $d) {
  print_r($d);

  $abonado = (float) $d['TOTAL_ABONADO'];
  $monto = (float) $d['MONDEUDX'];
  $estado = ($abonado >= $monto) ? 'PAGADO' : 'ACTIVO';

  echo "Abonado: $abonado / Total: $monto => Estado: $estado\n";

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

  echo "✔ Abono actualizado\n";
}

echo "\n=============================\n";
echo "CRON DE DEUDAS EJECUTADO OK\n";
echo "=============================\n";
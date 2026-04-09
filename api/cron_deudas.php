<?php
/**
 * cron_deudas.php
 * Cron job para gestión automática de deudas.
 *
 * BUGS CORREGIDOS:
 *  - FASE 1: LEFT JOIN convertido en INNER JOIN por mal posicionamiento del filtro REGESTXX en WHERE (→ ON).
 *  - FASE 1: TIPGASXX hardcodeado a 'PERSONAL', ahora usa el TIPPERXX real de la deuda.
 *  - FASE 1: Faltaba DESDEUDX en el GROUP BY.
 *  - FASE 1: Sin verificación de duplicados → posibles cuotas dobles si el cron fallaba y se reejecutaba.
 *  - FASE 2: Conteo de cuotas no filtraba REGESTXX = 'ACTIVO' en FINANCIX.
 *
 * MEJORAS AÑADIDAS:
 *  - Try/catch global con logging de errores por fase.
 *  - Resumen final de ejecución con estadísticas.
 *  - Timestamp de inicio y duración.
 *  - Verificación de duplicados por (DEUDIDXX, FINCFECX) antes de insertar.
 *  - Soporte correcto para TIPPERXX (PERSONAL / BEATAN) al generar cuotas.
 *  - CATGASXX dinámica según tipo de deuda.
 */

require_once __DIR__ . '/../config/db.php';
date_default_timezone_set('America/Bogota');

$startTime = microtime(true);
$timestamp = date('Y-m-d H:i:s');

// ─────────────────────────────────────────────
//  Contadores globales para el resumen final
// ─────────────────────────────────────────────
$stats = [
    'fase1_deudas_procesadas'    => 0,
    'fase1_cuotas_generadas'     => 0,
    'fase1_cuotas_omitidas'      => 0,
    'fase2_deudas_actualizadas'  => 0,
    'fase2_deudas_pagadas'       => 0,
    'fase3_deudas_actualizadas'  => 0,
    'fase3_deudas_pagadas'       => 0,
    'errores'                    => [],
];

// ─────────────────────────────────────────────
//  Conexión
// ─────────────────────────────────────────────
$pdo = getDbConnection();
if (!$pdo) {
    echo "[ERROR CRÍTICO] No se pudo conectar a la base de datos.\n";
    exit(1);
}

$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$hoy = new DateTime('today');
$hoyStr = $hoy->format('Y-m-d');

echo "============================================================\n";
echo "  CRON DE DEUDAS — $timestamp\n";
echo "============================================================\n";
echo "Fecha de ejecución: $hoyStr\n\n";

/* ============================================================
   FASE 1: GENERAR CUOTAS AUTOMÁTICAS
   ============================================================ */
echo "──────────────────────────────────────────────────────────\n";
echo "[FASE 1] GENERAR CUOTAS AUTOMÁTICAS\n";
echo "──────────────────────────────────────────────────────────\n";

/*
 * BUG CORREGIDO: el filtro `F.REGESTXX = 'ACTIVO'` se movió al ON del LEFT JOIN.
 * Antes estaba en el WHERE, lo que convertía el LEFT JOIN en INNER JOIN, impidiendo
 * que deudas sin cuotas generadas (F.* = NULL) fueran incluidas.
 *
 * BUG CORREGIDO: Se añade D.DESDEUDX y D.TIPPERXX al SELECT y al GROUP BY.
 *
 * Solo se generan cuotas para deudas que AÚN no tienen todas sus cuotas creadas
 * (EXISTENTES < NUMCUOTX), usando HAVING para filtrar en la misma query.
 */
$sqlFase1 = "
    SELECT
        D.DEUDIDXX,
        D.DESDEUDX,
        D.USRIDXXX,
        D.NUMCUOTX,
        D.MONCUOTX,
        D.FECCUOTX,
        D.TIPPERXX,
        D.TIPDEUDX,
        D.REGFECXX,
        COUNT(F.FINCTIDX) AS EXISTENTES
    FROM DEUDASIX D
    LEFT JOIN FINANCIX F
        ON  F.DEUDIDXX   = D.DEUDIDXX
        AND F.REGESTXX   = 'ACTIVO'
    WHERE D.NUMCUOTX IS NOT NULL
      AND D.MONCUOTX IS NOT NULL
      AND D.FECCUOTX IS NOT NULL
      AND D.REGESTXX = 'ACTIVO'
    GROUP BY
        D.DEUDIDXX,
        D.DESDEUDX,
        D.USRIDXXX,
        D.NUMCUOTX,
        D.MONCUOTX,
        D.FECCUOTX,
        D.TIPPERXX,
        D.TIPDEUDX,
        D.REGFECXX
    HAVING EXISTENTES < D.NUMCUOTX
";

try {
    $stmt  = $pdo->query($sqlFase1);
    $deudas = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo "Deudas con cuotas pendientes: " . count($deudas) . "\n\n";

    foreach ($deudas as $d) {
        $stats['fase1_deudas_procesadas']++;

        echo "  ► Deuda ID: {$d['DEUDIDXX']} | {$d['DESDEUDX']} | Usuario: {$d['USRIDXXX']} | Tipo: {$d['TIPPERXX']}\n";

        $inicio     = new DateTime($d['REGFECXX']);
        $maxCuotas  = (int) $d['NUMCUOTX'];
        $existentes = (int) $d['EXISTENTES'];
        $faltantes  = $maxCuotas - $existentes;

        echo "    Cuotas: $existentes/$maxCuotas (faltan $faltantes)\n";

        // Determinar TIPGASXX según TIPPERXX de la deuda (BUG CORREGIDO: antes siempre era 'PERSONAL')
        $tipgasxx = ($d['TIPPERXX'] === 'BEATAN') ? 'BEATAN' : 'PERSONAL';

        // Si la deuda es A FAVOR (nos deben a nosotros) → ingreso positivo
        // Si la deuda es EN CONTRA (debemos nosotros)  → gasto negativo
        $esFavor  = (strtoupper(trim($d['TIPDEUDX'])) === 'A FAVOR');
        $catgasxx = $esFavor ? 'INGRESO' : 'DEUDA';

        for ($i = $existentes; $i < $maxCuotas; $i++) {
            $fechaCuota = clone $inicio;
            $fechaCuota->modify("+{$i} month");

            $dia        = str_pad((string)$d['FECCUOTX'], 2, '0', STR_PAD_LEFT);
            $fechaFinal = $fechaCuota->format("Y-m") . "-{$dia}";

            // BUG CORREGIDO: Verificar si ya existe una cuota para esa fecha + deuda
            // (evita duplicados si el cron se ejecutó parcialmente antes)
            $checkStmt = $pdo->prepare("
                SELECT COUNT(*) FROM FINANCIX
                WHERE DEUDIDXX  = :deuda
                  AND FINCFECX  = :fecha
                  AND REGESTXX != 'INACTIVO'
            ");
            $checkStmt->execute([':deuda' => $d['DEUDIDXX'], ':fecha' => $fechaFinal]);
            $yaExiste = (int) $checkStmt->fetchColumn();

            if ($yaExiste > 0) {
                echo "    [OMITIDA] Cuota #" . ($i + 1) . " fecha $fechaFinal ya existe.\n";
                $stats['fase1_cuotas_omitidas']++;
                continue;
            }

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
                    :tipgasxx,
                    :catgasxx,
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
                ':usr'      => $d['USRIDXXX'],
                ':deuda'    => $d['DEUDIDXX'],
                ':tipgasxx' => $tipgasxx,
                ':catgasxx' => $catgasxx,
                ':monto'    => $esFavor ? abs((float) $d['MONCUOTX']) : abs((float) $d['MONCUOTX']) * -1,
                ':desc'     => 'Cuota #' . ($i + 1) . ' — ' . $d['DESDEUDX'],
                ':fecha'    => $fechaFinal,
            ]);

            echo "    [✔] Cuota #" . ($i + 1) . " generada para $fechaFinal\n";
            $stats['fase1_cuotas_generadas']++;
        }
        echo "\n";
    }
} catch (Exception $e) {
    $msg = "[FASE 1] ERROR: " . $e->getMessage();
    echo "$msg\n";
    $stats['errores'][] = $msg;
    error_log("cron_deudas FASE1: " . $e->getMessage());
}

/* ============================================================
   FASE 2: ACTUALIZAR ESTADO DE DEUDAS CON CUOTAS
   ============================================================ */
echo "──────────────────────────────────────────────────────────\n";
echo "[FASE 2] ACTUALIZAR ESTADO (CUOTAS)\n";
echo "──────────────────────────────────────────────────────────\n";

/*
 * BUG CORREGIDO: Se añade `AND F.REGESTXX = 'ACTIVO'` en el JOIN de FINANCIX
 * para no contar cuotas inactivas/eliminadas como pagadas.
 *
 * Solo considera cuotas con fecha <= hoy (ya vencidas/pagadas).
 */
$sqlFase2 = "
    SELECT
        D.DEUDIDXX,
        D.NUMCUOTX,
        COUNT(F.FINCTIDX) AS PAGADAS
    FROM DEUDASIX D
    LEFT JOIN FINANCIX F
        ON  F.DEUDIDXX = D.DEUDIDXX
        AND F.FINCFECX <= CURDATE()
        AND F.REGESTXX  = 'ACTIVO'
    WHERE D.NUMCUOTX IS NOT NULL
      AND D.REGESTXX = 'ACTIVO'
    GROUP BY D.DEUDIDXX, D.NUMCUOTX
";

try {
    $stmt        = $pdo->query($sqlFase2);
    $deudasCuotas = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo "Registros a evaluar: " . count($deudasCuotas) . "\n\n";

    foreach ($deudasCuotas as $d) {
        $pagadas = (int) $d['PAGADAS'];
        $total   = (int) $d['NUMCUOTX'];
        $estado  = ($pagadas >= $total && $total > 0) ? 'PAGADO' : 'ACTIVO';

        echo "  ► Deuda ID: {$d['DEUDIDXX']} | Pagadas: $pagadas/$total → $estado\n";

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
            ':estado'  => $estado,
            ':id'      => $d['DEUDIDXX'],
        ]);

        $stats['fase2_deudas_actualizadas']++;
        if ($estado === 'PAGADO') $stats['fase2_deudas_pagadas']++;
    }
    echo "\n";
} catch (Exception $e) {
    $msg = "[FASE 2] ERROR: " . $e->getMessage();
    echo "$msg\n";
    $stats['errores'][] = $msg;
    error_log("cron_deudas FASE2: " . $e->getMessage());
}

/* ============================================================
   FASE 3: DEUDAS SIN CUOTAS (ABONOS LIBRES)
   ============================================================ */
echo "──────────────────────────────────────────────────────────\n";
echo "[FASE 3] ACTUALIZAR ESTADO (ABONOS LIBRES)\n";
echo "──────────────────────────────────────────────────────────\n";

/*
 * También corregido: filtro de REGESTXX en el JOIN de FINANCIX.
 */
$sqlFase3 = "
    SELECT
        D.DEUDIDXX,
        D.MONDEUDX,
        IFNULL(SUM(ABS(F.MONTGASX)), 0) AS TOTAL_ABONADO
    FROM DEUDASIX D
    LEFT JOIN FINANCIX F
        ON  F.DEUDIDXX = D.DEUDIDXX
        AND F.FINCFECX <= CURDATE()
        AND F.REGESTXX  = 'ACTIVO'
    WHERE D.NUMCUOTX IS NULL
      AND D.REGESTXX = 'ACTIVO'
    GROUP BY D.DEUDIDXX, D.MONDEUDX
";

try {
    $stmt       = $pdo->query($sqlFase3);
    $deudasAbono = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo "Deudas sin cuotas: " . count($deudasAbono) . "\n\n";

    foreach ($deudasAbono as $d) {
        $abonado = (float) $d['TOTAL_ABONADO'];
        $monto   = (float) $d['MONDEUDX'];
        $estado  = ($monto > 0 && $abonado >= $monto) ? 'PAGADO' : 'ACTIVO';

        echo "  ► Deuda ID: {$d['DEUDIDXX']} | Abonado: $abonado / Total: $monto → $estado\n";

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
            ':estado'  => $estado,
            ':id'      => $d['DEUDIDXX'],
        ]);

        $stats['fase3_deudas_actualizadas']++;
        if ($estado === 'PAGADO') $stats['fase3_deudas_pagadas']++;
    }
    echo "\n";
} catch (Exception $e) {
    $msg = "[FASE 3] ERROR: " . $e->getMessage();
    echo "$msg\n";
    $stats['errores'][] = $msg;
    error_log("cron_deudas FASE3: " . $e->getMessage());
}

/* ============================================================
   RESUMEN FINAL
   ============================================================ */
$duration = round(microtime(true) - $startTime, 3);

echo "============================================================\n";
echo "  RESUMEN DE EJECUCIÓN\n";
echo "============================================================\n";
echo "  Duración total          : {$duration}s\n";
echo "  ─── FASE 1 ───────────────────────────────────────────\n";
echo "  Deudas procesadas       : {$stats['fase1_deudas_procesadas']}\n";
echo "  Cuotas generadas        : {$stats['fase1_cuotas_generadas']}\n";
echo "  Cuotas omitidas (dup)   : {$stats['fase1_cuotas_omitidas']}\n";
echo "  ─── FASE 2 ───────────────────────────────────────────\n";
echo "  Deudas actualizadas     : {$stats['fase2_deudas_actualizadas']}\n";
echo "  Deudas marcadas PAGADO  : {$stats['fase2_deudas_pagadas']}\n";
echo "  ─── FASE 3 ───────────────────────────────────────────\n";
echo "  Deudas actualizadas     : {$stats['fase3_deudas_actualizadas']}\n";
echo "  Deudas marcadas PAGADO  : {$stats['fase3_deudas_pagadas']}\n";

if (!empty($stats['errores'])) {
    echo "  ─── ERRORES ──────────────────────────────────────────\n";
    foreach ($stats['errores'] as $err) {
        echo "  ⚠  $err\n";
    }
    echo "============================================================\n";
    echo "  CRON FINALIZADO CON ERRORES\n";
    exit(1);
} else {
    echo "============================================================\n";
    echo "  CRON FINALIZADO EXITOSAMENTE ✔\n";
}

echo "============================================================\n";
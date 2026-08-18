<?php
// dashboard/api/migrate_v2.php
// Script de migración idempotente para aplicar índices de rendimiento en base de datos MySQL

header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/api_helper.php';

$auth = api_require_auth();
if (!$auth['is_admin']) {
    api_response(false, 'Solo el usuario administrador puede ejecutar migraciones de BD', 403);
}

$pdo = getDbConnection();
if (!$pdo) {
    api_response(false, 'No se pudo conectar a la base de datos', 500);
}

$results = [];

/**
 * Agrega un índice si no existe previamente
 */
function addIndexIfNotExists(PDO $pdo, string $table, string $indexName, string $columns): string {
    try {
        // Verificar si el índice ya existe
        $stmt = $pdo->prepare("SHOW INDEX FROM `$table` WHERE Key_name = :idxName");
        $stmt->execute([':idxName' => $indexName]);
        if ($stmt->fetch()) {
            return "El índice '$indexName' ya existe en '$table'. Skipping.";
        }

        // Crear el índice
        $sql = "ALTER TABLE `$table` ADD INDEX `$indexName` ($columns)";
        $pdo->exec($sql);
        return "✅ Índice '$indexName' creado exitosamente en '$table' ($columns).";
    } catch (Exception $e) {
        return "❌ Error creando '$indexName' en '$table': " . $e->getMessage();
    }
}

$results[] = addIndexIfNotExists($pdo, 'FINANCIX', 'idx_fin_user_tipo_fec', '`USRIDXXX`, `TIPGASXX`, `REGESTXX`, `FINCFECX`');
$results[] = addIndexIfNotExists($pdo, 'DEUDASIX', 'idx_deu_user_reg_tip', '`USRIDXXX`, `REGESTXX`, `TIPDEUDX`');
$results[] = addIndexIfNotExists($pdo, 'BEATPROY', 'idx_proy_est', '`PROYESTX`, `REGESTXX`');
$results[] = addIndexIfNotExists($pdo, 'BEATFACT', 'idx_fact_est_fec', '`FACTESTX`, `FACTFECX`');

api_response(true, [
    'message' => 'Migración v2 completada.',
    'logs' => $results
]);

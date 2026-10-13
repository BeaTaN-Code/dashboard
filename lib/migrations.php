<?php
// dashboard/lib/migrations.php
// Migraciones idempotentes y NO destructivas (solo CREATE TABLE IF NOT EXISTS / índices).

function app_migrations(): array
{
  $audit = "
      REGUSRXX VARCHAR(20) NULL COMMENT 'Usuario que crea',
      REGFECXX DATE NULL COMMENT 'Fecha de creación',
      REGHORXX TIME NULL COMMENT 'Hora de creación',
      REGUSRMX VARCHAR(20) NULL COMMENT 'Usuario que modifica',
      REGFECMX DATE NULL COMMENT 'Fecha de modificación',
      REGHORMX TIME NULL COMMENT 'Hora de modificación',
      REGESTXX ENUM('ACTIVO', 'INACTIVO') NOT NULL DEFAULT 'ACTIVO' COMMENT 'Estado del registro',
      REGSTAMP TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT 'Marca de tiempo'";

  return [
    'v3_presupuestos' => [
      'description' => 'Presupuestos mensuales por categoría',
      'table' => 'BEATPRES',
      'sql' => "CREATE TABLE IF NOT EXISTS BEATPRES (
        PRESIDXX INT AUTO_INCREMENT PRIMARY KEY COMMENT 'ID del presupuesto',
        USRIDXXX VARCHAR(15) NOT NULL COMMENT 'Cédula del usuario',
        TIPGASXX VARCHAR(20) NOT NULL COMMENT 'Cuenta (PERSONAL / BEATAN)',
        CATGASXX VARCHAR(30) NOT NULL COMMENT 'Categoría presupuestada',
        PRESMONX DECIMAL(12,2) NOT NULL COMMENT 'Límite mensual',
        PRESALRX TINYINT NOT NULL DEFAULT 80 COMMENT 'Umbral de alerta en %',
        $audit,
        INDEX idx_pres_user_acc (USRIDXXX, TIPGASXX, REGESTXX)
      ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Presupuestos mensuales por categoría'",
    ],
    'v3_metas' => [
      'description' => 'Metas de ahorro',
      'table' => 'BEATMETA',
      'sql' => "CREATE TABLE IF NOT EXISTS BEATMETA (
        METAIDXX INT AUTO_INCREMENT PRIMARY KEY COMMENT 'ID de la meta',
        USRIDXXX VARCHAR(15) NOT NULL COMMENT 'Cédula del usuario',
        TIPGASXX VARCHAR(20) NOT NULL DEFAULT 'PERSONAL' COMMENT 'Cuenta (PERSONAL / BEATAN)',
        METANOMX VARCHAR(80) NOT NULL COMMENT 'Nombre de la meta',
        METAOBJX DECIMAL(12,2) NOT NULL COMMENT 'Importe objetivo',
        METAFECX DATE NULL COMMENT 'Fecha deseada',
        METAESTX ENUM('ACTIVA', 'ARCHIVADA') NOT NULL DEFAULT 'ACTIVA' COMMENT 'Estado de la meta',
        $audit,
        INDEX idx_meta_user_acc (USRIDXXX, TIPGASXX, REGESTXX)
      ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Metas de ahorro'",
    ],
    'v3_metas_movimientos' => [
      'description' => 'Aportes y retiros de metas de ahorro',
      'table' => 'BEATMEMO',
      'sql' => "CREATE TABLE IF NOT EXISTS BEATMEMO (
        MEMOIDXX INT AUTO_INCREMENT PRIMARY KEY COMMENT 'ID del movimiento de meta',
        METAIDXX INT NOT NULL COMMENT 'Meta asociada',
        USRIDXXX VARCHAR(15) NOT NULL COMMENT 'Usuario que registra',
        MEMOMONX DECIMAL(12,2) NOT NULL COMMENT 'Monto (+ aporte, - retiro)',
        MEMOFECX DATE NOT NULL COMMENT 'Fecha del movimiento',
        MEMODESX VARCHAR(150) NULL COMMENT 'Nota',
        $audit,
        INDEX idx_memo_meta (METAIDXX, REGESTXX),
        CONSTRAINT FK_BEATMEMO_BEATMETA FOREIGN KEY (METAIDXX) REFERENCES BEATMETA(METAIDXX)
      ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Movimientos de metas de ahorro'",
    ],
  ];
}

function migration_table_exists(PDO $pdo, string $table): bool
{
  try {
    $pdo->query("SELECT 1 FROM $table LIMIT 1");
    return true;
  } catch (PDOException $e) {
    return false;
  }
}

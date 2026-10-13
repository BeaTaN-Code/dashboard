<?php
// dashboard/api/migrate.php
// Migraciones de base de datos (solo administradores).
// GET  => estado de las migraciones.  POST => aplica las pendientes.
// Todas son idempotentes y no destructivas (CREATE TABLE IF NOT EXISTS).

require_once __DIR__ . '/../config/api_helper.php';
require_once __DIR__ . '/../lib/migrations.php';

api_bootstrap();
api_require_admin();
$pdo = api_db();

$legacy = [
  'BEATPROY' => "CREATE TABLE IF NOT EXISTS BEATPROY (
      PROYIDXX INT AUTO_INCREMENT PRIMARY KEY COMMENT 'ID del proyecto',
      PROYNOMX VARCHAR(100) NOT NULL COMMENT 'Nombre del proyecto',
      PROYDESX VARCHAR(255) COMMENT 'Descripción del proyecto',
      PROYESTX ENUM('PLANIFICACION', 'DESARROLLO', 'TERMINADO', 'INACTIVO') DEFAULT 'PLANIFICACION' COMMENT 'Estado del proyecto',
      REGUSRXX VARCHAR(20), REGFECXX DATE, REGHORXX TIME, REGUSRMX VARCHAR(20), REGFECMX DATE, REGHORMX TIME,
      REGESTXX ENUM('', 'ACTIVO', 'INACTIVO') DEFAULT 'ACTIVO',
      REGSTAMP TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Tabla de proyectos de BeaTaN'",
  'BEATHORA' => "CREATE TABLE IF NOT EXISTS BEATHORA (
      HORAIDXX INT AUTO_INCREMENT PRIMARY KEY, PROYIDXX INT NOT NULL, USRIDXXX VARCHAR(15) NOT NULL,
      HORADEDX DECIMAL(5,2) NOT NULL, HORAFECX DATE NOT NULL, HORADESX VARCHAR(255),
      REGUSRXX VARCHAR(20), REGFECXX DATE, REGHORXX TIME, REGUSRMX VARCHAR(20), REGFECMX DATE, REGHORMX TIME,
      REGESTXX ENUM('', 'ACTIVO', 'INACTIVO') DEFAULT 'ACTIVO',
      REGSTAMP TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
      CONSTRAINT FK_BEATHORA_BEATPROY FOREIGN KEY (PROYIDXX) REFERENCES BEATPROY(PROYIDXX),
      CONSTRAINT FK_BEATHORA_BEATUSRS FOREIGN KEY (USRIDXXX) REFERENCES BEATUSRS(USRIDXXX)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Tabla de registro de horas de proyectos'",
  'BEATFACT' => "CREATE TABLE IF NOT EXISTS BEATFACT (
      FACTIDXX INT AUTO_INCREMENT PRIMARY KEY, FACTNUMX VARCHAR(30) NOT NULL UNIQUE, PROYIDXX INT NULL,
      FACTFECX DATE NOT NULL, FACTVENX DATE NOT NULL, CLIEIDXX VARCHAR(30), CLIENOMX VARCHAR(100) NOT NULL,
      CLIEMLXX VARCHAR(150), CLIEDIRX VARCHAR(150), CLIECELL VARCHAR(20),
      FACTSUBT DECIMAL(12,2) NOT NULL, FACTIVAX DECIMAL(12,2) DEFAULT 0, FACTDESC DECIMAL(12,2) DEFAULT 0, FACTTOTA DECIMAL(12,2) NOT NULL,
      FACTESTX ENUM('BORRADOR', 'ENVIADA', 'PAGADA', 'ANULADA') DEFAULT 'BORRADOR',
      REGUSRXX VARCHAR(20), REGFECXX DATE, REGHORXX TIME, REGUSRMX VARCHAR(20), REGFECMX DATE, REGHORMX TIME,
      REGESTXX ENUM('', 'ACTIVO', 'INACTIVO') DEFAULT 'ACTIVO',
      REGSTAMP TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Tabla de facturas y presupuestos'",
  'BEATFACD' => "CREATE TABLE IF NOT EXISTS BEATFACD (
      FACDIDXX INT AUTO_INCREMENT PRIMARY KEY, FACTIDXX INT NOT NULL, ITEMDESX VARCHAR(255) NOT NULL,
      ITEMCANT DECIMAL(10,2) NOT NULL, ITEMVALU DECIMAL(12,2) NOT NULL, ITEMTOTA DECIMAL(12,2) NOT NULL,
      REGUSRXX VARCHAR(20), REGFECXX DATE, REGHORXX TIME, REGUSRMX VARCHAR(20), REGFECMX DATE, REGHORMX TIME,
      REGESTXX ENUM('', 'ACTIVO', 'INACTIVO') DEFAULT 'ACTIVO',
      REGSTAMP TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
      CONSTRAINT FK_BEATFACD_BEATFACT FOREIGN KEY (FACTIDXX) REFERENCES BEATFACT(FACTIDXX) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Tabla de detalles de facturas/presupuestos'",
  'BEATFASE' => "CREATE TABLE IF NOT EXISTS BEATFASE (
      FASEIDXX INT AUTO_INCREMENT PRIMARY KEY, PROYIDXX INT NOT NULL, FASENOMX VARCHAR(100) NOT NULL,
      FASEFECI DATE NOT NULL, FASEFECT DATE NOT NULL, FASEHITO ENUM('SI', 'NO') DEFAULT 'NO',
      REGUSRXX VARCHAR(20), REGFECXX DATE, REGHORXX TIME, REGUSRMX VARCHAR(20), REGFECMX DATE, REGHORMX TIME,
      REGESTXX ENUM('', 'ACTIVO', 'INACTIVO') DEFAULT 'ACTIVO',
      REGSTAMP TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
      CONSTRAINT FK_BEATFASE_BEATPROY FOREIGN KEY (PROYIDXX) REFERENCES BEATPROY(PROYIDXX) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Tabla de fases y tareas de proyectos'",
];

$migrations = app_migrations();

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
  $status = [];
  foreach ($migrations as $id => $m) {
    $status[] = ['id' => $id, 'description' => $m['description'], 'applied' => migration_table_exists($pdo, $m['table'])];
  }
  api_response(true, ['migrations' => $status, 'pending' => count(array_filter($status, fn($s) => !$s['applied']))]);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
  api_response(false, 'Método no permitido', 405);
}

$results = [];
$failed = 0;
foreach ($legacy as $table => $sql) {
  if (migration_table_exists($pdo, $table))
    continue;
  try {
    $pdo->exec($sql);
    $results[] = "Tabla $table creada.";
  } catch (Exception $e) {
    $failed++;
    api_log_error("Migration $table", $e);
    $results[] = "No se pudo crear $table.";
  }
}
foreach ($migrations as $id => $m) {
  if (migration_table_exists($pdo, $m['table'])) {
    $results[] = "{$m['description']}: ya aplicada.";
    continue;
  }
  try {
    $pdo->exec($m['sql']);
    $ok = migration_table_exists($pdo, $m['table']);
    $results[] = $ok ? "{$m['description']}: aplicada." : "{$m['description']}: no se pudo verificar.";
    if (!$ok)
      $failed++;
  } catch (Exception $e) {
    $failed++;
    api_log_error("Migration $id", $e);
    $results[] = "{$m['description']}: error al aplicar.";
  }
}

api_response($failed === 0, $failed === 0 ? ['details' => $results] : 'Algunas migraciones fallaron. Revisa el log del servidor.', $failed === 0 ? 200 : 500, ['details' => $results]);

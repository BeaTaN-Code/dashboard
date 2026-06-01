<?php
// dashboard/api/migrate.php
// Script temporal para ejecutar la migración de base de datos desde la web
header('Content-Type: application/json');
session_start();
require_once __DIR__ . '/../config/db.php';

// Verificar autenticación y que sea administrador
if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'No autorizado']);
    exit;
}

if (!isset($_SESSION['is_admin']) || $_SESSION['is_admin'] !== true) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Acceso denegado. Se requiere administrador']);
    exit;
}

$pdo = getDbConnection();
if (!$pdo) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Error de conexion a la base de datos']);
    exit;
}

$queries = [
    "CREATE TABLE IF NOT EXISTS BEATPROY (
      PROYIDXX INT AUTO_INCREMENT PRIMARY KEY COMMENT 'ID del proyecto',
      PROYNOMX VARCHAR(100) NOT NULL COMMENT 'Nombre del proyecto',
      PROYDESX VARCHAR(255) COMMENT 'Descripción del proyecto',
      PROYESTX ENUM('PLANIFICACION', 'DESARROLLO', 'TERMINADO', 'INACTIVO') DEFAULT 'PLANIFICACION' COMMENT 'Estado del proyecto',
    
      REGUSRXX VARCHAR(20) COMMENT 'Usuario que crea el registro',
      REGFECXX DATE COMMENT 'Fecha de creación',
      REGHORXX TIME COMMENT 'Hora de creación',
      REGUSRMX VARCHAR(20) COMMENT 'Usuario que modifica',
      REGFECMX DATE COMMENT 'Fecha de modificación',
      REGHORMX TIME COMMENT 'Hora de modificación',
      REGESTXX ENUM('', 'ACTIVO', 'INACTIVO') DEFAULT 'ACTIVO' COMMENT 'Estado del registro (ACTIVO o INACTIVO)',
      REGSTAMP TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT 'Marca de tiempo del sistema'
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Tabla de proyectos de BeaTaN';",

    "CREATE TABLE IF NOT EXISTS BEATHORA (
      HORAIDXX INT AUTO_INCREMENT PRIMARY KEY COMMENT 'ID del registro de horas',
      PROYIDXX INT NOT NULL COMMENT 'ID del proyecto asociado',
      USRIDXXX VARCHAR(15) NOT NULL COMMENT 'Cédula del usuario',
      HORADEDX DECIMAL(5,2) NOT NULL COMMENT 'Horas dedicadas',
      HORAFECX DATE NOT NULL COMMENT 'Fecha de dedicación',
      HORADESX VARCHAR(255) COMMENT 'Descripción de tareas realizadas',
    
      REGUSRXX VARCHAR(20) COMMENT 'Usuario que crea el registro',
      REGFECXX DATE COMMENT 'Fecha de creación',
      REGHORXX TIME COMMENT 'Hora de creación',
      REGUSRMX VARCHAR(20) COMMENT 'Usuario que modifica',
      REGFECMX DATE COMMENT 'Fecha de modificación',
      REGHORMX TIME COMMENT 'Hora de modificación',
      REGESTXX ENUM('', 'ACTIVO', 'INACTIVO') DEFAULT 'ACTIVO' COMMENT 'Estado del registro (ACTIVO o INACTIVO)',
      REGSTAMP TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT 'Marca de tiempo del sistema',
    
      CONSTRAINT FK_BEATHORA_BEATPROY FOREIGN KEY (PROYIDXX) REFERENCES BEATPROY(PROYIDXX),
      CONSTRAINT FK_BEATHORA_BEATUSRS FOREIGN KEY (USRIDXXX) REFERENCES BEATUSRS(USRIDXXX)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Tabla de registro de horas de proyectos';",

    "CREATE TABLE IF NOT EXISTS BEATFACT (
      FACTIDXX INT AUTO_INCREMENT PRIMARY KEY COMMENT 'ID de la factura',
      FACTNUMX VARCHAR(30) NOT NULL UNIQUE COMMENT 'Número de factura/presupuesto',
      PROYIDXX INT NULL COMMENT 'Proyecto asociado (opcional)',
      FACTFECX DATE NOT NULL COMMENT 'Fecha de emisión',
      FACTVENX DATE NOT NULL COMMENT 'Fecha de vencimiento',
      CLIEIDXX VARCHAR(30) COMMENT 'Identificación del cliente (NIT/RUT/CC)',
      CLIENOMX VARCHAR(100) NOT NULL COMMENT 'Nombre del cliente',
      CLIEMLXX VARCHAR(150) COMMENT 'Email del cliente',
      CLIEDIRX VARCHAR(150) COMMENT 'Dirección del cliente',
      CLIECELL VARCHAR(20) COMMENT 'Celular del cliente',
      FACTSUBT DECIMAL(12,2) NOT NULL COMMENT 'Subtotal',
      FACTIVAX DECIMAL(12,2) DEFAULT 0 COMMENT 'IVA o impuestos %',
      FACTDESC DECIMAL(12,2) DEFAULT 0 COMMENT 'Descuento',
      FACTTOTA DECIMAL(12,2) NOT NULL COMMENT 'Total',
      FACTESTX ENUM('BORRADOR', 'ENVIADA', 'PAGADA', 'ANULADA') DEFAULT 'BORRADOR' COMMENT 'Estado de la factura',
    
      REGUSRXX VARCHAR(20) COMMENT 'Usuario que crea el registro',
      REGFECXX DATE COMMENT 'Fecha de creación',
      REGHORXX TIME COMMENT 'Hora de creación',
      REGUSRMX VARCHAR(20) COMMENT 'Usuario que modifica',
      REGFECMX DATE COMMENT 'Fecha de modificación',
      REGHORMX TIME COMMENT 'Hora de modificación',
      REGESTXX ENUM('', 'ACTIVO', 'INACTIVO') DEFAULT 'ACTIVO' COMMENT 'Estado del registro (ACTIVO o INACTIVO)',
      REGSTAMP TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT 'Marca de tiempo del sistema'
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Tabla de facturas y presupuestos';",

    "CREATE TABLE IF NOT EXISTS BEATFACD (
      FACDIDXX INT AUTO_INCREMENT PRIMARY KEY COMMENT 'ID del detalle de factura',
      FACTIDXX INT NOT NULL COMMENT 'ID de la factura asociada',
      ITEMDESX VARCHAR(255) NOT NULL COMMENT 'Descripción del item',
      ITEMCANT DECIMAL(10,2) NOT NULL COMMENT 'Cantidad',
      ITEMVALU DECIMAL(12,2) NOT NULL COMMENT 'Valor unitario',
      ITEMTOTA DECIMAL(12,2) NOT NULL COMMENT 'Total del item',
    
      REGUSRXX VARCHAR(20) COMMENT 'Usuario que crea el registro',
      REGFECXX DATE COMMENT 'Fecha de creación',
      REGHORXX TIME COMMENT 'Hora de creación',
      REGUSRMX VARCHAR(20) COMMENT 'Usuario que modifica',
      REGFECMX DATE COMMENT 'Fecha de modificación',
      REGHORMX TIME COMMENT 'Hora de modificación',
      REGESTXX ENUM('', 'ACTIVO', 'INACTIVO') DEFAULT 'ACTIVO' COMMENT 'Estado del registro (ACTIVO o INACTIVO)',
      REGSTAMP TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT 'Marca de tiempo del sistema',
    
      CONSTRAINT FK_BEATFACD_BEATFACT FOREIGN KEY (FACTIDXX) REFERENCES BEATFACT(FACTIDXX) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Tabla de detalles de facturas/presupuestos';",

    "CREATE TABLE IF NOT EXISTS BEATFASE (
      FASEIDXX INT AUTO_INCREMENT PRIMARY KEY COMMENT 'ID de la fase',
      PROYIDXX INT NOT NULL COMMENT 'ID del proyecto asociado',
      FASENOMX VARCHAR(100) NOT NULL COMMENT 'Nombre de la fase/tarea',
      FASEFECI DATE NOT NULL COMMENT 'Fecha de inicio de la fase',
      FASEFECT DATE NOT NULL COMMENT 'Fecha de término de la fase',
      FASEHITO ENUM('SI', 'NO') DEFAULT 'NO' COMMENT 'Indica si es un hito (SI/NO)',
    
      REGUSRXX VARCHAR(20) COMMENT 'Usuario que crea el registro',
      REGFECXX DATE COMMENT 'Fecha de creación',
      REGHORXX TIME COMMENT 'Hora de creación',
      REGUSRMX VARCHAR(20) COMMENT 'Usuario que modifica',
      REGFECMX DATE COMMENT 'Fecha de modificación',
      REGHORMX TIME COMMENT 'Hora de modificación',
      REGESTXX ENUM('', 'ACTIVO', 'INACTIVO') DEFAULT 'ACTIVO' COMMENT 'Estado del registro (ACTIVO o INACTIVO)',
      REGSTAMP TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT 'Marca de tiempo del sistema',
    
      CONSTRAINT FK_BEATFASE_BEATPROY FOREIGN KEY (PROYIDXX) REFERENCES BEATPROY(PROYIDXX) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Tabla de fases y tareas de proyectos';"
];

$results = [];
foreach ($queries as $i => $q) {
    try {
        $pdo->exec($q);
        $results[] = "Query " . ($i + 1) . " ejecutada con éxito.";
    } catch (Exception $e) {
        $results[] = "Error en Query " . ($i + 1) . ": " . $e->getMessage();
    }
}

echo json_encode([
    'success' => true,
    'message' => 'Proceso de migración ejecutado',
    'details' => $results
]);

<?php
// dashboard/api/invoices.php
// API para gestionar facturas y presupuestos de BeaTaN (solo admin)

require_once __DIR__ . '/../config/api_helper.php';
require_once __DIR__ . '/../lib/validation.php';
api_bootstrap();

// Verificar autenticacion
if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
  http_response_code(401);
  echo json_encode(['success' => false, 'error' => 'No autorizado']);
  exit;
}

// Verificar que sea admin
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

$method = $_SERVER['REQUEST_METHOD'];
$adminId = $_SESSION['user_id'];

switch ($method) {
  case 'GET':
    getInvoices($pdo);
    break;
  case 'POST':
    createInvoice($pdo, $adminId);
    break;
  case 'PUT':
    updateInvoice($pdo, $adminId);
    break;
  case 'DELETE':
    deleteInvoice($pdo, $adminId);
    break;
  default:
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Metodo no permitido']);
}

function getInvoices($pdo) {
  $id = intval($_GET['id'] ?? 0);

  try {
    if ($id > 0) {
      // Obtener una factura específica con sus detalles
      $stmt = $pdo->prepare("
        SELECT f.*, p.PROYNOMX 
        FROM BEATFACT f 
        LEFT JOIN BEATPROY p ON f.PROYIDXX = p.PROYIDXX 
        WHERE f.FACTIDXX = :id AND f.REGESTXX = 'ACTIVO'
      ");
      $stmt->execute([':id' => $id]);
      $invoice = $stmt->fetch();

      if (!$invoice) {
        http_response_code(404);
        echo json_encode(['success' => false, 'error' => 'Factura no encontrada']);
        return;
      }

      // Obtener los detalles
      $stmtD = $pdo->prepare("SELECT * FROM BEATFACD WHERE FACTIDXX = :id AND REGESTXX = 'ACTIVO'");
      $stmtD->execute([':id' => $id]);
      $items = $stmtD->fetchAll();

      echo json_encode([
        'success' => true,
        'data' => $invoice,
        'items' => $items
      ]);
    } else {
      // Obtener todas las facturas
      $stmt = $pdo->query("
        SELECT f.*, p.PROYNOMX 
        FROM BEATFACT f 
        LEFT JOIN BEATPROY p ON f.PROYIDXX = p.PROYIDXX 
        WHERE f.REGESTXX = 'ACTIVO' 
        ORDER BY f.FACTIDXX DESC
      ");
      $invoices = $stmt->fetchAll();
      echo json_encode(['success' => true, 'data' => $invoices]);
    }
  } catch (Exception $e) {
    api_log_error(basename(__FILE__), $e);
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Error al obtener facturas']);
  }
}

function createInvoice($pdo, $adminId) {
  $input = json_decode(file_get_contents('php://input'), true);

  $numero      = trim($input['factnumx'] ?? '');
  $proyectoId  = !empty($input['proyidxx']) ? intval($input['proyidxx']) : null;
  $fechaEmis   = trim($input['factfecx'] ?? '');
  $fechaVenc   = trim($input['factvenx'] ?? '');
  $clienteId   = trim($input['clieidxx'] ?? '');
  $clienteNom  = trim($input['clienomx'] ?? '');
  $clienteMail = trim($input['cliemlxx'] ?? '');
  $clienteDir  = trim($input['cliedirx'] ?? '');
  $clienteCel  = trim($input['cliecell'] ?? '');
  $subtotal    = floatval($input['factsubt'] ?? 0);
  $iva         = floatval($input['factivax'] ?? 0);
  $descuento   = floatval($input['factdesc'] ?? 0);
  $total       = floatval($input['facttota'] ?? 0);
  $estado      = trim($input['factestx'] ?? 'BORRADOR');
  $items       = $input['items'] ?? [];

  // Los totales se recalculan en el servidor (antes se confiaba en los enviados por el navegador).
  $subtotalCents = 0;
  if (is_array($items)) {
    foreach ($items as $it) {
      $c = money_to_cents($it['itemcant'] ?? 0);
      $v = money_to_cents($it['itemvalu'] ?? 0);
      if ($c !== null && $v !== null && $c > 0 && $v > 0) {
        $subtotalCents += (int) round($c * $v / 100);
      }
    }
  }
  if ($iva < 0 || $iva > 100 || $descuento < 0) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'El IVA debe estar entre 0 y 100 % y el descuento no puede ser negativo']);
    return;
  }
  $ivaCents = (int) round($subtotalCents * $iva / 100);
  $descCents = money_to_cents($descuento) ?? 0;
  $totalCents = $subtotalCents + $ivaCents - $descCents;
  $subtotal = cents_to_decimal($subtotalCents);
  $descuento = cents_to_decimal($descCents);
  $total = $totalCents / 100;
  $totalDecimal = cents_to_decimal($totalCents);

  if (!valid_date($fechaEmis) || !valid_date($fechaVenc) || $fechaVenc < $fechaEmis) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Revisa las fechas: el vencimiento no puede ser anterior a la emisión']);
    return;
  }
  if (!in_array($estado, ['BORRADOR', 'ENVIADA', 'PAGADA', 'ANULADA'], true)) {
    $estado = 'BORRADOR';
  }

  if (empty($numero) || empty($fechaEmis) || empty($fechaVenc) || empty($clienteNom) || $total <= 0 || empty($items)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Datos de factura incompletos o inválidos']);
    return;
  }

  try {
    $pdo->beginTransaction();

    // Validar número duplicado
    $stmtCheck = $pdo->prepare("SELECT FACTIDXX FROM BEATFACT WHERE FACTNUMX = :num AND REGESTXX = 'ACTIVO'");
    $stmtCheck->execute([':num' => $numero]);
    if ($stmtCheck->fetch()) {
      http_response_code(400);
      echo json_encode(['success' => false, 'error' => 'El número de factura ya existe']);
      $pdo->rollBack();
      return;
    }

    // Insertar factura
    $stmt = $pdo->prepare("
      INSERT INTO BEATFACT (
        FACTNUMX, PROYIDXX, FACTFECX, FACTVENX, CLIEIDXX, CLIENOMX, CLIEMLXX, CLIEDIRX, CLIECELL, 
        FACTSUBT, FACTIVAX, FACTDESC, FACTTOTA, FACTESTX, 
        REGUSRXX, REGFECXX, REGHORXX, 
        REGUSRMX, REGFECMX, REGHORMX, 
        REGESTXX, REGSTAMP
      ) VALUES (
        :numero, :proyectoId, :fechaEmis, :fechaVenc, :clienteId, :clienteNom, :clienteMail, :clienteDir, :clienteCel,
        :subtotal, :iva, :descuento, :total, :estado, 
        :adminId, CURDATE(), CURTIME(), 
        :adminId, CURDATE(), CURTIME(), 
        'ACTIVO', CURRENT_TIMESTAMP
      )
    ");
    $stmt->execute([
      ':numero'      => $numero,
      ':proyectoId'  => $proyectoId,
      ':fechaEmis'   => $fechaEmis,
      ':fechaVenc'   => $fechaVenc,
      ':clienteId'   => $clienteId,
      ':clienteNom'  => $clienteNom,
      ':clienteMail' => $clienteMail,
      ':clienteDir'  => $clienteDir,
      ':clienteCel'  => $clienteCel,
      ':subtotal'    => $subtotal,
      ':iva'         => $iva,
      ':descuento'   => $descuento,
      ':total'       => $totalDecimal,
      ':estado'      => $estado,
      ':adminId'     => $adminId
    ]);

    $invoiceId = $pdo->lastInsertId();

    // Insertar items
    $stmtItem = $pdo->prepare("
      INSERT INTO BEATFACD (
        FACTIDXX, ITEMDESX, ITEMCANT, ITEMVALU, ITEMTOTA, 
        REGUSRXX, REGFECXX, REGHORXX, 
        REGUSRMX, REGFECMX, REGHORMX, 
        REGESTXX, REGSTAMP
      ) VALUES (
        :invoiceId, :descripcion, :cantidad, :valorUnit, :totalItem, 
        :adminId, CURDATE(), CURTIME(), 
        :adminId, CURDATE(), CURTIME(), 
        'ACTIVO', CURRENT_TIMESTAMP
      )
    ");

    foreach ($items as $item) {
      $desc = trim($item['itemdesx'] ?? '');
      $cant = floatval($item['itemcant'] ?? 0);
      $valu = floatval($item['itemvalu'] ?? 0);
      $tota = cents_to_decimal((int) round((money_to_cents($cant) ?? 0) * (money_to_cents($valu) ?? 0) / 100));

      if (empty($desc) || $cant <= 0 || $valu <= 0) {
        throw new Exception("Campos de item inválidos en el detalle");
      }

      $stmtItem->execute([
        ':invoiceId'    => $invoiceId,
        ':descripcion'  => $desc,
        ':cantidad'     => $cant,
        ':valorUnit'    => $valu,
        ':totalItem'    => $tota,
        ':adminId'      => $adminId
      ]);
    }

    $pdo->commit();
    echo json_encode(['success' => true, 'message' => 'Factura creada exitosamente', 'id' => $invoiceId]);
  } catch (Exception $e) {
    if ($pdo->inTransaction()) {
      $pdo->rollBack();
    }
    api_log_error(basename(__FILE__), $e);
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Error al crear factura']);
  }
}

function updateInvoice($pdo, $adminId) {
  $input = json_decode(file_get_contents('php://input'), true);
  $id     = intval($input['id'] ?? 0);
  $estado = trim($input['estado'] ?? '');

  if ($id <= 0 || !in_array($estado, ['BORRADOR', 'ENVIADA', 'PAGADA', 'ANULADA'], true)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'ID y estado son requeridos']);
    return;
  }

  try {
    $stmt = $pdo->prepare("
      UPDATE BEATFACT 
      SET FACTESTX = :estado, 
          REGUSRMX = :adminId, 
          REGFECMX = CURDATE(), 
          REGHORMX = CURTIME()
      WHERE FACTIDXX = :id AND REGESTXX = 'ACTIVO'
    ");
    $stmt->execute([
      ':estado' => $estado,
      ':adminId' => $adminId,
      ':id' => $id
    ]);
    echo json_encode(['success' => true, 'message' => 'Estado de factura actualizado exitosamente']);
  } catch (Exception $e) {
    api_log_error(basename(__FILE__), $e);
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Error al actualizar factura']);
  }
}

function deleteInvoice($pdo, $adminId) {
  $id = intval($_GET['id'] ?? 0);

  if ($id <= 0) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'ID de factura inválido']);
    return;
  }

  try {
    $pdo->beginTransaction();

    // Eliminar lógicamente la factura
    $stmtF = $pdo->prepare("
      UPDATE BEATFACT 
      SET REGESTXX = 'INACTIVO', 
          REGUSRMX = :adminId, 
          REGFECMX = CURDATE(), 
          REGHORMX = CURTIME()
      WHERE FACTIDXX = :id
    ");
    $stmtF->execute([
      ':adminId' => $adminId,
      ':id' => $id
    ]);

    // Eliminar lógicamente los detalles asociados
    $stmtD = $pdo->prepare("
      UPDATE BEATFACD 
      SET REGESTXX = 'INACTIVO', 
          REGUSRMX = :adminId, 
          REGFECMX = CURDATE(), 
          REGHORMX = CURTIME()
      WHERE FACTIDXX = :id
    ");
    $stmtD->execute([
      ':adminId' => $adminId,
      ':id' => $id
    ]);

    $pdo->commit();
    echo json_encode(['success' => true, 'message' => 'Factura eliminada exitosamente']);
  } catch (Exception $e) {
    if ($pdo->inTransaction()) {
      $pdo->rollBack();
    }
    api_log_error(basename(__FILE__), $e);
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Error al eliminar factura']);
  }
}

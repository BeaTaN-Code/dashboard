<?php
// dashboard/api/ai_chat.php
// API de Inteligencia Artificial Financiera Generativa Real para BeaTaN

header('Content-Type: application/json');
date_default_timezone_set('America/Bogota');
session_start();
require_once __DIR__ . '/../config/db.php';

// Polyfill para compatibilidad con PHP < 8.0
if (!function_exists('str_contains')) {
  function str_contains($haystack, $needle) {
    return $needle !== '' && strpos($haystack, $needle) !== false;
  }
}

$envPaths = [__DIR__ . '/../../.env', __DIR__ . '/../.env', '.env'];
$env = [];
foreach ($envPaths as $path) {
  if (file_exists($path)) {
    $env = parse_env($path);
    break;
  }
}

if (!isset($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
  http_response_code(401);
  echo json_encode(['success' => false, 'error' => 'No autorizado']);
  exit;
}

$pdo = getDbConnection();
if (!$pdo) {
  http_response_code(500);
  echo json_encode(['success' => false, 'error' => 'Error de conexión a la base de datos']);
  exit;
}

$userId = $_SESSION['user_id'];
$isAdmin = $_SESSION['is_admin'] ?? false;
$input = json_decode(file_get_contents('php://input'), true);

$message = trim($input['message'] ?? '');
$tipgasxx = strtoupper($input['tipgasxx'] ?? 'PERSONAL');
$chatHistory = $input['history'] ?? [];
$userApiKey = trim($input['apiKey'] ?? '');

if (empty($message)) {
  http_response_code(400);
  echo json_encode(['success' => false, 'error' => 'El mensaje no puede estar vacío']);
  exit;
}

try {
  // 1. RECOLECTAR TODA LA INFORMACIÓN FINANCIERA DEL USUARIO EN LA BASE DE DATOS
  $stmtUser = $pdo->prepare("SELECT USRNMEXX, USRMAILX FROM BEATUSRS WHERE USRIDXXX = :userId LIMIT 1");
  $stmtUser->execute([':userId' => $userId]);
  $userData = $stmtUser->fetch();
  $userName = $userData['USRNMEXX'] ?? 'Usuario';

  // Balance general acumulado
  $stmtBalance = $pdo->prepare("SELECT 
      COALESCE(SUM(CASE WHEN MONTGASX > 0 THEN MONTGASX ELSE 0 END), 0) as income,
      COALESCE(SUM(CASE WHEN MONTGASX < 0 THEN ABS(MONTGASX) ELSE 0 END), 0) as expense
      FROM FINANCIX 
      WHERE REGESTXX = 'ACTIVO' AND USRIDXXX = :userId AND TIPGASXX = :tipgasxx");
  $stmtBalance->execute([':userId' => $userId, ':tipgasxx' => $tipgasxx]);
  $bal = $stmtBalance->fetch();
  $totalIncome = floatval($bal['income']);
  $totalExpense = floatval($bal['expense']);
  $totalBalance = $totalIncome - $totalExpense;

  // Desglose por mes (Enero a Diciembre)
  $stmtMonths = $pdo->prepare("SELECT 
      DATE_FORMAT(FINCFECX, '%Y-%m') as mes,
      SUM(CASE WHEN MONTGASX > 0 THEN MONTGASX ELSE 0 END) as ingresos,
      SUM(CASE WHEN MONTGASX < 0 THEN ABS(MONTGASX) ELSE 0 END) as gastos,
      SUM(MONTGASX) as balance_mes,
      COUNT(*) as num_movimientos
      FROM FINANCIX
      WHERE REGESTXX = 'ACTIVO' AND USRIDXXX = :userId AND TIPGASXX = :tipgasxx
      GROUP BY mes ORDER BY mes ASC");
  $stmtMonths->execute([':userId' => $userId, ':tipgasxx' => $tipgasxx]);
  $monthlyBreakdown = $stmtMonths->fetchAll();

  $monthsSummary = [];
  foreach ($monthlyBreakdown as $m) {
    $monthsSummary[] = "- Mes {$m['mes']}: Ingresos = $" . number_format($m['ingresos'], 2, '.', ',') . " | Gastos = $" . number_format($m['gastos'], 2, '.', ',') . " | Saldo Neto = $" . number_format($m['balance_mes'], 2, '.', ',') . " ({$m['num_movimientos']} movimientos)";
  }
  $monthsSummaryText = !empty($monthsSummary) ? implode("\n", $monthsSummary) : "Sin movimientos agrupados por mes.";

  // Desglose por categoría
  $stmtCat = $pdo->prepare("SELECT 
      CATGASXX,
      SUM(CASE WHEN MONTGASX > 0 THEN MONTGASX ELSE 0 END) as ingresos,
      SUM(CASE WHEN MONTGASX < 0 THEN ABS(MONTGASX) ELSE 0 END) as gastos,
      COUNT(*) as total_movs
      FROM FINANCIX
      WHERE REGESTXX = 'ACTIVO' AND USRIDXXX = :userId AND TIPGASXX = :tipgasxx
      GROUP BY CATGASXX ORDER BY gastos DESC");
  $stmtCat->execute([':userId' => $userId, ':tipgasxx' => $tipgasxx]);
  $categories = $stmtCat->fetchAll();

  $catSummary = [];
  foreach ($categories as $c) {
    $catSummary[] = "- Categoría '{$c['CATGASXX']}': Gastos = $" . number_format($c['gastos'], 2, '.', ',') . " | Ingresos = $" . number_format($c['ingresos'], 2, '.', ',') . " ({$c['total_movs']} movimientos)";
  }
  $catSummaryText = !empty($catSummary) ? implode("\n", $catSummary) : "Sin categorías.";

  // Deudas del usuario (DEUDASIX)
  $stmtDeudas = $pdo->prepare("SELECT TIPDEUDX, TIPPERXX, DESDEUDX, MONDEUDX, MONCUOTX, PAGCUOTX, NUMCUOTX, REGESTXX 
      FROM DEUDASIX 
      WHERE USRIDXXX = :userId AND REGESTXX = 'ACTIVO'");
  $stmtDeudas->execute([':userId' => $userId]);
  $deudas = $stmtDeudas->fetchAll();

  $deudasSummary = [];
  foreach ($deudas as $d) {
    $deudasSummary[] = "- Deuda '{$d['DESDEUDX']}': Total = $" . number_format($d['MONDEUDX'], 2, '.', ',') . " | Cuota = $" . number_format($d['MONCUOTX'], 2, '.', ',') . " | Avance = {$d['PAGCUOTX']}/{$d['NUMCUOTX']} cuotas ({$d['TIPDEUDX']})";
  }
  $deudasSummaryText = !empty($deudasSummary) ? implode("\n", $deudasSummary) : "No hay deudas activas registradas.";

  // Últimas 50 transacciones detalladas
  $stmtTx = $pdo->prepare("SELECT FINCFECX, CATGASXX, DESGASXX, MONTGASX 
      FROM FINANCIX
      WHERE REGESTXX = 'ACTIVO' AND USRIDXXX = :userId AND TIPGASXX = :tipgasxx
      ORDER BY FINCFECX DESC LIMIT 50");
  $stmtTx->execute([':userId' => $userId, ':tipgasxx' => $tipgasxx]);
  $transactions = $stmtTx->fetchAll();

  $txList = [];
  foreach ($transactions as $t) {
    $tipo = $t['MONTGASX'] < 0 ? 'GASTO' : 'INGRESO';
    $montoFormatted = "$" . number_format(abs($t['MONTGASX']), 2, '.', ',');
    $txList[] = "• [{$t['FINCFECX']}] {$tipo}: {$montoFormatted} | Categoría: '{$t['CATGASXX']}' | Concepto: '{$t['DESGASXX']}'";
  }
  $txListText = !empty($txList) ? implode("\n", $txList) : "Sin transacciones registradas.";

  // 2. CONSTRUIR EL CONTEXTO COMPLETO PARA EL MODELO DE LENGUAJE GENERATIVO
  $systemPrompt = "Eres 'BeaTaN AI', un asistente de Inteligencia Artificial Financiera verdaderamente avanzado, empático, preciso y profesional creado exclusivamente para guiar a '{$userName}'.

TIENES ACCESO TOTAL A LA BASE DE DATOS FINANCIERA EN TIEMPO REAL DE '{$userName}' PARA LA CUENTA [{$tipgasxx}]:

=== RESUMEN GENERAL HASTA HOY ===
- Usuario: {$userName}
- Tipo de Cuenta: {$tipgasxx}
- Balance Total Disponible: $" . number_format($totalBalance, 2, '.', ',') . " COP
- Ingresos Totales Históricos: $" . number_format($totalIncome, 2, '.', ',') . " COP
- Gastos Totales Históricos: $" . number_format($totalExpense, 2, '.', ',') . " COP

=== HISTORIAL DETALLADO POR MESES ===
{$monthsSummaryText}

=== DESGLOSE ACUMULADO POR CATEGORÍAS ===
{$catSummaryText}

=== DEUDAS Y COMPROMISOS ACTIVOS ===
{$deudasSummaryText}

=== ÚLTIMOS 50 MOVIMIENTOS REGISTRADOS EN BD ===
{$txListText}

INSTRUCCIONES DE COMPORTAMIENTO:
1. Responde de manera 100% natural, fluida, inteligente y personalizada en español. NUNCA uses plantillas repetitivas.
2. Utiliza los datos exactos del informe financiero anterior para calcular valores o responder preguntas.
3. TIENES CAPACIDAD DE EJECUTAR ACCIONES REALES EN LA BASE DE DATOS usandos tus herramientas (tools):
   - Si el usuario pide registrar un gasto o ingreso (\"Registra un gasto de 50000 en comida\", \"Añade ingreso de 2000000\"), EJECUTA LA HERRAMIENTA `add_transaction`.
   - Si el usuario pide crear una deuda o préstamo (\"Registra una deuda de 300000 a Juan\"), EJECUTA LA HERRAMIENTA `create_debt`.
4. Usa formato Markdown limpio con negritas, listas y emojis para una lectura agradable.";

  // 3. OBTENER LLAVE DE API (DE ENTRADA DE USUARIO, DE .ENV O LLAVE INCLUIDA)
  $effectiveApiKey = !empty($userApiKey) ? $userApiKey : ($env['GEMINI_API_KEY'] ?? getenv('GEMINI_API_KEY') ?? '');

  // Intentar generar respuesta con la API de Gemini si hay llave disponible
  if (!empty($effectiveApiKey)) {
    $aiResult = callGeminiApiWithTools($systemPrompt, $chatHistory, $message, $effectiveApiKey, $pdo, $userId, $tipgasxx);
    if ($aiResult && !empty($aiResult['reply'])) {
      echo json_encode([
        'success' => true,
        'reply' => $aiResult['reply'],
        'actionExecuted' => $aiResult['actionExecuted'] ?? false,
        'provider' => 'gemini'
      ]);
      exit;
    }
  }

  // Devolver el contexto completo para que el cliente JS ejecute la llamada LLM o solicite la API Key
  echo json_encode([
    'success' => true,
    'systemPrompt' => $systemPrompt,
    'hasKey' => !empty($effectiveApiKey),
    'requiresKeyPrompt' => empty($effectiveApiKey)
  ]);

} catch (Throwable $e) {
  error_log("AI Chat Exception: " . $e->getMessage());
  http_response_code(500);
  echo json_encode(['success' => false, 'error' => 'Error al recopilar datos financieros: ' . $e->getMessage()]);
  exit;
}

/**
 * Realiza la petición a la API de Google Gemini con soporte de Herramientas (Function Calling)
 */
function callGeminiApiWithTools($systemPrompt, $chatHistory, $userMessage, $apiKey, $pdo, $userId, $currentTipgasxx) {
  $cleanKey = trim(str_replace(['"', "'"], '', $apiKey));
  if (empty($cleanKey)) return null;

  $targetModel = 'gemini-3.6-flash';
  $listUrl = "https://generativelanguage.googleapis.com/v1beta/models?key=" . urlencode($cleanKey);
  $listRaw = executeCurl($listUrl);
  if ($listRaw) {
    $listJson = json_decode($listRaw, true);
    if (!empty($listJson['models']) && is_array($listJson['models'])) {
      $preferredModel = null;
      foreach ($listJson['models'] as $m) {
        if (isset($m['supportedGenerationMethods']) && in_array('generateContent', $m['supportedGenerationMethods'])) {
          $modelName = str_replace('models/', '', $m['name']);
          if (strpos($modelName, '3.6-flash') !== false) {
            $preferredModel = $modelName;
            break;
          } elseif (strpos($modelName, '3.6') !== false && !$preferredModel) {
            $preferredModel = $modelName;
          } elseif (strpos($modelName, 'flash') !== false && !$preferredModel) {
            $preferredModel = $modelName;
          }
        }
      }
      if ($preferredModel) {
        $targetModel = $preferredModel;
      }
    }
  }

  $url = "https://generativelanguage.googleapis.com/v1beta/models/{$targetModel}:generateContent?key=" . urlencode($cleanKey);

  $contents = [];
  if (is_array($chatHistory)) {
    foreach ($chatHistory as $h) {
      if (isset($h['role'], $h['content']) && in_array($h['role'], ['user', 'assistant'])) {
        $contents[] = [
          'role' => ($h['role'] === 'user') ? 'user' : 'model',
          'parts' => [['text' => $h['content']]]
        ];
      }
    }
  }

  $contents[] = [
    'role' => 'user',
    'parts' => [['text' => $userMessage]]
  ];

  // Declaración de Herramientas (Tools)
  $tools = [
    [
      'functionDeclarations' => [
        [
          'name' => 'add_transaction',
          'description' => 'Registra un movimiento financiero (ingreso o gasto) en la base de datos',
          'parameters' => [
            'type' => 'OBJECT',
            'properties' => [
              'tipo' => ['type' => 'STRING', 'description' => 'INGRESO o GASTO'],
              'monto' => ['type' => 'NUMBER', 'description' => 'Monto positivo en COP'],
              'categoria' => ['type' => 'STRING', 'description' => 'Categoría del movimiento'],
              'descripcion' => ['type' => 'STRING', 'description' => 'Descripción o concepto'],
              'tipgasxx' => ['type' => 'STRING', 'description' => 'BEATAN o PERSONAL (opcional, por defecto según contexto)']
            ],
            'required' => ['tipo', 'monto', 'categoria', 'descripcion']
          ]
        ],
        [
          'name' => 'create_debt',
          'description' => 'Registra una nueva deuda o compromiso a favor o en contra',
          'parameters' => [
            'type' => 'OBJECT',
            'properties' => [
              'tipdeudx' => ['type' => 'STRING', 'description' => 'A FAVOR o EN CONTRA'],
              'tipperxx' => ['type' => 'STRING', 'description' => 'BEATAN o PERSONAL'],
              'descripcion' => ['type' => 'STRING', 'description' => 'Descripción o acreedor/deudor'],
              'monto_total' => ['type' => 'NUMBER', 'description' => 'Monto total de la deuda'],
              'monto_cuota' => ['type' => 'NUMBER', 'description' => 'Monto de la cuota'],
              'num_cuotas' => ['type' => 'INTEGER', 'description' => 'Número de cuotas']
            ],
            'required' => ['tipdeudx', 'tipperxx', 'descripcion', 'monto_total']
          ]
        ]
      ]
    ]
  ];

  $payload = [
    'systemInstruction' => [
      'parts' => [['text' => $systemPrompt]]
    ],
    'contents' => $contents,
    'tools' => $tools,
    'generationConfig' => [
      'temperature' => 0.2,
      'maxOutputTokens' => 4096
    ]
  ];

  $res = executeCurl($url, $payload);
  $actionExecuted = false;
  $replyText = '';

  if ($res) {
    $json = json_decode($res, true);
    $candidate = $json['candidates'][0] ?? null;
    if ($candidate && isset($candidate['content']['parts'])) {
      foreach ($candidate['content']['parts'] as $part) {
        if (isset($part['text'])) {
          $replyText .= $part['text'] . "\n";
        }
        if (isset($part['functionCall'])) {
          $fc = $part['functionCall'];
          $fnName = $fc['name'] ?? '';
          $args = $fc['args'] ?? [];

          if ($fnName === 'add_transaction') {
            $execRes = executeAiAddTransaction($pdo, $userId, $args, $currentTipgasxx);
            if ($execRes['success']) {
              $actionExecuted = true;
              $replyText .= "\n\n⚡ **[Acción Ejecutada por BeaTaN AI]**: " . $execRes['message'];
            }
          } elseif ($fnName === 'create_debt') {
            $execRes = executeAiCreateDebt($pdo, $userId, $args);
            if ($execRes['success']) {
              $actionExecuted = true;
              $replyText .= "\n\n⚡ **[Acción Ejecutada por BeaTaN AI]**: " . $execRes['message'];
            }
          }
        }
      }
    }
  }

  if (!empty($replyText)) {
    return [
      'reply' => trim($replyText),
      'actionExecuted' => $actionExecuted
    ];
  }

  return null;
}

/**
 * Ejecutores de BD para Herramientas de la IA
 */
function executeAiAddTransaction($pdo, $userId, $args, $defaultTipgas) {
  try {
    $tipo = strtoupper($args['tipo'] ?? 'GASTO');
    $monto = abs(floatval($args['monto'] ?? 0));
    if ($monto <= 0) return ['success' => false, 'message' => 'Monto inválido'];

    $montgasx = ($tipo === 'INGRESO') ? $monto : -$monto;
    $catgasxx = trim($args['categoria'] ?? 'Varios');
    $desgasxx = trim($args['descripcion'] ?? 'Registrado por BeaTaN AI');
    $tipgasxx = strtoupper($args['tipgasxx'] ?? $defaultTipgas);
    if (!in_array($tipgasxx, ['BEATAN', 'PERSONAL'])) $tipgasxx = 'PERSONAL';

    $today = date('Y-m-d');
    $nowTime = date('H:i:s');

    $stmt = $pdo->prepare("INSERT INTO FINANCIX 
      (USRIDXXX, TIPGASXX, MONTGASX, CATGASXX, DESGASXX, FINCFECX, REGUSRXX, REGFECXX, REGHORXX, REGESTXX)
      VALUES (:userId, :tipgasxx, :montgasx, :catgasxx, :desgasxx, :fincfecx, :regusr, :regfec, :reghor, 'ACTIVO')");
    
    $stmt->execute([
      ':userId' => $userId,
      ':tipgasxx' => $tipgasxx,
      ':montgasx' => $montgasx,
      ':catgasxx' => $catgasxx,
      ':desgasxx' => $desgasxx,
      ':fincfecx' => $today,
      ':regusr' => $userId,
      ':regfec' => $today,
      ':reghor' => $nowTime
    ]);

    $sign = ($tipo === 'INGRESO') ? '+' : '-';
    return [
      'success' => true,
      'message' => "Registrado {$tipo} de {$sign}\$" . number_format($monto, 2) . " COP ('{$desgasxx}') en categoría '{$catgasxx}' para cuenta {$tipgasxx}."
    ];
  } catch (Exception $e) {
    return ['success' => false, 'message' => 'Error BD: ' . $e->getMessage()];
  }
}

function executeAiCreateDebt($pdo, $userId, $args) {
  try {
    $tipdeudx = strtoupper($args['tipdeudx'] ?? 'EN CONTRA');
    $tipperxx = strtoupper($args['tipperxx'] ?? 'PERSONAL');
    $desdeudx = trim($args['descripcion'] ?? 'Deuda agregada por IA');
    $mondeudx = floatval($args['monto_total'] ?? 0);
    $numcuotx = intval($args['num_cuotas'] ?? 1);
    $moncuotx = floatval($args['monto_cuota'] ?? ($mondeudx / max($numcuotx, 1)));

    if ($mondeudx <= 0) return ['success' => false, 'message' => 'Monto total inválido'];

    $today = date('Y-m-d');
    $nowTime = date('H:i:s');

    $stmt = $pdo->prepare("INSERT INTO DEUDASIX
      (USRIDXXX, TIPDEUDX, TIPPERXX, DESDEUDX, MONDEUDX, MONCUOTX, NUMCUOTX, PAGCUOTX, ABONCUOT, FECCUOTX, REGUSRXX, REGFECXX, REGHORXX, REGESTXX)
      VALUES (:userId, :tipdeudx, :tipperxx, :desdeudx, :mondeudx, :moncuotx, :numcuotx, 0, 0, 15, :regusr, :regfec, :reghor, 'ACTIVO')");

    $stmt->execute([
      ':userId' => $userId,
      ':tipdeudx' => $tipdeudx,
      ':tipperxx' => $tipperxx,
      ':desdeudx' => $desdeudx,
      ':mondeudx' => $mondeudx,
      ':moncuotx' => $moncuotx,
      ':numcuotx' => $numcuotx,
      ':regusr' => $userId,
      ':regfec' => $today,
      ':reghor' => $nowTime
    ]);

    return [
      'success' => true,
      'message' => "Deuda '{$desdeudx}' por \$" . number_format($mondeudx, 2) . " COP registrada exitosamente ({$tipdeudx})."
    ];
  } catch (Exception $e) {
    return ['success' => false, 'message' => 'Error BD: ' . $e->getMessage()];
  }
}


/**
 * Realiza una petición cURL HTTP con soporte SSL flexible
 */
function executeCurl($url, $payload = null) {
  if (!function_exists('curl_init')) return null;

  $ch = curl_init();
  curl_setopt($ch, CURLOPT_URL, $url);
  curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
  curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
  curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
  curl_setopt($ch, CURLOPT_TIMEOUT, 15);
  curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 6);
  curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) BeaTaN-AI/1.0');

  if ($payload !== null) {
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, is_array($payload) ? json_encode($payload) : $payload);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
  }

  $response = curl_exec($ch);
  $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
  curl_close($ch);

  if ($response !== false && $httpCode >= 200 && $httpCode < 300) {
    return $response;
  }
  return null;
}

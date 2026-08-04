<?php
// dashboard/api/ai_chat.php
// API de Inteligencia Artificial Financiera Generativa Real para BeaTaN

header('Content-Type: application/json');
date_default_timezone_set('America/Bogota');
session_start();
require_once __DIR__ . '/../config/db.php';

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
2. Utiliza los datos exactos del informe financiero anterior para calcular valores, responder preguntas sobre meses específicos (Enero, Febrero, Julio, etc.), fechas, categorías o conceptos particulares.
3. Si el usuario te saluda, salúdalo con calidez mencionando su nombre y ofreciendo ayuda sobre sus finanzas.
4. Si el usuario pide recomendaciones, analiza sus deudas y categorías con más egresos para darle consejos concretos y accionables.
5. Usa formato Markdown limpio con negritas, listas y emojis para una lectura agradable.";

  // 3. OBTENER LLAVE DE API (DE ENTRADA DE USUARIO, DE .ENV O LLAVE INCLUIDA)
  $effectiveApiKey = !empty($userApiKey) ? $userApiKey : ($env['GEMINI_API_KEY'] ?? getenv('GEMINI_API_KEY') ?? '');

  // Intentar generar respuesta con la API de Gemini si hay llave disponible
  if (!empty($effectiveApiKey)) {
    $llmReply = callGeminiApi($systemPrompt, $chatHistory, $message, $effectiveApiKey);
    if (!empty($llmReply)) {
      echo json_encode([
        'success' => true,
        'reply' => $llmReply,
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

} catch (Exception $e) {
  error_log("AI Chat Exception: " . $e->getMessage());
  http_response_code(500);
  echo json_encode(['success' => false, 'error' => 'Error al recopilar datos financieros: ' . $e->getMessage()]);
}

/**
 * Realiza la petición a la API de Google Gemini descubriendo dinámicamente el modelo adecuado para la API Key
 */
function callGeminiApi($systemPrompt, $chatHistory, $userMessage, $apiKey) {
  $cleanKey = trim(str_replace(['"', "'"], '', $apiKey));
  if (empty($cleanKey)) return null;

  // 1. Consultar modelos dinámicamente habilitados para esta API Key
  $targetModel = 'gemini-1.5-flash';
  $listUrl = "https://generativelanguage.googleapis.com/v1beta/models?key=" . urlencode($cleanKey);
  $listRaw = executeCurl($listUrl);
  if ($listRaw) {
    $listJson = json_decode($listRaw, true);
    if (!empty($listJson['models']) && is_array($listJson['models'])) {
      foreach ($listJson['models'] as $m) {
        if (isset($m['supportedGenerationMethods']) && in_array('generateContent', $m['supportedGenerationMethods'])) {
          if (str_contains($m['name'], 'flash') || str_contains($m['name'], 'pro')) {
            $targetModel = str_replace('models/', '', $m['name']);
            break;
          }
        }
      }
    }
  }

  // 2. Ejecutar generación con el modelo detectado
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

  $payload = [
    'systemInstruction' => [
      'parts' => [['text' => $systemPrompt]]
    ],
    'contents' => $contents,
    'generationConfig' => [
      'temperature' => 0.4,
      'maxOutputTokens' => 1200
    ]
  ];

  $res = executeCurl($url, $payload);
  if ($res) {
    $json = json_decode($res, true);
    $text = $json['candidates'][0]['content']['parts'][0]['text'] ?? '';
    if (!empty($text)) {
      return trim($text);
    }
  }

  return null;
}

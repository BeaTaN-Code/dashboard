<?php
// dashboard/api/ai_chat.php
// API de Inteligencia Artificial Financiera en tiempo real para BeaTaN

header('Content-Type: application/json');
date_default_timezone_set('America/Bogota');
session_start();
require_once __DIR__ . '/../config/db.php';

// Cargar .env para obtener llaves si existen
$envPaths = [__DIR__ . '/../../.env', __DIR__ . '/../.env', '.env'];
$env = [];
foreach ($envPaths as $path) {
  if (file_exists($path)) {
    $env = parse_env($path);
    break;
  }
}

// Verificar autenticación
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

if (empty($message)) {
  http_response_code(400);
  echo json_encode(['success' => false, 'error' => 'El mensaje no puede estar vacío']);
  exit;
}

try {
  // 1. RECOLECTAR DATOS FINANCIEROS DEL USUARIO
  $sqlUser = "SELECT USRNMEXX FROM BEATUSRS WHERE USRIDXXX = :userId LIMIT 1";
  $stmtUser = $pdo->prepare($sqlUser);
  $stmtUser->execute([':userId' => $userId]);
  $userData = $stmtUser->fetch();
  $userName = $userData['USRNMEXX'] ?? 'Usuario';

  // Balance general
  $sqlBalance = "SELECT 
      COALESCE(SUM(CASE WHEN MONTGASX > 0 THEN MONTGASX ELSE 0 END), 0) as income,
      COALESCE(SUM(CASE WHEN MONTGASX < 0 THEN ABS(MONTGASX) ELSE 0 END), 0) as expense
      FROM FINANCIX 
      WHERE REGESTXX = 'ACTIVO' AND USRIDXXX = :userId AND TIPGASXX = :tipgasxx";
  $stmtBalance = $pdo->prepare($sqlBalance);
  $stmtBalance->execute([':userId' => $userId, ':tipgasxx' => $tipgasxx]);
  $bal = $stmtBalance->fetch();
  $totalIncome = floatval($bal['income']);
  $totalExpense = floatval($bal['expense']);
  $totalBalance = $totalIncome - $totalExpense;

  // Desglose por categoría
  $sqlCat = "SELECT 
      CATGASXX,
      SUM(CASE WHEN MONTGASX > 0 THEN MONTGASX ELSE 0 END) as ingresos,
      SUM(CASE WHEN MONTGASX < 0 THEN ABS(MONTGASX) ELSE 0 END) as gastos
      FROM FINANCIX
      WHERE REGESTXX = 'ACTIVO' AND USRIDXXX = :userId AND TIPGASXX = :tipgasxx
      GROUP BY CATGASXX
      ORDER BY gastos DESC";
  $stmtCat = $pdo->prepare($sqlCat);
  $stmtCat->execute([':userId' => $userId, ':tipgasxx' => $tipgasxx]);
  $categories = $stmtCat->fetchAll();

  $catSummary = [];
  foreach ($categories as $c) {
    $catSummary[] = "- {$c['CATGASXX']}: Gastos = $" . number_format($c['gastos'], 2, '.', ',') . " | Ingresos = $" . number_format($c['ingresos'], 2, '.', ',');
  }
  $catSummaryText = implode("\n", $catSummary);

  // Últimas 35 transacciones
  $sqlTx = "SELECT FINCFECX, CATGASXX, DESGASXX, MONTGASX 
      FROM FINANCIX
      WHERE REGESTXX = 'ACTIVO' AND USRIDXXX = :userId AND TIPGASXX = :tipgasxx
      ORDER BY FINCFECX DESC LIMIT 35";
  $stmtTx = $pdo->prepare($sqlTx);
  $stmtTx->execute([':userId' => $userId, ':tipgasxx' => $tipgasxx]);
  $transactions = $stmtTx->fetchAll();

  $txList = [];
  foreach ($transactions as $t) {
    $tipo = $t['MONTGASX'] < 0 ? 'GASTO' : 'INGRESO';
    $montoFormatted = "$" . number_format(abs($t['MONTGASX']), 2, '.', ',');
    $txList[] = "• [{$t['FINCFECX']}] {$tipo}: {$montoFormatted} | Categoría: {$t['CATGASXX']} | Concepto: {$t['DESGASXX']}";
  }
  $txListText = implode("\n", $txList);

  // 2. CONSTRUIR PROMPT DEL SISTEMA PARA LA IA
  $systemPrompt = "Eres 'BeaTaN AI', un asistente experto en finanzas personales e inteligencia financiera creado para ayudar al usuario '{$userName}'.
Responde de forma clara, directa, amable y profesional usando formato Markdown limpio (listas, negritas, subtítulos si aplica).

ESTADO FINANCIERO ACTUAL DEL USUARIO EN LA CUENTA [{$tipgasxx}]:
- Balance Total Disponible: $" . number_format($totalBalance, 2, '.', ',') . " COP
- Total Ingresos Históricos: $" . number_format($totalIncome, 2, '.', ',') . " COP
- Total Gastos Históricos: $" . number_format($totalExpense, 2, '.', ',') . " COP

RESUMEN DE GASTOS E INGRESOS POR CATEGORÍA:
{$catSummaryText}

ÚLTIMOS MOVIMIENTOS REGISTRADOS:
{$txListText}

REGLAS DE RESPUESTA:
1. Responde basándote en los datos financieros REALES proporcionados arriba.
2. Si te preguntan sobre un gasto específico, busca en las transacciones y categorías de arriba y da cifras exactas.
3. Si te piden consejos de ahorro o análisis, ofrece sugerencias realistas según su nivel de gastos y balance.
4. Mantén las respuestas fluidas y concisas en español.";

  // 3. ENVIAR A SERVICIO DE LLM CON SOPORTE DE CURL Y FALLBACK
  $messages = [
    ['role' => 'system', 'content' => $systemPrompt]
  ];

  if (is_array($chatHistory)) {
    foreach ($chatHistory as $h) {
      if (isset($h['role'], $h['content']) && in_array($h['role'], ['user', 'assistant'])) {
        $messages[] = ['role' => $h['role'], 'content' => $h['content']];
      }
    }
  }
  $messages[] = ['role' => 'user', 'content' => $message];

  $aiResponseText = getAiResponse($messages, $env, $message, $userName, $tipgasxx, $totalBalance, $totalIncome, $totalExpense, $categories, $transactions);

  echo json_encode([
    'success' => true,
    'reply' => $aiResponseText
  ]);

} catch (Exception $e) {
  error_log("AI Chat Error: " . $e->getMessage());
  // Incluso ante cualquier excepción, responder con diagnóstico limpio en lugar de 500 error
  $fallback = generateSmartFinancialAnalysis($message, $userName ?? 'Usuario', $tipgasxx ?? 'PERSONAL', $totalBalance ?? 0, $totalIncome ?? 0, $totalExpense ?? 0, $categories ?? [], $transactions ?? []);
  echo json_encode([
    'success' => true,
    'reply' => $fallback
  ]);
}

/**
 * Función principal que intenta consultar los servicios de LLM vía cURL.
 */
function getAiResponse($messages, $env, $userMessage, $userName, $tipgasxx, $totalBalance, $totalIncome, $totalExpense, $categories, $transactions) {
  $geminiKey = $env['GEMINI_API_KEY'] ?? getenv('GEMINI_API_KEY') ?? '';

  // 1. Probar Gemini API si existe API Key
  if (!empty($geminiKey)) {
    $geminiUrl = "https://generativelanguage.googleapis.com/v1beta/models/gemini-1.5-flash:generateContent?key=" . urlencode($geminiKey);
    $systemInstruction = '';
    $contents = [];
    foreach ($messages as $m) {
      if ($m['role'] === 'system') {
        $systemInstruction = $m['content'];
      } else {
        $contents[] = [
          'role' => ($m['role'] === 'user') ? 'user' : 'model',
          'parts' => [['text' => $m['content']]]
        ];
      }
    }

    $payload = [
      'systemInstruction' => ['parts' => [['text' => $systemInstruction]]],
      'contents' => $contents,
      'generationConfig' => ['temperature' => 0.3, 'maxOutputTokens' => 1000]
    ];

    $raw = makeCurlRequest($geminiUrl, $payload);
    if ($raw) {
      $json = json_decode($raw, true);
      $text = $json['candidates'][0]['content']['parts'][0]['text'] ?? '';
      if (!empty($text)) return $text;
    }
  }

  // 2. Probar Pollinations POST API vía cURL
  $pollinationsUrl = 'https://text.pollinations.ai/';
  $payload = [
    'messages' => $messages,
    'model' => 'openai',
    'seed' => rand(1, 99999)
  ];
  $raw = makeCurlRequest($pollinationsUrl, $payload);
  if ($raw && strlen($raw) > 5 && !str_contains($raw, '<html')) {
    return trim($raw);
  }

  // 3. Probar Pollinations GET API
  $systemContent = $messages[0]['content'] ?? '';
  $getUrl = 'https://text.pollinations.ai/' . urlencode($userMessage) . '?system=' . urlencode(substr($systemContent, 0, 1500));
  $rawGet = makeCurlRequest($getUrl);
  if ($rawGet && strlen($rawGet) > 5 && !str_contains($rawGet, '<html')) {
    return trim($rawGet);
  }

  // 4. Si los servidores externos están bloqueados por firewall del hosting, usar analizador contable inteligente
  return generateSmartFinancialAnalysis($userMessage, $userName, $tipgasxx, $totalBalance, $totalIncome, $totalExpense, $categories, $transactions);
}

/**
 * Cliente HTTP cURL con soporte SSL desactivado y timeouts óptimos.
 */
function makeCurlRequest($url, $payload = null, $extraHeaders = []) {
  if (function_exists('curl_init')) {
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
    curl_setopt($ch, CURLOPT_TIMEOUT, 15);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 6);
    curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) BeaTaN-AI/1.0');
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);

    $headers = ['Accept: application/json', 'User-Agent: BeaTaN-AI/1.0'];

    if ($payload !== null) {
      curl_setopt($ch, CURLOPT_POST, true);
      curl_setopt($ch, CURLOPT_POSTFIELDS, is_array($payload) ? json_encode($payload) : $payload);
      $headers[] = 'Content-Type: application/json';
    }

    if (!empty($extraHeaders)) {
      $headers = array_merge($headers, $extraHeaders);
    }

    curl_setopt($ch, CURLOPT_HTTPHEADER, array_unique($headers));
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($response !== false && $httpCode >= 200 && $httpCode < 300) {
      return $response;
    }
  }

  // Fallback file_get_contents con SSL desactivado
  $httpOptions = [
    'ssl' => ['verify_peer' => false, 'verify_peer_name' => false],
    'http' => ['timeout' => 15, 'user_agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) BeaTaN-AI/1.0']
  ];

  if ($payload !== null) {
    $httpOptions['http']['method'] = 'POST';
    $httpOptions['http']['header'] = "Content-Type: application/json\r\n";
    $httpOptions['http']['content'] = is_array($payload) ? json_encode($payload) : $payload;
  }

  $context = stream_context_create($httpOptions);
  return @file_get_contents($url, false, $context);
}

/**
 * Motor de análisis contable inteligente en PHP (Garantía de respuesta rápida cuando el servidor bloquea salida HTTP).
 */
function generateSmartFinancialAnalysis($query, $userName, $accountType, $balance, $income, $expense, $categories, $transactions) {
  $queryLower = strtolower($query);

  // Formatos de moneda
  $balFormatted = "$" . number_format($balance, 2, '.', ',');
  $incFormatted = "$" . number_format($income, 2, '.', ',');
  $expFormatted = "$" . number_format($expense, 2, '.', ',');

  // Buscar categoría principal de gasto
  $topCategory = !empty($categories) ? $categories[0] : null;
  $topCatName = $topCategory['CATGASXX'] ?? 'Varios';
  $topCatValue = "$" . number_format($topCategory['gastos'] ?? 0, 2, '.', ',');

  // 1. Consulta sobre categoría / mayor gasto
  if (str_contains($queryLower, 'categor') || str_contains($queryLower, 'más') || str_contains($queryLower, 'mas') || str_contains($queryLower, 'gasto')) {
    $html = "### 📊 Análisis de Gastos por Categoría ({$accountType})\n\n";
    $html .= "Hola **{$userName}**, basándome en tus registros activos de la base de datos:\n\n";
    $html .= "🏆 **Tu mayor categoría de gasto es:** `{$topCatName}` con un total acumulado de **{$topCatValue}**.\n\n";
    
    if (count($categories) > 1) {
      $html .= "**Desglose de tus principales categorías:**\n";
      $count = 0;
      foreach ($categories as $cat) {
        if ($cat['gastos'] > 0 && $count < 5) {
          $val = "$" . number_format($cat['gastos'], 2, '.', ',');
          $html .= "- **{$cat['CATGASXX']}**: {$val}\n";
          $count++;
        }
      }
    }
    return $html;
  }

  // 2. Consulta sobre balance / resumen general
  if (str_contains($queryLower, 'balance') || str_contains($queryLower, 'resumen') || str_contains($queryLower, 'estado')) {
    $html = "### 📈 Resumen del Estado Financiero ({$accountType})\n\n";
    $html .= "Aquí tienes la radiografía actual de tu cuenta **{$accountType}**:\n\n";
    $html .= "- **Balance Disponible:** `{$balFormatted}`\n";
    $html .= "- **Ingresos Totales:** `{$incFormatted}`\n";
    $html .= "- **Gastos Totales:** `{$expFormatted}`\n\n";
    
    if ($balance > 0) {
      $html .= "✅ **Estado:** Tu balance general se encuentra en **terreno positivo**. Mantienes un margen de capital disponible.";
    } else {
      $html .= "⚠️ **Estado:** Tus gastos registrados superan tus ingresos en esta cuenta. Te recomiendo revisar tus compromisos prioritarios.";
    }
    return $html;
  }

  // 3. Consulta sobre consejos de ahorro
  if (str_contains($queryLower, 'ahorr') || str_contains($queryLower, 'consej') || str_contains($queryLower, 'recomiend')) {
    $html = "### 💡 Consejos de Ahorro Personalizados ({$userName})\n\n";
    $html .= "Analizando tus movimientos en la cuenta **{$accountType}**:\n\n";
    $html .= "1. **Control en `{$topCatName}`:** Tu mayor egreso está en **{$topCatName}** ({$topCatValue}). Revisar suscripciones o compras no esenciales en esta categoría puede liberar saldo de inmediato.\n";
    $html .= "2. **Regla del 50/30/20:** Con tus ingresos totales de **{$incFormatted}**, intenta destinar al menos el **20%** al ahorro automático antes de programar gastos adicionales.\n";
    $html .= "3. **Conciliación periódica:** Continúa subiendo tus extractos en PDF/Imagen para mantener el historial al día y evitar cobros no identificados.\n";
    return $html;
  }

  // 4. Respuesta general por defecto con contexto real
  $html = "### 🤖 Análisis BeaTaN AI ({$accountType})\n\n";
  $html .= "Hola **{$userName}**, aquí tienes un resumen en tiempo real de tus finanzas:\n\n";
  $html .= "- **Balance Total:** `{$balFormatted}`\n";
  $html .= "- **Ingresos Registrados:** `{$incFormatted}`\n";
  $html .= "- **Gastos Registrados:** `{$expFormatted}`\n";
  $html .= "- **Categoría Principal de Gasto:** `{$topCatName}` ({$topCatValue})\n\n";
  $html .= "¿Desearías profundizar en alguna categoría o revisar consejos específicos para optimizar tu balance?";
  
  return $html;
}

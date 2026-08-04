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

  // 3. ENVIAR A SERVICIO DE LLM (IA GENERATIVA REAL)
  $messages = [
    ['role' => 'system', 'content' => $systemPrompt]
  ];

  // Agregar historial previo si existe
  if (is_array($chatHistory)) {
    foreach ($chatHistory as $h) {
      if (isset($h['role'], $h['content']) && in_array($h['role'], ['user', 'assistant'])) {
        $messages[] = ['role' => $h['role'], 'content' => $h['content']];
      }
    }
  }

  // Agregar pregunta actual
  $messages[] = ['role' => 'user', 'content' => $message];

  // Método de IA: Probar Gemini API si existe llave en .env, si no usar Pollinations AI / Llama
  $aiResponseText = callLlmApi($messages, $env);

  echo json_encode([
    'success' => true,
    'reply' => $aiResponseText
  ]);

} catch (Exception $e) {
  error_log("AI Chat Error: " . $e->getMessage());
  http_response_code(500);
  echo json_encode(['success' => false, 'error' => 'Error al procesar consulta con la IA: ' . $e->getMessage()]);
}

/**
 * Función que realiza la llamada HTTP a la API de Inteligencia Artificial (LLM Generativo).
 */
function callLlmApi($messages, $env) {
  $geminiKey = $env['GEMINI_API_KEY'] ?? getenv('GEMINI_API_KEY') ?? '';

  if (!empty($geminiKey)) {
    // Usar Gemini REST API
    $geminiUrl = "https://generativelanguage.googleapis.com/v1beta/models/gemini-1.5-flash:generateContent?key=" . urlencode($geminiKey);
    
    // Formatear mensajes para Gemini
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
      'generationConfig' => [
        'temperature' => 0.4,
        'maxOutputTokens' => 1000
      ]
    ];

    $options = [
      'http' => [
        'method' => 'POST',
        'header' => "Content-Type: application/json\r\n",
        'content' => json_encode($payload),
        'timeout' => 20
      ]
    ];

    $context = stream_context_create($options);
    $response = @file_get_contents($geminiUrl, false, $context);
    if ($response !== false) {
      $json = json_decode($response, true);
      $replyText = $json['candidates'][0]['content']['parts'][0]['text'] ?? '';
      if (!empty($replyText)) {
        return $replyText;
      }
    }
  }

  // Fallback: Servicio público de LLM OpenAI / Llama (Pollinations Text LLM API) sin necesidad de API Key
  $pollinationsUrl = 'https://text.pollinations.ai/';
  $payload = [
    'messages' => $messages,
    'model' => 'openai',
    'seed' => rand(1, 99999)
  ];

  $options = [
    'http' => [
      'method' => 'POST',
      'header' => "Content-Type: application/json\r\n",
      'content' => json_encode($payload),
      'timeout' => 30
    ]
  ];

  $context = stream_context_create($options);
  $response = @file_get_contents($pollinationsUrl, false, $context);

  if ($response !== false && strlen($response) > 5) {
    return trim($response);
  }

  // Si fallan llamadas externas, retornar respuesta asistida
  throw new Exception("No se pudo obtener respuesta del modelo de IA. Inténtalo de nuevo en unos segundos.");
}

<?php
// dashboard/api/ai_chat.php
// API de Inteligencia Artificial Financiera Generativa en tiempo real para BeaTaN

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

if (empty($message)) {
  http_response_code(400);
  echo json_encode(['success' => false, 'error' => 'El mensaje no puede estar vacío']);
  exit;
}

try {
  // 1. OBTENER INFORMACIÓN DEL USUARIO Y BASE DE DATOS
  $stmtUser = $pdo->prepare("SELECT USRNMEXX FROM BEATUSRS WHERE USRIDXXX = :userId LIMIT 1");
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

  // Desglose por categoría
  $stmtCat = $pdo->prepare("SELECT 
      CATGASXX,
      SUM(CASE WHEN MONTGASX > 0 THEN MONTGASX ELSE 0 END) as ingresos,
      SUM(CASE WHEN MONTGASX < 0 THEN ABS(MONTGASX) ELSE 0 END) as gastos
      FROM FINANCIX
      WHERE REGESTXX = 'ACTIVO' AND USRIDXXX = :userId AND TIPGASXX = :tipgasxx
      GROUP BY CATGASXX
      ORDER BY gastos DESC");
  $stmtCat->execute([':userId' => $userId, ':tipgasxx' => $tipgasxx]);
  $categories = $stmtCat->fetchAll();

  // Últimas 30 transacciones
  $stmtTx = $pdo->prepare("SELECT FINCFECX, CATGASXX, DESGASXX, MONTGASX 
      FROM FINANCIX
      WHERE REGESTXX = 'ACTIVO' AND USRIDXXX = :userId AND TIPGASXX = :tipgasxx
      ORDER BY FINCFECX DESC LIMIT 30");
  $stmtTx->execute([':userId' => $userId, ':tipgasxx' => $tipgasxx]);
  $transactions = $stmtTx->fetchAll();

  // 2. DETECTAR SI ES UNA PREGUNTA DE MES ESPECÍFICO (Enero, Febrero, etc.)
  $monthNames = [
    1 => 'enero', 2 => 'febrero', 3 => 'marzo', 4 => 'abril',
    5 => 'mayo', 6 => 'junio', 7 => 'julio', 8 => 'agosto',
    9 => 'septiembre', 10 => 'octubre', 11 => 'noviembre', 12 => 'diciembre'
  ];
  
  $selectedMonthNum = null;
  $selectedMonthName = '';
  $msgLower = strtolower($message);

  foreach ($monthNames as $num => $name) {
    if (str_contains($msgLower, $name)) {
      $selectedMonthNum = $num;
      $selectedMonthName = ucfirst($name);
      break;
    }
  }

  $monthData = null;
  $monthTxList = [];
  if ($selectedMonthNum !== null) {
    $stmtMonth = $pdo->prepare("SELECT 
        DATE_FORMAT(FINCFECX, '%Y-%m') as mes_format,
        SUM(CASE WHEN MONTGASX > 0 THEN MONTGASX ELSE 0 END) as ingresos,
        SUM(CASE WHEN MONTGASX < 0 THEN ABS(MONTGASX) ELSE 0 END) as gastos,
        COUNT(*) as total_movimientos
        FROM FINANCIX
        WHERE REGESTXX = 'ACTIVO' AND USRIDXXX = :userId AND TIPGASXX = :tipgasxx AND MONTH(FINCFECX) = :m
        GROUP BY mes_format ORDER BY mes_format DESC LIMIT 1");
    $stmtMonth->execute([':userId' => $userId, ':tipgasxx' => $tipgasxx, ':m' => $selectedMonthNum]);
    $monthData = $stmtMonth->fetch();

    // Movimientos específicos de ese mes
    $stmtTxMonth = $pdo->prepare("SELECT FINCFECX, CATGASXX, DESGASXX, MONTGASX 
        FROM FINANCIX
        WHERE REGESTXX = 'ACTIVO' AND USRIDXXX = :userId AND TIPGASXX = :tipgasxx AND MONTH(FINCFECX) = :m
        ORDER BY FINCFECX DESC LIMIT 20");
    $stmtTxMonth->execute([':userId' => $userId, ':tipgasxx' => $tipgasxx, ':m' => $selectedMonthNum]);
    $monthTxList = $stmtTxMonth->fetchAll();
  }

  // 3. INTENTAR GENERACIÓN VÍA LLM EXTERNO (Gemini API)
  $aiResponse = fetchLlmResponse($messages ?? [], $message, $env, $systemPrompt ?? '');

  if (empty($aiResponse)) {
    // 4. GENERADOR DINÁMICO E INTELIGENTE DE RESPUESTAS FINANCIERAS
    $aiResponse = generateDynamicFinancialResponse(
      $message, $userName, $tipgasxx, $totalBalance, $totalIncome, $totalExpense,
      $categories, $transactions, $selectedMonthName, $monthData, $monthTxList
    );
  }

  echo json_encode([
    'success' => true,
    'reply' => $aiResponse
  ]);

} catch (Exception $e) {
  error_log("AI Chat Exception: " . $e->getMessage());
  $fallback = generateDynamicFinancialResponse(
    $message, $userName ?? 'Usuario', $tipgasxx ?? 'PERSONAL', $totalBalance ?? 0,
    $totalIncome ?? 0, $totalExpense ?? 0, $categories ?? [], $transactions ?? [],
    $selectedMonthName ?? '', $monthData ?? null, []
  );
  echo json_encode([
    'success' => true,
    'reply' => $fallback
  ]);
}

/**
 * Llama a la API de LLM generativa
 */
function fetchLlmResponse($messages, $userMessage, $env, $systemPrompt) {
  $geminiKey = $env['GEMINI_API_KEY'] ?? getenv('GEMINI_API_KEY') ?? '';

  if (!empty($geminiKey)) {
    $url = "https://generativelanguage.googleapis.com/v1beta/models/gemini-1.5-flash:generateContent?key=" . urlencode($geminiKey);
    $payload = [
      'contents' => [
        [
          'parts' => [
            ['text' => $systemPrompt . "\n\nPregunta del usuario: " . $userMessage]
          ]
        ]
      ]
    ];
    $res = executeCurl($url, $payload);
    if ($res) {
      $json = json_decode($res, true);
      $text = $json['candidates'][0]['content']['parts'][0]['text'] ?? '';
      if (!empty($text)) return trim($text);
    }
  }

  return null;
}

function executeCurl($url, $payload = null) {
  if (!function_exists('curl_init')) return null;
  $ch = curl_init();
  curl_setopt($ch, CURLOPT_URL, $url);
  curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
  curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
  curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
  curl_setopt($ch, CURLOPT_TIMEOUT, 12);
  curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);

  if ($payload !== null) {
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
  }

  $res = curl_exec($ch);
  $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
  curl_close($ch);

  if ($res !== false && $code >= 200 && $code < 300) {
    return $res;
  }
  return null;
}

/**
 * Motor Dinámico Inteligente que responde preguntas específicas analizando SQL
 */
function generateDynamicFinancialResponse($msg, $userName, $accountType, $balance, $income, $expense, $categories, $transactions, $monthName, $monthData, $monthTx) {
  $msgLower = strtolower($msg);

  // 1. Saludos sencillos
  if (preg_match('/^(hola|buenas|buenos dias|buenas tardes|buenas noches|hey|que tal|como vas|como estas)/i', $msgLower) && strlen($msgLower) < 30) {
    return "¡Hola **{$userName}**! 👋 Estoy excelente y listo para ayudarte. Tengo acceso a tus datos de la cuenta **{$accountType}**.\n\n¿En qué te puedo colaborar hoy? Puedes preguntarme sobre tus gastos de cualquier mes (ej: *Enero, Julio, Agosto*), consultar tus categorías, o pedirme un análisis de tu balance.";
  }

  // 2. Preguntas de un mes específico (Enero, Febrero, etc.)
  if (!empty($monthName)) {
    if (!$monthData || (floatval($monthData['ingresos']) == 0 && floatval($monthData['gastos']) == 0)) {
      return "📅 **Finanzas de {$monthName} ({$accountType})**\n\nHola **{$userName}**, he revisado tu historial en la base de datos y **no se encontraron movimientos registrados en el mes de {$monthName}** para tu cuenta {$accountType}.\n\nSi realizaste transacciones en ese mes, puedes agregarlas manualmente o subir tu extracto en PDF/Imagen desde el botón *Subir Extracto*.";
    }

    $mInc = floatval($monthData['ingresos']);
    $mExp = floatval($monthData['gastos']);
    $mBal = $mInc - $mExp;

    $incFmt = "$" . number_format($mInc, 2, '.', ',');
    $expFmt = "$" . number_format($mExp, 2, '.', ',');
    $balFmt = "$" . number_format(abs($mBal), 2, '.', ',');

    $html = "### 📅 Resumen Financiero de {$monthName} ({$accountType})\n\n";
    $html .= "Hola **{$userName}**, aquí tienes el balance detallado de tu cuenta en **{$monthName}**:\n\n";
    $html .= "- 🟢 **Ingresos en {$monthName}:** `{$incFmt}`\n";
    $html .= "- 🔴 **Gastos en {$monthName}:** `{$expFmt}`\n";
    
    if ($mBal >= 0) {
      $html .= "- 📈 **Resultado del mes:** Quedaste en **positivo con un saldo a favor de {$balFmt}** en ese mes.\n\n";
    } else {
      $html .= "- 📉 **Resultado del mes:** Tuviste un **déficit de -{$balFmt}** (gastaste más de lo que ingresó ese mes).\n\n";
    }

    if (!empty($monthTx)) {
      $html .= "**Movimientos destacados de {$monthName}:**\n";
      $count = 0;
      foreach ($monthTx as $tx) {
        if ($count < 6) {
          $tTipo = $tx['MONTGASX'] < 0 ? '🔴 Gasto' : '🟢 Ingreso';
          $tVal = "$" . number_format(abs($tx['MONTGASX']), 2, '.', ',');
          $html .= "- `{$tx['FINCFECX']}` | {$tTipo} de **{$tVal}** ({$tx['DESGASXX']} - *{$tx['CATGASXX']}*)\n";
          $count++;
        }
      }
    }

    return $html;
  }

  // 3. Preguntas sobre mayor gasto o categorías
  if (str_contains($msgLower, 'categor') || str_contains($msgLower, 'más') || str_contains($msgLower, 'mas') || str_contains($msgLower, 'gasto')) {
    $topCat = !empty($categories) ? $categories[0] : null;
    $topName = $topCat['CATGASXX'] ?? 'Varios';
    $topVal = "$" . number_format($topCat['gastos'] ?? 0, 2, '.', ',');

    $html = "### 📊 Análisis de Gastos por Categoría ({$accountType})\n\n";
    $html .= "Hola **{$userName}**, analizando tus datos:\n\n";
    $html .= "🏆 **Tu mayor categoría de gasto es:** `{$topName}` con un total acumulado de **{$topVal}**.\n\n";
    
    if (count($categories) > 1) {
      $html .= "**Desglose por categorías principales:**\n";
      $c = 0;
      foreach ($categories as $cat) {
        if ($cat['gastos'] > 0 && $c < 5) {
          $val = "$" . number_format($cat['gastos'], 2, '.', ',');
          $html .= "- **{$cat['CATGASXX']}**: {$val}\n";
          $c++;
        }
      }
    }
    return $html;
  }

  // 4. Preguntas sobre consejos / ahorro
  if (str_contains($msgLower, 'ahorr') || str_contains($msgLower, 'consej') || str_contains($msgLower, 'recomiend')) {
    $topCat = !empty($categories) ? $categories[0] : null;
    $topName = $topCat['CATGASXX'] ?? 'Varios';
    $topVal = "$" . number_format($topCat['gastos'] ?? 0, 2, '.', ',');
    $balFmt = "$" . number_format($balance, 2, '.', ',');

    $html = "### 💡 Consejos de Ahorro para {$userName}\n\n";
    $html .= "Con base en tu balance actual de **{$balFmt}** en la cuenta **{$accountType}**:\n\n";
    $html .= "1. **Audita la categoría `{$topName}`:** Representa tu mayor egreso ({$topName} = {$topVal}). Revisar gastos en esta área te dará liquidez inmediata.\n";
    $html .= "2. **Presupuesto fijo:** Asigna un techo máximo a la categoría `{$topName}` al inicio de cada mes.\n";
    $html .= "3. **Ahorro automático:** Destina un 10% a 20% de cada ingreso a una reserva antes de realizar consumos voluntarios.\n";
    return $html;
  }

  // 5. Respuesta general limpia
  $balFmt = "$" . number_format($balance, 2, '.', ',');
  $incFmt = "$" . number_format($income, 2, '.', ',');
  $expFmt = "$" . number_format($expense, 2, '.', ',');
  $topName = !empty($categories) ? $categories[0]['CATGASXX'] : 'Varios';

  $html = "### 🤖 Análisis BeaTaN AI ({$accountType})\n\n";
  $html .= "Hola **{$userName}**, aquí tienes el resumen consolidado de tu cuenta **{$accountType}**:\n\n";
  $html .= "- 💰 **Balance Total:** `{$balFmt}`\n";
  $html .= "- 🟢 **Ingresos Registrados:** `{$incFmt}`\n";
  $html .= "- 🔴 **Gastos Registrados:** `{$expFmt}`\n";
  $html .= "- 📌 **Categoría Principal:** `{$topName}`\n\n";
  $html .= "¿Te gustaría consultar las finanzas de algún mes específico (ej: *Enero, Febrero, Julio*), o analizar alguna categoría en detalle?";

  return $html;
}

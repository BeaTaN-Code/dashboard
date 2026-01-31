<?php
// dashboard/api/holidays.php
// API para obtener festivos de Colombia

header('Content-Type: application/json');
session_start();
require_once __DIR__ . '/../config/db.php';

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

$year = intval($_GET['year'] ?? date('Y'));
$month = intval($_GET['month'] ?? 0);

try {
  // Intentar obtener festivos de la base de datos
  $sql = "SELECT FESTNOMX as nombre, FESTFECX as fecha FROM FESTCOLX WHERE YEAR(FESTFECX) = :year AND REGESTXX = 'ACTIVO'";
  $params = [':year' => $year];

  if ($month > 0) {
    $sql .= " AND MONTH(FESTFECX) = :month";
    $params[':month'] = $month;
  }

  $sql .= " ORDER BY FESTFECX ASC";

  $stmt = $pdo->prepare($sql);
  $stmt->execute($params);
  $holidays = $stmt->fetchAll();

  // Si no hay festivos en la BD, usar los festivos predefinidos de Colombia
  if (empty($holidays)) {
    $holidays = getColombianHolidays($year, $month);
  }

  echo json_encode([
    'success' => true,
    'data' => $holidays,
    'year' => $year
  ]);

} catch (Exception $e) {
  error_log('Holidays error: ' . $e->getMessage());

  // Fallback a festivos predefinidos
  $holidays = getColombianHolidays($year, $month);
  echo json_encode([
    'success' => true,
    'data' => $holidays,
    'year' => $year,
    'source' => 'predefined'
  ]);
}

/**
 * Obtiene los festivos oficiales de Colombia para un año dado
 * Incluye festivos fijos y móviles (Ley Emiliani)
 */
function getColombianHolidays($year, $filterMonth = 0)
{
  $holidays = [];

  // Festivos fijos
  $fixedHolidays = [
    ['month' => 1, 'day' => 1, 'name' => 'Año Nuevo'],
    ['month' => 5, 'day' => 1, 'name' => 'Día del Trabajo'],
    ['month' => 7, 'day' => 20, 'name' => 'Día de la Independencia'],
    ['month' => 8, 'day' => 7, 'name' => 'Batalla de Boyacá'],
    ['month' => 12, 'day' => 8, 'name' => 'Inmaculada Concepción'],
    ['month' => 12, 'day' => 25, 'name' => 'Navidad'],
  ];

  foreach ($fixedHolidays as $h) {
    if ($filterMonth === 0 || $filterMonth === $h['month']) {
      $holidays[] = [
        'nombre' => $h['name'],
        'fecha' => sprintf('%d-%02d-%02d', $year, $h['month'], $h['day'])
      ];
    }
  }

  // Festivos que se mueven al lunes siguiente (Ley Emiliani)
  $emilianiHolidays = [
    ['month' => 1, 'day' => 6, 'name' => 'Reyes Magos'],
    ['month' => 3, 'day' => 19, 'name' => 'San José'],
    ['month' => 6, 'day' => 29, 'name' => 'San Pedro y San Pablo'],
    ['month' => 8, 'day' => 15, 'name' => 'Asunción de la Virgen'],
    ['month' => 10, 'day' => 12, 'name' => 'Día de la Raza'],
    ['month' => 11, 'day' => 1, 'name' => 'Todos los Santos'],
    ['month' => 11, 'day' => 11, 'name' => 'Independencia de Cartagena'],
  ];

  foreach ($emilianiHolidays as $h) {
    $date = mktime(0, 0, 0, $h['month'], $h['day'], $year);
    $dayOfWeek = date('N', $date); // 1 = Monday, 7 = Sunday

    // Si no es lunes, mover al siguiente lunes
    if ($dayOfWeek != 1) {
      $daysToAdd = (8 - $dayOfWeek) % 7;
      if ($daysToAdd == 0)
        $daysToAdd = 7;
      $date = strtotime("+{$daysToAdd} days", $date);
    }

    $actualMonth = intval(date('n', $date));
    if ($filterMonth === 0 || $filterMonth === $actualMonth) {
      $holidays[] = [
        'nombre' => $h['name'],
        'fecha' => date('Y-m-d', $date)
      ];
    }
  }

  // Festivos basados en Semana Santa (móviles)
  $easter = calculateEaster($year);

  // Jueves Santo (3 días antes de Pascua)
  $holyThursday = strtotime('-3 days', $easter);
  $actualMonth = intval(date('n', $holyThursday));
  if ($filterMonth === 0 || $filterMonth === $actualMonth) {
    $holidays[] = [
      'nombre' => 'Jueves Santo',
      'fecha' => date('Y-m-d', $holyThursday)
    ];
  }

  // Viernes Santo (2 días antes de Pascua)
  $goodFriday = strtotime('-2 days', $easter);
  $actualMonth = intval(date('n', $goodFriday));
  if ($filterMonth === 0 || $filterMonth === $actualMonth) {
    $holidays[] = [
      'nombre' => 'Viernes Santo',
      'fecha' => date('Y-m-d', $goodFriday)
    ];
  }

  // Ascensión del Señor (39 días después de Pascua, se mueve al lunes)
  $ascension = strtotime('+39 days', $easter);
  $dayOfWeek = date('N', $ascension);
  if ($dayOfWeek != 1) {
    $daysToAdd = (8 - $dayOfWeek) % 7;
    if ($daysToAdd == 0)
      $daysToAdd = 7;
    $ascension = strtotime("+{$daysToAdd} days", $ascension);
  }
  $actualMonth = intval(date('n', $ascension));
  if ($filterMonth === 0 || $filterMonth === $actualMonth) {
    $holidays[] = [
      'nombre' => 'Ascensión del Señor',
      'fecha' => date('Y-m-d', $ascension)
    ];
  }

  // Corpus Christi (60 días después de Pascua, se mueve al lunes)
  $corpusChristi = strtotime('+60 days', $easter);
  $dayOfWeek = date('N', $corpusChristi);
  if ($dayOfWeek != 1) {
    $daysToAdd = (8 - $dayOfWeek) % 7;
    if ($daysToAdd == 0)
      $daysToAdd = 7;
    $corpusChristi = strtotime("+{$daysToAdd} days", $corpusChristi);
  }
  $actualMonth = intval(date('n', $corpusChristi));
  if ($filterMonth === 0 || $filterMonth === $actualMonth) {
    $holidays[] = [
      'nombre' => 'Corpus Christi',
      'fecha' => date('Y-m-d', $corpusChristi)
    ];
  }

  // Sagrado Corazón (68 días después de Pascua, se mueve al lunes)
  $sacredHeart = strtotime('+68 days', $easter);
  $dayOfWeek = date('N', $sacredHeart);
  if ($dayOfWeek != 1) {
    $daysToAdd = (8 - $dayOfWeek) % 7;
    if ($daysToAdd == 0)
      $daysToAdd = 7;
    $sacredHeart = strtotime("+{$daysToAdd} days", $sacredHeart);
  }
  $actualMonth = intval(date('n', $sacredHeart));
  if ($filterMonth === 0 || $filterMonth === $actualMonth) {
    $holidays[] = [
      'nombre' => 'Sagrado Corazón de Jesús',
      'fecha' => date('Y-m-d', $sacredHeart)
    ];
  }

  // Ordenar por fecha
  usort($holidays, function ($a, $b) {
    return strcmp($a['fecha'], $b['fecha']);
  });

  return $holidays;
}

/**
 * Calcula la fecha de Pascua usando el algoritmo de Gauss
 */
function calculateEaster($year)
{
  $a = $year % 19;
  $b = floor($year / 100);
  $c = $year % 100;
  $d = floor($b / 4);
  $e = $b % 4;
  $f = floor(($b + 8) / 25);
  $g = floor(($b - $f + 1) / 3);
  $h = (19 * $a + $b - $d - $g + 15) % 30;
  $i = floor($c / 4);
  $k = $c % 4;
  $l = (32 + 2 * $e + 2 * $i - $h - $k) % 7;
  $m = floor(($a + 11 * $h + 22 * $l) / 451);
  $month = floor(($h + $l - 7 * $m + 114) / 31);
  $day = (($h + $l - 7 * $m + 114) % 31) + 1;

  return mktime(0, 0, 0, $month, $day, $year);
}
?>
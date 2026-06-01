<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);
// dashboard/index.php
require_once __DIR__ . '/config/db.php';
date_default_timezone_set('America/Bogota');

/* CARGAR .ENV PRIMERO */
$envPaths = [
  __DIR__ . '/../../.env',
  __DIR__ . '/../.env',
  '.env'
];

$env = [];
foreach ($envPaths as $path) {
  if (file_exists($path)) {
    $env = parse_env($path);
    break;
  }
}

if (!$env) {
  die("❌ No se pudo cargar el .env");
}

/* AHORA SÍ sesión */
checkSession();
$user = getCurrentUser();

/* AHORA SÍ BD */
$pdo = getDbConnection();
if (!$pdo) {
  die("❌ No se pudo conectar a la base de datos");
}

// Obtener estadísticas del formulario
$formStats = ['total' => 0, 'pendiente' => 0, 'leido' => 0, 'respondido' => 0];
try {
  $stmt = $pdo->query("SELECT ESTADO, COUNT(*) as count FROM FORMULARIO WHERE REGESTXX = 'ACTIVO' GROUP BY ESTADO");
  while ($row = $stmt->fetch()) {
    $estado = strtolower($row['ESTADO']);
    $formStats[$estado] = (int) $row['count'];
    $formStats['total'] += (int) $row['count'];
  }
} catch (Exception $e) {
  error_log('Form stats error: ' . $e->getMessage());
}

// Obtener datos del formulario
$formData = [];
try {
  $stmt = $pdo->query("SELECT * FROM FORMULARIO WHERE REGESTXX = 'ACTIVO' ORDER BY HORCREA DESC LIMIT 100");
  $formData = $stmt->fetchAll();
} catch (Exception $e) {
  error_log('Form data error: ' . $e->getMessage());
}

// Obtener BEATUSRS (solo para admin)
$usersData = [];
if ($user['is_admin']) {
  try {
    $stmt = $pdo->query("SELECT * FROM BEATUSRS ORDER BY REGFECXX DESC");
    $usersData = $stmt->fetchAll();
  } catch (Exception $e) {
    error_log('Users data error: ' . $e->getMessage());
  }
}

// Obtener gastos programados proximos (para notificaciones)
$upcomingExpenses = [];
try {
  $today = date('Y-m-d');
  $nextWeek = date('Y-m-d', strtotime('+7 days'));

  $stmt = $pdo->prepare("
    SELECT * FROM FINANCIX 
    WHERE REGESTXX = 'ACTIVO' 
    AND MONTGASX < 0 
    AND TIPGASXX = 'PERSONAL'
    AND FINCFECX BETWEEN :today AND :nextWeek
    AND USRIDXXX = :userId
    ORDER BY FINCFECX ASC
  ");
  $stmt->execute([':today' => $today, ':nextWeek' => $nextWeek, ':userId' => $user['id']]);
  $upcomingExpenses = $stmt->fetchAll();

  $upcomingExpensesBeatan = [];
  if ($user['is_admin']) {
    $stmtB = $pdo->prepare("
      SELECT * FROM FINANCIX 
      WHERE REGESTXX = 'ACTIVO' 
      AND MONTGASX < 0 
      AND TIPGASXX = 'BEATAN'
      AND FINCFECX BETWEEN :today AND :nextWeek
      ORDER BY FINCFECX ASC
    ");
    $stmtB->execute([':today' => $today, ':nextWeek' => $nextWeek]);
    $upcomingExpensesBeatan = $stmtB->fetchAll();
  }
} catch (Exception $e) {
  error_log('Upcoming expenses error: ' . $e->getMessage());
}

// Obtener mensajes nuevos (para notificaciones admin)
$newMessages = [];
if ($user['is_admin']) {
  try {
    $stmt = $pdo->query("SELECT COUNT(*) as count FROM FORMULARIO WHERE REGESTXX = 'ACTIVO' AND ESTADO = 'PENDIENTE'");
    $newMessages = $stmt->fetch();
  } catch (Exception $e) {
    error_log('New messages error: ' . $e->getMessage());
  }
}

// Obtener datos para la gráfica Personal (últimos 6 meses)
$chartLabels = [];
$chartIncome = [];
$chartExpense = [];
$chartBalance = [];
$chartAccumulated = [];

// Obtener datos para la gráfica BeaTaN (últimos 6 meses)
$chartLabelsBeatan = [];
$chartIncomeBeatan = [];
$chartExpenseBeatan = [];
$chartBalanceBeatan = [];
$chartAccumulatedBeatan = [];

try {
  // Query chart Personal
  $stmt = $pdo->prepare("
    SELECT 
      mes, ingresos, gastos, balance,
      SUM(balance) OVER (ORDER BY mes) AS acumulado
    FROM (
      SELECT 
        DATE_FORMAT(FINCFECX, '%Y-%m') AS mes,
        SUM(CASE WHEN MONTGASX > 0 THEN MONTGASX ELSE 0 END) AS ingresos,
        SUM(CASE WHEN MONTGASX < 0 THEN ABS(MONTGASX) ELSE 0 END) AS gastos,
        (SUM(CASE WHEN MONTGASX > 0 THEN MONTGASX ELSE 0 END) - SUM(CASE WHEN MONTGASX < 0 THEN ABS(MONTGASX) ELSE 0 END)) AS balance
      FROM FINANCIX
      WHERE REGESTXX = 'ACTIVO' AND USRIDXXX = :userId AND TIPGASXX = 'PERSONAL' AND FINCFECX >= DATE_SUB(CURDATE(), INTERVAL 6 MONTH)
      GROUP BY mes
    ) t ORDER BY mes ASC;
  ");
  $stmt->execute([':userId' => $user['id']]);
  foreach ($stmt->fetchAll() as $row) {
    if (!in_array(date("M", strtotime($row['mes'] . "-01")), $chartLabels)) {
      $chartLabels[] = date("M", strtotime($row['mes'] . "-01"));
    }
    $chartBalance[] = (float) $row['balance'];
    $chartIncome[] = (float) $row['ingresos'];
    $chartExpense[] = (float) $row['gastos'];
    $chartAccumulated[] = (float) $row['acumulado'];
  }

  // Query chart BeaTaN (solo si es admin)
  if ($user['is_admin']) {
    $stmtBeatan = $pdo->prepare("
      SELECT 
        mes, ingresos, gastos, balance,
        SUM(balance) OVER (ORDER BY mes) AS acumulado
      FROM (
        SELECT 
          DATE_FORMAT(FINCFECX, '%Y-%m') AS mes,
          SUM(CASE WHEN MONTGASX > 0 THEN MONTGASX ELSE 0 END) AS ingresos,
          SUM(CASE WHEN MONTGASX < 0 THEN ABS(MONTGASX) ELSE 0 END) AS gastos,
          (SUM(CASE WHEN MONTGASX > 0 THEN MONTGASX ELSE 0 END) - SUM(CASE WHEN MONTGASX < 0 THEN ABS(MONTGASX) ELSE 0 END)) AS balance
        FROM FINANCIX
        WHERE REGESTXX = 'ACTIVO' AND TIPGASXX = 'BEATAN' AND FINCFECX >= DATE_SUB(CURDATE(), INTERVAL 6 MONTH)
        GROUP BY mes
      ) t ORDER BY mes ASC;
    ");
    $stmtBeatan->execute();
    foreach ($stmtBeatan->fetchAll() as $row) {
      if (!in_array(date("M", strtotime($row['mes'] . "-01")), $chartLabelsBeatan)) {
        $chartLabelsBeatan[] = date("M", strtotime($row['mes'] . "-01"));
      }
      $chartBalanceBeatan[] = (float) $row['balance'];
      $chartIncomeBeatan[] = (float) $row['ingresos'];
      $chartExpenseBeatan[] = (float) $row['gastos'];
      $chartAccumulatedBeatan[] = (float) $row['acumulado'];
    }
  }

} catch (Exception $e) {
  error_log("Chart error: " . $e->getMessage());
}
?>
<!DOCTYPE html>
<html lang="es">

<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Dashboard - BeaTaNCode</title>
  <link rel="stylesheet" href="css/dashboard.css">
  <link rel="icon" type="image/png" href="img/Logo.png">
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css">
  <link href="https://fonts.googleapis.com/css2?family=Orbitron:wght@400;600;700&display=swap" rel="stylesheet">
  <link href="https://fonts.googleapis.com/css2?family=Geist:wght@400;500;600;700&display=swap" rel="stylesheet">
  <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
  <script src="https://cdnjs.cloudflare.com/ajax/libs/html2pdf.js/0.10.1/html2pdf.bundle.min.js"></script>
  <script src="https://cdnjs.cloudflare.com/ajax/libs/exceljs/4.3.0/exceljs.min.js"></script>
</head>

<body>
  <div class="dashboard-wrapper">
    <!-- Notification Container -->
    <div class="notification-container" id="notificationContainer"></div>

    <!-- Sidebar -->
    <aside class="sidebar" id="sidebar">
      <div class="sidebar-header">
        <img src="img/Logo.png" alt="Logo" class="sidebar-logo">
        <span class="sidebar-brand">BeaTaN</span>
      </div>

      <nav class="sidebar-nav">

        <?php if ($user['is_admin']) { ?>
          <div class="nav-section">
            <span class="nav-section-title">Principal</span>
            <a href="#" class="nav-item" data-tab="dashboard">
              <i class="bi bi-grid-1x2"></i>
              <span>Dashboard</span>
              <?php if ($newMessages && $newMessages['count'] > 0): ?>
                <span class="nav-badge"><?php echo $newMessages['count']; ?></span>
              <?php endif; ?>
            </a>
            <a href="#" class="nav-item" data-tab="BEATUSRS">
              <i class="bi bi-people"></i>
              <span>Usuarios</span>
            </a>
            <a href="#" class="nav-item" data-tab="cronograma">
              <i class="bi bi-calendar-event"></i>
              <span>Cronograma</span>
            </a>
            <a href="#" class="nav-item" data-tab="facturacion">
              <i class="bi bi-receipt-cutoff"></i>
              <span>Facturación</span>
            </a>
          </div>
        <?php } ?>

        <div class="nav-section">
          <span class="nav-section-title">Finanzas</span>
          <?php if ($user['is_admin']) { ?>
            <a href="#" class="nav-item" data-tab="financiero-beatan">
              <i class="bi bi-building"></i>
              <span>Financiero (BeaTaN)</span>
              <?php if (count($upcomingExpensesBeatan) > 0): ?>
                <span class="nav-badge warning"><?php echo count($upcomingExpensesBeatan); ?></span>
              <?php endif; ?>
            </a>
          <?php } ?>
          <a href="#" class="nav-item active" data-tab="financiero-personal">
            <i class="bi bi-person-circle"></i>
            <span>Financiero (Personal)</span>
            <?php if (count($upcomingExpenses) > 0): ?>
              <span class="nav-badge warning"><?php echo count($upcomingExpenses); ?></span>
            <?php endif; ?>
          </a>
        </div>

        <div class="calendar-dad">
          <div class="calendar-container">
            <div class="calendar-header">
              <h4 style="font-size: 1rem; color: #fff;">Calendario</h4>
              <div class="calendar-nav">
                <button onclick="changeMonth('beatan', -1)"><i class="bi bi-chevron-left"></i></button>
                <span class="calendar-month" id="beatan-calendar-month"></span>
                <button onclick="changeMonth('beatan', 1)"><i class="bi bi-chevron-right"></i></button>
              </div>
            </div>
            <div class="calendar-grid" id="beatan-calendar">
              <!-- Calendar days will be rendered here -->
            </div>
            <div class="calendar-legend">
              <div class="legend-item">
                <span class="legend-dot today"></span>
                <span>Hoy</span>
              </div>
              <div class="legend-item">
                <span class="legend-dot holiday"></span>
                <span>Festivo</span>
              </div>
            </div>
            <div class="holiday-info" id="beatan-holiday-info" style="display: none;">
              <h5 id="beatan-holiday-name"></h5>
              <p id="beatan-holiday-date"></p>
            </div>
          </div>
        </div>

      </nav>

      <div class="sidebar-footer">
        <div class="user-info">
          <div class="user-avatar">
            <?php
            $userId = $user['id'];
            $imgPath = "img/Equipo/{$userId}.png";

            if (file_exists($imgPath)) {
              echo "<img src='$imgPath' alt='Avatar' class='avatar-img'>";
            } else {
              echo strtoupper(substr($user['name'] ?? 'U', 0, 1));
            }
            ?>
          </div>
          <div class="user-details">
            <div class="user-name"><?php echo htmlspecialchars($user['name'] ?? 'Usuario'); ?></div>
            <div class="user-role"><?php echo $user['is_admin'] ? 'Administrador' : 'Usuario'; ?></div>
          </div>
        </div>
        <a href="logout.php" class="logout-btn">
          <i class="bi bi-box-arrow-right"></i>
          Cerrar Sesión
        </a>
      </div>
    </aside>

    <!-- Overlay for mobile -->
    <div class="sidebar-overlay" id="sidebarOverlay"></div>

    <!-- Main Content -->
    <main class="main-content">
      <header class="top-bar">
        <div class="page-title">
          <button class="mobile-toggle" id="mobileToggle">
            <i class="bi bi-list"></i>
          </button>
          <i class="bi bi-grid-1x2" id="pageIcon"></i>
          <h1 id="pageTitle">Dashboard</h1>
        </div>
        <div class="top-bar-actions" style="position: relative;">
          <button class="notification-bell" id="notificationBell" onclick="toggleNotificationPanel()">
            <i class="bi bi-bell"></i>
            <span class="notification-count" id="notificationCount" style="display: none;">0</span>
          </button>
          <span class="top-bar-date">
            <?php echo date('d/m/Y'); ?>
          </span>

          <!-- Notification Panel (ahora anclado a actions) -->
          <div class="notification-panel" id="notificationPanel">
            <div class="notification-panel-header">
              <h4><i class="bi bi-bell"></i> Notificaciones</h4>
              <button class="btn-icon" onclick="clearAllNotifications()">
                <i class="bi bi-trash"></i>
              </button>
            </div>
            <div class="notification-panel-body" id="notificationPanelBody">
              <div class="empty-notifications">
                <i class="bi bi-bell-slash"></i>
                <p>No hay notificaciones</p>
              </div>
            </div>
          </div>
        </div>
      </header>

      <div class="content-area">
        <!-- TAB: Dashboard -->
        <?php if ($user['is_admin']): ?>
          <div class="tab-content" id="tab-dashboard">
            <!-- Stats Cards -->
            <div class="stats-grid">
              <div class="stat-card">
                <div class="stat-icon primary">
                  <i class="bi bi-envelope"></i>
                </div>
                <div class="stat-info">
                  <h3><?php echo $formStats['total']; ?></h3>
                  <p>Total Mensajes</p>
                </div>
              </div>
              <div class="stat-card">
                <div class="stat-icon warning">
                  <i class="bi bi-clock"></i>
                </div>
                <div class="stat-info">
                  <h3><?php echo $formStats['pendiente']; ?></h3>
                  <p>Pendientes</p>
                </div>
              </div>
              <div class="stat-card">
                <div class="stat-icon primary">
                  <i class="bi bi-eye"></i>
                </div>
                <div class="stat-info">
                  <h3><?php echo $formStats['leido']; ?></h3>
                  <p>Leidos</p>
                </div>
              </div>
              <div class="stat-card">
                <div class="stat-icon success">
                  <i class="bi bi-check-circle"></i>
                </div>
                <div class="stat-info">
                  <h3><?php echo $formStats['respondido']; ?></h3>
                  <p>Respondidos</p>
                </div>
              </div>
            </div>

            <!-- Formulario Table -->
            <div class="card">
              <div class="card-header">
                <h3 class="card-title">
                  <i class="bi bi-table"></i>
                  Mensajes del Formulario
                </h3>
                <div class="filters-bar" style="margin-bottom: 0;">
                  <div class="filter-group">
                    <label>Estado:</label>
                    <select id="filterEstado" onchange="filterFormTable()">
                      <option value="">Todos</option>
                      <option value="PENDIENTE">Pendiente</option>
                      <option value="LEIDO">Leido</option>
                      <option value="RESPONDIDO">Respondido</option>
                      <option value="CERRADO">Cerrado</option>
                    </select>
                  </div>
                </div>
              </div>
              <div class="table-container">
                <?php if (empty($formData)): ?>
                  <div class="empty-state">
                    <i class="bi bi-inbox"></i>
                    <h3>Sin mensajes</h3>
                    <p>Aun no hay mensajes en el formulario de contacto.</p>
                  </div>
                <?php else: ?>
                  <table class="data-table" id="formTable">
                    <thead>
                      <tr>
                        <th>ID</th>
                        <th>Nombre</th>
                        <th>Email</th>
                        <th>Empresa</th>
                        <th>Asunto</th>
                        <th>Estado</th>
                        <th>Fecha</th>
                        <th>Acciones</th>
                      </tr>
                    </thead>
                    <tbody>
                      <?php foreach ($formData as $row): ?>
                        <tr data-estado="<?php echo htmlspecialchars($row['ESTADO']); ?>">
                          <td><?php echo $row['ID']; ?></td>
                          <td><?php echo htmlspecialchars($row['NOMBRE']); ?></td>
                          <td><?php echo htmlspecialchars($row['EMAIL']); ?></td>
                          <td><?php echo htmlspecialchars($row['NOMEMP'] ?? '-'); ?></td>
                          <td><?php echo htmlspecialchars($row['ASUNTO'] ?? '-'); ?></td>
                          <td>
                            <?php
                            $badgeClass = 'badge-pending';
                            switch ($row['ESTADO']) {
                              case 'LEIDO':
                                $badgeClass = 'badge-read';
                                break;
                              case 'RESPONDIDO':
                                $badgeClass = 'badge-responded';
                                break;
                              case 'CERRADO':
                                $badgeClass = 'badge-closed';
                                break;
                            }
                            ?>
                            <span class="badge <?php echo $badgeClass; ?>"><?php echo $row['ESTADO']; ?></span>
                          </td>
                          <td><?php echo date('d/m/Y H:i', strtotime($row['HORCREA'])); ?></td>
                          <td>
                            <div class="action-buttons">
                              <button class="btn-icon" onclick="viewMessage(<?php echo $row['ID']; ?>)" title="Ver">
                                <i class="bi bi-eye"></i>
                              </button>
                              <button class="btn-icon" onclick="updateMessageStatus(<?php echo $row['ID']; ?>)"
                                title="Cambiar Estado">
                                <i class="bi bi-pencil"></i>
                              </button>
                            </div>
                          </td>
                        </tr>
                      <?php endforeach; ?>
                    </tbody>
                  </table>
                <?php endif; ?>
              </div>
            </div>
          </div>

          <!-- TAB: BEATUSRS (Solo Admin) -->
          <div class="tab-content" id="tab-BEATUSRS">
            <div class="card">
              <div class="card-header">
                <h3 class="card-title">
                  <i class="bi bi-people"></i>
                  Gestion de usuarios
                </h3>
                <button class="btn-primary" onclick="openModal('addUserModal')">
                  <i class="bi bi-person-plus"></i>
                  Nuevo Usuario
                </button>
              </div>
              <div class="table-container">
                <?php if (empty($usersData)): ?>
                  <div class="empty-state">
                    <i class="bi bi-people"></i>
                    <h3>Sin Usuarios</h3>
                    <p>No hay Usuarios registrados.</p>
                  </div>
                <?php else: ?>
                  <table class="data-table" id="usersTable">
                    <thead>
                      <tr>
                        <th>ID</th>
                        <th>Nombre</th>
                        <th>Email</th>
                        <th>Celular</th>
                        <th>Rol</th>
                        <th>Estado</th>
                        <th>Registro</th>
                        <th>Acciones</th>
                      </tr>
                    </thead>
                    <tbody>
                      <?php foreach ($usersData as $userRow): ?>
                        <tr>
                          <td><?php echo $userRow['USRIDXXX']; ?></td>
                          <td><?php echo htmlspecialchars($userRow['USRNMEXX'] ?? '-'); ?></td>
                          <td><?php echo htmlspecialchars($userRow['USRMAILX'] ?? '-'); ?></td>
                          <td><?php echo htmlspecialchars($userRow['USRCELUL'] ?? '-'); ?></td>
                          <td>
                            <span class="badge <?php echo $userRow['ISADMINX'] == 1 ? 'badge-responded' : 'badge-read'; ?>">
                              <?php echo $userRow['ISADMINX'] == "SI" ? 'Admin' : 'Usuario'; ?>
                            </span>
                          </td>
                          <td>
                            <span
                              class="badge <?php echo $userRow['REGESTXX'] == 'ACTIVO' ? 'badge-responded' : 'badge-closed'; ?>">
                              <?php echo $userRow['REGESTXX']; ?>
                            </span>
                          </td>
                          <td><?php echo date('d/m/Y', strtotime($userRow['REGFECXX'])); ?></td>
                          <td>
                            <div class="action-buttons">
                              <button class="btn-icon" onclick="editUser(<?php echo $userRow['USRIDXXX']; ?>)" title="Editar">
                                <i class="bi bi-pencil"></i>
                              </button>
                              <?php if ($userRow['USRIDXXX'] != $user['id']): ?>
                                <button class="btn-icon"
                                  onclick="toggleUserStatus(<?php echo $userRow['USRIDXXX']; ?>, '<?php echo $userRow['REGESTXX']; ?>')"
                                  title="<?php echo $userRow['REGESTXX'] == 'ACTIVO' ? 'Inactivar' : 'Activar'; ?>"
                                  style="color: <?php echo $userRow['REGESTXX'] == 'ACTIVO' ? 'var(--error-color)' : 'var(--success-color)'; ?>;">
                                  <i
                                    class="bi bi-<?php echo $userRow['REGESTXX'] == 'ACTIVO' ? 'person-dash' : 'person-check'; ?>"></i>
                                </button>
                              <?php endif; ?>
                            </div>
                          </td>
                        </tr>
                      <?php endforeach; ?>
                    </tbody>
                  </table>
                <?php endif; ?>
              </div>
            </div>
          </div>
        <?php endif; ?>

        <!-- TAB: Cronograma (Solo Admin) -->
        <?php if ($user['is_admin']): ?>
          <div class="tab-content" id="tab-cronograma">
            <div class="card" style="margin-bottom: 20px;">
              <div class="card-header">
                <h3 class="card-title">
                  <i class="bi bi-calendar-event"></i>
                  Cronograma de Horas
                </h3>
                <div style="display: flex; gap: 10px;">
                  <button class="btn-primary" onclick="openModal('addProjectModal')">
                    <i class="bi bi-folder-plus"></i> Nuevo Proyecto
                  </button>
                  <button id="btnNewPhase" class="btn-secondary" onclick="openPhaseModal()" style="display: none;">
                    <i class="bi bi-calendar-plus"></i> Nueva Fase
                  </button>
                  <button class="btn-success" onclick="openHoursModal()">
                    <i class="bi bi-clock-history"></i> Registrar Horas
                  </button>
                </div>
              </div>
              
              <!-- Filtros de Cronograma -->
              <div class="filters-bar" style="margin-bottom: 0; flex-wrap: wrap; gap: 12px;">
                <div class="filter-group">
                  <label><i class="bi bi-folder"></i> Proyecto:</label>
                  <select id="cronFilterProject" onchange="onProjectChange()" style="min-width: 160px;">
                    <option value="">Todos los proyectos</option>
                  </select>
                </div>
                <div class="filter-group">
                  <label><i class="bi bi-person"></i> Colaborador:</label>
                  <select id="cronFilterUser" onchange="loadHoursLogs()" style="min-width: 160px;">
                    <option value="">Todos los colaboradores</option>
                  </select>
                </div>
                <div class="filter-group">
                  <label><i class="bi bi-calendar-range"></i> Desde:</label>
                  <input type="date" id="cronFilterStart" onchange="loadHoursLogs()">
                </div>
                <div class="filter-group">
                  <label><i class="bi bi-calendar-range"></i> Hasta:</label>
                  <input type="date" id="cronFilterEnd" onchange="loadHoursLogs()">
                </div>
                <div style="margin-left: auto; display: flex; gap: 10px; align-items: center;">
                  <button class="btn-secondary" onclick="exportCronogramaPDF(true)" title="Exportar cronograma para el cliente (Gantt)" style="padding: 8px 14px;">
                    <i class="bi bi-calendar-check"></i> Exportar Cronograma
                  </button>
                  <button class="btn-primary" onclick="exportCronogramaPDF(false)" title="Exportar reporte completo (Gantt y Horas)" style="padding: 8px 14px;">
                    <i class="bi bi-file-earmark-bar-graph"></i> Exportar Reporte
                  </button>
                </div>
              </div>
            </div>

            <!-- Diagrama de Gantt -->
            <div class="card" id="ganttCard" style="margin-bottom: 20px; display: none;">
              <div class="card-header">
                <h3 class="card-title">
                  <i class="bi bi-bar-chart-steps"></i>
                  Cronograma de Fases (Gantt)
                </h3>
              </div>
              <div style="overflow-x: auto; width: 100%;">
                <div id="ganttChartContainer" style="min-width: 700px; padding: 10px 0;">
                  <!-- Renderizado dinámico vía JS -->
                </div>
              </div>
            </div>

            <!-- Resumen de Horas -->
            <div class="stats-grid" style="margin-bottom: 20px;">
              <div class="stat-card">
                <div class="stat-icon primary">
                  <i class="bi bi-clock"></i>
                </div>
                <div class="stat-info">
                  <h3 id="cronTotalHours">0.00</h3>
                  <p>Total Horas Dedicadas</p>
                </div>
              </div>
              <div class="stat-card">
                <div class="stat-icon success">
                  <i class="bi bi-briefcase"></i>
                </div>
                <div class="stat-info">
                  <h3 id="cronTotalProjects">0</h3>
                  <p>Proyectos Activos</p>
                </div>
              </div>
            </div>

            <!-- Tabla de Registros de Horas -->
            <div class="card">
              <div class="table-container" id="hoursTableContainer">
                <div class="loading">
                  <div class="spinner"></div>
                </div>
              </div>
            </div>
          </div>

          <!-- TAB: Facturación (Solo Admin) -->
          <div class="tab-content" id="tab-facturacion">
            <div class="card" style="margin-bottom: 20px;">
              <div class="card-header">
                <h3 class="card-title">
                  <i class="bi bi-receipt-cutoff"></i>
                  Facturas y Presupuestos
                </h3>
                <button class="btn-primary" onclick="openInvoiceModal()">
                  <i class="bi bi-receipt"></i> Nueva Factura
                </button>
              </div>
              <div class="filters-bar" style="margin-bottom: 0;">
                <div class="filter-group">
                  <label>Buscar Cliente:</label>
                  <input type="text" id="invoiceSearch" placeholder="Nombre del cliente..." oninput="filterInvoicesTable()" style="min-width: 200px;">
                </div>
              </div>
            </div>

            <div class="card">
              <div class="table-container" id="invoicesTableContainer">
                <div class="loading">
                  <div class="spinner"></div>
                </div>
              </div>
            </div>
          </div>
        <?php endif; ?>

        <!-- TAB: Financiero BeaTaN -->
        <?php if ($user['is_admin']): ?>
          <div class="tab-content" id="tab-financiero-beatan">
            <div class="financial-layout">
              <div class="financial-main">
                <!-- Balance Cards -->
                <div class="balance-grid">
                  <div class="balance-card total">
                    <h4>Balance Total</h4>
                    <div class="amount positive" id="beatan-balance">$0</div>
                  </div>
                  <div class="balance-card income">
                    <h4>Ingresos</h4>
                    <div class="amount positive" id="beatan-income">$0</div>
                  </div>
                  <div class="balance-card expense">
                    <h4>Gastos</h4>
                    <div class="amount negative" id="beatan-expense">$0</div>
                  </div>
                </div>

                <!-- Add Transaction -->
                <div id="movBeatan" class="modal-overlay">
                  <div class="modal">
                    <div class="modal-header">
                      <h3>
                        <i class="bi bi-plus-circle"></i>
                        Nuevo Movimiento BeaTaN
                      </h3>
                      <button class="modal-close" onclick="closeModal('movBeatan')">&times;</button>
                    </div>
                    <div class="modal-body">
                      <form class="transaction-form" id="beatanTransactionForm" onsubmit="addTransaction(event, 'BEATAN')">

                        <!-- Tipo como selector visual -->
                        <div class="form-group full-width">
                          <label><i class="bi bi-tag"></i> Tipo *</label>
                          <div class="type-selector">
                            <label class="type-option">
                              <input type="radio" name="tipo" value="INGRESO" required>
                              <span class="type-pill income"><i class="bi bi-arrow-down-left"></i> Ingreso</span>
                            </label>
                            <label class="type-option">
                              <input type="radio" name="tipo" value="GASTO">
                              <span class="type-pill expense"><i class="bi bi-arrow-up-right"></i> Gasto</span>
                            </label>
                          </div>
                        </div>

                        <div class="form-group">
                          <label><i class="bi bi-currency-dollar"></i> Monto *</label>
                          <input type="number" name="monto" step="0.01" min="0" placeholder="0.00" required>
                        </div>
                        <div class="form-group">
                          <label><i class="bi bi-calendar"></i> Fecha *</label>
                          <input type="date" name="fecha" required value="<?php echo date('Y-m-d'); ?>">
                        </div>
                        <div class="form-group">
                          <label><i class="bi bi-bookmark"></i> Categoría *</label>
                          <select name="categoria" required>
                            <option value="">Seleccionar...</option>
                            <option value="Ventas">Ventas</option>
                            <option value="Servicios">Servicios</option>
                            <option value="Inversiones">Inversiones</option>
                            <option value="Salarios">Salarios</option>
                            <option value="Marketing">Marketing</option>
                            <option value="Infraestructura">Infraestructura</option>
                            <option value="Operaciones">Operaciones</option>
                            <option value="Impuestos">Impuestos</option>
                            <option value="Otros">Otros</option>
                          </select>
                        </div>
                        <div class="form-group">
                          <label><i class="bi bi-link-45deg"></i> Deuda Asociada</label>
                          <select name="deudidxx" id="beatanAddDeudidxx">
                            <option value="">Sin deuda</option>
                          </select>
                        </div>
                        <div class="form-group full-width">
                          <label><i class="bi bi-text-left"></i> Descripción</label>
                          <input type="text" name="descripcion" placeholder="Descripción del movimiento..." maxlength="150">
                        </div>
                        <button type="submit" class="btn-primary full-width-btn">
                          <i class="bi bi-plus-lg"></i>
                          Registrar Movimiento
                        </button>
                      </form>
                    </div>
                  </div>
                </div>

                <!-- Chart rendimiento (movido arriba) -->
                <div class="card" style="margin-bottom: 22px;">
                  <p class="chart-card-title"><i class="bi bi-graph-up"></i> Rendimiento</p>
                  <canvas id="performanceChartBeatan"></canvas>
                </div>

                <div class="financial-tables-grid">
                  <!-- Transactions Table -->
                  <div class="card">
                  <div class="card-header">
                    <h3 class="card-title">
                      <i class="bi bi-list-ul"></i>
                      Movimientos
                      <button class="btn-secondary" onclick="openBeatanModal()">
                        <i class="bi bi-plus"></i>
                      </button>
                    </h3>
                    <div class="filters-bar" style="margin-bottom: 0; flex-wrap: wrap; gap: 8px;">
                      <div class="filter-group">
                        <label>Tipo:</label>
                        <select id="beatanFilterTipo" onchange="loadTransactions('BEATAN')">
                          <option value="">Todos</option>
                          <option value="INGRESO">Ingresos</option>
                          <option value="GASTO">Gastos</option>
                        </select>
                      </div>
                      <div class="filter-group">
                        <label>Mes:</label>
                        <input type="month" id="beatanFilterMonth" onchange="loadTransactions('BEATAN')"
                          value="<?php echo date('Y-m'); ?>">
                      </div>
                      <div class="filter-group">
                        <label>Categoría:</label>
                        <select id="beatanFilterCategoria" onchange="loadTransactions('BEATAN')">
                          <option value="">Todas</option>
                          <option value="Ventas">Ventas</option>
                          <option value="Servicios">Servicios</option>
                          <option value="Inversiones">Inversiones</option>
                          <option value="Salarios">Salarios</option>
                          <option value="Marketing">Marketing</option>
                          <option value="Infraestructura">Infraestructura</option>
                          <option value="Operaciones">Operaciones</option>
                          <option value="Impuestos">Impuestos</option>
                          <option value="Otros">Otros</option>
                        </select>
                      </div>
                      <div class="filter-group">
                        <label>Buscar:</label>
                        <input type="text" id="beatanFilterSearch" placeholder="Descripción..." oninput="debounceLoadTransactions('BEATAN')" style="min-width:140px;">
                      </div>
                      <button class="btn-secondary" onclick="openReportModal('BEATAN')" title="Generar Reporte" style="margin-left:auto;">
                        <i class="bi bi-file-earmark-bar-graph"></i> Reporte
                      </button>
                    </div>
                  </div>
                  <div class="table-container" id="beatanTransactionsTable">
                    <div class="loading">
                      <div class="spinner"></div>
                    </div>
                  </div>
                </div>

                <!-- Card deudas BeaTaN -->
                <div class="card">
                  <div class="card-header">
                    <div class="debt-card-title">
                      <div class="debt-card-title-left">
                        <i class="bi bi-credit-card-2-front"></i>
                        Deudas BeaTaN
                      </div>
                      <div class="debt-card-actions">
                        <button class="btn-primary" onclick="openAddDebtModal('BEATAN')">
                          <i class="bi bi-plus-lg"></i>
                          Nueva
                        </button>
                      </div>
                    </div>
                  </div>
                  <div class="debt-filters">
                    <select id="beatanDebtFilterEstado" class="debt-filter-select" onchange="loadDebts('BEATAN')">
                      <option value="ACTIVO">Activas</option>
                      <option value="PAGADO">Pagadas</option>
                      <option value="INACTIVO">Inactivas</option>
                    </select>
                    <select id="beatanDebtFilterTipo" class="debt-filter-select" onchange="loadDebts('BEATAN')">
                      <option value="">Todos los tipos</option>
                      <option value="A FAVOR">A favor</option>
                      <option value="EN CONTRA">En contra</option>
                    </select>
                    <input type="text" id="beatanDebtSearch" class="debt-filter-input" placeholder="&#x1F50D; Buscar deuda..." oninput="debounceLoadDebts('BEATAN')">
                  </div>
                  <div class="table-container" id="beatanDebtsTable">
                    <div class="loading">
                      <div class="spinner"></div>
                    </div>
                  </div>
                </div>
                </div> <!-- fin financial-tables-grid -->
              </div>
            </div>
          </div>
        <?php endif; ?>

        <!-- TAB: Financiero Personal -->
        <div class="tab-content active" id="tab-financiero-personal">
          <div class="financial-layout">
            <div class="financial-main">
              <!-- Balance Cards -->
              <div class="balance-grid">
                <div class="balance-card total">
                  <h4>Balance Total</h4>
                  <div class="amount positive" id="personal-balance">$0</div>
                </div>
                <div class="balance-card income">
                  <h4>Ingresos</h4>
                  <div class="amount positive" id="personal-income">$0</div>
                </div>
                <div class="balance-card expense">
                  <h4>Gastos</h4>
                  <div class="amount negative" id="personal-expense">$0</div>
                </div>
              </div>

              <!-- Add Transaction -->
              <div id="movPersonal" class="modal-overlay">
                <div class="modal">
                  <div class="modal-header">
                    <h3>
                      <i class="bi bi-plus-circle"></i>
                      Nuevo Movimiento Personal
                    </h3>
                    <button class="modal-close" onclick="closeModal('movPersonal')">&times;</button>
                  </div>
                  <div class="modal-body">
                    <form class="transaction-form" id="personalTransactionForm"
                      onsubmit="addTransaction(event, 'PERSONAL')">

                      <!-- Tipo como selector visual -->
                      <div class="form-group full-width">
                        <label><i class="bi bi-tag"></i> Tipo *</label>
                        <div class="type-selector">
                          <label class="type-option">
                            <input type="radio" name="tipo" value="INGRESO" required>
                            <span class="type-pill income"><i class="bi bi-arrow-down-left"></i> Ingreso</span>
                          </label>
                          <label class="type-option">
                            <input type="radio" name="tipo" value="GASTO">
                            <span class="type-pill expense"><i class="bi bi-arrow-up-right"></i> Gasto</span>
                          </label>
                        </div>
                      </div>

                      <div class="form-group">
                        <label><i class="bi bi-currency-dollar"></i> Monto *</label>
                        <input type="number" name="monto" step="0.01" min="0" placeholder="0.00" required>
                      </div>
                      <div class="form-group">
                        <label><i class="bi bi-calendar"></i> Fecha *</label>
                        <input type="date" name="fecha" required value="<?php echo date('Y-m-d'); ?>">
                      </div>
                      <div class="form-group">
                        <label><i class="bi bi-bookmark"></i> Categoría *</label>
                        <select name="categoria" required>
                          <option value="">Seleccionar...</option>
                          <option value="Salario">Salario</option>
                          <option value="Freelance">Freelance</option>
                          <option value="Inversiones">Inversiones</option>
                          <option value="Alimentacion">Alimentacion</option>
                          <option value="Transporte">Transporte</option>
                          <option value="Vivienda">Vivienda</option>
                          <option value="Servicios">Servicios</option>
                          <option value="Entretenimiento">Entretenimiento</option>
                          <option value="Salud">Salud</option>
                          <option value="Educacion">Educacion</option>
                          <option value="Otros">Otros</option>
                        </select>
                      </div>
                      <div class="form-group">
                        <label><i class="bi bi-link-45deg"></i> Deuda Asociada</label>
                        <select name="deudidxx" id="addDeudidxx">
                          <option value="">Sin deuda</option>
                        </select>
                      </div>
                      <div class="form-group full-width">
                        <label><i class="bi bi-text-left"></i> Descripción</label>
                        <input type="text" name="descripcion" placeholder="Descripción del movimiento..." maxlength="150">
                      </div>
                      <button type="submit" class="btn-primary full-width-btn">
                        <i class="bi bi-plus-lg"></i>
                        Registrar Movimiento
                      </button>
                    </form>
                  </div>
                </div>
              </div>

              <!-- Chart rendimiento (movido arriba) -->
              <div class="card" style="margin-bottom: 22px;">
                <p class="chart-card-title"><i class="bi bi-graph-up"></i> Rendimiento</p>
                <canvas id="performanceChart"></canvas>
              </div>

              <div class="financial-tables-grid">
                <!-- Transactions Table -->
                <div class="card">
                <div class="card-header">
                  <h3 class="card-title">
                    <i class="bi bi-currency-dollar"></i>
                    Movimientos Personales
                    <button class="btn-secondary" onclick="openPersonalModal()">
                      <i class="bi bi-plus"></i>
                    </button>
                  </h3>
                  <div class="filters-bar" style="margin-bottom: 0; flex-wrap: wrap; gap: 8px;">
                    <div class="filter-group">
                      <label>Tipo:</label>
                      <select id="personalFilterTipo" onchange="loadTransactions('PERSONAL')">
                        <option value="">Todos</option>
                        <option value="INGRESO">Ingresos</option>
                        <option value="GASTO">Gastos</option>
                      </select>
                    </div>
                    <div class="filter-group">
                      <label>Mes:</label>
                      <input type="month" id="personalFilterMonth" onchange="loadTransactions('PERSONAL')"
                        value="<?php echo date('Y-m'); ?>">
                    </div>
                    <div class="filter-group">
                      <label>Categoría:</label>
                      <select id="personalFilterCategoria" onchange="loadTransactions('PERSONAL')">
                        <option value="">Todas</option>
                        <option value="Salario">Salario</option>
                        <option value="Freelance">Freelance</option>
                        <option value="Inversiones">Inversiones</option>
                        <option value="Alimentacion">Alimentacion</option>
                        <option value="Transporte">Transporte</option>
                        <option value="Vivienda">Vivienda</option>
                        <option value="Servicios">Servicios</option>
                        <option value="Entretenimiento">Entretenimiento</option>
                        <option value="Salud">Salud</option>
                        <option value="Educacion">Educacion</option>
                        <option value="Otros">Otros</option>
                      </select>
                    </div>
                    <div class="filter-group">
                      <label>Buscar:</label>
                      <input type="text" id="personalFilterSearch" placeholder="Descripción..." oninput="debounceLoadTransactions('PERSONAL')" style="min-width:140px;">
                    </div>
                    <button class="btn-secondary" onclick="openReportModal('PERSONAL')" title="Generar Reporte" style="margin-left:auto;">
                      <i class="bi bi-file-earmark-bar-graph"></i> Reporte
                    </button>
                  </div>
                </div>
                <div class="table-container" id="personalTransactionsTable">
                  <div class="loading">
                    <div class="spinner"></div>
                  </div>
                </div>
              </div>

              <!-- Card deudas -->
              <div class="card">
                <div class="card-header">
                  <div class="debt-card-title">
                    <div class="debt-card-title-left">
                      <i class="bi bi-credit-card-2-front"></i>
                      Mis Deudas
                    </div>
                    <div class="debt-card-actions">
                      <button class="btn-primary" onclick="openAddDebtModal('PERSONAL')">
                        <i class="bi bi-plus-lg"></i>
                        Nueva
                      </button>
                    </div>
                  </div>
                </div>
                <div class="debt-filters">
                  <select id="personalDebtFilterEstado" class="debt-filter-select" onchange="loadDebts('PERSONAL')">
                    <option value="ACTIVO">Activas</option>
                    <option value="PAGADO">Pagadas</option>
                    <option value="INACTIVO">Inactivas</option>
                  </select>
                  <select id="personalDebtFilterTipo" class="debt-filter-select" onchange="loadDebts('PERSONAL')">
                    <option value="">Todos los tipos</option>
                    <option value="A FAVOR">A favor</option>
                    <option value="EN CONTRA">En contra</option>
                  </select>
                  <input type="text" id="personalDebtSearch" class="debt-filter-input" placeholder="&#x1F50D; Buscar deuda..." oninput="debounceLoadDebts('PERSONAL')">
                </div>
                <div class="table-container" id="personalDebtsTable">
                  <div class="loading">
                    <div class="spinner"></div>
                  </div>
                </div>
              </div>
              </div> <!-- fin financial-tables-grid -->
            </div>
          </div>
        </div>
      </div>
    </main>
  </div>

  <!-- Modal for View Message -->
  <div class="modal-overlay" id="messageModal">
    <div class="modal">
      <div class="modal-header">
        <h3><i class="bi bi-envelope-open"></i> Detalle del Mensaje</h3>
        <button class="modal-close" onclick="closeModal('messageModal')">&times;</button>
      </div>
      <div class="modal-body" id="messageModalBody">
        <!-- Content loaded dynamically -->
      </div>
      <div class="modal-footer">
        <button class="btn-secondary" onclick="closeModal('messageModal')">Cerrar</button>
      </div>
    </div>
  </div>

  <!-- Modal for Edit Transaction -->
  <div class="modal-overlay" id="editTransactionModal">
    <div class="modal">
      <div class="modal-header">
        <h3><i class="bi bi-pencil"></i> Editar Movimiento</h3>
        <button class="modal-close" onclick="closeModal('editTransactionModal')">&times;</button>
      </div>
      <div class="modal-body">
        <form id="editTransactionForm" onsubmit="updateTransaction(event)">
          <input type="hidden" name="id" id="editTransactionId">
          <input type="hidden" name="tipgasxx" id="editTransactionTipgasxx">
          <div class="form-group">
            <label><i class="bi bi-tag"></i> Tipo</label>
            <select name="tipo" id="editTransactionTipo" required>
              <option value="INGRESO">Ingreso</option>
              <option value="GASTO">Gasto</option>
            </select>
          </div>

          <div class="form-group">
            <label><i class="bi bi-tag"></i> Deuda Asociada</label>
            <select name="deudidxx" id="editTransactionDeudidxx">
              <option value="">Seleccione una deuda</option>
            </select>
          </div>

          <div class="form-group">
            <label><i class="bi bi-currency-dollar"></i> Monto</label>
            <input type="number" name="monto" id="editTransactionMonto" step="0.01" min="0" required>
          </div>
          <div class="form-group">
            <label><i class="bi bi-bookmark"></i> Categoría</label>
            <input type="text" name="categoria" id="editTransactionCategoria" required>
          </div>
          <div class="form-group">
            <label><i class="bi bi-calendar"></i> Fecha</label>
            <input type="date" name="fecha" id="editTransactionFecha" required>
          </div>
          <div class="form-group">
            <label><i class="bi bi-text-left"></i> Descripción</label>
            <input type="text" name="descripcion" id="editTransactionDescripcion" maxlength="150">
          </div>
        </form>
      </div>
      <div class="modal-footer">
        <button class="btn-secondary" onclick="closeModal('editTransactionModal')">Cancelar</button>
        <button class="btn-primary"
          onclick="document.getElementById('editTransactionForm').dispatchEvent(new Event('submit'))">
          <i class="bi bi-check-lg"></i> Guardar
        </button>
      </div>
    </div>
  </div>

  <!-- Modal Add Debt -->
  <div class="modal-overlay" id="addDebtModal">
    <div class="modal">
      <div class="modal-header">
        <h3><i class="bi bi-credit-card"></i> Nueva Deuda</h3>
        <button class="modal-close" onclick="closeModal('addDebtModal')">&times;</button>
      </div>
      <div class="modal-body">
        <form id="addDebtForm" onsubmit="addDebt(event)">
          <input type="hidden" id="addDebtTipperxx" name="tipperxx">
          <div class="form-group">
            <label>Tipo deuda *</label>
            <select name="tipdeudx" required>
              <option value="A FAVOR">A favor</option>
              <option value="EN CONTRA">En contra</option>
            </select>
          </div>

          <div class="form-group">
            <label>Día de pago de cuota</label>
            <input type="number" name="feccuotx" min="1" max="31" step="1"
              oninput="if(this.value > 31) this.value = 31; if(this.value < 1) this.value = 1;">
          </div>

          <div class="form-group">
            <label>Monto total *</label>
            <input type="number" class="mondeudx" name="mondeudx" step="0.01" required>
          </div>

          <div class="form-group">
            <label>N° Cuotas</label>
            <input type="number" class="numcuotx" name="numcuotx" min="1">
          </div>

          <div class="form-group">
            <label>Valor cuota</label>
            <input type="number" class="moncuotx" name="moncuotx" step="0.01" min="0" placeholder="0.00">
          </div>

          <div class="form-group full-width">
            <label>Descripción *</label>
            <input type="text" name="desdeudx" maxlength="150" required>
          </div>

          <button type="submit" class="btn-primary">
            Guardar deuda
          </button>

        </form>
      </div>
    </div>
  </div>

  <!-- Modal Edit Debt -->
  <div class="modal-overlay" id="editDebtModal">
    <div class="modal">
      <div class="modal-header">
        <h3><i class="bi bi-credit-card"></i> Editar Deuda</h3>
        <button class="modal-close" onclick="closeModal('editDebtModal')">&times;</button>
      </div>
      <div class="modal-body">
        <form id="editDebtForm" onsubmit="updateDebt(event)">

          <input type="hidden" name="id" id="editDebtId">
          <input type="hidden" name="tipperxx" id="editDebtTipperxx">

          <div class="form-group">
            <label>Tipo deuda *</label>
            <select name="tipdeudx" id="editDebtTipo" required>
              <option value="A FAVOR">A favor</option>
              <option value="EN CONTRA">En contra</option>
            </select>
          </div>

          <div class="form-group">
            <label>Día de pago de cuota</label>
            <input type="number" name="feccuotx" id="editDebtFecha" min="1" max="31" step="1"
              oninput="if(this.value > 31) this.value = 31; if(this.value < 1) this.value = 1;">
          </div>

          <div class="form-group">
            <label>Monto total *</label>
            <input type="number" id="mondeudx" name="mondeudx" step="0.01" required>
          </div>

          <div class="form-group">
            <label>N° Cuotas</label>
            <input type="number" id="numcuotx" name="numcuotx" min="1">
          </div>

          <div class="form-group">
            <label>Valor cuota</label>
            <input type="number" id="moncuotx" name="moncuotx" step="0.01" min="0" placeholder="0.00">
          </div>

          <div class="form-group full-width">
            <label>Descripción *</label>
            <input type="text" name="desdeudx" id="editDebtDesc" maxlength="150" required>
          </div>

          <button type="submit" class="btn-primary">
            Guardar deuda
          </button>

        </form>
      </div>
    </div>
  </div>

  <!-- Modal for Update Message Status -->
  <div class="modal-overlay" id="statusModal">
    <div class="modal">
      <div class="modal-header">
        <h3><i class="bi bi-pencil"></i> Cambiar Estado</h3>
        <button class="modal-close" onclick="closeModal('statusModal')">&times;</button>
      </div>
      <div class="modal-body">
        <form id="statusForm" onsubmit="saveMessageStatus(event)">
          <input type="hidden" name="id" id="statusMessageId">
          <div class="form-group">
            <label><i class="bi bi-flag"></i> Estado</label>
            <select name="estado" id="statusSelect" required>
              <option value="PENDIENTE">Pendiente</option>
              <option value="LEIDO">Leído</option>
              <option value="RESPONDIDO">Respondido</option>
              <option value="CERRADO">Cerrado</option>
            </select>
          </div>
        </form>
      </div>
      <div class="modal-footer">
        <button class="btn-secondary" onclick="closeModal('statusModal')">Cancelar</button>
        <button class="btn-primary" onclick="document.getElementById('statusForm').dispatchEvent(new Event('submit'))">
          <i class="bi bi-check-lg"></i> Guardar
        </button>
      </div>
    </div>
  </div>

  <!-- Modal for Add User -->
  <div class="modal-overlay" id="addUserModal">
    <div class="modal">
      <div class="modal-header">
        <h3><i class="bi bi-person-plus"></i> Nuevo Usuario</h3>
        <button class="modal-close" onclick="closeModal('addUserModal')">&times;</button>
      </div>
      <div class="modal-body">
        <form id="addUserForm" onsubmit="saveNewUser(event)">
          <div class="form-group">
            <label><i class="bi bi-person-badge"></i> Cédula</label>
            <input type="text" name="cedula" id="addUserCedula" required maxlength="100">
          </div>
          <div class="form-group">
            <label><i class="bi bi-person"></i> Nombre Completo</label>
            <input type="text" name="nombre" id="addUserNombre" required maxlength="100">
          </div>
          <div class="form-group">
            <label><i class="bi bi-envelope"></i> Email</label>
            <input type="email" name="email" id="addUserEmail" required maxlength="100">
          </div>
          <div class="form-group">
            <label><i class="bi bi-phone"></i> Celular</label>
            <input type="tel" name="celular" id="addUserCelular" maxlength="20">
          </div>
          <div class="form-group">
            <label><i class="bi bi-key"></i> Contrasena</label>
            <input type="password" name="password" id="addUserPassword" required minlength="6">
          </div>
          <div class="form-group">
            <label><i class="bi bi-shield"></i> Rol</label>
            <select name="admin" id="addUserAdmin">
              <option value="NO">Usuario</option>
              <option value="SI">Administrador</option>
            </select>
          </div>
        </form>
      </div>
      <div class="modal-footer">
        <button class="btn-secondary" onclick="closeModal('addUserModal')">Cancelar</button>
        <button class="btn-primary" onclick="document.getElementById('addUserForm').dispatchEvent(new Event('submit'))">
          <i class="bi bi-check-lg"></i> Crear Usuario
        </button>
      </div>
    </div>
  </div>

  <!-- Modal for Edit User -->
  <div class="modal-overlay" id="editUserModal">
    <div class="modal">
      <div class="modal-header">
        <h3><i class="bi bi-person-gear"></i> Editar Usuario</h3>
        <button class="modal-close" onclick="closeModal('editUserModal')">&times;</button>
      </div>
      <div class="modal-body">
        <form id="editUserForm" onsubmit="updateUser(event)">
          <input type="hidden" name="id" id="editUserId">
          <div class="form-group">
            <label><i class="bi bi-person"></i> Nombre Completo</label>
            <input type="text" name="nombre" id="editUserNombre" required maxlength="100">
          </div>
          <div class="form-group">
            <label><i class="bi bi-envelope"></i> Email</label>
            <input type="email" name="email" id="editUserEmail" required maxlength="100">
          </div>
          <div class="form-group">
            <label><i class="bi bi-phone"></i> Celular</label>
            <input type="tel" name="celular" id="editUserCelular" maxlength="20">
          </div>
          <div class="form-group">
            <label><i class="bi bi-key"></i> Nueva Contrasena (dejar vacio para no cambiar)</label>
            <input type="password" name="password" id="editUserPassword" minlength="6">
          </div>
          <div class="form-group">
            <label><i class="bi bi-shield"></i> Rol</label>
            <select name="admin" id="editUserAdmin">
              <option value="NO">Usuario</option>
              <option value="SI">Administrador</option>
            </select>
          </div>
        </form>
      </div>
      <div class="modal-footer">
        <button class="btn-secondary" onclick="closeModal('editUserModal')">Cancelar</button>
        <button class="btn-primary"
          onclick="document.getElementById('editUserForm').dispatchEvent(new Event('submit'))">
          <i class="bi bi-check-lg"></i> Guardar Cambios
        </button>
      </div>
    </div>
  </div>

  <!-- Modal para Nueva Fase/Hito -->
  <div class="modal-overlay" id="addPhaseModal">
    <div class="modal">
      <div class="modal-header">
        <h3><i class="bi bi-calendar-plus"></i> Nueva Fase / Hito</h3>
        <button class="modal-close" onclick="closeModal('addPhaseModal')">&times;</button>
      </div>
      <div class="modal-body">
        <form id="addPhaseForm" onsubmit="saveNewPhase(event)">
          <input type="hidden" id="addPhaseProjId" name="proyidxx">
          <div class="form-group">
            <label>Nombre de la Fase / Hito *</label>
            <input type="text" id="addPhaseName" name="fasenomx" required placeholder="Ej: Diseño de UI, Cierre de Proyecto">
          </div>
          <div class="form-row">
            <div class="form-group">
              <label>Fecha de Inicio *</label>
              <input type="date" id="addPhaseStart" name="fasefeci" required value="<?php echo date('Y-m-d'); ?>">
            </div>
            <div class="form-group">
              <label>Fecha de Término *</label>
              <input type="date" id="addPhaseEnd" name="fasefect" required value="<?php echo date('Y-m-d'); ?>">
            </div>
          </div>
          <div class="form-group" style="flex-direction: row; align-items: center; gap: 10px; margin-top: 10px;">
            <input type="checkbox" id="addPhaseIsHito" name="fasehito_check" onchange="toggleHitoCheckbox(this)" style="width: 18px; height: 18px; cursor: pointer;">
            <label for="addPhaseIsHito" style="cursor: pointer; margin: 0; display: inline-flex; align-items: center; gap: 5px;">
              ¿Es un Hito? (Milestone)
            </label>
            <input type="hidden" id="addPhaseHitoHidden" name="fasehito" value="NO">
          </div>
        </form>
      </div>
      <div class="modal-footer">
        <button class="btn-secondary" onclick="closeModal('addPhaseModal')">Cancelar</button>
        <button class="btn-primary" onclick="document.getElementById('addPhaseForm').dispatchEvent(new Event('submit'))">
          <i class="bi bi-check-lg"></i> Guardar Fase
        </button>
      </div>
    </div>
  </div>

  <!-- Modal para Agregar Proyecto -->
  <div class="modal-overlay" id="addProjectModal">
    <div class="modal">
      <div class="modal-header">
        <h3><i class="bi bi-folder-plus"></i> Nuevo Proyecto</h3>
        <button class="modal-close" onclick="closeModal('addProjectModal')">&times;</button>
      </div>
      <div class="modal-body">
        <form id="addProjectForm" onsubmit="saveNewProject(event)">
          <div class="form-group">
            <label>Nombre del Proyecto *</label>
            <input type="text" id="addProjName" name="nombre" required placeholder="Nombre del proyecto">
          </div>
          <div class="form-group">
            <label>Descripción</label>
            <textarea id="addProjDesc" name="descripcion" placeholder="Descripción del proyecto..." rows="3"></textarea>
          </div>
          <div class="form-group">
            <label>Estado Inicial</label>
            <select id="addProjStatus" name="estado">
              <option value="PLANIFICACION">Planificación</option>
              <option value="DESARROLLO" selected>Desarrollo</option>
              <option value="TERMINADO">Terminado</option>
              <option value="INACTIVO">Inactivo</option>
            </select>
          </div>
        </form>
      </div>
      <div class="modal-footer">
        <button class="btn-secondary" onclick="closeModal('addProjectModal')">Cancelar</button>
        <button class="btn-primary" onclick="document.getElementById('addProjectForm').dispatchEvent(new Event('submit'))">
          <i class="bi bi-check-lg"></i> Guardar Proyecto
        </button>
      </div>
    </div>
  </div>

  <!-- Modal para Registrar Horas -->
  <div class="modal-overlay" id="addHoursModal">
    <div class="modal">
      <div class="modal-header">
        <h3><i class="bi bi-clock-history"></i> Registrar Horas</h3>
        <button class="modal-close" onclick="closeModal('addHoursModal')">&times;</button>
      </div>
      <div class="modal-body">
        <form id="addHoursForm" onsubmit="saveNewHoursLog(event)">
          <div class="form-group">
            <label>Proyecto *</label>
            <select id="addHoursProject" name="proyidxx" required>
              <option value="">Seleccionar proyecto...</option>
            </select>
          </div>
          <div class="form-group">
            <label>Colaborador *</label>
            <select id="addHoursUser" name="usridxxx" required>
              <option value="">Seleccionar colaborador...</option>
            </select>
          </div>
          <div class="form-group">
            <label>Horas Dedicadas *</label>
            <input type="number" id="addHoursDed" name="horadedx" step="0.25" min="0.25" placeholder="0.00" required>
          </div>
          <div class="form-group">
            <label>Fecha *</label>
            <input type="date" id="addHoursDate" name="horafecx" required value="<?php echo date('Y-m-d'); ?>">
          </div>
          <div class="form-group">
            <label>Descripción de tareas</label>
            <textarea id="addHoursDesc" name="horadesx" placeholder="Qué tareas realizaste..." rows="3"></textarea>
          </div>
        </form>
      </div>
      <div class="modal-footer">
        <button class="btn-secondary" onclick="closeModal('addHoursModal')">Cancelar</button>
        <button class="btn-primary" onclick="document.getElementById('addHoursForm').dispatchEvent(new Event('submit'))">
          <i class="bi bi-check-lg"></i> Registrar
        </button>
      </div>
    </div>
  </div>

  <!-- Modal para Nueva Factura/Presupuesto -->
  <div class="modal-overlay" id="addInvoiceModal">
    <div class="modal" style="max-width: 800px; width: 95%;">
      <div class="modal-header">
        <h3><i class="bi bi-receipt"></i> Nueva Factura / Presupuesto</h3>
        <button class="modal-close" onclick="closeModal('addInvoiceModal')">&times;</button>
      </div>
      <div class="modal-body">
        <form id="addInvoiceForm" onsubmit="saveNewInvoice(event)">
          <div class="form-row">
            <div class="form-group">
              <label>Número de Factura *</label>
              <input type="text" id="addInvNum" name="factnumx" required placeholder="FAC-001">
            </div>
            <div class="form-group">
              <label>Proyecto Asociado</label>
              <select id="addInvProject" name="proyidxx">
                <option value="">Ninguno</option>
              </select>
            </div>
          </div>
          <div class="form-row">
            <div class="form-group">
              <label>Fecha de Emisión *</label>
              <input type="date" id="addInvDate" name="factfecx" required value="<?php echo date('Y-m-d'); ?>">
            </div>
            <div class="form-group">
              <label>Fecha de Vencimiento *</label>
              <input type="date" id="addInvDueDate" name="factvenx" required value="<?php echo date('Y-m-d', strtotime('+30 days')); ?>">
            </div>
          </div>
          <hr style="border: 0; border-top: 1px solid var(--border-color); margin: 15px 0;">
          <h4 style="margin-bottom: 10px; color: var(--primary-cyan); font-family: var(--font-title); font-size: 0.95rem;">Datos del Cliente</h4>
          <div class="form-row">
            <div class="form-group">
              <label>Identificación Cliente (NIT/RUT/CC)</label>
              <input type="text" id="addInvClientId" name="clieidxx" placeholder="123456789-0">
            </div>
            <div class="form-group">
              <label>Nombre Cliente *</label>
              <input type="text" id="addInvClientName" name="clienomx" required placeholder="Nombre o Razón Social">
            </div>
          </div>
          <div class="form-row">
            <div class="form-group">
              <label>Email Cliente</label>
              <input type="email" id="addInvClientMail" name="cliemlxx" placeholder="cliente@correo.com">
            </div>
            <div class="form-group">
              <label>Celular Cliente</label>
              <input type="text" id="addInvClientCell" name="cliecell" placeholder="3001234567">
            </div>
          </div>
          <div class="form-group" style="margin-bottom: 15px;">
            <label>Dirección Cliente</label>
            <input type="text" id="addInvClientDir" name="cliedirx" placeholder="Calle 123 # 45-67">
          </div>
          
          <hr style="border: 0; border-top: 1px solid var(--border-color); margin: 15px 0;">
          <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 15px;">
            <h4 style="color: var(--primary-cyan); margin: 0; font-family: var(--font-title); font-size: 0.95rem;">Detalle de Conceptos</h4>
            <button type="button" class="btn-secondary" onclick="addInvoiceItemRow()" style="padding: 5px 12px; font-size: 0.8rem;">
              <i class="bi bi-plus-lg"></i> Añadir Item
            </button>
          </div>
          
          <table class="data-table" id="invoiceItemsTable" style="margin-bottom: 15px;">
            <thead>
              <tr>
                <th>Concepto / Descripción</th>
                <th style="width: 100px;">Cant.</th>
                <th style="width: 150px;">Val. Unitario</th>
                <th style="width: 150px;">Total</th>
                <th style="width: 50px;"></th>
              </tr>
            </thead>
            <tbody id="invoiceItemsBody">
              <!-- Dinámico -->
            </tbody>
          </table>

          <div style="display: flex; justify-content: flex-end;">
            <div style="width: 300px; display: flex; flex-direction: column; gap: 8px;">
              <div style="display: flex; justify-content: space-between; align-items: center;">
                <span>Subtotal:</span>
                <span id="invoiceSubtotal">$0.00</span>
              </div>
              <div style="display: flex; justify-content: space-between; align-items: center; gap: 10px;">
                <span>IVA (%):</span>
                <input type="number" id="invoiceIvaPct" name="factivax" value="0" min="0" max="100" style="width: 70px; text-align: right; padding: 4px;" oninput="recalculateInvoiceTotals()">
              </div>
              <div style="display: flex; justify-content: space-between; align-items: center; gap: 10px;">
                <span>Descuento ($):</span>
                <input type="number" id="invoiceDiscount" name="factdesc" value="0" min="0" style="width: 100px; text-align: right; padding: 4px;" oninput="recalculateInvoiceTotals()">
              </div>
              <div style="display: flex; justify-content: space-between; align-items: center; font-weight: bold; border-top: 1px solid var(--border-color); padding-top: 8px; color: var(--primary-cyan);">
                <span>Total:</span>
                <span id="invoiceTotal">$0.00</span>
              </div>
            </div>
          </div>
        </form>
      </div>
      <div class="modal-footer">
        <button class="btn-secondary" onclick="closeModal('addInvoiceModal')">Cancelar</button>
        <button class="btn-primary" onclick="document.getElementById('addInvoiceForm').dispatchEvent(new Event('submit'))">
          <i class="bi bi-check-lg"></i> Guardar Factura
        </button>
      </div>
    </div>
  </div>

  <!-- Modal de Vista Previa de Factura (Imprimible/Exportable) -->
  <div class="modal-overlay" id="viewInvoiceModal">
    <div class="modal" style="max-width: 850px; width: 95%;">
      <div class="modal-header">
        <h3><i class="bi bi-eye"></i> Vista Previa de Factura</h3>
        <button class="modal-close" onclick="closeModal('viewInvoiceModal')">&times;</button>
      </div>
      <div class="modal-body" style="background: #111; padding: 15px;">
        <div style="display: flex; justify-content: flex-end; gap: 10px; margin-bottom: 15px;">
          <button class="btn-primary" onclick="downloadInvoicePDF()">
            <i class="bi bi-file-earmark-pdf"></i> Descargar PDF
          </button>
        </div>
        
        <!-- Contenedor de la Factura (con fondo blanco para impresión profesional) -->
        <div id="invoicePrintArea" class="invoice-print-container">
          <div class="invoice-header-row">
            <div class="invoice-logo-section">
              <img src="img/Logo.png" alt="BeaTaN Logo" class="invoice-logo">
              <div class="invoice-company-info">
                <h2>BeaTaN Code</h2>
                <p>C.C. 1000787831</p>
                <p>Email: beatancode@gmail.com</p>
                <p>Bogotá, Colombia</p>
              </div>
            </div>
            <div class="invoice-number-section">
              <h1>FACTURA</h1>
              <div class="invoice-number-box">
                <span class="label">NÚMERO</span>
                <span class="value" id="viewInvoiceNumber">FAC-000</span>
              </div>
            </div>
          </div>
          
          <div class="invoice-details-row">
            <div class="invoice-client-details">
              <h3>CLIENTE</h3>
              <p><strong>Nombre:</strong> <span id="viewClientName">Nombre</span></p>
              <p><strong>Identificación:</strong> <span id="viewClientId">NIT</span></p>
              <p><strong>Dirección:</strong> <span id="viewClientDir">Dirección</span></p>
              <p><strong>Teléfono:</strong> <span id="viewClientCell">Celular</span></p>
              <p><strong>Email:</strong> <span id="viewClientMail">Email</span></p>
            </div>
            <div class="invoice-meta-details">
              <h3>INFORMACIÓN</h3>
              <p><strong>Fecha Emisión:</strong> <span id="viewInvoiceDate">Date</span></p>
              <p><strong>Fecha Vencimiento:</strong> <span id="viewInvoiceDueDate">Date</span></p>
              <p><strong>Proyecto:</strong> <span id="viewInvoiceProject">Proyecto</span></p>
              <p><strong>Estado:</strong> <span class="invoice-state-badge" id="viewInvoiceState">BORRADOR</span></p>
            </div>
          </div>
          
          <table class="invoice-items-table">
            <thead>
              <tr>
                <th>Descripción / Servicio</th>
                <th class="text-right">Cantidad</th>
                <th class="text-right">Valor Unitario</th>
                <th class="text-right">Total</th>
              </tr>
            </thead>
            <tbody id="viewInvoiceItemsBody">
              <!-- Dinámico -->
            </tbody>
          </table>
          
          <div class="invoice-summary-row">
            <div class="invoice-notes">
              <h4>Notas / Términos de Pago</h4>
              <p>Por favor realizar transferencia bancaria a la cuenta de ahorros indicada en la propuesta. El pago debe efectuarse en el plazo especificado de vencimiento.</p>
            </div>
            <div class="invoice-totals">
              <div class="summary-line">
                <span class="label">Subtotal:</span>
                <span class="val" id="viewInvoiceSubtotal">$0.00</span>
              </div>
              <div class="summary-line">
                <span class="label">IVA:</span>
                <span class="val" id="viewInvoiceIva">$0.00</span>
              </div>
              <div class="summary-line">
                <span class="label">Descuento:</span>
                <span class="val" id="viewInvoiceDiscount">$0.00</span>
              </div>
              <div class="summary-line total-line">
                <span class="label">TOTAL A PAGAR:</span>
                <span class="val" id="viewInvoiceTotal">$0.00</span>
              </div>
            </div>
          </div>
        </div>
      </div>
    </div>
  </div>

  <!-- Modal de Reporte Mensual -->
  <div class="modal-overlay" id="reportModal">
    <div class="modal" style="max-width:860px;width:95%;">
      <div class="modal-header">
        <h3><i class="bi bi-file-earmark-bar-graph"></i> Reporte Mensual — <span id="reportTitle"></span></h3>
        <button class="modal-close" onclick="closeModal('reportModal')">&times;</button>
      </div>
      <div class="modal-body" id="reportModalBody">
        <!-- Controles -->
        <div style="display:flex;gap:12px;align-items:center;flex-wrap:wrap;margin-bottom:20px;">
          <div class="filter-group">
            <label><i class="bi bi-calendar3"></i> Mes:</label>
            <input type="month" id="reportMonth" value="<?php echo date('Y-m'); ?>" onchange="refreshReport()">
          </div>
          <input type="hidden" id="reportTipgasxx" value="PERSONAL">
          <button class="btn-secondary" onclick="printReport()" style="margin-left:auto;">
            <i class="bi bi-printer"></i> Imprimir
          </button>
          <button class="btn-secondary" onclick="exportReportExcel()">
            <i class="bi bi-file-earmark-excel"></i> Excel
          </button>
        </div>

        <!-- Contenido dinámico -->
        <div id="reportContent">
          <div class="loading"><div class="spinner"></div></div>
        </div>
      </div>
    </div>
  </div>

  <!-- Pass PHP data to JavaScript -->
  <script>
    window.isAdmin = <?php echo $user['is_admin'] ? 'true' : 'false'; ?>;
    window.upcomingExpenses = <?php echo json_encode($upcomingExpenses); ?>;
    window.upcomingExpensesBeatan = <?php echo json_encode($upcomingExpensesBeatan ?? []); ?>;
    window.newMessagesCount = <?php echo $newMessages ? $newMessages['count'] : 0; ?>;

    const chartLabels = <?php echo json_encode($chartLabels); ?>;
    const chartIncome = <?php echo json_encode($chartIncome); ?>;
    const chartExpense = <?php echo json_encode($chartExpense); ?>;
    const chartBalance = <?php echo json_encode($chartBalance); ?>;
    const chartAccumulated = <?php echo json_encode($chartAccumulated); ?>;

    const chartLabelsBeatan = <?php echo json_encode($chartLabelsBeatan ?? []); ?>;
    const chartIncomeBeatan = <?php echo json_encode($chartIncomeBeatan ?? []); ?>;
    const chartExpenseBeatan = <?php echo json_encode($chartExpenseBeatan ?? []); ?>;
    const chartBalanceBeatan = <?php echo json_encode($chartBalanceBeatan ?? []); ?>;
    const chartAccumulatedBeatan = <?php echo json_encode($chartAccumulatedBeatan ?? []); ?>;
    const input = document.getElementById("editUserCelular");

    input.addEventListener("input", function (e) {
      let value = input.value.replace(/\D/g, ""); // solo números

      if (value.startsWith("57")) {
        value = value.substring(2);
      }

      let formatted = "+57 ";

      if (value.length > 0) {
        formatted += value.substring(0, 3);
      }
      if (value.length >= 4) {
        formatted += " " + value.substring(3, 10);
      }

      input.value = formatted;
    });

    const input2 = document.getElementById("addUserCelular");

    input2.addEventListener("input", function (e) {
      let value = input2.value.replace(/\D/g, ""); // solo números

      if (value.startsWith("57")) {
        value = value.substring(2);
      }

      let formatted = "+57 ";

      if (value.length > 0) {
        formatted += value.substring(0, 3);
      }
      if (value.length >= 4) {
        formatted += " " + value.substring(3, 10);
      }

      input2.value = formatted;
    });

    const publicVapidKey = "<?php echo $env['VAPID_PUBLIC_KEY']; ?>";

    navigator.serviceWorker.register("sw.js");

    console.log("SW soportado:", "serviceWorker" in navigator);

    navigator.serviceWorker.getRegistrations().then(regs => {
      console.log("Registrations:", regs);
    });

    async function subscribeUser() {
      try {
        const reg = await navigator.serviceWorker.ready;
        const convertedKey = urlBase64ToUint8Array(publicVapidKey);
        const sub = await reg.pushManager.subscribe({
          userVisibleOnly: true,
          applicationServerKey: convertedKey
        });
        const res = await fetch("api/save_suscription.php", {
          method: "POST",
          body: JSON.stringify(sub),
          headers: {
            "Content-Type": "application/json"
          }
        });
        const text = await res.text();
      } catch (err) {
        console.error("❌ Error en subscribeUser:", err);
      }
    }

    function urlBase64ToUint8Array(base64String) {
      const padding = '='.repeat((4 - base64String.length % 4) % 4);
      const base64 = (base64String + padding).replace(/-/g, '+').replace(/_/g, '/');
      const raw = atob(base64);
      return Uint8Array.from([...raw].map(c => c.charCodeAt(0)));
    }

    Notification.requestPermission().then(p => {
      if (p === "granted") subscribeUser();
    });

    function createPerformanceChart(ctxId, lLabels, lIncome, lExpense, lBalance, lAccumulated) {
      const ctx = document.getElementById(ctxId);
      if (!ctx) return;
      new Chart(ctx, {
        type: 'line',
        data: {
          labels: lLabels,
          datasets: [
            {
              label: 'Ingresos',
              data: lIncome,
              borderColor: '#22c55e',
              backgroundColor: 'rgba(34,197,94,0.2)',
              tension: 0.4,
              fill: true,
              pointRadius: 3,
              order: 2
            },
            {
              label: 'Gastos',
              data: lExpense,
              borderColor: '#ef4444',
              backgroundColor: 'rgba(239,68,68,0.2)',
              tension: 0.4,
              fill: true,
              pointRadius: 3,
              order: 2
            },
            {
              label: 'Rendimiento',
              data: lBalance,
              borderColor: '#bac522',
              backgroundColor: 'rgba(197,194,34,0.15)',
              tension: 0.4,
              fill: false,
              borderDash: [6, 6],
              pointRadius: 4,
              order: 3
            },
            {
              label: 'Acumulado',
              data: lAccumulated,
              borderColor: '#3b82f6',
              backgroundColor: 'rgba(59,130,246,0.1)',
              tension: 0.4,
              fill: false,
              borderWidth: 3,
              pointRadius: 5,
              order: 1
            }
          ]
        },
        options: {
          responsive: true,
          maintainAspectRatio: false,
          plugins: {
            legend: {
              labels: {
                color: '#fff'
              }
            },
            tooltip: {
              callbacks: {
                label: function (context) {
                  const value = context.raw;
                  return `${context.dataset.label}: $${value.toLocaleString()}`;
                }
              }
            }
          },
          scales: {
            x: {
              ticks: { color: '#aaa' }
            },
            y: {
              ticks: {
                color: '#aaa',
                callback: function (value) {
                  return '$' + value.toLocaleString();
                }
              }
            }
          }
        }
      });
    }

    createPerformanceChart('performanceChart', chartLabels, chartIncome, chartExpense, chartBalance, chartAccumulated);
    if (window.isAdmin) {
      createPerformanceChart('performanceChartBeatan', chartLabelsBeatan, chartIncomeBeatan, chartExpenseBeatan, chartBalanceBeatan, chartAccumulatedBeatan);
    }

    // =========================================================
    // CÁLCULO AUTOMÁTICO DE CUOTA — funciona en ambos modales
    // =========================================================
    function calcularCuotaEnForm(form) {
      const montoEl  = form.querySelector('[name="mondeudx"]');
      const cuotasEl = form.querySelector('[name="numcuotx"]');
      const valorEl  = form.querySelector('[name="moncuotx"]');
      if (!montoEl || !cuotasEl || !valorEl) return;

      const monto  = parseFloat(montoEl.value);
      const cuotas = parseInt(cuotasEl.value);

      if (!isNaN(monto) && !isNaN(cuotas) && cuotas > 0) {
        valorEl.value = (monto / cuotas).toFixed(2);
      }
    }

    // Adjuntar listeners a ambos formularios de deuda
    ['addDebtForm', 'editDebtForm'].forEach(function(formId) {
      const form = document.getElementById(formId);
      if (!form) return;
      ['mondeudx', 'numcuotx'].forEach(function(fieldName) {
        var el = form.querySelector('[name="' + fieldName + '"]');
        if (el) el.addEventListener('input', function() { calcularCuotaEnForm(form); });
      });
    });
  </script>

  <script src="js/dashboard.js"></script>
</body>

</html>
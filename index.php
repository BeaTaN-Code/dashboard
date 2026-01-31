<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);
// dashboard/index.php
require_once __DIR__ . '/config/db.php';
checkSession();

$user = getCurrentUser();
$pdo = getDbConnection();
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
    AND FINCFECX BETWEEN :today AND :nextWeek
    AND USRIDXXX = :userId
    ORDER BY FINCFECX ASC
  ");
  $stmt->execute([':today' => $today, ':nextWeek' => $nextWeek, ':userId' => $user['id']]);
  $upcomingExpenses = $stmt->fetchAll();
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
          </div>
        <?php } ?>

        <div class="nav-section">
          <span class="nav-section-title">Finanzas</span>
          <?php if ($user['is_admin']) { ?>
            <a href="#" class="nav-item" data-tab="financiero-beatan">
              <i class="bi bi-building"></i>
              <span>Financiero (BeaTaN)</span>
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
        <div class="top-bar-actions">
          <button class="notification-bell" id="notificationBell" onclick="toggleNotificationPanel()">
            <i class="bi bi-bell"></i>
            <span class="notification-count" id="notificationCount" style="display: none;">0</span>
          </button>
          <span style="color: var(--muted-white); font-size: 0.9rem;">
            <?php echo date('d/m/Y'); ?>
          </span>
        </div>
      </header>

      <!-- Notification Panel -->
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
                <div class="card">
                  <div class="card-header">
                    <h3 class="card-title">
                      <i class="bi bi-plus-circle"></i>
                      Nuevo Movimiento
                    </h3>
                  </div>
                  <form class="transaction-form" id="beatanTransactionForm" onsubmit="addTransaction(event, 'BEATAN')">
                    <div class="form-group">
                      <label><i class="bi bi-tag"></i> Tipo</label>
                      <select name="tipo" required>
                        <option value="">Seleccionar...</option>
                        <option value="INGRESO">Ingreso</option>
                        <option value="GASTO">Gasto</option>
                      </select>
                    </div>
                    <div class="form-group">
                      <label><i class="bi bi-currency-dollar"></i> Monto</label>
                      <input type="number" name="monto" step="0.01" min="0" placeholder="0.00" required>
                    </div>
                    <div class="form-group">
                      <label><i class="bi bi-bookmark"></i> Categoria</label>
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
                      <label><i class="bi bi-calendar"></i> Fecha</label>
                      <input type="date" name="fecha" required value="<?php echo date('Y-m-d'); ?>">
                    </div>
                    <div class="form-group full-width">
                      <label><i class="bi bi-text-left"></i> Descripcion</label>
                      <input type="text" name="descripcion" placeholder="Descripcion del movimiento" maxlength="150">
                    </div>
                    <button type="submit" class="btn-primary">
                      <i class="bi bi-plus-lg"></i>
                      Agregar Movimiento
                    </button>
                  </form>
                </div>

                <!-- Transactions Table -->
                <div class="card">
                  <div class="card-header">
                    <h3 class="card-title">
                      <i class="bi bi-list-ul"></i>
                      Movimientos
                    </h3>
                    <div class="filters-bar" style="margin-bottom: 0;">
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
                    </div>
                  </div>
                  <div class="table-container" id="beatanTransactionsTable">
                    <div class="loading">
                      <div class="spinner"></div>
                    </div>
                  </div>
                </div>
              </div>

              <!-- Calendar Sidebar -->
              <div class="financial-sidebar">
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

              <!-- Upcoming Expenses Alert -->
              <?php if (count($upcomingExpenses) > 0): ?>
                <div class="upcoming-expenses-alert">
                  <div class="alert-header">
                    <i class="bi bi-exclamation-triangle"></i>
                    <h4>Gastos Proximos (7 dias)</h4>
                  </div>
                  <div class="upcoming-list">
                    <?php foreach ($upcomingExpenses as $expense): ?>
                      <div class="upcoming-item">
                        <div class="upcoming-info">
                          <span class="upcoming-category"><?php echo htmlspecialchars($expense['CATGASXX']); ?></span>
                          <span class="upcoming-desc"><?php echo htmlspecialchars($expense['DESGASXX'] ?? ''); ?></span>
                        </div>
                        <div class="upcoming-details">
                          <span class="upcoming-amount">$<?php echo number_format(abs($expense['MONTGASX']), 2); ?></span>
                          <span class="upcoming-date"><?php echo date('d/m', strtotime($expense['FINCFECX'])); ?></span>
                        </div>
                      </div>
                    <?php endforeach; ?>
                  </div>
                </div>
              <?php endif; ?>

              <!-- Add Transaction -->
              <div class="card">
                <div class="card-header">
                  <h3 class="card-title">
                    <i class="bi bi-plus-circle"></i>
                    Nuevo Movimiento Personal
                  </h3>
                </div>
                <form class="transaction-form" id="personalTransactionForm"
                  onsubmit="addTransaction(event, 'PERSONAL')">
                  <div class="form-group">
                    <label><i class="bi bi-tag"></i> Tipo</label>
                    <select name="tipo" required>
                      <option value="">Seleccionar...</option>
                      <option value="INGRESO">Ingreso</option>
                      <option value="GASTO">Gasto</option>
                    </select>
                  </div>
                  <div class="form-group">
                    <label><i class="bi bi-currency-dollar"></i> Monto</label>
                    <input type="number" name="monto" step="0.01" min="0" placeholder="0.00" required>
                  </div>
                  <div class="form-group">
                    <label><i class="bi bi-bookmark"></i> Categoria</label>
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
                    <label><i class="bi bi-calendar"></i> Fecha</label>
                    <input type="date" name="fecha" required value="<?php echo date('Y-m-d'); ?>">
                  </div>
                  <div class="form-group full-width">
                    <label><i class="bi bi-text-left"></i> Descripcion</label>
                    <input type="text" name="descripcion" placeholder="Descripcion del movimiento" maxlength="150">
                  </div>
                  <button type="submit" class="btn-primary">
                    <i class="bi bi-plus-lg"></i>
                    Agregar Movimiento
                  </button>
                </form>
              </div>

              <!-- Transactions Table -->
              <div class="card">
                <div class="card-header">
                  <h3 class="card-title">
                    <i class="bi bi-list-ul"></i>
                    Movimientos Personales
                  </h3>
                  <div class="filters-bar" style="margin-bottom: 0;">
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
                  </div>
                </div>
                <div class="table-container" id="personalTransactionsTable">
                  <div class="loading">
                    <div class="spinner"></div>
                  </div>
                </div>
              </div>
            </div>

            <!-- Calendar Sidebar -->
            <div class="financial-sidebar">
              <div class="calendar-container">
                <div class="calendar-header">
                  <h4 style="font-size: 1rem; color: #fff;">Calendario</h4>
                  <div class="calendar-nav">
                    <button onclick="changeMonth('personal', -1)"><i class="bi bi-chevron-left"></i></button>
                    <span class="calendar-month" id="personal-calendar-month"></span>
                    <button onclick="changeMonth('personal', 1)"><i class="bi bi-chevron-right"></i></button>
                  </div>
                </div>
                <div class="calendar-grid" id="personal-calendar">
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
                <div class="holiday-info" id="personal-holiday-info" style="display: none;">
                  <h5 id="personal-holiday-name"></h5>
                  <p id="personal-holiday-date"></p>
                </div>
              </div>
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
              <option value="0">Usuario</option>
              <option value="1">Administrador</option>
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

  <!-- Pass PHP data to JavaScript -->
  <script>
    window.isAdmin = <?php echo $user['is_admin'] ? 'true' : 'false'; ?>;
    window.upcomingExpenses = <?php echo json_encode($upcomingExpenses); ?>;
    window.newMessagesCount = <?php echo $newMessages ? $newMessages['count'] : 0; ?>;

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

    const publicVapidKey = "<?php echo $env['VAPID_PUBLIC_KEY']; ?>";

    navigator.serviceWorker.register("sw.js");

    console.log("SW soportado:", "serviceWorker" in navigator);

    navigator.serviceWorker.getRegistrations().then(regs => {
      console.log("Registrations:", regs);
    });

    async function subscribeUser() {
      try {
        console.log("🟡 Iniciando suscripción push...");

        const reg = await navigator.serviceWorker.ready;
        console.log("🟢 ServiceWorker listo:", reg);

        console.log("🟡 Usando VAPID key:", publicVapidKey);

        const convertedKey = urlBase64ToUint8Array(publicVapidKey);
        console.log("🟢 VAPID convertida:", convertedKey);

        const sub = await reg.pushManager.subscribe({
          userVisibleOnly: true,
          applicationServerKey: convertedKey
        });

        console.log("🟢 Suscripción generada:", sub);
        console.log("📌 Endpoint:", sub.endpoint);
        console.log("📌 p256dh:", btoa(String.fromCharCode(...new Uint8Array(sub.getKey("p256dh")))));
        console.log("📌 auth:", btoa(String.fromCharCode(...new Uint8Array(sub.getKey("auth")))));

        const res = await fetch("api/save_suscription.php", {
          method: "POST",
          body: JSON.stringify(sub),
          headers: {
            "Content-Type": "application/json"
          }
        });

        console.log("🟡 Respuesta del servidor:", res);

        const text = await res.text();
        console.log("🟢 Respuesta body:", text);

        console.log("✅ Usuario suscrito correctamente");
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
  </script>

  <script src="js/dashboard.js"></script>
</body>

</html>
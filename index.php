<?php
// dashboard/index.php
require_once __DIR__ . '/config/db.php';
checkSession();

$user = getCurrentUser();
$pdo = getDbConnection();

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
          <span style="color: var(--muted-white); font-size: 0.9rem;">
            <?php echo date('d/m/Y'); ?>
          </span>
        </div>
      </header>

      <div class="content-area">
        <!-- TAB: Dashboard -->
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
                <p>Leídos</p>
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
                    <option value="LEIDO">Leído</option>
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
                  <p>Aún no hay mensajes en el formulario de contacto.</p>
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

        <!-- TAB: Financiero BeaTaN -->
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
                    <label><i class="bi bi-bookmark"></i> Categoría</label>
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
                    <label><i class="bi bi-text-left"></i> Descripción</label>
                    <input type="text" name="descripcion" placeholder="Descripción del movimiento" maxlength="150">
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
                    <label><i class="bi bi-bookmark"></i> Categoría</label>
                    <select name="categoria" required>
                      <option value="">Seleccionar...</option>
                      <option value="Salario">Salario</option>
                      <option value="Freelance">Freelance</option>
                      <option value="Inversiones">Inversiones</option>
                      <option value="Alimentación">Alimentación</option>
                      <option value="Transporte">Transporte</option>
                      <option value="Vivienda">Vivienda</option>
                      <option value="Servicios">Servicios</option>
                      <option value="Entretenimiento">Entretenimiento</option>
                      <option value="Salud">Salud</option>
                      <option value="Educación">Educación</option>
                      <option value="Otros">Otros</option>
                    </select>
                  </div>
                  <div class="form-group">
                    <label><i class="bi bi-calendar"></i> Fecha</label>
                    <input type="date" name="fecha" required value="<?php echo date('Y-m-d'); ?>">
                  </div>
                  <div class="form-group full-width">
                    <label><i class="bi bi-text-left"></i> Descripción</label>
                    <input type="text" name="descripcion" placeholder="Descripción del movimiento" maxlength="150">
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

  <script src="js/dashboard.js"></script>
</body>

</html>
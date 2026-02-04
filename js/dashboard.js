// dashboard/js/dashboard.js
// JavaScript para el Dashboard de BeaTaNCode

// ===============================================
// VARIABLES GLOBALES
// ===============================================
let currentTab = "financiero-personal";
let beatanCalendarDate = new Date();
let personalCalendarDate = new Date();
let holidaysCache = {};
let notificationsData = [];
let notificationCheckInterval = null;
let lastMessageCount = 0;

// ===============================================
// INICIALIZACION
// ===============================================
document.addEventListener("DOMContentLoaded", function () {
  initTabs();
  initSidebar();
  initCalendars();
  initNotifications();

  // Cargar datos iniciales
  if (window.isAdmin) {
    loadTransactions("BEATAN");
  }
  loadTransactions("PERSONAL");

  // Cerrar panel de notificaciones al hacer clic fuera
  document.addEventListener("click", function (e) {
    const panel = document.getElementById("notificationPanel");
    const bell = document.getElementById("notificationBell");
    if (
      panel &&
      !panel.contains(e.target) &&
      bell &&
      !bell.contains(e.target)
    ) {
      panel.classList.remove("active");
    }
  });
});

// ===============================================
// NAVEGACION POR PESTANAS
// ===============================================
function initTabs() {
  const navItems = document.querySelectorAll(".nav-item[data-tab]");

  navItems.forEach((item) => {
    item.addEventListener("click", function (e) {
      e.preventDefault();
      const tabId = this.getAttribute("data-tab");
      switchTab(tabId);
    });
  });
}

function switchTab(tabId) {
  // Actualizar navegacion activa
  document.querySelectorAll(".nav-item").forEach((item) => {
    item.classList.remove("active");
  });
  const activeItem = document.querySelector(`.nav-item[data-tab="${tabId}"]`);
  if (activeItem) {
    activeItem.classList.add("active");
  }

  // Mostrar contenido de la pestana
  document.querySelectorAll(".tab-content").forEach((content) => {
    content.classList.remove("active");
  });
  const tabContent = document.getElementById(`tab-${tabId}`);
  if (tabContent) {
    tabContent.classList.add("active");
  }

  // Actualizar titulo de la pagina
  const titles = {
    dashboard: { title: "Dashboard", icon: "bi-grid-1x2" },
    usuarios: { title: "Gestion de Usuarios", icon: "bi-people" },
    "financiero-beatan": { title: "Financiero (BeaTaN)", icon: "bi-building" },
    "financiero-personal": {
      title: "Financiero (Personal)",
      icon: "bi-person-circle",
    },
  };

  const titleEl = document.getElementById("pageTitle");
  const iconEl = document.getElementById("pageIcon");
  if (titleEl && titles[tabId]) {
    titleEl.textContent = titles[tabId].title;
  }
  if (iconEl && titles[tabId]) {
    iconEl.className = "bi " + titles[tabId].icon;
  }

  currentTab = tabId;

  // Cerrar sidebar en movil
  closeSidebar();
}

// ===============================================
// SIDEBAR MOVIL
// ===============================================
function initSidebar() {
  const mobileToggle = document.getElementById("mobileToggle");
  const sidebar = document.getElementById("sidebar");
  const overlay = document.getElementById("sidebarOverlay");

  if (mobileToggle) {
    mobileToggle.addEventListener("click", function () {
      sidebar.classList.toggle("active");
      overlay.classList.toggle("active");
    });
  }

  if (overlay) {
    overlay.addEventListener("click", closeSidebar);
  }
}

function closeSidebar() {
  const sidebar = document.getElementById("sidebar");
  const overlay = document.getElementById("sidebarOverlay");
  if (sidebar) sidebar.classList.remove("active");
  if (overlay) overlay.classList.remove("active");
}

// ===============================================
// NOTIFICACIONES
// ===============================================
function initNotifications() {
  loadNotifications();

  // Verificar notificaciones cada 30 segundos
  notificationCheckInterval = setInterval(loadNotifications, 30000);

  // Mostrar notificaciones de gastos proximos al cargar
  if (window.upcomingExpenses && window.upcomingExpenses.length > 0) {
    setTimeout(() => {
      showUpcomingExpensesNotification();
    }, 1000);
  }

  // Notificar mensajes nuevos
  if (window.isAdmin && window.newMessagesCount > 0) {
    setTimeout(() => {
      showToast(
        "info",
        "Mensajes Pendientes",
        `Tienes ${window.newMessagesCount} mensaje(s) sin leer`
      );
    }, 2000);
  }
}

async function loadNotifications() {
  try {
    const response = await fetch("api/notifications.php");
    const data = await response.json();

    if (data.success) {
      notificationsData = data.data;
      updateNotificationBadge(data.count);
      renderNotificationPanel();

      // Verificar nuevos mensajes (solo admin)
      if (window.isAdmin) {
        checkForNewMessages();
      }
    }
  } catch (error) {
    console.error("Error loading notifications:", error);
  }
}

function updateNotificationBadge(count) {
  const badge = document.getElementById("notificationCount");
  if (badge) {
    if (count > 0) {
      badge.textContent = count > 9 ? "9+" : count;
      badge.style.display = "block";
    } else {
      badge.style.display = "none";
    }
  }
}

function renderNotificationPanel() {
  const container = document.getElementById("notificationPanelBody");
  if (!container) return;

  if (notificationsData.length === 0) {
    container.innerHTML = `
            <div class="empty-notifications">
                <i class="bi bi-bell-slash"></i>
                <p>No hay notificaciones</p>
            </div>
        `;
    return;
  }

  let html = "";
  notificationsData.forEach((notification) => {
    html += `
            <div class="notification-item" onclick="handleNotificationClick('${notification.type}', '${notification.id}')">
                <div class="notification-item-header">
                    <div class="notification-item-icon ${notification.urgency}">
                        <i class="bi ${notification.icon}"></i>
                    </div>
                    <span class="notification-item-title">${notification.title}</span>
                    <span class="notification-item-date">${notification.date}</span>
                </div>
                <div class="notification-item-message">${notification.message}</div>
                ${notification.detail ? `<div class="notification-item-detail">${notification.detail}</div>` : ""}
            </div>
        `;
  });

  container.innerHTML = html;
}

function toggleNotificationPanel() {
  const panel = document.getElementById("notificationPanel");
  if (panel) {
    panel.classList.toggle("active");
  }
}

function clearAllNotifications() {
  notificationsData = [];
  renderNotificationPanel();
  updateNotificationBadge(0);
}

function handleNotificationClick(type, id) {
  const panel = document.getElementById("notificationPanel");
  if (panel) {
    panel.classList.remove("active");
  }

  if (type === "expense") {
    switchTab("financiero-personal");
  } else if (type === "message") {
    switchTab("dashboard");
    const msgId = id.replace("message_", "");
    setTimeout(() => viewMessage(parseInt(msgId)), 300);
  }
}

function showUpcomingExpensesNotification() {
  const expenses = window.upcomingExpenses || [];
  if (expenses.length === 0) return;

  const urgentExpenses = expenses.filter((e) => {
    const daysUntil =
      (new Date(e.FINCFECX) - new Date()) / (1000 * 60 * 60 * 24);
    return daysUntil <= 3;
  });

  if (urgentExpenses.length > 0) {
    const total = urgentExpenses.reduce(
      (sum, e) => sum + Math.abs(parseFloat(e.MONTGASX)),
      0
    );
    showToast(
      "warning",
      "Gastos Proximos",
      `Tienes ${urgentExpenses.length} gasto(s) por pagar: $${formatNumber(total)}`
    );
  }
}

async function checkForNewMessages() {
  try {
    const response = await fetch("api/messages.php?count_pending=1");
    const data = await response.json();

    if (data.success && data.count > lastMessageCount && lastMessageCount > 0) {
      showToast("info", "Nuevo Mensaje", "Has recibido un nuevo mensaje");
    }

    lastMessageCount = data.count || 0;
  } catch (error) {
    console.error("Error checking messages:", error);
  }
}

// ===============================================
// TOAST NOTIFICATIONS
// ===============================================
function showToast(type, title, message) {
  const container = document.getElementById("notificationContainer");
  if (!container) return;

  const icons = {
    success: "bi-check-circle-fill",
    error: "bi-x-circle-fill",
    warning: "bi-exclamation-triangle-fill",
    info: "bi-info-circle-fill",
  };

  const toast = document.createElement("div");
  toast.className = `toast-notification ${type}`;
  toast.innerHTML = `
        <i class="bi ${icons[type]}"></i>
        <div class="toast-content">
            <div class="toast-title">${title}</div>
            <div class="toast-message">${message}</div>
        </div>
        <button class="toast-close" onclick="this.parentElement.remove()">&times;</button>
    `;

  container.appendChild(toast);

  // Auto remove after 5 seconds
  setTimeout(() => {
    toast.style.animation = "slideOut 0.3s ease forwards";
    setTimeout(() => toast.remove(), 300);
  }, 5000);
}

function showAlert(message, type = "info") {
  showToast(type, type === "error" ? "Error" : "Aviso", message);
}

// ===============================================
// FILTRO DE TABLA DE FORMULARIO
// ===============================================
function filterFormTable() {
  const filter = document.getElementById("filterEstado").value;
  const rows = document.querySelectorAll("#formTable tbody tr");

  rows.forEach((row) => {
    const estado = row.getAttribute("data-estado");
    if (!filter || estado === filter) {
      row.style.display = "";
    } else {
      row.style.display = "none";
    }
  });
}

// ===============================================
// GESTION DE MENSAJES
// ===============================================
async function viewMessage(id) {
  try {
    const response = await fetch(`api/messages.php?id=${id}`);
    const data = await response.json();

    if (data.success) {
      const msg = data.data;
      const modalBody = document.getElementById("messageModalBody");

      modalBody.innerHTML = `
                <div style="display: flex; flex-direction: column; gap: 15px;">
                    <div>
                        <strong style="color: var(--primary-cyan);">Nombre:</strong>
                        <p style="margin-top: 5px;">${escapeHtml(msg.NOMBRE)}</p>
                    </div>
                    <div>
                        <strong style="color: var(--primary-cyan);">Email:</strong>
                        <p style="margin-top: 5px;">${escapeHtml(msg.EMAIL)}</p>
                    </div>
                    <div>
                        <strong style="color: var(--primary-cyan);">Telefono:</strong>
                        <p style="margin-top: 5px;">${escapeHtml(msg.INDICATIVO || "")} ${escapeHtml(msg.TELEFONO || "No proporcionado")}</p>
                    </div>
                    <div>
                        <strong style="color: var(--primary-cyan);">Empresa:</strong>
                        <p style="margin-top: 5px;">${escapeHtml(msg.NOMEMP || "No proporcionado")} (${escapeHtml(msg.DESPEMP || "")})</p>
                    </div>
                    <div>
                        <strong style="color: var(--primary-cyan);">Asunto:</strong>
                        <p style="margin-top: 5px;">${escapeHtml(msg.ASUNTO || "Sin asunto")}</p>
                    </div>
                    <div>
                        <strong style="color: var(--primary-cyan);">Mensaje:</strong>
                        <p style="margin-top: 5px; white-space: pre-wrap;">${escapeHtml(msg.MENSAJE)}</p>
                    </div>
                    <div>
                        <strong style="color: var(--primary-cyan);">Fecha:</strong>
                        <p style="margin-top: 5px;">${formatDateTime(msg.HORCREA)}</p>
                    </div>
                </div>
            `;

      openModal("messageModal");
    } else {
      showAlert("Error al cargar el mensaje", "error");
    }
  } catch (error) {
    console.error("Error:", error);
    showAlert("Error de conexion", "error");
  }
}

function updateMessageStatus(id) {
  document.getElementById("statusMessageId").value = id;
  openModal("statusModal");
}

async function saveMessageStatus(e) {
  e.preventDefault();

  const id = document.getElementById("statusMessageId").value;
  const estado = document.getElementById("statusSelect").value;

  try {
    const response = await fetch("api/messages.php", {
      method: "PUT",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify({ id, estado }),
    });

    const data = await response.json();

    if (data.success) {
      showAlert("Estado actualizado correctamente", "success");
      closeModal("statusModal");
      // Recargar la pagina para actualizar la tabla
      setTimeout(() => location.reload(), 1000);
    } else {
      showAlert(data.error || "Error al actualizar", "error");
    }
  } catch (error) {
    console.error("Error:", error);
    showAlert("Error de conexion", "error");
  }
}

// ===============================================
// GESTION DE USUARIOS
// ===============================================
async function editUser(id) {
  try {
    const response = await fetch(`api/users.php?id=${id}`);
    const data = await response.json();

    if (data.success) {
      const user = data.data;
      document.getElementById("editUserId").value = user.USRIDXXX;
      document.getElementById("editUserNombre").value = user.USRNMEXX || "";
      document.getElementById("editUserEmail").value = user.USRMAILX || "";
      document.getElementById("editUserCelular").value = user.USRCELUL || "";
      document.getElementById("editUserPassword").value = "";
      document.getElementById("editUserAdmin").value = user.ISADMINX;

      openModal("editUserModal");
    } else {
      showAlert(data.error || "Error al cargar usuario", "error");
    }
  } catch (error) {
    console.error("Error:", error);
    showAlert("Error de conexion", "error");
  }
}

async function saveNewUser(e) {
  e.preventDefault();

  const data = {
    cedula: document.getElementById("addUserCedula").value,
    nombre: document.getElementById("addUserNombre").value,
    email: document.getElementById("addUserEmail").value,
    celular: document.getElementById("addUserCelular").value,
    password: document.getElementById("addUserPassword").value,
    admin: document.getElementById("addUserAdmin").value,
  };

  try {
    const response = await fetch("api/users.php", {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify(data),
    });

    const result = await response.json();

    if (result.success) {
      showAlert("Usuario creado exitosamente", "success");
      closeModal("addUserModal");
      document.getElementById("addUserForm").reset();
      setTimeout(() => location.reload(), 1000);
    } else {
      showAlert(result.error || "Error al crear usuario", "error");
    }
  } catch (error) {
    console.error("Error:", error);
    showAlert("Error de conexion", "error");
  }
}

async function updateUser(e) {
  e.preventDefault();

  const data = {
    id: document.getElementById("editUserId").value,
    nombre: document.getElementById("editUserNombre").value,
    email: document.getElementById("editUserEmail").value,
    celular: document.getElementById("editUserCelular").value,
    password: document.getElementById("editUserPassword").value,
    admin: document.getElementById("editUserAdmin").value,
  };

  try {
    const response = await fetch("api/users.php", {
      method: "PUT",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify(data),
    });

    const result = await response.json();

    if (result.success) {
      showAlert("Usuario actualizado exitosamente", "success");
      closeModal("editUserModal");
      setTimeout(() => location.reload(), 1000);
    } else {
      showAlert(result.error || "Error al actualizar usuario", "error");
    }
  } catch (error) {
    console.error("Error:", error);
    showAlert("Error de conexion", "error");
  }
}

async function toggleUserStatus(id, currentStatus) {
  const newStatus = currentStatus === "ACTIVO" ? "INACTIVO" : "ACTIVO";
  const action = newStatus === "ACTIVO" ? "activar" : "inactivar";

  if (!confirm(`Esta seguro de ${action} este usuario?`)) {
    return;
  }

  try {
    const response = await fetch(
      `api/users.php?id=${id}&status=${newStatus}`,
      {
        method: "DELETE",
      }
    );

    const result = await response.json();

    if (result.success) {
      showAlert(result.message, "success");
      setTimeout(() => location.reload(), 1000);
    } else {
      showAlert(result.error || "Error al cambiar estado", "error");
    }
  } catch (error) {
    console.error("Error:", error);
    showAlert("Error de conexion", "error");
  }
}

// ===============================================
// GESTION DE TRANSACCIONES
// ===============================================
async function loadTransactions(tipgasxx) {
  const prefix = tipgasxx.toLowerCase();
  const tableContainer = document.getElementById(
    `${prefix === "beatan" ? "beatan" : "personal"}TransactionsTable`
  );
  const filterTipoEl = document.getElementById(
    `${prefix === "beatan" ? "beatan" : "personal"}FilterTipo`
  );
  const filterMonthEl = document.getElementById(
    `${prefix === "beatan" ? "beatan" : "personal"}FilterMonth`
  );

  if (!tableContainer) return;

  const filterTipo = filterTipoEl ? filterTipoEl.value : "";
  const filterMonth = filterMonthEl ? filterMonthEl.value : "";

  tableContainer.innerHTML =
    '<div class="loading"><div class="spinner"></div></div>';

  try {
    let url = `api/transactions.php?tipgasxx=${tipgasxx}`;
    if (filterTipo) url += `&tipo=${filterTipo}`;
    if (filterMonth) url += `&month=${filterMonth}`;

    const response = await fetch(url);
    const data = await response.json();

    if (data.success) {
      // Actualizar balances
      updateBalances(tipgasxx, data.balance);

      // Renderizar tabla
      renderTransactionsTable(tipgasxx, data.data);
    } else {
      tableContainer.innerHTML = `
                <div class="empty-state">
                    <i class="bi bi-exclamation-circle"></i>
                    <h3>Error</h3>
                    <p>${data.error || "No se pudieron cargar las transacciones"}</p>
                </div>
            `;
    }
  } catch (error) {
    console.error("Error:", error);
    tableContainer.innerHTML = `
            <div class="empty-state">
                <i class="bi bi-wifi-off"></i>
                <h3>Error de conexion</h3>
                <p>No se pudo conectar con el servidor</p>
            </div>
        `;
  }
}

function updateBalances(tipgasxx, balance) {
  const prefix = tipgasxx === "BEATAN" ? "beatan" : "personal";

  const balanceEl = document.getElementById(`${prefix}-balance`);
  const incomeEl = document.getElementById(`${prefix}-income`);
  const expenseEl = document.getElementById(`${prefix}-expense`);

  if (balanceEl) {
    balanceEl.textContent = formatCurrency(balance.total);
    balanceEl.className = `amount ${balance.total >= 0 ? "positive" : "negative"}`;
  }

  if (incomeEl) {
    incomeEl.textContent = formatCurrency(balance.income);
  }

  if (expenseEl) {
    expenseEl.textContent = formatCurrency(balance.expense);
  }
}

function renderTransactionsTable(tipgasxx, transactions) {
  const prefix = tipgasxx === "BEATAN" ? "beatan" : "personal";
  const container = document.getElementById(`${prefix}TransactionsTable`);

  if (!container) return;

  if (!transactions || transactions.length === 0) {
    container.innerHTML = `
            <div class="empty-state">
                <i class="bi bi-inbox"></i>
                <h3>Sin movimientos</h3>
                <p>No hay transacciones registradas para este periodo</p>
            </div>
        `;
    return;
  }

  const today = new Date().toISOString().split("T")[0];

  let html = `
        <table class="data-table">
            <thead>
                <tr>
                    <th>Fecha</th>
                    <th>Categoria</th>
                    <th>Descripcion</th>
                    <th>Monto</th>
                    <th>Acciones</th>
                </tr>
            </thead>
            <tbody>
    `;

  transactions.forEach((t) => {
    const monto = parseFloat(t.MONTGASX);
    const isIncome = monto > 0;
    const isFuture = t.FINCFECX > today;

    html += `
            <tr style="${isFuture ? "opacity: 0.5;" : ""}">
                <td>
                    ${formatDate(t.FINCFECX)}
                    ${isFuture ? '<br><small style="color: var(--warning-color);">(Futuro)</small>' : ""}
                </td>
                <td>
                    <span class="badge ${isIncome ? "badge-income" : "badge-expense"}">
                        ${escapeHtml(t.CATGASXX)}
                    </span>
                </td>
                <td>${escapeHtml(t.DESGASXX || "-")}</td>
                <td>
                    <span class="amount ${isIncome ? "positive" : "negative"}">
                        ${isIncome ? "+" : ""}${formatCurrency(monto)}
                    </span>
                </td>
                <td>
                    <div class="action-buttons">
                        <button class="btn-icon" onclick="editTransaction(${t.FINCTIDX}, '${tipgasxx}')" title="Editar">
                            <i class="bi bi-pencil"></i>
                        </button>
                        <button class="btn-icon" onclick="deleteTransaction(${t.FINCTIDX}, '${tipgasxx}')" title="Eliminar" style="color: var(--error-color);">
                            <i class="bi bi-trash"></i>
                        </button>
                    </div>
                </td>
            </tr>
        `;
  });

  html += "</tbody></table>";
  container.innerHTML = html;
}

async function addTransaction(e, tipgasxx) {
  e.preventDefault();

  const form = e.target;
  const formData = new FormData(form);

  const data = {
    tipgasxx: tipgasxx,
    tipo: formData.get("tipo"),
    monto: parseFloat(formData.get("monto")),
    categoria: formData.get("categoria"),
    descripcion: formData.get("descripcion"),
    fecha: formData.get("fecha"),
  };

  try {
    const response = await fetch("api/transactions.php", {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify(data),
    });

    const result = await response.json();

    if (result.success) {
      showAlert("Transaccion agregada exitosamente", "success");
      form.reset();
      form.querySelector('[name="fecha"]').value = new Date()
        .toISOString()
        .split("T")[0];
      loadTransactions(tipgasxx);
      loadNotifications(); // Actualizar notificaciones
    } else {
      showAlert(result.error || "Error al agregar transaccion", "error");
    }
  } catch (error) {
    console.error("Error:", error);
    showAlert("Error de conexion", "error");
  }
}

async function editTransaction(id, tipgasxx) {
  try {
    const response = await fetch(`api/transactions.php?tipgasxx=${tipgasxx}`);
    const data = await response.json();

    if (data.success) {
      const transaction = data.data.find((t) => t.FINCTIDX == id);

      if (transaction) {
        const monto = parseFloat(transaction.MONTGASX);

        document.getElementById("editTransactionId").value = id;
        document.getElementById("editTransactionTipgasxx").value = tipgasxx;
        document.getElementById("editTransactionTipo").value =
          monto >= 0 ? "INGRESO" : "GASTO";
        document.getElementById("editTransactionMonto").value = Math.abs(monto);
        document.getElementById("editTransactionCategoria").value =
          transaction.CATGASXX;
        document.getElementById("editTransactionFecha").value =
          transaction.FINCFECX;
        document.getElementById("editTransactionDescripcion").value =
          transaction.DESGASXX || "";

        openModal("editTransactionModal");
      }
    }
  } catch (error) {
    console.error("Error:", error);
    showAlert("Error al cargar la transaccion", "error");
  }
}

async function updateTransaction(e) {
  e.preventDefault();

  const data = {
    id: document.getElementById("editTransactionId").value,
    tipo: document.getElementById("editTransactionTipo").value,
    monto: parseFloat(document.getElementById("editTransactionMonto").value),
    categoria: document.getElementById("editTransactionCategoria").value,
    fecha: document.getElementById("editTransactionFecha").value,
    descripcion: document.getElementById("editTransactionDescripcion").value,
  };

  const tipgasxx = document.getElementById("editTransactionTipgasxx").value;

  try {
    const response = await fetch("api/transactions.php", {
      method: "PUT",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify(data),
    });

    const result = await response.json();

    if (result.success) {
      showAlert("Transaccion actualizada exitosamente", "success");
      closeModal("editTransactionModal");
      loadTransactions(tipgasxx);
      loadNotifications();
    } else {
      showAlert(result.error || "Error al actualizar", "error");
    }
  } catch (error) {
    console.error("Error:", error);
    showAlert("Error de conexion", "error");
  }
}

async function deleteTransaction(id, tipgasxx) {
  if (!confirm("Esta seguro de eliminar esta transaccion?")) {
    return;
  }

  try {
    const response = await fetch(`api/transactions.php?id=${id}`, {
      method: "DELETE",
    });

    const result = await response.json();

    if (result.success) {
      showAlert("Transaccion eliminada exitosamente", "success");
      loadTransactions(tipgasxx);
      loadNotifications();
    } else {
      showAlert(result.error || "Error al eliminar", "error");
    }
  } catch (error) {
    console.error("Error:", error);
    showAlert("Error de conexion", "error");
  }
}

// ===============================================
// CALENDARIO
// ===============================================
function initCalendars() {
  if (window.isAdmin) {
    renderCalendar("beatan", beatanCalendarDate);
  }
  renderCalendar("personal", personalCalendarDate);
}

function changeMonth(calendarId, delta) {
  if (calendarId === "beatan") {
    beatanCalendarDate.setMonth(beatanCalendarDate.getMonth() + delta);
    renderCalendar("beatan", beatanCalendarDate);
  } else {
    personalCalendarDate.setMonth(personalCalendarDate.getMonth() + delta);
    renderCalendar("personal", personalCalendarDate);
  }
}

async function renderCalendar(calendarId, date) {
  const container = document.getElementById(`${calendarId}-calendar`);
  const monthLabel = document.getElementById(`${calendarId}-calendar-month`);

  if (!container || !monthLabel) return;

  const year = date.getFullYear();
  const month = date.getMonth();

  // Actualizar etiqueta del mes
  const monthNames = [
    "Enero",
    "Febrero",
    "Marzo",
    "Abril",
    "Mayo",
    "Junio",
    "Julio",
    "Agosto",
    "Septiembre",
    "Octubre",
    "Noviembre",
    "Diciembre",
  ];
  monthLabel.textContent = `${monthNames[month]} ${year}`;

  // Obtener festivos
  const holidays = await getHolidays(year, month + 1);

  // Generar calendario
  let html = "";

  // Encabezados de dias
  const dayNames = ["Dom", "Lun", "Mar", "Mie", "Jue", "Vie", "Sab"];
  dayNames.forEach((day) => {
    html += `<div class="calendar-day-header">${day}</div>`;
  });

  // Primer dia del mes
  const firstDay = new Date(year, month, 1);
  const startingDay = firstDay.getDay();

  // Ultimo dia del mes
  const lastDay = new Date(year, month + 1, 0);
  const totalDays = lastDay.getDate();

  // Dias del mes anterior
  const prevMonth = new Date(year, month, 0);
  const prevMonthDays = prevMonth.getDate();

  // Dia actual
  const today = new Date();
  const todayStr = today.toISOString().split("T")[0];

  // Dias del mes anterior (relleno)
  for (let i = startingDay - 1; i >= 0; i--) {
    const day = prevMonthDays - i;
    html += `<div class="calendar-day other-month">${day}</div>`;
  }

  // Dias del mes actual
  for (let day = 1; day <= totalDays; day++) {
    const dateStr = `${year}-${String(month + 1).padStart(2, "0")}-${String(day).padStart(2, "0")}`;
    const holiday = holidays.find((h) => h.fecha === dateStr);
    const isToday = dateStr === todayStr;

    let classes = "calendar-day";
    if (isToday) classes += " today";
    if (holiday) classes += " holiday";

    html += `
            <div class="${classes}" onclick="selectCalendarDay('${calendarId}', '${dateStr}', ${holiday ? `'${escapeHtml(holiday.nombre)}'` : "null"})">
                ${day}
                ${holiday ? '<span class="holiday-dot"></span>' : ""}
            </div>
        `;
  }

  // Dias del siguiente mes (relleno)
  const remainingDays = 42 - (startingDay + totalDays);
  for (let day = 1; day <= remainingDays; day++) {
    html += `<div class="calendar-day other-month">${day}</div>`;
  }

  container.innerHTML = html;
}

async function getHolidays(year, month) {
  const cacheKey = `${year}-${month}`;

  if (holidaysCache[cacheKey]) {
    return holidaysCache[cacheKey];
  }

  try {
    const response = await fetch(
      `api/holidays.php?year=${year}&month=${month}`
    );
    const data = await response.json();

    if (data.success) {
      holidaysCache[cacheKey] = data.data;
      return data.data;
    }
  } catch (error) {
    console.error("Error fetching holidays:", error);
  }

  return [];
}

function selectCalendarDay(calendarId, dateStr, holidayName) {
  // Quitar seleccion anterior
  document.querySelectorAll(`#${calendarId}-calendar .calendar-day`).forEach((d) => {
    d.classList.remove("selected");
  });

  // Seleccionar nuevo dia
  const selectedDay = document.querySelector(
    `#${calendarId}-calendar .calendar-day:not(.other-month)`
  );

  // Mostrar info del festivo si aplica
  const holidayInfo = document.getElementById(`${calendarId}-holiday-info`);
  const holidayNameEl = document.getElementById(`${calendarId}-holiday-name`);
  const holidayDateEl = document.getElementById(`${calendarId}-holiday-date`);

  if (holidayName && holidayName !== "null" && holidayInfo) {
    holidayNameEl.textContent = holidayName;
    holidayDateEl.textContent = formatDate(dateStr);
    holidayInfo.style.display = "block";
  } else if (holidayInfo) {
    holidayInfo.style.display = "none";
  }
}

// ===============================================
// MODALES
// ===============================================
function openModal(modalId) {
  const modal = document.getElementById(modalId);
  if (modal) {
    modal.classList.add("active");
    document.body.style.overflow = "hidden";
  }
}

function closeModal(modalId) {
  const modal = document.getElementById(modalId);
  if (modal) {
    modal.classList.remove("active");
    document.body.style.overflow = "";
  }
}

// Cerrar modal al hacer clic fuera
document.addEventListener("click", function (e) {
  if (e.target.classList.contains("modal-overlay")) {
    e.target.classList.remove("active");
    document.body.style.overflow = "";
  }
});

// Cerrar modal con ESC
document.addEventListener("keydown", function (e) {
  if (e.key === "Escape") {
    document.querySelectorAll(".modal-overlay.active").forEach((modal) => {
      modal.classList.remove("active");
    });
    document.body.style.overflow = "";
  }
});

// ===============================================
// UTILIDADES
// ===============================================
function escapeHtml(text) {
  if (!text) return "";
  const div = document.createElement("div");
  div.textContent = text;
  return div.innerHTML;
}

function formatCurrency(amount) {
  return new Intl.NumberFormat("es-CO", {
    style: "currency",
    currency: "COP",
    minimumFractionDigits: 0,
    maximumFractionDigits: 0,
  }).format(amount);
}

function formatNumber(num) {
  return new Intl.NumberFormat("es-CO").format(num);
}

function formatDate(dateStr) {
  if (!dateStr) return "";
  const date = new Date(dateStr + "T00:00:00");
  return date.toLocaleDateString("es-CO", {
    day: "2-digit",
    month: "2-digit",
    year: "numeric",
  });
}

function formatDateTime(dateStr) {
  if (!dateStr) return "";
  const date = new Date(dateStr);
  return date.toLocaleDateString("es-CO", {
    day: "2-digit",
    month: "2-digit",
    year: "numeric",
    hour: "2-digit",
    minute: "2-digit",
  });
}

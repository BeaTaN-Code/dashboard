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

  if (window.isAdmin) {
    loadDebts("BEATAN");
    loadDeudasSelect("beatanAddDeudidxx");
  }
  loadDebts("PERSONAL");

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

  if (type === "expense_personal") {
    switchTab("financiero-personal");
  } else if (type === "expense_beatan") {
    switchTab("financiero-beatan");
  } else if (type === "message") {
    switchTab("dashboard");
    const msgId = id.replace("message_", "");
    setTimeout(() => viewMessage(parseInt(msgId)), 300);
  }
}

function showUpcomingExpensesNotification() {
  const personalExpenses = window.upcomingExpenses || [];
  const beatanExpenses = window.upcomingExpensesBeatan || [];

  const urgentPersonal = personalExpenses.filter((e) => {
    return (new Date(e.FINCFECX) - new Date()) / (1000 * 60 * 60 * 24) <= 3;
  });

  const urgentBeatan = beatanExpenses.filter((e) => {
    return (new Date(e.FINCFECX) - new Date()) / (1000 * 60 * 60 * 24) <= 3;
  });

  if (urgentPersonal.length > 0) {
    const total = urgentPersonal.reduce((sum, e) => sum + Math.abs(parseFloat(e.MONTGASX)), 0);
    showToast("warning", "Gastos Personales Próximos", `Tienes ${urgentPersonal.length} gasto(s) por pagar: $${formatNumber(total)}`);
  }

  if (window.isAdmin && urgentBeatan.length > 0) {
    const totalB = urgentBeatan.reduce((sum, e) => sum + Math.abs(parseFloat(e.MONTGASX)), 0);
    setTimeout(() => {
      showToast("warning", "Gastos Empresa (BeaTaN) Próximos", `Tienes ${urgentBeatan.length} gasto(s) por pagar: $${formatNumber(totalB)}`);
    }, 1500);
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
  const filterTipoEl     = document.getElementById(`${prefix === "beatan" ? "beatan" : "personal"}FilterTipo`);
  const filterMonthEl    = document.getElementById(`${prefix === "beatan" ? "beatan" : "personal"}FilterMonth`);
  const filterCatEl      = document.getElementById(`${prefix === "beatan" ? "beatan" : "personal"}FilterCategoria`);
  const filterSearchEl   = document.getElementById(`${prefix === "beatan" ? "beatan" : "personal"}FilterSearch`);

  if (!tableContainer) return;

  const filterTipo     = filterTipoEl   ? filterTipoEl.value   : "";
  const filterMonth    = filterMonthEl  ? filterMonthEl.value  : "";
  const filterCat      = filterCatEl    ? filterCatEl.value    : "";
  const filterSearch   = filterSearchEl ? filterSearchEl.value : "";

  tableContainer.innerHTML =
    '<div class="loading"><div class="spinner"></div></div>';

  try {
    let url = `api/transactions.php?tipgasxx=${tipgasxx}`;
    if (filterTipo)   url += `&tipo=${filterTipo}`;
    if (filterMonth)  url += `&month=${filterMonth}`;
    if (filterCat)    url += `&categoria=${encodeURIComponent(filterCat)}`;
    if (filterSearch) url += `&search=${encodeURIComponent(filterSearch)}`;

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
    deudidxx: formData.get("deudidxx"),
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
        loadDeudasSelect("editTransactionDeudidxx", transaction.DEUDIDXX);

        document.getElementById("editTransactionId").value = id;
        document.getElementById("editTransactionTipgasxx").value = tipgasxx;
        document.getElementById("editTransactionDeudidxx").value = transaction.DEUDIDXX ?? "";
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
    deudidxx: document.getElementById("editTransactionDeudidxx").value,
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

/**
 * Gestión de Deudas
 */

async function loadDebts(tipperxx) {
  const prefix = tipperxx.toLowerCase(); // beatan | personal
  const container = document.getElementById(`${prefix}DebtsTable`);

  if (!container) return;

  container.innerHTML = '<div class="loading"><div class="spinner"></div></div>';

  // Leer filtros desde la UI
  const estado  = document.getElementById(`${prefix}DebtFilterEstado`)?.value || 'ACTIVO';
  const tipo    = document.getElementById(`${prefix}DebtFilterTipo`)?.value   || '';
  const search  = document.getElementById(`${prefix}DebtSearch`)?.value       || '';

  try {
    let url = `api/debts.php?tipperxx=${tipperxx}&regestxx=${encodeURIComponent(estado)}`;
    if (tipo)   url += `&tipdeudx=${encodeURIComponent(tipo)}`;
    if (search) url += `&search=${encodeURIComponent(search)}`;

    const response = await fetch(url);
    const data = await response.json();

    if (data.success) {
      renderDebtsTable(tipperxx, data.data, estado);
    } else {
      container.innerHTML = `<p>Error al cargar deudas</p>`;
    }
  } catch (err) {
    console.error(err);
    container.innerHTML = `<p>Error de conexion</p>`;
  }
}

function renderDebtsTable(tipperxx, debts, estadoFiltro = 'ACTIVO') {
  const prefix = tipperxx.toLowerCase();
  const container = document.getElementById(`${prefix}DebtsTable`);

  if (!debts || debts.length === 0) {
    container.innerHTML = `<div class="empty-state">
                <i class="bi bi-inbox"></i>
                <h3>Sin deudas</h3>
                <p>No hay Deudas registradas con ese filtro</p>
            </div>`;
    return;
  }

  let html = `
    <table class="data-table">
      <thead>
        <tr>
          <th>Día pago</th>
          <th>Tipo</th>
          <th>Descripcion</th>
          <th>Monto Total</th>
          <th>Valor Cuota</th>
          <th>Pagado</th>
          <th>Restante</th>
          <th>N° Cuotas</th>
          <th>Estado</th>
          <th>Acciones</th>
        </tr>
      </thead>
      <tbody>
  `;

  debts.forEach(d => {
    const monto    = parseFloat(d.MONDEUDX);
    const valCuot  = parseFloat(d.MONCUOTX);
    const abonCuot = d.MONCUOTX && d.PAGCUOTX
      ? d.MONCUOTX * d.PAGCUOTX
      : (d.ABONCUOT ?? 0);
    const resCuot  = parseFloat(d.MONDEUDX - (abonCuot));
    const isIncome = d.TIPDEUDX;

    const estadoBadge = d.REGESTXX === 'PAGADO'
      ? 'badge-responded'
      : (d.REGESTXX === 'INACTIVO' ? 'badge-closed' : 'badge-read');

    const canEdit = d.REGESTXX === 'ACTIVO';

    html += `
      <tr>
        <td>${d.FECCUOTX}</td>
        <td>
          <span class="badge ${isIncome == "A FAVOR" ? "badge-income" : "badge-expense"}">
            ${escapeHtml(d.TIPDEUDX)}
          </span>
        </td>
        <td>${escapeHtml(d.DESDEUDX || "-")}</td>
        <td>
          <span class="amount ${isIncome == "A FAVOR" ? "positive" : "negative"}">
            ${isIncome == "A FAVOR" ? "+" : "-"}${formatCurrency(monto)}
          </span>
        </td>
        <td>
          <span class="amount ${isIncome == "A FAVOR" ? "positive" : "negative"}">
            ${formatCurrency(valCuot)}
          </span>
        </td>
        <td>${formatCurrency(abonCuot)}</td>
        <td>${formatCurrency(resCuot)}</td>
        <td>${(d.PAGCUOTX ?? 0) + "/" + (d.NUMCUOTX ?? "-") ?? "-"}</td>
        <td><span class="badge ${estadoBadge}">${d.REGESTXX}</span></td>
        <td>
        <div class="action-buttons">
          ${canEdit ? `
            <button class="btn-icon" onclick="editDebt(${d.DEUDIDXX}, '${tipperxx}')" title="Editar">
              <i class="bi bi-pencil"></i>
            </button>
            <button class="btn-icon" onclick="markDebtPaid(${d.DEUDIDXX}, '${tipperxx}')" title="Marcar Pagada" style="color:var(--success-color);">
              <i class="bi bi-check-circle"></i>
            </button>` : ''}
          <button class="btn-icon" onclick="deleteDebt(${d.DEUDIDXX}, '${tipperxx}')" title="Eliminar" style="color: var(--error-color);">
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

async function addDebt(e) {
  e.preventDefault();

  const form = e.target;
  const tipperxx = document.getElementById("addDebtTipperxx").value;
  const data = {
    tipperxx: tipperxx,
    tipdeudx: form.tipdeudx.value,
    mondeudx: parseFloat(form.mondeudx.value),
    desdeudx: form.desdeudx.value,
    numcuotx: form.numcuotx.value || null,
    feccuotx: form.feccuotx.value || null,
    moncuotx: form.moncuotx.value || null
  };

  try {
    const response = await fetch("api/debts.php", {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify(data)
    });

    const result = await response.json();

    if (result.success) {
      showAlert("Deuda registrada", "success");
      form.reset();
      loadDebts(tipperxx);
    } else {
      showAlert(result.error, "error");
    }
  } catch (err) {
    console.error(err);
    showAlert("Error de conexion", "error");
  }
}

async function editDebt(id, tipperxx) {
  try {
    const response = await fetch(`api/debts.php?tipperxx=${tipperxx}`);
    const data = await response.json();

    if (data.success) {
      const d = data.data.find(x => x.DEUDIDXX == id);

      document.getElementById("editDebtId").value = d.DEUDIDXX;
      document.getElementById("editDebtTipo").value = d.TIPDEUDX;
      document.getElementById("mondeudx").value = d.MONDEUDX;
      document.getElementById("editDebtDesc").value = d.DESDEUDX;
      document.getElementById("moncuotx").value = d.NUMCUOTX;
      document.getElementById("editDebtFecha").value = d.FECCUOTX;
      document.getElementById("moncuotx").value = d.MONCUOTX;
      document.getElementById("editDebtTipperxx").value = tipperxx;

      openModal("editDebtModal");
    }
  } catch (err) {
    console.error(err);
    showAlert("Error al cargar deuda", "error");
  }
}

async function updateDebt(e) {
  e.preventDefault();

  const data = {
    id: document.getElementById("editDebtId").value,
    tipdeudx: document.getElementById("editDebtTipo").value,
    mondeudx: document.getElementById("mondeudx").value,
    desdeudx: document.getElementById("editDebtDesc").value,
    numcuotx: document.getElementById("numcuotx").value || null,
    feccuotx: document.getElementById("editDebtFecha").value,
    moncuotx: document.getElementById("moncuotx").value
  };

  const tipperxx = document.getElementById("editDebtTipperxx").value;

  const response = await fetch("api/debts.php", {
    method: "PUT",
    headers: { "Content-Type": "application/json" },
    body: JSON.stringify(data)
  });

  const result = await response.json();

  if (result.success) {
    showAlert("Deuda actualizada", "success");
    closeModal("editDebtModal");
    loadDebts(tipperxx);
  } else {
    showAlert(result.error, "error");
  }
}

async function deleteDebt(id, tipperxx) {
  if (!confirm("Eliminar deuda?")) return;

  const response = await fetch(`api/debts.php?id=${id}`, {
    method: "DELETE"
  });

  const result = await response.json();

  if (result.success) {
    showAlert("Deuda eliminada", "success");
    loadDebts(tipperxx);
  } else {
    showAlert(result.error, "error");
  }
}

/**
 * Marcar deuda como PAGADA
 */
async function markDebtPaid(id, tipperxx) {
  if (!confirm("\u00bfMarcar esta deuda como PAGADA?")) return;

  try {
    const response = await fetch("api/debts.php", {
      method: "PUT",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify({ id, regestxx: 'PAGADO' })
    });
    const result = await response.json();
    if (result.success) {
      showAlert("Deuda marcada como pagada", "success");
      loadDebts(tipperxx);
    } else {
      showAlert(result.error || "Error al actualizar", "error");
    }
  } catch (err) {
    console.error(err);
    showAlert("Error de conexion", "error");
  }
}

// ===============================================
// DEBOUNCE HELPERS (para búsqueda de texto)
// ===============================================
let _debounceTimerTx  = null;
let _debounceTimerDbt = null;

function debounceLoadTransactions(tipgasxx) {
  clearTimeout(_debounceTimerTx);
  _debounceTimerTx = setTimeout(() => loadTransactions(tipgasxx), 350);
}

function debounceLoadDebts(tipperxx) {
  clearTimeout(_debounceTimerDbt);
  _debounceTimerDbt = setTimeout(() => loadDebts(tipperxx), 350);
}

// ===============================================
// REPORTE MENSUAL
// ===============================================
let reportChartInstance = null;
let _reportData = null;

function openReportModal(tipgasxx) {
  document.getElementById('reportTipgasxx').value = tipgasxx;
  const month = document.getElementById(
    tipgasxx === 'BEATAN' ? 'beatanFilterMonth' : 'personalFilterMonth'
  )?.value || new Date().toISOString().slice(0, 7);
  document.getElementById('reportMonth').value = month;

  const label = tipgasxx === 'BEATAN' ? 'BeaTaN' : 'Personal';
  document.getElementById('reportTitle').textContent = `${label} — ${formatMonthLabel(month)}`;

  openModal('reportModal');
  loadReport(tipgasxx, month);
}

function refreshReport() {
  const tipgasxx = document.getElementById('reportTipgasxx').value;
  const month    = document.getElementById('reportMonth').value;
  const label    = tipgasxx === 'BEATAN' ? 'BeaTaN' : 'Personal';
  document.getElementById('reportTitle').textContent = `${label} — ${formatMonthLabel(month)}`;
  loadReport(tipgasxx, month);
}

async function loadReport(tipgasxx, month) {
  const content = document.getElementById('reportContent');
  content.innerHTML = '<div class="loading"><div class="spinner"></div></div>';

  if (reportChartInstance) {
    reportChartInstance.destroy();
    reportChartInstance = null;
  }

  try {
    const res  = await fetch(`api/report.php?tipgasxx=${tipgasxx}&month=${month}`);
    const data = await res.json();

    if (!data.success) {
      content.innerHTML = `<div class="empty-state"><i class="bi bi-exclamation-circle"></i><h3>Error</h3><p>${data.error}</p></div>`;
      return;
    }

    _reportData = data;
    renderReportContent(data);
  } catch (err) {
    console.error(err);
    content.innerHTML = `<div class="empty-state"><i class="bi bi-wifi-off"></i><h3>Error de conexión</h3></div>`;
  }
}

function renderReportContent(data) {
  const r = data.resumen;
  const prevMonth = data.periodo.prevMonth;

  const varBadge = (val, inverse = false) => {
    if (val === null) return '<span style="color:var(--muted-white)">N/A</span>';
    const positive = inverse ? val < 0 : val > 0;
    const color = positive ? 'var(--success-color)' : 'var(--error-color)';
    const icon  = val > 0  ? '&#x2191;' : '&#x2193;';
    return `<span style="color:${color};font-weight:600;">${icon} ${Math.abs(val)}%</span>`;
  };

  // ── KPI Cards ──────────────────────────────────────────────────────────
  let html = `
    <div class="report-kpis">
      <div class="report-kpi income">
        <div class="kpi-icon"><i class="bi bi-arrow-up-circle-fill"></i></div>
        <div class="kpi-body">
          <span class="kpi-label">Ingresos</span>
          <span class="kpi-value positive">${formatCurrency(r.ingresos)}</span>
          <span class="kpi-var">${varBadge(r.varIngresos)} vs mes anterior</span>
        </div>
      </div>
      <div class="report-kpi expense">
        <div class="kpi-icon"><i class="bi bi-arrow-down-circle-fill"></i></div>
        <div class="kpi-body">
          <span class="kpi-label">Gastos</span>
          <span class="kpi-value negative">${formatCurrency(r.gastos)}</span>
          <span class="kpi-var">${varBadge(r.varGastos, true)} vs mes anterior</span>
        </div>
      </div>
      <div class="report-kpi balance">
        <div class="kpi-icon"><i class="bi bi-wallet2"></i></div>
        <div class="kpi-body">
          <span class="kpi-label">Balance Neto</span>
          <span class="kpi-value ${r.balance >= 0 ? 'positive' : 'negative'}">${formatCurrency(r.balance)}</span>
          <span class="kpi-var">${varBadge(r.varBalance)} vs mes anterior</span>
        </div>
      </div>
      <div class="report-kpi movs">
        <div class="kpi-icon"><i class="bi bi-list-check"></i></div>
        <div class="kpi-body">
          <span class="kpi-label">Movimientos</span>
          <span class="kpi-value" style="color:var(--primary-cyan);">${r.movimientos}</span>
          <span class="kpi-var">en el periodo</span>
        </div>
      </div>
    </div>
  `;

  // ── Gráfica por categoría ───────────────────────────────────────────────
  html += `
    <div style="display:grid;grid-template-columns:1fr 1fr;gap:16px;margin:20px 0;">
      <div class="card" style="padding:16px;">
        <h4 style="margin-bottom:12px;font-size:0.95rem;"><i class="bi bi-bar-chart-fill"></i> Por Categoría</h4>
        <div style="position:relative;height:200px;"><canvas id="reportCatChart"></canvas></div>
      </div>
      <div class="card" style="padding:16px;overflow:auto;max-height:260px;">
        <h4 style="margin-bottom:10px;font-size:0.95rem;"><i class="bi bi-table"></i> Detalle</h4>
        <table class="data-table" style="font-size:0.8rem;">
          <thead><tr><th>Categoría</th><th>Ing.</th><th>Gas.</th><th>Movs.</th></tr></thead>
          <tbody>
            ${data.categorias.map(c => `
              <tr>
                <td>${escapeHtml(c.CATGASXX)}</td>
                <td class="amount positive">${formatCurrency(c.ingresos)}</td>
                <td class="amount negative">${formatCurrency(c.gastos)}</td>
                <td>${c.movimientos}</td>
              </tr>`).join('')}
          </tbody>
        </table>
      </div>
    </div>
  `;

  // ── Movimientos del mes ─────────────────────────────────────────────────
  html += `
    <div class="card" style="padding:16px;margin-bottom:16px;">
      <h4 style="margin-bottom:10px;font-size:0.95rem;"><i class="bi bi-receipt"></i> Movimientos del Mes</h4>
      <div style="max-height:220px;overflow:auto;">
        <table class="data-table" style="font-size:0.8rem;">
          <thead><tr><th>Fecha</th><th>Categoría</th><th>Descripción</th><th>Monto</th></tr></thead>
          <tbody>
            ${data.movimientos.length === 0
              ? '<tr><td colspan="4" style="text-align:center;color:var(--muted-white);">Sin movimientos</td></tr>'
              : data.movimientos.map(m => {
                  const isInc = parseFloat(m.MONTGASX) > 0;
                  return `<tr>
                    <td>${formatDate(m.FINCFECX)}</td>
                    <td>${escapeHtml(m.CATGASXX)}</td>
                    <td>${escapeHtml(m.DESGASXX || '-')}</td>
                    <td><span class="amount ${isInc?'positive':'negative'}">${isInc?'+':''}${formatCurrency(parseFloat(m.MONTGASX))}</span></td>
                  </tr>`;
                }).join('')
            }
          </tbody>
        </table>
      </div>
    </div>
  `;

  // ── Deudas ─────────────────────────────────────────────────────────────
  const dActivas = data.deudas.activas;
  const dPagadas = data.deudas.pagadas;
  html += `
    <div style="display:grid;grid-template-columns:1fr 1fr;gap:16px;">
      <div class="card" style="padding:16px;">
        <h4 style="margin-bottom:10px;font-size:0.95rem;color:var(--warning-color);"><i class="bi bi-exclamation-diamond"></i> Deudas Activas (${dActivas.length})</h4>
        ${dActivas.length === 0 ? '<p style="color:var(--muted-white);font-size:0.85rem;">Sin deudas activas</p>' : `
          <div style="max-height:150px;overflow:auto;">
            <table class="data-table" style="font-size:0.78rem;">
              <thead><tr><th>Descr.</th><th>Monto</th><th>Tipo</th></tr></thead>
              <tbody>${dActivas.map(d => `<tr>
                <td>${escapeHtml(d.DESDEUDX)}</td>
                <td><span class="amount ${d.TIPDEUDX==='A FAVOR'?'positive':'negative'}">${formatCurrency(d.MONDEUDX)}</span></td>
                <td>${escapeHtml(d.TIPDEUDX)}</td>
              </tr>`).join('')}</tbody>
            </table>
          </div>
          <div style="margin-top:8px;font-size:0.82rem;">
            <span style="color:var(--error-color);"><b>En contra:</b> ${formatCurrency(data.deudas.totalContra)}</span> &nbsp;
            <span style="color:var(--success-color);"><b>A favor:</b> ${formatCurrency(data.deudas.totalFavor)}</span>
          </div>
        `}
      </div>
      <div class="card" style="padding:16px;">
        <h4 style="margin-bottom:10px;font-size:0.95rem;color:var(--success-color);"><i class="bi bi-check-circle"></i> Deudas Pagadas (${dPagadas.length})</h4>
        ${dPagadas.length === 0 ? '<p style="color:var(--muted-white);font-size:0.85rem;">Sin deudas pagadas</p>' : `
          <div style="max-height:150px;overflow:auto;">
            <table class="data-table" style="font-size:0.78rem;">
              <thead><tr><th>Descr.</th><th>Monto</th><th>Tipo</th></tr></thead>
              <tbody>${dPagadas.map(d => `<tr>
                <td>${escapeHtml(d.DESDEUDX)}</td>
                <td><span class="amount ${d.TIPDEUDX==='A FAVOR'?'positive':'negative'}">${formatCurrency(d.MONDEUDX)}</span></td>
                <td>${escapeHtml(d.TIPDEUDX)}</td>
              </tr>`).join('')}</tbody>
            </table>
          </div>
        `}
      </div>
    </div>
  `;

  document.getElementById('reportContent').innerHTML = html;

  // ── Crear gráfica categorías ────────────────────────────────────────────
  const catCtx = document.getElementById('reportCatChart');
  if (catCtx && data.categorias.length > 0) {
    const labels  = data.categorias.map(c => c.CATGASXX);
    const ingData = data.categorias.map(c => parseFloat(c.ingresos));
    const gasData = data.categorias.map(c => parseFloat(c.gastos));

    reportChartInstance = new Chart(catCtx, {
      type: 'bar',
      data: {
        labels,
        datasets: [
          { label: 'Ingresos', data: ingData, backgroundColor: 'rgba(34,197,94,0.7)', borderRadius: 4 },
          { label: 'Gastos',   data: gasData, backgroundColor: 'rgba(239,68,68,0.7)',  borderRadius: 4 }
        ]
      },
      options: {
        responsive: true, maintainAspectRatio: false,
        plugins: {
          legend: { labels: { color: '#fff', font: { size: 11 } } },
          tooltip: { callbacks: { label: ctx => `${ctx.dataset.label}: ${formatCurrency(ctx.raw)}` } }
        },
        scales: {
          x: { ticks: { color: '#aaa', font: { size: 10 } }, grid: { color: 'rgba(255,255,255,0.05)' } },
          y: { ticks: { color: '#aaa', callback: v => '$' + Number(v).toLocaleString('es-CO') }, grid: { color: 'rgba(255,255,255,0.08)' } }
        }
      }
    });
  }
}

function printReport() {
  window.print();
}

function exportReportCSV() {
  if (!_reportData) return;
  const movs = _reportData.movimientos;
  if (!movs || movs.length === 0) {
    showAlert('No hay movimientos para exportar', 'warning');
    return;
  }

  const tipgasxx = document.getElementById('reportTipgasxx').value;
  const month    = document.getElementById('reportMonth').value;
  const r        = _reportData.resumen;

  let csv  = `Reporte Mensual - ${tipgasxx} - ${month}\n`;
  csv     += `Ingresos;${r.ingresos}\nGastos;${r.gastos}\nBalance;${r.balance}\n\n`;
  csv     += `Fecha;Categoria;Descripcion;Monto\n`;

  movs.forEach(m => {
    const monto = parseFloat(m.MONTGASX).toFixed(2);
    csv += `${m.FINCFECX};${m.CATGASXX};"${(m.DESGASXX || '').replace(/"/g, '""')}";${monto}\n`;
  });

  const blob = new Blob(['\uFEFF' + csv], { type: 'text/csv;charset=utf-8;' });
  const url  = URL.createObjectURL(blob);
  const a    = document.createElement('a');
  a.href     = url;
  a.download = `reporte_${tipgasxx}_${month}.csv`;
  a.click();
  URL.revokeObjectURL(url);
}

function formatMonthLabel(month) {
  const [y, m] = month.split('-');
  const names = ['Enero','Febrero','Marzo','Abril','Mayo','Junio','Julio','Agosto','Septiembre','Octubre','Noviembre','Diciembre'];
  return `${names[parseInt(m, 10) - 1]} ${y}`;
}

async function loadDeudasSelect(selectId, selectedDeudidxx = null) {
  try {
    const response = await fetch("api/deudas_select.php");
    const data = await response.json();

    const select = document.getElementById(selectId);
    if (!select) return;

    select.innerHTML = `<option value="">Seleccione una deuda</option>`;

    if (data.success) {
      data.data.forEach((d) => {
        const option = document.createElement("option");
        option.value = d.DEUDIDXX;
        option.textContent = `${d.DEUDIDXX} - ${d.DESDEUDX}`;

        if (selectedDeudidxx && d.DEUDIDXX == selectedDeudidxx) {
          option.selected = true;
        }

        select.appendChild(option);
      });
    }
  } catch (error) {
    console.error("Error cargando deudas:", error);
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

function openAddDebtModal(tipperxx) {
  document.getElementById("addDebtTipperxx").value = tipperxx;
  openModal('addDebtModal');
}

function openPersonalModal() {
  openModal('movPersonal');
  loadDeudasSelect("addDeudidxx");
}

function openBeatanModal() {
  openModal('movBeatan');
  loadDeudasSelect("beatanAddDeudidxx");
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

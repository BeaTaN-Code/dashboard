// dashboard/js/dashboard.js
// Módulos de administración heredados: mensajes del formulario, usuarios, cronograma (Gantt)
// y facturación. Las vistas financieras viven en finance.js / planning.js.
// Utilidades compartidas con el resto de la app (App.*) para mantener un único sistema de avisos.

function showToast(type, title, message) {
  window.App.toast(type === "error" ? "error" : type, title, message);
}

function showAlert(message, type = "info") {
  window.App.toast(type, type === "error" ? "No se pudo completar" : type === "success" ? "Listo" : "Aviso", message);
}

function localISODate(d = new Date()) {
  return window.FinanceCore.toISODate(d);
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
// UTILIDADES DE FORMATO
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

function formatGanttDate(dateStr) {
  if (!dateStr) return "-";
  const parts = dateStr.split('-');
  if (parts.length !== 3) return dateStr;
  const year = parseInt(parts[0], 10);
  const month = parseInt(parts[1], 10) - 1;
  const day = parseInt(parts[2], 10);
  const date = new Date(year, month, day);
  
  const daysOfWeek = ["dom", "lun", "mar", "mié", "jue", "vie", "sáb"];
  const months = ["ene", "feb", "mar", "abr", "may", "jun", "jul", "ago", "sep", "oct", "nov", "dic"];
  
  const dow = daysOfWeek[date.getDay()];
  const m = months[date.getMonth()];
  const yr = String(date.getFullYear()).substring(2);
  const dy = String(day).padStart(2, '0');
  
  return `${dow}.,${dy}-${m}.-${yr}`;
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

// ===============================================
// MODULO CRONOGRAMA Y FACTURACION (ADMIN)
// ===============================================
let projectsCache = [];
let usersCache = [];

async function loadProjectsSelects() {
  if (!window.isAdmin) return;
  try {
    const res = await fetch("api/projects.php");
    const data = await res.json();
    if (data.success) {
      projectsCache = data.data;
      
      const filterSelect = document.getElementById("cronFilterProject");
      const addHoursSelect = document.getElementById("addHoursProject");
      const addInvProjectSelect = document.getElementById("addInvProject");
      
      const optionsHtml = data.data.map(p => `<option value="${p.PROYIDXX}">${escapeHtml(p.PROYNOMX)}</option>`).join("");
      
      if (filterSelect) {
        filterSelect.innerHTML = '<option value="">Todos los proyectos</option>' + optionsHtml;
      }
      if (addHoursSelect) {
        addHoursSelect.innerHTML = '<option value="">Seleccionar proyecto...</option>' + optionsHtml;
      }
      if (addInvProjectSelect) {
        addInvProjectSelect.innerHTML = '<option value="">Ninguno</option>' + optionsHtml;
      }

      const totalProjectsEl = document.getElementById("cronTotalProjects");
      if (totalProjectsEl) {
        totalProjectsEl.textContent = data.data.filter(p => p.PROYESTX !== 'INACTIVO').length;
      }
    }
  } catch (err) {
    console.error("Error loading projects:", err);
  }
}

async function loadUsersSelects() {
  if (!window.isAdmin) return;
  try {
    const res = await fetch("api/users.php");
    const data = await res.json();
    if (data.success) {
      usersCache = data.data;
      
      const filterSelect = document.getElementById("cronFilterUser");
      const addHoursSelect = document.getElementById("addHoursUser");
      
      const optionsHtml = data.data
        .filter(u => u.REGESTXX === 'ACTIVO')
        .map(u => `<option value="${u.USRIDXXX}">${escapeHtml(u.USRNMEXX)}</option>`).join("");
      
      if (filterSelect) {
        filterSelect.innerHTML = '<option value="">Todos los colaboradores</option>' + optionsHtml;
      }
      if (addHoursSelect) {
        addHoursSelect.innerHTML = '<option value="">Seleccionar colaborador...</option>' + optionsHtml;
      }
    }
  } catch (err) {
    console.error("Error loading users:", err);
  }
}

async function loadHoursLogs() {
  if (!window.isAdmin) return;
  const container = document.getElementById("hoursTableContainer");
  if (!container) return;

  const projectId = document.getElementById("cronFilterProject").value;
  const userId = document.getElementById("cronFilterUser").value;
  const startDate = document.getElementById("cronFilterStart").value;
  const endDate = document.getElementById("cronFilterEnd").value;

  container.innerHTML = '<div class="loading"><div class="spinner"></div></div>';

  try {
    let url = "api/project_hours.php?";
    if (projectId) url += `&proyidxx=${projectId}`;
    if (userId) url += `&usridxxx=${userId}`;
    if (startDate) url += `&start_date=${startDate}`;
    if (endDate) url += `&end_date=${endDate}`;

    const res = await fetch(url);
    const data = await res.json();

    if (data.success) {
      const total = data.data.reduce((sum, row) => sum + parseFloat(row.HORADEDX), 0);
      const totalHoursEl = document.getElementById("cronTotalHours");
      if (totalHoursEl) {
        totalHoursEl.textContent = total.toFixed(2);
      }

      if (data.data.length === 0) {
        container.innerHTML = `
          <div class="empty-state">
            <i class="bi bi-calendar-x"></i>
            <h3>Sin registros</h3>
            <p>No se encontraron registros de horas con los filtros seleccionados.</p>
          </div>
        `;
        return;
      }

      let html = `
        <table class="data-table">
          <thead>
            <tr>
              <th>Fecha</th>
              <th>Proyecto</th>
              <th>Colaborador</th>
              <th>Horas</th>
              <th>Detalle / Tarea</th>
              <th>Acciones</th>
            </tr>
          </thead>
          <tbody>
      `;

      data.data.forEach(row => {
        html += `
          <tr>
            <td>${formatDate(row.HORAFECX)}</td>
            <td><strong>${escapeHtml(row.PROYNOMX)}</strong></td>
            <td>${escapeHtml(row.USRNMEXX)}</td>
            <td><span class="badge badge-responded" style="font-size:0.85rem;">${parseFloat(row.HORADEDX).toFixed(2)} hrs</span></td>
            <td>${escapeHtml(row.HORADESX || "-")}</td>
            <td>
              <button class="btn-icon" onclick="deleteHoursLog(${row.HORAIDXX})" title="Eliminar Registro" style="color: var(--error-color);">
                <i class="bi bi-trash"></i>
              </button>
            </td>
          </tr>
        `;
      });

      html += "</tbody></table>";
      container.innerHTML = html;
    } else {
      container.innerHTML = `<div class="alert alert-error">${escapeHtml(data.error)}</div>`;
    }
  } catch (err) {
    console.error("Error loading hours logs:", err);
    container.innerHTML = '<div class="alert alert-error">Error al conectar con el servidor</div>';
  }
}

function openHoursModal() {
  const form = document.getElementById("addHoursForm");
  if (form) form.reset();
  document.getElementById("addHoursDate").value = localISODate();
  openModal("addHoursModal");
}

async function saveNewProject(e) {
  e.preventDefault();
  const form = e.target;
  const formData = new FormData(form);
  const payload = {
    nombre: formData.get("nombre"),
    descripcion: formData.get("descripcion"),
    estado: formData.get("estado")
  };

  try {
    const res = await fetch("api/projects.php", {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify(payload)
    });
    const data = await res.json();
    if (data.success) {
      showAlert("Proyecto creado exitosamente", "success");
      closeModal("addProjectModal");
      form.reset();
      loadProjectsSelects();
    } else {
      showAlert(data.error, "error");
    }
  } catch (err) {
    console.error("Error creating project:", err);
    showAlert("Error de conexión", "error");
  }
}

async function saveNewHoursLog(e) {
  e.preventDefault();
  const form = e.target;
  const formData = new FormData(form);
  const payload = {
    proyidxx: parseInt(formData.get("proyidxx")),
    usridxxx: formData.get("usridxxx"),
    horadedx: parseFloat(formData.get("horadedx")),
    horafecx: formData.get("horafecx"),
    horadesx: formData.get("horadesx")
  };

  try {
    const res = await fetch("api/project_hours.php", {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify(payload)
    });
    const data = await res.json();
    if (data.success) {
      showAlert("Horas registradas exitosamente", "success");
      closeModal("addHoursModal");
      form.reset();
      loadHoursLogs();
    } else {
      showAlert(data.error, "error");
    }
  } catch (err) {
    console.error("Error logging hours:", err);
    showAlert("Error de conexión", "error");
  }
}

async function deleteHoursLog(id) {
  if (!confirm("¿Está seguro de eliminar este registro de horas?")) return;
  try {
    const res = await fetch(`api/project_hours.php?id=${id}`, { method: "DELETE" });
    const data = await res.json();
    if (data.success) {
      showAlert("Registro eliminado exitosamente", "success");
      loadHoursLogs();
    } else {
      showAlert(data.error, "error");
    }
  } catch (err) {
    console.error("Error deleting hour log:", err);
    showAlert("Error de conexión", "error");
  }
}

let invoicesCache = [];

async function loadInvoices() {
  if (!window.isAdmin) return;
  const container = document.getElementById("invoicesTableContainer");
  if (!container) return;

  container.innerHTML = '<div class="loading"><div class="spinner"></div></div>';

  try {
    const res = await fetch("api/invoices.php");
    const data = await res.json();

    if (data.success) {
      invoicesCache = data.data;
      renderInvoicesTable(data.data);
    } else {
      container.innerHTML = `<div class="alert alert-error">${escapeHtml(data.error)}</div>`;
    }
  } catch (err) {
    console.error("Error loading invoices:", err);
    container.innerHTML = '<div class="alert alert-error">Error al conectar con el servidor</div>';
  }
}

function renderInvoicesTable(invoices) {
  const container = document.getElementById("invoicesTableContainer");
  if (!container) return;

  if (invoices.length === 0) {
    container.innerHTML = `
      <div class="empty-state">
        <i class="bi bi-receipt"></i>
        <h3>Sin facturas</h3>
        <p>No hay facturas o presupuestos registrados.</p>
      </div>
    `;
    return;
  }

  let html = `
    <table class="data-table" id="invoicesTable">
      <thead>
        <tr>
          <th>Número</th>
          <th>Cliente</th>
          <th>Proyecto</th>
          <th>Emisión</th>
          <th>Vencimiento</th>
          <th>Total</th>
          <th>Estado</th>
          <th>Acciones</th>
        </tr>
      </thead>
      <tbody>
  `;

  invoices.forEach(inv => {
    let stateBadge = "badge-pending";
    if (inv.FACTESTX === "ENVIADA") stateBadge = "badge-read";
    else if (inv.FACTESTX === "PAGADA") stateBadge = "badge-responded";
    else if (inv.FACTESTX === "ANULADA") stateBadge = "badge-closed";

    html += `
      <tr data-client="${escapeHtml(inv.CLIENOMX.toLowerCase())}">
        <td><strong>${escapeHtml(inv.FACTNUMX)}</strong></td>
        <td>${escapeHtml(inv.CLIENOMX)}</td>
        <td>${escapeHtml(inv.PROYNOMX || "Ninguno")}</td>
        <td>${formatDate(inv.FACTFECX)}</td>
        <td>${formatDate(inv.FACTVENX)}</td>
        <td><strong class="amount positive">${formatCurrency(inv.FACTTOTA)}</strong></td>
        <td>
          <span class="badge ${stateBadge}">${inv.FACTESTX}</span>
        </td>
        <td>
          <div class="action-buttons">
            <button class="btn-icon" onclick="viewInvoice(${inv.FACTIDXX})" title="Ver / PDF">
              <i class="bi bi-eye"></i>
            </button>
            <select style="background:var(--bg-dark); color:#fff; border:1px solid var(--border-color); padding:2px 4px; border-radius:4px; font-size:0.75rem;" onchange="changeInvoiceStatus(${inv.FACTIDXX}, this.value)">
              <option value="BORRADOR" ${inv.FACTESTX === 'BORRADOR' ? 'selected' : ''}>Borrador</option>
              <option value="ENVIADA" ${inv.FACTESTX === 'ENVIADA' ? 'selected' : ''}>Enviada</option>
              <option value="PAGADA" ${inv.FACTESTX === 'PAGADA' ? 'selected' : ''}>Pagada</option>
              <option value="ANULADA" ${inv.FACTESTX === 'ANULADA' ? 'selected' : ''}>Anulada</option>
            </select>
            <button class="btn-icon" onclick="deleteInvoice(${inv.FACTIDXX})" title="Eliminar" style="color:var(--error-color);">
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

function filterInvoicesTable() {
  const query = document.getElementById("invoiceSearch").value.toLowerCase();
  const rows = document.querySelectorAll("#invoicesTable tbody tr");
  rows.forEach(row => {
    const client = row.getAttribute("data-client");
    if (client.includes(query)) {
      row.style.display = "";
    } else {
      row.style.display = "none";
    }
  });
}

function openInvoiceModal() {
  const form = document.getElementById("addInvoiceForm");
  if (form) form.reset();
  
  const tbody = document.getElementById("invoiceItemsBody");
  if (tbody) tbody.innerHTML = "";
  
  document.getElementById("addInvDate").value = localISODate();
  const due = new Date();
  due.setDate(due.getDate() + 30);
  document.getElementById("addInvDueDate").value = localISODate(due);
  
  loadProjectsSelects();
  addInvoiceItemRow();
  
  recalculateInvoiceTotals();
  openModal("addInvoiceModal");
}

function addInvoiceItemRow() {
  const tbody = document.getElementById("invoiceItemsBody");
  if (!tbody) return;

  const tr = document.createElement("tr");
  tr.className = "invoice-item-row";
  tr.innerHTML = `
    <td>
      <input type="text" class="item-desc" style="width:100%;" placeholder="Concepto / Servicio" required>
    </td>
    <td>
      <input type="number" class="item-qty" style="width:100%; text-align:right;" value="1" min="0.01" step="0.01" oninput="calculateItemRowTotal(this)" required>
    </td>
    <td>
      <input type="number" class="item-price" style="width:100%; text-align:right;" placeholder="0" min="0" step="100" oninput="calculateItemRowTotal(this)" required>
    </td>
    <td>
      <span class="item-total-val" style="display:block; text-align:right; font-weight:bold;">$0.00</span>
    </td>
    <td style="text-align:center;">
      <button type="button" class="btn-icon" onclick="deleteInvoiceItemRow(this)" style="color:var(--error-color);">
        <i class="bi bi-trash"></i>
      </button>
    </td>
  `;
  tbody.appendChild(tr);
  recalculateInvoiceTotals();
}

function deleteInvoiceItemRow(btn) {
  const tbody = document.getElementById("invoiceItemsBody");
  if (!tbody) return;
  
  if (tbody.querySelectorAll("tr").length <= 1) {
    showAlert("La factura debe contener al menos un item", "warning");
    return;
  }
  
  btn.closest("tr").remove();
  recalculateInvoiceTotals();
}

function calculateItemRowTotal(input) {
  const tr = input.closest("tr");
  const qty = parseFloat(tr.querySelector(".item-qty").value) || 0;
  const price = parseFloat(tr.querySelector(".item-price").value) || 0;
  const total = qty * price;
  
  tr.querySelector(".item-total-val").textContent = formatCurrency(total);
  tr.querySelector(".item-total-val").dataset.value = total;
  
  recalculateInvoiceTotals();
}

function recalculateInvoiceTotals() {
  let subtotal = 0;
  document.querySelectorAll("#invoiceItemsBody tr").forEach(tr => {
    const qty = parseFloat(tr.querySelector(".item-qty").value) || 0;
    const price = parseFloat(tr.querySelector(".item-price").value) || 0;
    subtotal += qty * price;
  });

  const ivaPct = parseFloat(document.getElementById("invoiceIvaPct").value) || 0;
  const discount = parseFloat(document.getElementById("invoiceDiscount").value) || 0;

  const ivaVal = subtotal * (ivaPct / 100);
  const total = subtotal + ivaVal - discount;

  document.getElementById("invoiceSubtotal").textContent = formatCurrency(subtotal);
  document.getElementById("invoiceTotal").textContent = formatCurrency(total);
  
  document.getElementById("invoiceSubtotal").dataset.value = subtotal;
  document.getElementById("invoiceTotal").dataset.value = total;
}

async function saveNewInvoice(e) {
  e.preventDefault();
  const form = e.target;
  const formData = new FormData(form);

  const items = [];
  let itemsValid = true;
  document.querySelectorAll("#invoiceItemsBody tr").forEach(tr => {
    const desc = tr.querySelector(".item-desc").value.trim();
    const qty = parseFloat(tr.querySelector(".item-qty").value);
    const price = parseFloat(tr.querySelector(".item-price").value);

    if (!desc || isNaN(qty) || qty <= 0 || isNaN(price) || price < 0) {
      itemsValid = false;
      return;
    }

    items.push({
      itemdesx: desc,
      itemcant: qty,
      itemvalu: price
    });
  });

  if (!itemsValid || items.length === 0) {
    showAlert("Asegúrese de rellenar correctamente todos los conceptos", "error");
    return;
  }

  const payload = {
    factnumx: formData.get("factnumx"),
    proyidxx: formData.get("proyidxx") || null,
    factfecx: formData.get("factfecx"),
    factvenx: formData.get("factvenx"),
    clieidxx: formData.get("clieidxx"),
    clienomx: formData.get("clienomx"),
    cliemlxx: formData.get("cliemlxx"),
    cliedirx: formData.get("cliedirx"),
    cliecell: formData.get("cliecell"),
    factsubt: parseFloat(document.getElementById("invoiceSubtotal").dataset.value || 0),
    factivax: parseFloat(formData.get("factivax") || 0),
    factdesc: parseFloat(formData.get("factdesc") || 0),
    facttota: parseFloat(document.getElementById("invoiceTotal").dataset.value || 0),
    factestx: 'BORRADOR',
    items: items
  };

  try {
    const res = await fetch("api/invoices.php", {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify(payload)
    });
    const data = await res.json();
    if (data.success) {
      showAlert("Factura guardada correctamente", "success");
      closeModal("addInvoiceModal");
      form.reset();
      loadInvoices();
    } else {
      showAlert(data.error, "error");
    }
  } catch (err) {
    console.error("Error creating invoice:", err);
    showAlert("Error de conexión", "error");
  }
}

async function changeInvoiceStatus(id, newStatus) {
  try {
    const res = await fetch("api/invoices.php", {
      method: "PUT",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify({ id: id, estado: newStatus })
    });
    const data = await res.json();
    if (data.success) {
      showAlert("Estado de factura actualizado", "success");
      loadInvoices();
    } else {
      showAlert(data.error, "error");
    }
  } catch (err) {
    console.error("Error updating invoice status:", err);
    showAlert("Error de conexión", "error");
  }
}

async function deleteInvoice(id) {
  if (!confirm("¿Está seguro de eliminar esta factura?")) return;
  try {
    const res = await fetch(`api/invoices.php?id=${id}`, { method: "DELETE" });
    const data = await res.json();
    if (data.success) {
      showAlert("Factura eliminada correctamente", "success");
      loadInvoices();
    } else {
      showAlert(data.error, "error");
    }
  } catch (err) {
    console.error("Error deleting invoice:", err);
    showAlert("Error de conexión", "error");
  }
}

let currentViewInvoice = null;

async function viewInvoice(id) {
  try {
    const res = await fetch(`api/invoices.php?id=${id}`);
    const data = await res.json();
    
    if (data.success) {
      const inv = data.data;
      currentViewInvoice = inv;
      
      document.getElementById("viewInvoiceNumber").textContent = inv.FACTNUMX;
      document.getElementById("viewClientName").textContent = inv.CLIENOMX;
      document.getElementById("viewClientId").textContent = inv.CLIEIDXX || "N/A";
      document.getElementById("viewClientDir").textContent = inv.CLIEDIRX || "N/A";
      document.getElementById("viewClientCell").textContent = inv.CLIECELL || "N/A";
      document.getElementById("viewClientMail").textContent = inv.CLIEMLXX || "N/A";
      
      document.getElementById("viewInvoiceDate").textContent = formatDate(inv.FACTFECX);
      document.getElementById("viewInvoiceDueDate").textContent = formatDate(inv.FACTVENX);
      document.getElementById("viewInvoiceProject").textContent = inv.PROYNOMX || "Ninguno";
      
      const stateEl = document.getElementById("viewInvoiceState");
      stateEl.textContent = inv.FACTESTX;
      stateEl.className = "invoice-state-badge " + inv.FACTESTX.toLowerCase();
      
      const itemsBody = document.getElementById("viewInvoiceItemsBody");
      itemsBody.innerHTML = "";
      
      data.items.forEach(item => {
        const qty = parseFloat(item.ITEMCANT);
        const val = parseFloat(item.ITEMVALU);
        const tot = parseFloat(item.ITEMTOTA);
        
        const tr = document.createElement("tr");
        tr.innerHTML = `
          <td>${escapeHtml(item.ITEMDESX)}</td>
          <td class="text-right">${qty}</td>
          <td class="text-right">${formatCurrency(val)}</td>
          <td class="text-right">${formatCurrency(tot)}</td>
        `;
        itemsBody.appendChild(tr);
      });
      
      const subtotal = parseFloat(inv.FACTSUBT);
      const ivaVal = subtotal * (parseFloat(inv.FACTIVAX) / 100);
      const discount = parseFloat(inv.FACTDESC);
      
      document.getElementById("viewInvoiceSubtotal").textContent = formatCurrency(subtotal);
      document.getElementById("viewInvoiceIva").textContent = `${formatCurrency(ivaVal)} (${parseFloat(inv.FACTIVAX)}%)`;
      document.getElementById("viewInvoiceDiscount").textContent = formatCurrency(discount);
      document.getElementById("viewInvoiceTotal").textContent = formatCurrency(parseFloat(inv.FACTTOTA));
      
      openModal("viewInvoiceModal");
    } else {
      showAlert(data.error, "error");
    }
  } catch (err) {
    console.error("Error viewing invoice:", err);
    showAlert("Error al cargar la factura", "error");
  }
}

async function downloadInvoicePDF() {
  if (!currentViewInvoice) return;
  const element = document.getElementById('invoicePrintArea');
  
  // Wrap in a full-bleed black container with print margins to avoid white borders
  const wrapper = document.createElement('div');
  wrapper.style.background = '#020304';
  wrapper.style.minHeight = '279.4mm'; // Height of letter page
  wrapper.style.boxSizing = 'border-box';
  wrapper.style.padding = '15mm';
  
  // Inject style to make the virtual body background dark and remove margins/padding completely
  const styleEl = document.createElement('style');
  styleEl.textContent = 'html, body { background-color: #020304 !important; margin: 0 !important; padding: 0 !important; }';
  wrapper.appendChild(styleEl);
  
  const clone = element.cloneNode(true);
  
  // Hide invoice status from the PDF output
  const stateEl = clone.querySelector('#viewInvoiceState');
  if (stateEl) {
    const parentP = stateEl.parentElement;
    if (parentP) parentP.remove();
  }
  
  clone.style.margin = '0';
  clone.style.padding = '0';
  clone.style.border = 'none';
  clone.style.boxShadow = 'none';
  clone.style.background = 'transparent';
  clone.style.width = '100%';
  
  wrapper.appendChild(clone);

  const opt = {
    margin:       0, // Zero margin to prevent html2pdf white borders
    filename:     `Factura_${currentViewInvoice.FACTNUMX}.pdf`,
    image:        { type: 'jpeg', quality: 0.98 },
    html2canvas:  { scale: 2, useCORS: true },
    jsPDF:        { unit: 'mm', format: 'letter', orientation: 'portrait' }
  };
  try {
    const html2pdf = await window.App.lib.html2pdf();
    html2pdf().set(opt).from(wrapper).save();
  } catch (err) {
    showAlert(err.message, 'error');
  }
}

async function exportCronogramaPDF(isClientOnly = false) {
  const projectId = document.getElementById("cronFilterProject").value;
  const projectText = document.getElementById("cronFilterProject").options[document.getElementById("cronFilterProject").selectedIndex].text;
  
  const container = document.createElement('div');
  container.className = 'invoice-print-container';
  container.style.padding = '15mm';
  container.style.color = '#fff';
  container.style.background = '#020304';
  container.style.fontFamily = 'Geist, sans-serif';
  container.style.boxSizing = 'border-box';
  container.style.border = 'none';
  container.style.boxShadow = 'none';
  container.style.maxWidth = 'none';
  container.style.width = 'calc(100% + 4px)';
  container.style.margin = '0';
  container.style.marginLeft = '-4px';
  container.style.borderRadius = '0';
  
  const orientation = isClientOnly ? 'landscape' : 'portrait';
  container.style.minHeight = isClientOnly ? '215.9mm' : '279.4mm';
  
  container.innerHTML = `
    <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:25px; border-bottom:2px solid #32B2CF; padding-bottom:15px;">
      <div>
        <h1 style="font-family:'Orbitron', sans-serif; font-size:1.6rem; color:#32B2CF; margin:0; letter-spacing:1px;">BeaTaN Code</h1>
        <p style="margin:5px 0 0 0; font-size:0.85rem; color:#aaa;">${isClientOnly ? 'Cronograma de Trabajo del Proyecto' : 'Reporte de Cronograma y Control de Horas'}</p>
      </div>
      <div style="text-align:right;">
        <p style="margin:0; font-size:0.85rem; color:#aaa;"><strong>Fecha Reporte:</strong> ${new Date().toLocaleDateString('es-CO')}</p>
        ${!isClientOnly ? `<p style="margin:5px 0 0 0; font-size:0.85rem; color:#aaa;"><strong>Total Horas:</strong> ${document.getElementById('cronTotalHours').textContent} hrs</p>` : ''}
      </div>
    </div>
    
    <div style="margin-bottom:25px; font-size:0.9rem; background:rgba(255,255,255,0.02); border:1px solid rgba(255,255,255,0.08); border-radius:6px; padding:15px;">
      <p style="margin:3px 0; color:#32B2CF; font-family:'Orbitron', sans-serif; font-size:0.85rem; letter-spacing:0.5px;">INFORMACIÓN DEL PROYECTO</p>
      <p style="margin:5px 0 0 0; color:#eee; font-size:0.85rem; line-height:1.6;">
        <strong>Proyecto:</strong> ${escapeHtml(projectText)}<br>
        ${!isClientOnly ? `<strong>Colaborador:</strong> ${escapeHtml(document.getElementById('cronFilterUser').options[document.getElementById('cronFilterUser').selectedIndex].text)}` : ''}
      </p>
    </div>
  `;

  const styleEl = document.createElement('style');
  styleEl.textContent = `
    html, body { background-color: #020304 !important; margin: 0 !important; padding: 0 !important; }
    .pdf-title { font-family:'Orbitron', sans-serif; font-size:1.1rem; color:#32B2CF; margin:20px 0 10px 0; border-bottom:1px solid rgba(255,255,255,0.1); padding-bottom:5px; text-transform: uppercase; }
    .pdf-table { width:100%; border-collapse:collapse; margin-top:5px; font-size:0.8rem; color:#fff !important; margin-bottom:20px; }
    .pdf-table th, .pdf-table td { border:1px solid rgba(255,255,255,0.1) !important; padding:8px 10px; text-align:left; color:#fff !important; }
    .pdf-table th { background:#161c26 !important; font-weight:bold; color:#32B2CF !important; }
    .pdf-table tr:nth-child(even) { background:rgba(255,255,255,0.02) !important; }
    .pdf-table .badge { background:rgba(50,178,207,0.15) !important; border:1px solid rgba(50,178,207,0.3) !important; padding:2px 6px; border-radius:4px; color:#fff !important; }
    .pdf-year-header { text-align:center !important; background:rgba(50,178,207,0.15) !important; }
    .pdf-month-header { text-align:center !important; font-size:0.7rem !important; }
    .pdf-cell-center { text-align:center !important; min-width:25px; }
    .pdf-bar-active { background:rgba(34,197,94,0.15) !important; }
    .pdf-green-bar { height:12px; background:linear-gradient(90deg, #22c55e, #15803d) !important; border-radius:3px; }
    .pdf-diamond { color:#3b82f6 !important; font-size:1rem; text-align:center; }
    .gantt-hito-badge { background:rgba(59,130,246,0.15) !important; border:1px solid rgba(59,130,246,0.3) !important; padding:2px 6px; border-radius:4px; color:#60a5fa !important; font-size:0.65rem; font-weight:bold; display:inline-block; }
  `;
  container.appendChild(styleEl);

  const ganttTable = document.getElementById('ganttChartContainer').querySelector('table');
  if (projectId && ganttTable) {
    const ganttTitle = document.createElement('h3');
    ganttTitle.className = 'pdf-title';
    ganttTitle.textContent = 'Cronograma de Fases (Gantt)';
    container.appendChild(ganttTitle);

    const ganttClone = ganttTable.cloneNode(true);
    ganttClone.querySelectorAll('tr').forEach(tr => {
      const lastCell = tr.cells[tr.cells.length - 1];
      if (lastCell) lastCell.remove();
    });
    ganttClone.className = 'pdf-table';
    
    ganttClone.querySelectorAll('.gantt-year-header').forEach(el => el.className = 'pdf-year-header');
    ganttClone.querySelectorAll('.gantt-month-header').forEach(el => el.className = 'pdf-month-header');
    ganttClone.querySelectorAll('.gantt-cell').forEach(el => el.className += ' pdf-cell-center');
    ganttClone.querySelectorAll('.gantt-bar-cell').forEach(el => el.className += ' pdf-bar-active');
    ganttClone.querySelectorAll('.gantt-green-bar').forEach(el => el.className = 'pdf-green-bar');
    ganttClone.querySelectorAll('.gantt-diamond').forEach(el => {
      el.className = 'pdf-diamond';
      el.textContent = '♦';
    });

    container.appendChild(ganttClone);
  }

  if (!isClientOnly) {
    const hoursTable = document.getElementById('hoursTableContainer').querySelector('table');
    if (hoursTable) {
      const hoursTitle = document.createElement('h3');
      hoursTitle.className = 'pdf-title';
      hoursTitle.textContent = 'Desglose de Horas Registradas';
      container.appendChild(hoursTitle);

      const hoursClone = hoursTable.cloneNode(true);
      hoursClone.querySelectorAll('tr').forEach(tr => {
        const lastCell = tr.cells[tr.cells.length - 1];
        if (lastCell) lastCell.remove();
      });
      hoursClone.className = 'pdf-table';
      container.appendChild(hoursClone);
    }
  }

  const filenamePrefix = isClientOnly ? 'Cronograma_Cliente' : 'Reporte_Completo';
  const opt = {
    margin:       0,
    filename:     `${filenamePrefix}_${projectText.replace(/\s+/g, '_')}_${localISODate()}.pdf`,
    image:        { type: 'jpeg', quality: 0.98 },
    html2canvas:  { scale: 2, useCORS: true },
    jsPDF:        { unit: 'mm', format: 'letter', orientation: orientation }
  };
  
  try {
    const html2pdf = await window.App.lib.html2pdf();
    html2pdf().set(opt).from(container).save();
  } catch (err) {
    showAlert(err.message, 'error');
  }
}

// Gantt and Phase functions
function onProjectChange() {
  const projectId = document.getElementById("cronFilterProject").value;
  const btnNewPhase = document.getElementById("btnNewPhase");
  const ganttCard = document.getElementById("ganttCard");
  
  if (projectId) {
    if (btnNewPhase) btnNewPhase.style.display = "inline-flex";
    if (ganttCard) ganttCard.style.display = "block";
    loadGanttChart(parseInt(projectId));
  } else {
    if (btnNewPhase) btnNewPhase.style.display = "none";
    if (ganttCard) ganttCard.style.display = "none";
  }
  loadHoursLogs();
}

function openPhaseModal() {
  const projectId = document.getElementById("cronFilterProject").value;
  if (!projectId) {
    showAlert("Por favor seleccione un proyecto primero", "warning");
    return;
  }
  const form = document.getElementById("addPhaseForm");
  if (form) form.reset();
  document.getElementById("addPhaseProjId").value = projectId;
  document.getElementById("addPhaseHitoHidden").value = "NO";
  document.getElementById("addPhaseStart").value = localISODate();
  document.getElementById("addPhaseEnd").value = localISODate();
  openModal("addPhaseModal");
}

function toggleHitoCheckbox(checkbox) {
  document.getElementById("addPhaseHitoHidden").value = checkbox.checked ? "SI" : "NO";
}

async function saveNewPhase(e) {
  e.preventDefault();
  const form = e.target;
  const formData = new FormData(form);
  const payload = {
    proyidxx: parseInt(formData.get("proyidxx")),
    fasenomx: formData.get("fasenomx"),
    fasefeci: formData.get("fasefeci"),
    fasefect: formData.get("fasefect"),
    fasehito: formData.get("fasehito")
  };

  try {
    const res = await fetch("api/phases.php", {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify(payload)
    });
    const data = await res.json();
    if (data.success) {
      showAlert("Fase creada exitosamente", "success");
      closeModal("addPhaseModal");
      form.reset();
      loadGanttChart(payload.proyidxx);
    } else {
      showAlert(data.error, "error");
    }
  } catch (err) {
    console.error("Error creating phase:", err);
    showAlert("Error de conexión", "error");
  }
}

async function deletePhase(phaseId, projectId) {
  if (!confirm("¿Está seguro de eliminar esta fase?")) return;
  try {
    const res = await fetch(`api/phases.php?id=${phaseId}`, { method: "DELETE" });
    const data = await res.json();
    if (data.success) {
      showAlert("Fase eliminada correctamente", "success");
      loadGanttChart(projectId);
    } else {
      showAlert(data.error, "error");
    }
  } catch (err) {
    console.error("Error deleting phase:", err);
    showAlert("Error de conexión", "error");
  }
}

async function loadGanttChart(projectId) {
  const container = document.getElementById("ganttChartContainer");
  if (!container) return;

  container.innerHTML = '<div class="loading"><div class="spinner"></div></div>';

  try {
    const res = await fetch(`api/phases.php?proyidxx=${projectId}`);
    const data = await res.json();

    if (data.success) {
      const phases = data.data;

      if (phases.length === 0) {
        container.innerHTML = `
          <div style="text-align: center; padding: 25px; color: var(--muted-white);">
            <i class="bi bi-calendar-x" style="font-size: 2rem; color: var(--primary-cyan); display: block; margin-bottom: 10px;"></i>
            <p>No hay fases o tareas registradas en este proyecto.</p>
            <p style="font-size: 0.85rem; margin-top: 5px; color: var(--primary-cyan); cursor: pointer;" onclick="openPhaseModal()"><strong>Haga clic aquí para registrar la primera fase</strong></p>
          </div>
        `;
        return;
      }

      let minDateStr = "";
      let maxDateStr = "";

      phases.forEach(p => {
        if (!minDateStr || p.FASEFECI < minDateStr) minDateStr = p.FASEFECI;
        if (!maxDateStr || p.FASEFECT > maxDateStr) maxDateStr = p.FASEFECT;
      });

      const minDate = new Date(minDateStr + "T12:00:00");
      const maxDate = new Date(maxDateStr + "T12:00:00");

      let startYear = minDate.getFullYear();
      let startMonth = minDate.getMonth();
      let endYear = maxDate.getFullYear();
      let endMonth = maxDate.getMonth();

      // Calculate span of days
      const diffTime = Math.abs(maxDate - minDate);
      const diffDays = Math.ceil(diffTime / (1000 * 60 * 60 * 24)) + 1;
      const isDaily = diffDays <= 45; // 45 days or less: show daily. Otherwise: monthly.

      const getYYYYMMDD = (d) => {
        const y = d.getFullYear();
        const m = String(d.getMonth() + 1).padStart(2, '0');
        const dy = String(d.getDate()).padStart(2, '0');
        return `${y}-${m}-${dy}`;
      };

      const realMonthNames = ["Ene", "Feb", "Mar", "Abr", "May", "Jun", "Jul", "Ago", "Sep", "Oct", "Nov", "Dic"];

      let html = `
        <table class="gantt-table">
          <thead>
            <tr>
              <th rowspan="2" style="width: 70px; text-align:center;"></th>
              <th rowspan="2" style="min-width: 180px;">Fase</th>
              <th rowspan="2" style="width: 110px; text-align:center;">Fecha Inicio</th>
              <th rowspan="2" style="width: 110px; text-align:center;">Fecha Término</th>
      `;

      const getEmptyCells = (count) => {
        let cells = "";
        for (let i = 0; i < count; i++) {
          cells += '<td class="gantt-cell"></td>';
        }
        return cells;
      };

      let days = [];
      let months = [];

      if (isDaily) {
        // Daily scale timeline
        let curr = new Date(minDate);
        while (curr <= maxDate) {
          days.push(new Date(curr));
          curr.setDate(curr.getDate() + 1);
        }

        const groups = [];
        let currentLabel = "";
        let currentSpan = 0;
        days.forEach(d => {
          const monthStr = realMonthNames[d.getMonth()];
          const label = `${monthStr} ${d.getFullYear()}`;
          if (label !== currentLabel) {
            if (currentSpan > 0) {
              groups.push({ label: currentLabel, span: currentSpan });
            }
            currentLabel = label;
            currentSpan = 1;
          } else {
            currentSpan++;
          }
        });
        if (currentSpan > 0) {
          groups.push({ label: currentLabel, span: currentSpan });
        }

        groups.forEach(g => {
          html += `<th colspan="${g.span}" class="gantt-year-header" style="text-transform: capitalize;">${g.label}</th>`;
        });

        html += `
              <th rowspan="2" style="width: 50px;"></th>
            </tr>
            <tr>
        `;

        days.forEach(d => {
          const dayNum = String(d.getDate()).padStart(2, '0');
          html += `<th class="gantt-month-header" style="min-width: 25px; padding: 4px !important; font-size: 0.7rem;">${dayNum}</th>`;
        });

      } else {
        // Monthly scale timeline
        let currYear = startYear;
        let currMonth = startMonth;

        while (currYear < endYear || (currYear === endYear && currMonth <= endMonth)) {
          months.push({ year: currYear, month: currMonth });
          currMonth++;
          if (currMonth > 11) {
            currMonth = 0;
            currYear++;
          }
        }

        const yearsGroup = {};
        months.forEach(m => {
          yearsGroup[m.year] = (yearsGroup[m.year] || 0) + 1;
        });

        for (const year in yearsGroup) {
          html += `<th colspan="${yearsGroup[year]}" class="gantt-year-header">${year}</th>`;
        }

        html += `
              <th rowspan="2" style="width: 50px;"></th>
            </tr>
            <tr>
        `;

        months.forEach(m => {
          html += `<th class="gantt-month-header">${realMonthNames[m.month]}</th>`;
        });
      }

      html += `
            </tr>
          </thead>
          <tbody>
      `;

      phases.forEach(p => {
        const isHito = p.FASEHITO === "SI";
        const startYM = p.FASEFECI.substring(0, 7);
        const endYM = p.FASEFECT.substring(0, 7);

        html += `
          <tr>
            <td style="text-align:center; padding: 5px;">
              ${isHito ? '<span class="gantt-hito-badge">HITO</span>' : ''}
            </td>
            <td class="gantt-task-name">
              <strong>${escapeHtml(p.FASENOMX)}</strong>
            </td>
        `;

        if (isDaily) {
          let startIdx = -1;
          let endIdx = -1;

          days.forEach((d, idx) => {
            const dStr = getYYYYMMDD(d);
            if (dStr === p.FASEFECI) startIdx = idx;
            if (dStr === p.FASEFECT) endIdx = idx;
          });

          if (startIdx === -1) startIdx = 0;
          if (endIdx === -1) endIdx = days.length - 1;
          if (endIdx < startIdx) endIdx = startIdx;

          if (isHito) {
            html += `
              <td colspan="2" class="gantt-date-cell" style="text-align:center;">${formatGanttDate(p.FASEFECI)}</td>
            `;
            html += getEmptyCells(startIdx);
            html += `<td class="gantt-cell gantt-hito-cell"><i class="bi bi-diamond-fill gantt-diamond" title="Hito: ${escapeHtml(p.FASENOMX)} en ${days[startIdx].toLocaleDateString('es-CO')}"></i></td>`;
            html += getEmptyCells(days.length - startIdx - 1);
          } else {
            html += `
              <td class="gantt-date-cell" style="text-align:center;">${formatGanttDate(p.FASEFECI)}</td>
              <td class="gantt-date-cell" style="text-align:center;">${formatGanttDate(p.FASEFECT)}</td>
            `;
            html += getEmptyCells(startIdx);
            const span = endIdx - startIdx + 1;
            html += `<td colspan="${span}" class="gantt-cell gantt-bar-cell"><div class="gantt-green-bar" title="${escapeHtml(p.FASENOMX)}: ${formatGanttDate(p.FASEFECI)} a ${formatGanttDate(p.FASEFECT)}"></div></td>`;
            html += getEmptyCells(days.length - endIdx - 1);
          }

        } else {
          let startIdx = -1;
          let endIdx = -1;

          months.forEach((m, idx) => {
            const mStr = `${m.year}-${String(m.month + 1).padStart(2, '0')}`;
            if (mStr === startYM) startIdx = idx;
            if (mStr === endYM) endIdx = idx;
          });

          if (startIdx === -1) {
            months.forEach((m, idx) => {
              const mStr = `${m.year}-${String(m.month + 1).padStart(2, '0')}`;
              if (startYM <= mStr && (startIdx === -1 || mStr < `${months[startIdx].year}-${String(months[startIdx].month + 1).padStart(2, '0')}`)) {
                startIdx = idx;
              }
            });
            if (startIdx === -1) startIdx = 0;
          }
          if (endIdx === -1) {
            months.forEach((m, idx) => {
              const mStr = `${m.year}-${String(m.month + 1).padStart(2, '0')}`;
              if (endYM >= mStr) {
                endIdx = idx;
              }
            });
            if (endIdx === -1) endIdx = months.length - 1;
          }
          if (endIdx < startIdx) endIdx = startIdx;

          if (isHito) {
            html += `
              <td colspan="2" class="gantt-date-cell" style="text-align:center;">${formatGanttDate(p.FASEFECI)}</td>
            `;
            html += getEmptyCells(startIdx);
            html += `<td class="gantt-cell gantt-hito-cell"><i class="bi bi-diamond-fill gantt-diamond" title="Hito: ${escapeHtml(p.FASENOMX)} en ${realMonthNames[months[startIdx].month]} ${months[startIdx].year}"></i></td>`;
            html += getEmptyCells(months.length - startIdx - 1);
          } else {
            html += `
              <td class="gantt-date-cell" style="text-align:center;">${formatGanttDate(p.FASEFECI)}</td>
              <td class="gantt-date-cell" style="text-align:center;">${formatGanttDate(p.FASEFECT)}</td>
            `;
            html += getEmptyCells(startIdx);
            const span = endIdx - startIdx + 1;
            html += `<td colspan="${span}" class="gantt-cell gantt-bar-cell"><div class="gantt-green-bar" title="${escapeHtml(p.FASENOMX)}: ${formatGanttDate(p.FASEFECI)} a ${formatGanttDate(p.FASEFECT)}"></div></td>`;
            html += getEmptyCells(months.length - endIdx - 1);
          }
        }

        html += `
            <td style="text-align: center;">
              <button class="btn-icon" onclick="deletePhase(${p.FASEIDXX}, ${projectId})" title="Eliminar Fase" style="color: var(--error-color); padding: 2px 5px;">
                <i class="bi bi-trash"></i>
              </button>
            </td>
          </tr>
        `;
      });

      html += "</tbody></table>";
      container.innerHTML = html;
    } else {
      container.innerHTML = `<div class="alert alert-error">${escapeHtml(data.error)}</div>`;
    }
  } catch (err) {
    console.error("Error loading Gantt chart:", err);
    container.innerHTML = '<div class="alert alert-error">Error al conectar con el servidor</div>';
  }
}

// Formato de celular colombiano (+57 300 1234567) en los formularios de usuarios
document.addEventListener("DOMContentLoaded", () => {
  ["editUserCelular", "addUserCelular"].forEach((id) => {
    const input = document.getElementById(id);
    if (!input) return;
    input.setAttribute("inputmode", "tel");
    input.addEventListener("input", () => {
      let value = input.value.replace(/\D/g, "");
      if (value.startsWith("57")) value = value.substring(2);
      let formatted = "+57 ";
      if (value.length > 0) formatted += value.substring(0, 3);
      if (value.length >= 4) formatted += " " + value.substring(3, 10);
      input.value = formatted;
    });
  });
});

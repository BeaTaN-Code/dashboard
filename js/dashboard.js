// dashboard/js/dashboard.js
// JavaScript para el Dashboard de BeaTaNCode

// ===============================================
// VARIABLES GLOBALES
// ===============================================
let currentTab = 'financiero-personal';
let beatanCalendarDate = new Date();
let personalCalendarDate = new Date();
let holidaysCache = {};

// ===============================================
// INICIALIZACIÓN
// ===============================================
document.addEventListener('DOMContentLoaded', function() {
    initTabs();
    initSidebar();
    initCalendars();
    
    // Cargar datos iniciales de las pestañas financieras
    loadTransactions('BEATAN');
    loadTransactions('PERSONAL');
});

// ===============================================
// NAVEGACIÓN POR PESTAÑAS
// ===============================================
function initTabs() {
    const navItems = document.querySelectorAll('.nav-item[data-tab]');
    
    navItems.forEach(item => {
        item.addEventListener('click', function(e) {
            e.preventDefault();
            const tabId = this.getAttribute('data-tab');
            switchTab(tabId);
        });
    });
}

function switchTab(tabId) {
    // Actualizar navegación activa
    document.querySelectorAll('.nav-item').forEach(item => {
        item.classList.remove('active');
    });
    document.querySelector(`.nav-item[data-tab="${tabId}"]`).classList.add('active');
    
    // Mostrar contenido de la pestaña
    document.querySelectorAll('.tab-content').forEach(content => {
        content.classList.remove('active');
    });
    document.getElementById(`tab-${tabId}`).classList.add('active');
    
    // Actualizar título de la página
    const titles = {
        'dashboard': { title: 'Dashboard', icon: 'bi-grid-1x2' },
        'financiero-beatan': { title: 'Financiero (BeaTaN)', icon: 'bi-building' },
        'financiero-personal': { title: 'Financiero (Personal)', icon: 'bi-person-circle' }
    };
    
    document.getElementById('pageTitle').textContent = titles[tabId].title;
    document.getElementById('pageIcon').className = 'bi ' + titles[tabId].icon;
    
    currentTab = tabId;
    
    // Cerrar sidebar en móvil
    closeSidebar();
}

// ===============================================
// SIDEBAR MÓVIL
// ===============================================
function initSidebar() {
    const mobileToggle = document.getElementById('mobileToggle');
    const sidebar = document.getElementById('sidebar');
    const overlay = document.getElementById('sidebarOverlay');
    
    mobileToggle.addEventListener('click', function() {
        sidebar.classList.toggle('active');
        overlay.classList.toggle('active');
    });
    
    overlay.addEventListener('click', closeSidebar);
}

function closeSidebar() {
    document.getElementById('sidebar').classList.remove('active');
    document.getElementById('sidebarOverlay').classList.remove('active');
}

// ===============================================
// FILTRO DE TABLA DE FORMULARIO
// ===============================================
function filterFormTable() {
    const filter = document.getElementById('filterEstado').value;
    const rows = document.querySelectorAll('#formTable tbody tr');
    
    rows.forEach(row => {
        const estado = row.getAttribute('data-estado');
        if (!filter || estado === filter) {
            row.style.display = '';
        } else {
            row.style.display = 'none';
        }
    });
}

// ===============================================
// GESTIÓN DE MENSAJES
// ===============================================
async function viewMessage(id) {
    try {
        const response = await fetch(`api/messages.php?id=${id}`);
        const data = await response.json();
        
        if (data.success) {
            const msg = data.data;
            const modalBody = document.getElementById('messageModalBody');
            
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
                        <strong style="color: var(--primary-cyan);">Teléfono:</strong>
                        <p style="margin-top: 5px;">${escapeHtml(msg.INDICATIVO || '')} ${escapeHtml(msg.TELEFONO || 'No proporcionado')}</p>
                    </div>
                    <div>
                        <strong style="color: var(--primary-cyan);">Empresa:</strong>
                        <p style="margin-top: 5px;">${escapeHtml(msg.NOMEMP || 'No proporcionado')} (${escapeHtml(msg.DESPEMP || '')})</p>
                    </div>
                    <div>
                        <strong style="color: var(--primary-cyan);">Asunto:</strong>
                        <p style="margin-top: 5px;">${escapeHtml(msg.ASUNTO || 'Sin asunto')}</p>
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
            
            openModal('messageModal');
        } else {
            showAlert('Error al cargar el mensaje', 'error');
        }
    } catch (error) {
        console.error('Error:', error);
        showAlert('Error de conexión', 'error');
    }
}

function updateMessageStatus(id) {
    document.getElementById('statusMessageId').value = id;
    openModal('statusModal');
}

async function saveMessageStatus(e) {
    e.preventDefault();
    
    const id = document.getElementById('statusMessageId').value;
    const estado = document.getElementById('statusSelect').value;
    
    try {
        const response = await fetch('api/messages.php', {
            method: 'PUT',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ id, estado })
        });
        
        const data = await response.json();
        
        if (data.success) {
            showAlert('Estado actualizado correctamente', 'success');
            closeModal('statusModal');
            // Recargar la página para actualizar la tabla
            setTimeout(() => location.reload(), 1000);
        } else {
            showAlert(data.error || 'Error al actualizar', 'error');
        }
    } catch (error) {
        console.error('Error:', error);
        showAlert('Error de conexión', 'error');
    }
}

// ===============================================
// GESTIÓN DE TRANSACCIONES
// ===============================================
async function loadTransactions(tipgasxx) {
    const prefix = tipgasxx.toLowerCase();
    const tableContainer = document.getElementById(`${prefix === 'beatan' ? 'beatan' : 'personal'}TransactionsTable`);
    const filterTipo = document.getElementById(`${prefix === 'beatan' ? 'beatan' : 'personal'}FilterTipo`).value;
    const filterMonth = document.getElementById(`${prefix === 'beatan' ? 'beatan' : 'personal'}FilterMonth`).value;
    
    tableContainer.innerHTML = '<div class="loading"><div class="spinner"></div></div>';
    
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
                    <p>${data.error || 'No se pudieron cargar las transacciones'}</p>
                </div>
            `;
        }
    } catch (error) {
        console.error('Error:', error);
        tableContainer.innerHTML = `
            <div class="empty-state">
                <i class="bi bi-wifi-off"></i>
                <h3>Error de conexión</h3>
                <p>No se pudo conectar con el servidor</p>
            </div>
        `;
    }
}

function updateBalances(tipgasxx, balance) {
    const prefix = tipgasxx === 'BEATAN' ? 'beatan' : 'personal';
    
    document.getElementById(`${prefix}-balance`).textContent = formatCurrency(balance.total);
    document.getElementById(`${prefix}-balance`).className = `amount ${balance.total >= 0 ? 'positive' : 'negative'}`;
    
    document.getElementById(`${prefix}-income`).textContent = formatCurrency(balance.income);
    document.getElementById(`${prefix}-expense`).textContent = formatCurrency(balance.expense);
}

function renderTransactionsTable(tipgasxx, transactions) {
    const prefix = tipgasxx === 'BEATAN' ? 'beatan' : 'personal';
    const container = document.getElementById(`${prefix}TransactionsTable`);
    
    if (!transactions || transactions.length === 0) {
        container.innerHTML = `
            <div class="empty-state">
                <i class="bi bi-inbox"></i>
                <h3>Sin movimientos</h3>
                <p>No hay transacciones registradas para este período</p>
            </div>
        `;
        return;
    }
    
    const today = new Date().toISOString().split('T')[0];
    
    let html = `
        <table class="data-table">
            <thead>
                <tr>
                    <th>Fecha</th>
                    <th>Categoría</th>
                    <th>Descripción</th>
                    <th>Monto</th>
                    <th>Acciones</th>
                </tr>
            </thead>
            <tbody>
    `;
    
    transactions.forEach(t => {
        const monto = parseFloat(t.MONTGASX);
        const isIncome = monto > 0;
        const isFuture = t.FINCFECX > today;
        
        html += `
            <tr style="${isFuture ? 'opacity: 0.5;' : ''}">
                <td>
                    ${formatDate(t.FINCFECX)}
                    ${isFuture ? '<br><small style="color: var(--warning-color);">(Futuro)</small>' : ''}
                </td>
                <td>
                    <span class="badge ${isIncome ? 'badge-income' : 'badge-expense'}">
                        ${escapeHtml(t.CATGASXX)}
                    </span>
                </td>
                <td>${escapeHtml(t.DESGASXX || '-')}</td>
                <td>
                    <span class="amount ${isIncome ? 'positive' : 'negative'}">
                        ${isIncome ? '+' : ''}${formatCurrency(monto)}
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
    
    html += '</tbody></table>';
    container.innerHTML = html;
}

async function addTransaction(e, tipgasxx) {
    e.preventDefault();
    
    const form = e.target;
    const formData = new FormData(form);
    
    const data = {
        tipgasxx: tipgasxx,
        tipo: formData.get('tipo'),
        monto: parseFloat(formData.get('monto')),
        categoria: formData.get('categoria'),
        descripcion: formData.get('descripcion'),
        fecha: formData.get('fecha')
    };
    
    try {
        const response = await fetch('api/transactions.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(data)
        });
        
        const result = await response.json();
        
        if (result.success) {
            showAlert('Transacción agregada exitosamente', 'success');
            form.reset();
            form.querySelector('[name="fecha"]').value = new Date().toISOString().split('T')[0];
            loadTransactions(tipgasxx);
        } else {
            showAlert(result.error || 'Error al agregar transacción', 'error');
        }
    } catch (error) {
        console.error('Error:', error);
        showAlert('Error de conexión', 'error');
    }
}

async function editTransaction(id, tipgasxx) {
    try {
        const response = await fetch(`api/transactions.php?tipgasxx=${tipgasxx}`);
        const data = await response.json();
        
        if (data.success) {
            const transaction = data.data.find(t => t.FINCTIDX == id);
            
            if (transaction) {
                const monto = parseFloat(transaction.MONTGASX);
                
                document.getElementById('editTransactionId').value = id;
                document.getElementById('editTransactionTipgasxx').value = tipgasxx;
                document.getElementById('editTransactionTipo').value = monto >= 0 ? 'INGRESO' : 'GASTO';
                document.getElementById('editTransactionMonto').value = Math.abs(monto);
                document.getElementById('editTransactionCategoria').value = transaction.CATGASXX;
                document.getElementById('editTransactionFecha').value = transaction.FINCFECX;
                document.getElementById('editTransactionDescripcion').value = transaction.DESGASXX || '';
                
                openModal('editTransactionModal');
            }
        }
    } catch (error) {
        console.error('Error:', error);
        showAlert('Error al cargar la transacción', 'error');
    }
}

async function updateTransaction(e) {
    e.preventDefault();
    
    const data = {
        id: document.getElementById('editTransactionId').value,
        tipo: document.getElementById('editTransactionTipo').value,
        monto: parseFloat(document.getElementById('editTransactionMonto').value),
        categoria: document.getElementById('editTransactionCategoria').value,
        fecha: document.getElementById('editTransactionFecha').value,
        descripcion: document.getElementById('editTransactionDescripcion').value
    };
    
    const tipgasxx = document.getElementById('editTransactionTipgasxx').value;
    
    try {
        const response = await fetch('api/transactions.php', {
            method: 'PUT',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(data)
        });
        
        const result = await response.json();
        
        if (result.success) {
            showAlert('Transacción actualizada exitosamente', 'success');
            closeModal('editTransactionModal');
            loadTransactions(tipgasxx);
        } else {
            showAlert(result.error || 'Error al actualizar', 'error');
        }
    } catch (error) {
        console.error('Error:', error);
        showAlert('Error de conexión', 'error');
    }
}

async function deleteTransaction(id, tipgasxx) {
    if (!confirm('¿Está seguro de eliminar esta transacción?')) {
        return;
    }
    
    try {
        const response = await fetch(`api/transactions.php?id=${id}`, {
            method: 'DELETE'
        });
        
        const result = await response.json();
        
        if (result.success) {
            showAlert('Transacción eliminada exitosamente', 'success');
            loadTransactions(tipgasxx);
        } else {
            showAlert(result.error || 'Error al eliminar', 'error');
        }
    } catch (error) {
        console.error('Error:', error);
        showAlert('Error de conexión', 'error');
    }
}

// ===============================================
// CALENDARIO
// ===============================================
function initCalendars() {
    renderCalendar('beatan', beatanCalendarDate);
    renderCalendar('personal', personalCalendarDate);
}

function changeMonth(calendarId, delta) {
    if (calendarId === 'beatan') {
        beatanCalendarDate.setMonth(beatanCalendarDate.getMonth() + delta);
        renderCalendar('beatan', beatanCalendarDate);
    } else {
        personalCalendarDate.setMonth(personalCalendarDate.getMonth() + delta);
        renderCalendar('personal', personalCalendarDate);
    }
}

async function renderCalendar(calendarId, date) {
    const container = document.getElementById(`${calendarId}-calendar`);
    const monthLabel = document.getElementById(`${calendarId}-calendar-month`);
    
    const year = date.getFullYear();
    const month = date.getMonth();
    
    // Actualizar etiqueta del mes
    const monthNames = ['Enero', 'Febrero', 'Marzo', 'Abril', 'Mayo', 'Junio',
                        'Julio', 'Agosto', 'Septiembre', 'Octubre', 'Noviembre', 'Diciembre'];
    monthLabel.textContent = `${monthNames[month]} ${year}`;
    
    // Obtener festivos
    const holidays = await getHolidays(year, month + 1);
    
    // Generar calendario
    let html = '';
    
    // Encabezados de días
    const dayNames = ['Dom', 'Lun', 'Mar', 'Mié', 'Jue', 'Vie', 'Sáb'];
    dayNames.forEach(day => {
        html += `<div class="calendar-day-header">${day}</div>`;
    });
    
    // Primer día del mes
    const firstDay = new Date(year, month, 1);
    const startingDay = firstDay.getDay();
    
    // Último día del mes
    const lastDay = new Date(year, month + 1, 0);
    const totalDays = lastDay.getDate();
    
    // Días del mes anterior
    const prevMonth = new Date(year, month, 0);
    const prevMonthDays = prevMonth.getDate();
    
    // Día actual
    const today = new Date();
    const todayStr = today.toISOString().split('T')[0];
    
    // Días del mes anterior (relleno)
    for (let i = startingDay - 1; i >= 0; i--) {
        const day = prevMonthDays - i;
        html += `<div class="calendar-day other-month">${day}</div>`;
    }
    
    // Días del mes actual
    for (let day = 1; day <= totalDays; day++) {
        const dateStr = `${year}-${String(month + 1).padStart(2, '0')}-${String(day).padStart(2, '0')}`;
        const holiday = holidays.find(h => h.fecha === dateStr);
        const isToday = dateStr === todayStr;
        
        let classes = 'calendar-day';
        if (isToday) classes += ' today';
        if (holiday) classes += ' holiday';
        
        html += `
            <div class="${classes}" onclick="selectCalendarDay('${calendarId}', '${dateStr}', ${holiday ? `'${escapeHtml(holiday.nombre)}'` : 'null'})">
                ${day}
                ${holiday ? '<span class="holiday-dot"></span>' : ''}
            </div>
        `;
    }
    
    // Días del siguiente mes (relleno)
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
        const response = await fetch(`api/holidays.php?year=${year}&month=${month}`);
        const data = await response.json();
        
        if (data.success) {
            holidaysCache[cacheKey] = data.data;
            return data.data;
        }
    } catch (error) {
        console.error('Error loading holidays:', error);
    }
    
    return [];
}

function selectCalendarDay(calendarId, dateStr, holidayName) {
    // Actualizar selección visual
    document.querySelectorAll(`#${calendarId}-calendar .calendar-day`).forEach(day => {
        day.classList.remove('selected');
    });
    event.target.closest('.calendar-day').classList.add('selected');
    
    // Mostrar info del festivo si aplica
    const holidayInfo = document.getElementById(`${calendarId}-holiday-info`);
    const holidayNameEl = document.getElementById(`${calendarId}-holiday-name`);
    const holidayDateEl = document.getElementById(`${calendarId}-holiday-date`);
    
    if (holidayName) {
        holidayNameEl.textContent = holidayName;
        holidayDateEl.textContent = formatDate(dateStr);
        holidayInfo.style.display = 'block';
    } else {
        holidayInfo.style.display = 'none';
    }
    
    // Actualizar filtro de fecha
    const filterMonth = document.getElementById(`${calendarId === 'beatan' ? 'beatan' : 'personal'}FilterMonth`);
    if (filterMonth) {
        filterMonth.value = dateStr.substring(0, 7);
        loadTransactions(calendarId === 'beatan' ? 'BEATAN' : 'PERSONAL');
    }
}

// ===============================================
// MODALES
// ===============================================
function openModal(modalId) {
    document.getElementById(modalId).classList.add('active');
    document.body.style.overflow = 'hidden';
}

function closeModal(modalId) {
    document.getElementById(modalId).classList.remove('active');
    document.body.style.overflow = '';
}

// Cerrar modal con Escape
document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') {
        document.querySelectorAll('.modal-overlay.active').forEach(modal => {
            modal.classList.remove('active');
        });
        document.body.style.overflow = '';
    }
});

// Cerrar modal al hacer clic fuera
document.querySelectorAll('.modal-overlay').forEach(overlay => {
    overlay.addEventListener('click', function(e) {
        if (e.target === this) {
            this.classList.remove('active');
            document.body.style.overflow = '';
        }
    });
});

// ===============================================
// UTILIDADES
// ===============================================
function formatCurrency(amount) {
    return new Intl.NumberFormat('es-CO', {
        style: 'currency',
        currency: 'COP',
        minimumFractionDigits: 0,
        maximumFractionDigits: 0
    }).format(amount);
}

function formatDate(dateStr) {
    if (!dateStr) return '-';
    const date = new Date(dateStr + 'T00:00:00');
    return date.toLocaleDateString('es-CO', {
        day: '2-digit',
        month: '2-digit',
        year: 'numeric'
    });
}

function formatDateTime(dateStr) {
    if (!dateStr) return '-';
    const date = new Date(dateStr);
    return date.toLocaleDateString('es-CO', {
        day: '2-digit',
        month: '2-digit',
        year: 'numeric',
        hour: '2-digit',
        minute: '2-digit'
    });
}

function escapeHtml(text) {
    if (!text) return '';
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}

function showAlert(message, type = 'info') {
    // Crear alerta temporal
    const alertDiv = document.createElement('div');
    alertDiv.className = `alert alert-${type === 'success' ? 'success' : type === 'error' ? 'error' : 'warning'}`;
    alertDiv.style.cssText = `
        position: fixed;
        top: 20px;
        right: 20px;
        z-index: 9999;
        min-width: 300px;
        animation: slideIn 0.3s ease;
    `;
    
    const icon = type === 'success' ? 'check-circle' : type === 'error' ? 'exclamation-triangle' : 'info-circle';
    alertDiv.innerHTML = `
        <i class="bi bi-${icon}"></i>
        ${escapeHtml(message)}
    `;
    
    document.body.appendChild(alertDiv);
    
    // Remover después de 3 segundos
    setTimeout(() => {
        alertDiv.style.animation = 'slideOut 0.3s ease';
        setTimeout(() => alertDiv.remove(), 300);
    }, 3000);
}

// Estilos para las animaciones de alertas
const style = document.createElement('style');
style.textContent = `
    @keyframes slideIn {
        from {
            transform: translateX(100%);
            opacity: 0;
        }
        to {
            transform: translateX(0);
            opacity: 1;
        }
    }
    @keyframes slideOut {
        from {
            transform: translateX(0);
            opacity: 1;
        }
        to {
            transform: translateX(100%);
            opacity: 0;
        }
    }
`;
document.head.appendChild(style);

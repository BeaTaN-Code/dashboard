<?php defined('APP_PAGE') || exit; ?>
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


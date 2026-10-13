<?php defined('APP_PAGE') || exit; ?>
          <section class="view" id="view-facturacion" data-view="facturacion" data-title="Facturación" hidden>
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
          </section>

<?php defined('APP_PAGE') || exit; ?>
          <section class="view" id="view-cronograma" data-view="cronograma" data-title="Cronograma" hidden>
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
          </section>


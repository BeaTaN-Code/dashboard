<?php defined('APP_PAGE') || exit; ?>
          <section class="view" id="view-mensajes" data-view="mensajes" data-title="Mensajes" hidden>
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
                          <td><?php echo (int) $row['ID']; ?></td>
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
                            <span class="badge <?php echo $badgeClass; ?>"><?php echo htmlspecialchars($row['ESTADO']); ?></span>
                          </td>
                          <td><?php echo date('d/m/Y H:i', strtotime($row['HORCREA'])); ?></td>
                          <td>
                            <div class="action-buttons">
                              <button class="btn-icon" onclick="viewMessage(<?php echo (int) $row['ID']; ?>)" title="Ver">
                                <i class="bi bi-eye"></i>
                              </button>
                              <button class="btn-icon" onclick="updateMessageStatus(<?php echo (int) $row['ID']; ?>)"
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
          </section>


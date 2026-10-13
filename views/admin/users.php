<?php defined('APP_PAGE') || exit; ?>
          <section class="view" id="view-usuarios" data-view="usuarios" data-title="Usuarios" hidden>
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
                          <td><?php echo htmlspecialchars($userRow['USRIDXXX']); ?></td>
                          <td><?php echo htmlspecialchars($userRow['USRNMEXX'] ?? '-'); ?></td>
                          <td><?php echo htmlspecialchars($userRow['USRMAILX'] ?? '-'); ?></td>
                          <td><?php echo htmlspecialchars($userRow['USRCELUL'] ?? '-'); ?></td>
                          <td>
                            <span class="badge <?php echo $userRow['ISADMINX'] === 'SI' ? 'badge-info' : 'badge-outline'; ?>">
                              <?php echo $userRow['ISADMINX'] == "SI" ? 'Admin' : 'Usuario'; ?>
                            </span>
                          </td>
                          <td>
                            <span
                              class="badge <?php echo $userRow['REGESTXX'] == 'ACTIVO' ? 'badge-responded' : 'badge-closed'; ?>">
                              <?php echo htmlspecialchars($userRow['REGESTXX']); ?>
                            </span>
                          </td>
                          <td><?php echo $userRow['REGFECXX'] ? date('d/m/Y', strtotime($userRow['REGFECXX'])) : '—'; ?></td>
                          <td>
                            <div class="action-buttons">
                              <button class="btn-icon" onclick="editUser(<?php echo htmlspecialchars(json_encode((string) $userRow['USRIDXXX']), ENT_QUOTES); ?>)" title="Editar">
                                <i class="bi bi-pencil"></i>
                              </button>
                              <?php if ($userRow['USRIDXXX'] != $user['id']): ?>
                                <button class="btn-icon"
                                  onclick="toggleUserStatus(<?php echo htmlspecialchars(json_encode((string) $userRow['USRIDXXX']), ENT_QUOTES); ?>, '<?php echo htmlspecialchars($userRow['REGESTXX']); ?>')"
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
          </section>

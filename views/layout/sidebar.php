<?php defined('APP_PAGE') || exit;
$navFinance = [
  ['resumen', 'bi-grid-1x2', 'Resumen'],
  ['movimientos', 'bi-arrow-left-right', 'Movimientos'],
  ['presupuestos', 'bi-pie-chart', 'Presupuestos'],
  ['metas', 'bi-piggy-bank', 'Metas de ahorro'],
  ['deudas', 'bi-credit-card-2-front', 'Deudas'],
];
$navAdmin = [
  ['mensajes', 'bi-envelope', 'Mensajes', $pendingMessages],
  ['usuarios', 'bi-people', 'Usuarios', 0],
  ['cronograma', 'bi-calendar-event', 'Cronograma', 0],
  ['facturacion', 'bi-receipt-cutoff', 'Facturación', 0],
];
?>
<aside class="sidebar" id="sidebar" aria-label="Navegación principal">
  <div class="sidebar-brand">
    <img src="img/Logo.png" alt="" width="32" height="32">
    <div>
      <strong>BeaTaN</strong>
      <span>Finanzas</span>
    </div>
  </div>

  <nav class="sidebar-nav">
    <div class="nav-section">
      <span class="nav-section-title">Finanzas</span>
      <?php foreach ($navFinance as [$id, $icon, $label]): ?>
        <a href="#<?= $id ?>" class="nav-item" data-nav="<?= $id ?>" title="<?= $label ?>">
          <i class="bi <?= $icon ?>" aria-hidden="true"></i><span><?= $label ?></span>
        </a>
      <?php endforeach; ?>
    </div>

    <?php if ($user['is_admin']): ?>
      <div class="nav-section">
        <span class="nav-section-title">Empresa</span>
        <?php foreach ($navAdmin as [$id, $icon, $label, $badge]): ?>
          <a href="#<?= $id ?>" class="nav-item" data-nav="<?= $id ?>" title="<?= $label ?>">
            <i class="bi <?= $icon ?>" aria-hidden="true"></i><span><?= $label ?></span>
            <?php if ($badge > 0): ?><span class="nav-badge" aria-label="<?= (int) $badge ?> pendientes"><?= (int) $badge ?></span><?php endif; ?>
          </a>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>

    <div class="nav-section">
      <span class="nav-section-title">Cuenta</span>
      <a href="#perfil" class="nav-item" data-nav="perfil" title="Perfil y ajustes">
        <i class="bi bi-gear" aria-hidden="true"></i><span>Perfil y ajustes</span>
      </a>
    </div>
  </nav>

  <div class="sidebar-footer">
    <div class="user-card">
      <div class="avatar" aria-hidden="true">
        <?php if ($avatarPath): ?><img src="<?= htmlspecialchars($avatarPath) ?>" alt=""><?php else: ?><?= htmlspecialchars(mb_strtoupper(mb_substr($user['name'] ?? 'U', 0, 1))) ?><?php endif; ?>
      </div>
      <div class="user-card-text" style="min-width:0">
        <div class="user-card-name"><?= htmlspecialchars($user['name'] ?? 'Usuario') ?></div>
        <div class="user-card-role"><?= $user['is_admin'] ? 'Administrador' : 'Usuario' ?></div>
      </div>
    </div>
    <a href="logout.php" class="btn-ghost btn-sm" title="Cerrar sesión" style="justify-content:flex-start">
      <i class="bi bi-box-arrow-right" aria-hidden="true"></i><span class="btn-label">Cerrar sesión</span>
    </a>
  </div>
</aside>
<div class="sidebar-overlay" id="sidebarOverlay" aria-hidden="true"></div>

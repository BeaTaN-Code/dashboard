<?php defined('APP_PAGE') || exit; ?>
<header class="topbar" id="topbar">
  <button class="icon-btn hide-desktop" id="mobileToggle" aria-label="Abrir menú" aria-controls="sidebar" aria-expanded="false">
    <i class="bi bi-list" aria-hidden="true"></i>
  </button>
  <div class="topbar-title">
    <div style="min-width:0">
      <h1 id="pageTitle">Resumen</h1>
      <p id="pageSubtitle">Tu situación financiera de un vistazo</p>
    </div>
  </div>
  <div class="topbar-actions">
    <?php if ($user['is_admin']): ?>
      <div class="account-switch desktop-only" role="group" aria-label="Cuenta a visualizar">
        <div class="segmented" id="accountSwitch">
          <button type="button" data-account="PERSONAL" aria-pressed="true"><i class="bi bi-person" aria-hidden="true"></i>Personal</button>
          <button type="button" data-account="BEATAN" aria-pressed="false"><i class="bi bi-building" aria-hidden="true"></i>Empresa</button>
        </div>
      </div>
    <?php endif; ?>
    <button class="search-trigger" type="button" data-action="open-palette" aria-label="Buscar o ejecutar un comando (Ctrl+K)">
      <i class="bi bi-search" aria-hidden="true"></i><span class="label-text">Buscar…</span><kbd>Ctrl K</kbd>
    </button>
    <button class="icon-btn theme-btn" type="button" id="themeToggle" aria-label="Cambiar tema">
      <i class="bi bi-circle-half" aria-hidden="true"></i>
    </button>
    <div style="position:relative">
      <button class="icon-btn" type="button" id="notificationBell" aria-label="Notificaciones" aria-haspopup="true" aria-expanded="false" aria-controls="notificationPanel">
        <i class="bi bi-bell" aria-hidden="true"></i>
        <span class="dot-count" id="notificationCount" hidden>0</span>
      </button>
      <div class="popover" id="notificationPanel" role="dialog" aria-label="Notificaciones">
        <div class="popover-header">
          <h4>Notificaciones</h4>
          <button class="btn-ghost btn-sm" type="button" data-action="clear-notifications">Ocultar todas</button>
        </div>
        <div class="popover-body" id="notificationPanelBody"></div>
      </div>
    </div>
    <button class="btn-primary desktop-only" type="button" data-action="new-transaction">
      <i class="bi bi-plus-lg" aria-hidden="true"></i><span>Nuevo movimiento</span>
    </button>
  </div>
</header>

<div class="toolbar" id="periodToolbar" role="region" aria-label="Periodo y cuenta">
  <?php if ($user['is_admin']): ?>
    <div class="segmented hide-desktop" id="accountSwitchMobile" role="group" aria-label="Cuenta a visualizar" style="width:100%">
      <button type="button" data-account="PERSONAL" aria-pressed="true">Personal</button>
      <button type="button" data-account="BEATAN" aria-pressed="false">Empresa</button>
    </div>
  <?php endif; ?>
  <div class="segmented" id="periodSwitch" role="group" aria-label="Periodo">
    <button type="button" data-period="week" aria-pressed="false">Semana</button>
    <button type="button" data-period="month" aria-pressed="true">Este mes</button>
    <button type="button" data-period="prev_month" aria-pressed="false">Mes anterior</button>
    <button type="button" data-period="year" aria-pressed="false">Este año</button>
    <button type="button" data-period="custom" aria-pressed="false">Personalizado</button>
  </div>
  <form class="period-custom" id="periodCustom" hidden>
    <label class="sr-only" for="periodFrom">Desde</label>
    <input class="input" type="date" id="periodFrom" required>
    <span class="muted">a</span>
    <label class="sr-only" for="periodTo">Hasta</label>
    <input class="input" type="date" id="periodTo" required>
    <button class="btn-secondary btn-sm" type="submit">Aplicar</button>
  </form>
  <span class="period-label" id="periodLabel" aria-live="polite"></span>
</div>

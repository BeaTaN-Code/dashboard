/* dashboard/js/app.js
 * Arranque: navegación por vistas, cuenta y periodo activos, menú móvil, atajos y paleta de comandos.
 */
(function () {
  'use strict';
  const App = window.App;
  const F = window.FinanceCore;
  const { $, $$, esc } = App;
  const ADMIN_VIEWS = ['mensajes', 'usuarios', 'cronograma', 'facturacion'];
  const loaded = new Set(); // vistas con datos vigentes

  /* ------------------------------- Periodo ------------------------------- */
  App.currentPeriod = function () {
    const p = App.state.period;
    return F.resolvePeriod(p.preset, p.from, p.to, App.today()) || F.resolvePeriod('month', null, null, App.today());
  };
  App.setPeriodLabel = function (period) {
    const p = period || App.currentPeriod();
    $('#periodLabel').textContent = F.formatDate(p.from) + ' – ' + F.formatDate(p.to);
  };
  function setPeriod(preset, from, to) {
    if (preset === 'custom' && !F.resolvePeriod('custom', from, to, App.today())) {
      App.toast('error', 'Rango inválido', 'La fecha inicial debe ser anterior a la final (máximo 3 años).');
      return;
    }
    App.state.period = { preset, from: from || null, to: to || null };
    App.store.set('beatan_period', JSON.stringify(App.state.period));
    $$('#periodSwitch [data-period]').forEach((b) => b.setAttribute('aria-pressed', String(b.dataset.period === preset)));
    $('#periodCustom').hidden = preset !== 'custom';
    App.setPeriodLabel();
    invalidate(['resumen', 'movimientos']);
    reloadCurrent();
  }

  /* -------------------------------- Cuenta ------------------------------- */
  App.setAccount = function (account) {
    if (account === 'BEATAN' && !App.config.isAdmin) return;
    if (App.state.account === account) return;
    App.state.account = account;
    App.store.set('beatan_account', account);
    $$('[data-account]').forEach((b) => b.setAttribute('aria-pressed', String(b.dataset.account === account)));
    document.documentElement.dataset.account = account;
    App.categories.known.clear();
    App.refreshCategoryOptions();
    if (App.Tx) App.Tx.rows = [];
    invalidate();
    reloadCurrent();
    App.toast('info', account === 'BEATAN' ? 'Viendo la cuenta de la empresa' : 'Viendo tu cuenta personal', '', { duration: 2200 });
  };

  /* ------------------------------ Navegación ------------------------------ */
  function invalidate(views) {
    if (!views) loaded.clear();
    else views.forEach((v) => loaded.delete(v));
  }
  function reloadCurrent() { show(App.state.view, { keepFocus: true }); }

  function show(view, opts) {
    const o = opts || {};
    let section = document.getElementById('view-' + view);
    if (!section || (ADMIN_VIEWS.includes(view) && !App.config.isAdmin)) { view = 'resumen'; section = document.getElementById('view-resumen'); }
    const changed = App.state.view !== view;
    App.state.view = view;
    $$('.view').forEach((s) => { s.hidden = s !== section; });
    $$('[data-nav]').forEach((a) => {
      if (a.dataset.nav === view) a.setAttribute('aria-current', 'page');
      else a.removeAttribute('aria-current');
      a.classList.toggle('active', a.dataset.nav === view);
    });
    $('#pageTitle').textContent = section.dataset.title || '';
    $('#pageSubtitle').textContent = (section.dataset.subtitle || '') + (App.state.account === 'BEATAN' && !ADMIN_VIEWS.includes(view) && view !== 'perfil' ? ' · Empresa' : '');
    document.title = (section.dataset.title || 'Inicio') + ' · BeaTaN';
    $('#periodToolbar').hidden = !section.dataset.period && !(App.config.isAdmin && App.isMobile() && !ADMIN_VIEWS.includes(view) && view !== 'perfil');
    $('#periodSwitch').hidden = !section.dataset.period;
    $('#periodCustom').hidden = !section.dataset.period || App.state.period.preset !== 'custom';
    $('#periodLabel').hidden = !section.dataset.period;
    closeSidebar();

    const ctrl = App.views && App.views[view];
    if (ctrl && !loaded.has(view)) {
      loaded.add(view);
      Promise.resolve(ctrl.load()).catch((e) => { loaded.delete(view); console.error(e); });
    }
    // Módulos heredados de administración
    if (view === 'cronograma' && typeof window.loadProjectsSelects === 'function') { window.loadProjectsSelects(); window.loadUsersSelects(); window.loadHoursLogs(); }
    if (view === 'facturacion' && typeof window.loadInvoices === 'function') window.loadInvoices();

    if (changed && !o.keepFocus) {
      window.scrollTo({ top: 0, behavior: 'instant' in window ? 'instant' : 'auto' });
      const main = $('#main');
      if (main && o.fromUser) main.focus({ preventScroll: true });
    }
  }
  App.go = function (view) {
    if (window.location.hash !== '#' + view) window.location.hash = view;
    else show(view, { fromUser: true });
  };

  // Al guardar algo, todas las vistas relacionadas quedan desactualizadas
  App.on('data', (what) => {
    const map = {
      transactions: ['resumen', 'movimientos', 'deudas', 'presupuestos'],
      debts: ['resumen', 'deudas', 'movimientos'],
      budgets: ['resumen', 'presupuestos'],
      goals: ['resumen', 'metas'],
    };
    invalidate(map[what] || null);
    reloadCurrent();
    if (App.Notifications) App.Notifications.load();
  });

  /* ------------------------------ Menú móvil ------------------------------ */
  function openSidebar() {
    $('#sidebar').classList.add('active');
    $('#sidebarOverlay').classList.add('active');
    $('#mobileToggle').setAttribute('aria-expanded', 'true');
    const first = $('#sidebar .nav-item');
    if (first) first.focus();
  }
  function closeSidebar() {
    const sb = $('#sidebar');
    if (!sb.classList.contains('active')) return;
    sb.classList.remove('active');
    $('#sidebarOverlay').classList.remove('active');
    $('#mobileToggle').setAttribute('aria-expanded', 'false');
  }

  /* --------------------------- Paleta de comandos --------------------------- */
  const commands = () => {
    const list = [
      { id: 'new-tx', title: 'Nuevo movimiento', cat: 'Acción', icon: 'bi-plus-lg', run: () => App.openTxModal({}) },
      { id: 'import', title: 'Importar extracto o CSV', cat: 'Acción', icon: 'bi-upload', run: () => App.Import.open() },
      { id: 'export', title: 'Exportar movimientos a CSV', cat: 'Acción', icon: 'bi-filetype-csv', run: () => document.querySelector('[data-action="export-csv"]').click() },
      { id: 'budget', title: 'Nuevo presupuesto', cat: 'Acción', icon: 'bi-pie-chart', run: () => document.querySelector('[data-action="new-budget"]').click() },
      { id: 'goal', title: 'Nueva meta de ahorro', cat: 'Acción', icon: 'bi-piggy-bank', run: () => document.querySelector('[data-action="new-goal"]').click() },
      { id: 'debt', title: 'Nueva deuda', cat: 'Acción', icon: 'bi-credit-card', run: () => App.DebtModal.open(null) },
      { id: 'ai', title: 'Abrir asistente financiero', cat: 'Acción', icon: 'bi-stars', run: () => $('#aiChatToggle').click() },
      { id: 'theme', title: 'Cambiar tema (claro/oscuro)', cat: 'Preferencia', icon: 'bi-circle-half', run: () => $('#themeToggle').click() },
    ];
    $$('.sidebar [data-nav]').forEach((a) => list.push({ id: 'nav-' + a.dataset.nav, title: 'Ir a ' + a.textContent.trim(), cat: 'Sección', icon: (a.querySelector('i') || {}).className || 'bi-arrow-right', run: () => App.go(a.dataset.nav) }));
    if (App.config.isAdmin) {
      list.push({ id: 'acc-p', title: 'Ver cuenta personal', cat: 'Cuenta', icon: 'bi-person', run: () => App.setAccount('PERSONAL') });
      list.push({ id: 'acc-b', title: 'Ver cuenta de la empresa', cat: 'Cuenta', icon: 'bi-building', run: () => App.setAccount('BEATAN') });
    }
    return list;
  };
  const Palette = {
    items: [],
    sel: 0,
    open() {
      const m = $('#commandPaletteModal');
      m.classList.add('active');
      $('#commandPaletteInput').value = '';
      this.filter();
      this.returnFocus = document.activeElement;
      setTimeout(() => $('#commandPaletteInput').focus(), 20);
    },
    close() {
      $('#commandPaletteModal').classList.remove('active');
      if (this.returnFocus) this.returnFocus.focus();
    },
    isOpen() { return $('#commandPaletteModal').classList.contains('active'); },
    filter() {
      const q = $('#commandPaletteInput').value.toLowerCase().normalize('NFD').replace(/[̀-ͯ]/g, '');
      this.items = commands().filter((c) => !q || (c.title + ' ' + c.cat).toLowerCase().normalize('NFD').replace(/[̀-ͯ]/g, '').includes(q));
      this.sel = 0;
      this.render();
    },
    render() {
      $('#commandPaletteResults').innerHTML = this.items.length ? this.items.map((c, i) => `
        <div class="command-item ${i === this.sel ? 'selected' : ''}" role="option" id="cmd-${i}" aria-selected="${i === this.sel}" data-cmd="${i}">
          <i class="bi ${esc(c.icon.replace(/^bi\s+/, ''))}" aria-hidden="true"></i><span class="command-item-title">${esc(c.title)}</span><span class="command-item-cat">${esc(c.cat)}</span>
        </div>`).join('') : '<p class="text-sm" style="padding:12px">Sin resultados.</p>';
      $('#commandPaletteInput').setAttribute('aria-activedescendant', this.items.length ? 'cmd-' + this.sel : '');
    },
    run(i) {
      const c = this.items[i];
      if (!c) return;
      this.close();
      setTimeout(() => c.run(), 30);
    },
  };

  /* -------------------------------- Eventos -------------------------------- */
  document.addEventListener('click', (e) => {
    let el;
    if ((el = e.target.closest('[data-period]'))) {
      const p = el.dataset.period;
      if (p === 'custom') {
        $$('#periodSwitch [data-period]').forEach((b) => b.setAttribute('aria-pressed', String(b === el)));
        const cur = App.currentPeriod();
        $('#periodFrom').value = App.state.period.from || cur.from;
        $('#periodTo').value = App.state.period.to || cur.to;
        $('#periodCustom').hidden = false;
        $('#periodFrom').focus();
      } else setPeriod(p);
      return;
    }
    if ((el = e.target.closest('[data-account]'))) { App.setAccount(el.dataset.account); return; }
    if ((el = e.target.closest('[data-cmd]'))) { Palette.run(Number(el.dataset.cmd)); return; }
    if (e.target.id === 'commandPaletteModal') { Palette.close(); return; }
    if (e.target.closest('#mobileToggle') || e.target.closest('[data-action="open-menu"]')) { openSidebar(); return; }
    if (e.target.closest('#sidebarOverlay')) { closeSidebar(); return; }
    if (e.target.closest('#themeToggle')) {
      const order = ['system', 'light', 'dark'];
      const next = order[(order.indexOf(App.theme.get()) + 1) % 3];
      App.theme.set(next);
      App.toast('info', 'Tema: ' + { system: 'automático (según tu dispositivo)', light: 'claro', dark: 'oscuro' }[next], '', { duration: 1800 });
      return;
    }
    if (e.target.closest('[data-action="open-palette"]')) { Palette.open(); return; }
    // Enlaces de navegación internos: cerrar menú móvil
    if (e.target.closest('a[href^="#"]')) closeSidebar();
  });

  document.addEventListener('keydown', (e) => {
    const typing = /^(INPUT|TEXTAREA|SELECT)$/.test(e.target.tagName) || e.target.isContentEditable;
    if ((e.ctrlKey || e.metaKey) && e.key.toLowerCase() === 'k') { e.preventDefault(); Palette.isOpen() ? Palette.close() : Palette.open(); return; }
    if (Palette.isOpen()) {
      if (e.key === 'Escape') { e.preventDefault(); Palette.close(); }
      else if (e.key === 'ArrowDown') { e.preventDefault(); Palette.sel = Math.min(Palette.items.length - 1, Palette.sel + 1); Palette.render(); }
      else if (e.key === 'ArrowUp') { e.preventDefault(); Palette.sel = Math.max(0, Palette.sel - 1); Palette.render(); }
      else if (e.key === 'Enter') { e.preventDefault(); Palette.run(Palette.sel); }
      return;
    }
    if (e.key === 'Escape' && $('#sidebar').classList.contains('active')) { closeSidebar(); $('#mobileToggle').focus(); return; }
    if (typing || App.modal.top() || e.ctrlKey || e.metaKey || e.altKey) return;
    if (e.key === 'n' || e.key === 'N') { e.preventDefault(); App.openTxModal({}); }
    else if (e.key === '/') { e.preventDefault(); App.go('movimientos'); setTimeout(() => $('#txSearch').focus(), 60); }
  });

  window.addEventListener('hashchange', () => show(window.location.hash.slice(1) || 'resumen', { fromUser: true }));

  document.addEventListener('DOMContentLoaded', () => {
    // Preferencias locales (solo comodidad: si el almacenamiento falla, se usan valores por defecto)
    App.theme.set(App.theme.get());
    const savedAcc = App.store.get('beatan_account', 'PERSONAL');
    App.state.account = savedAcc === 'BEATAN' && App.config.isAdmin ? 'BEATAN' : 'PERSONAL';
    $$('[data-account]').forEach((b) => b.setAttribute('aria-pressed', String(b.dataset.account === App.state.account)));
    try {
      const p = JSON.parse(App.store.get('beatan_period', 'null'));
      if (p && p.preset && (p.preset !== 'custom' || F.resolvePeriod('custom', p.from, p.to, App.today()))) App.state.period = p;
    } catch (e) { /* periodo por defecto */ }
    $$('#periodSwitch [data-period]').forEach((b) => b.setAttribute('aria-pressed', String(b.dataset.period === App.state.period.preset)));
    if (App.state.period.preset === 'custom') { $('#periodFrom').value = App.state.period.from; $('#periodTo').value = App.state.period.to; }
    App.setPeriodLabel();
    App.refreshCategoryOptions();

    $('#periodCustom').addEventListener('submit', (e) => { e.preventDefault(); setPeriod('custom', $('#periodFrom').value, $('#periodTo').value); });
    $('#commandPaletteInput').addEventListener('input', () => Palette.filter());

    // Accesibilidad de módulos heredados: botones solo con icono reciben nombre accesible
    $$('button[title]:not([aria-label])').forEach((b) => { if (!b.textContent.trim()) b.setAttribute('aria-label', b.title); });
    $$('.modal-overlay').forEach((m) => { if (!m.hasAttribute('role')) { m.setAttribute('role', 'dialog'); m.setAttribute('aria-modal', 'true'); } });

    const topbar = $('#topbar');
    const onScroll = () => topbar.classList.toggle('is-scrolled', window.scrollY > 4);
    window.addEventListener('scroll', onScroll, { passive: true });
    onScroll();

    show(window.location.hash.slice(1) || 'resumen');
    if (App.state.account === 'BEATAN') App.toast('info', 'Viendo la cuenta de la empresa', 'Cámbiala desde la parte superior.', { duration: 2500 });
  });
})();

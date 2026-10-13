/* dashboard/js/planning.js
 * Presupuestos, metas de ahorro y perfil/ajustes.
 */
(function () {
  'use strict';
  const App = window.App;
  const F = window.FinanceCore;
  const { esc, $, money } = App;
  const { emptyState, errorState } = App.ui;

  const migrationNotice = (what) => `<div class="empty"><i class="bi bi-database-gear" aria-hidden="true"></i><h3>El módulo de ${what} aún no está activado</h3>
    <p>${App.config.isAdmin ? 'Aplica la migración v3 desde Perfil y ajustes. Es segura: solo crea tablas nuevas.' : 'Pide a un administrador que aplique la actualización de la base de datos.'}</p>
    ${App.config.isAdmin ? '<a class="btn-primary btn-sm" href="#perfil">Ir a ajustes</a>' : ''}</div>`;

  /* ============================ PRESUPUESTOS ============================ */
  const Budgets = {
    month: App.today().slice(0, 7),
    data: null,
    async load() {
      $('#budgetMonthLabel').textContent = F.monthLabel(this.month);
      const box = $('#budgetsContent');
      try {
        const res = await App.api('GET', `api/budgets.php?tipgasxx=${App.state.account}&month=${this.month}`, { key: 'budgets' });
        this.data = res.data;
        this.render();
      } catch (err) {
        if (App.isAbort(err)) return;
        box.innerHTML = err.status === 503 ? migrationNotice('presupuestos') : errorState(err, 'reload-budgets');
      }
    },
    render() {
      const d = this.data;
      const box = $('#budgetsContent');
      const isCurrent = this.month === App.today().slice(0, 7);
      if (!d.budgets.length) {
        box.innerHTML = `<article class="card">${emptyState('bi-pie-chart', 'Todavía no tienes presupuestos', 'Empieza por 2 o 3 categorías donde más gastas. Por ejemplo: alimentación, transporte y entretenimiento.', ['new-budget', 'Crear mi primer presupuesto'])}</article>` + this.suggestions(d);
        return;
      }
      const t = d.totals;
      const pct = t.limit > 0 ? Math.round((t.spent / t.limit) * 1000) / 10 : 0;
      const status = t.spent > t.limit ? 'excedido' : pct >= 85 ? 'alerta' : 'ok';
      box.innerHTML = `
        <div class="grid grid-overview">
          <article class="card">
            <div class="card-header"><h3 class="card-title">Categorías ${App.helpButton('budget', '¿Qué es un presupuesto?')}</h3><span class="text-sm muted">${isCurrent ? `Día ${d.days_elapsed} de ${d.days_total}` : F.monthLabel(this.month)}</span></div>
            <div class="card-body"><div class="budget-list">${d.budgets.map((b) => `<div class="flex gap-2 items-center" style="align-items:flex-start"><div style="flex:1;min-width:0">${App.budgetRow(b)}</div>
              <button class="btn-icon" type="button" data-budget-edit="${b.id}" aria-label="Editar presupuesto de ${esc(b.category)}" title="Editar"><i class="bi bi-pencil" aria-hidden="true"></i></button></div>`).join('')}</div></div>
          </article>
          <div class="stack side">
            <article class="card kpi">
              <span class="kpi-label">Total presupuestado</span>
              <span class="kpi-value">${money(t.limit)}</span>
              <div class="progress ${status}" role="progressbar" aria-label="Gasto total frente al presupuesto" aria-valuemin="0" aria-valuemax="100" aria-valuenow="${Math.min(100, pct)}"><span style="--value:${Math.min(100, pct)}%"></span></div>
              <span class="kpi-meta">Gastado ${money(t.spent)} (${pct.toLocaleString('es-CO')} %) · ${t.limit - t.spent >= 0 ? 'quedan ' + money(t.limit - t.spent) : 'excedido en ' + money(t.spent - t.limit)}</span>
            </article>
            ${this.suggestions(d)}
          </div>
        </div>`;
    },
    suggestions(d) {
      if (!d.unbudgeted.length) return '';
      return `<article class="card"><div class="card-header"><div><h3 class="card-title">Sin presupuesto</h3><p class="card-subtitle">Categorías con gastos este mes</p></div></div>
        <div class="card-body"><div class="list">${d.unbudgeted.slice(0, 6).map((u) => `
          <div class="list-row"><span class="list-body"><span class="list-title">${esc(u.category)}</span><span class="list-sub">Gastado: ${money(u.spent)}</span></span>
          <button class="btn-secondary btn-sm" type="button" data-budget-suggest="${esc(u.category)}" data-spent="${u.spent}">Crear</button></div>`).join('')}</div></div></article>`;
    },
    find(id) { return this.data && this.data.budgets.find((b) => b.id === Number(id)); },
  };

  const BudgetModal = {
    open(b, preset) {
      const form = $('#budgetForm');
      form.reset();
      App.formError($('#budgetError'), null);
      $('#budgetModalTitle').textContent = b ? 'Editar presupuesto' : 'Nuevo presupuesto';
      form.id.value = b ? b.id : '';
      form.categoria.value = b ? b.category : (preset && preset.category) || '';
      App.setMoney(form.monto, b ? b.limit : null);
      form.alerta.value = b ? b.alert_pct : 80;
      $('#budgetAlertVal').textContent = form.alerta.value;
      $('[data-action="budget-delete"]').hidden = !b;
      $('#budgetSuggest').textContent = preset && preset.spent ? `Este mes llevas ${money(preset.spent)} en esta categoría.` : '';
      App.modal.open('budgetModal', { focus: b || preset ? '#budgetAmount' : '#budgetCat' });
    },
    async submit(e) {
      e.preventDefault();
      const form = $('#budgetForm');
      const cents = F.parseAmount(form.monto.value);
      const errors = [];
      if (!form.categoria.value.trim()) errors.push('Elige una categoría.');
      if (!cents || cents <= 0) errors.push('El límite debe ser mayor que cero.');
      if (errors.length) { App.formError($('#budgetError'), { errors }); return; }
      const body = { tipgasxx: App.state.account, categoria: form.categoria.value.trim(), monto: F.centsToDecimal(cents), alerta: Number(form.alerta.value) };
      await App.busy(form.querySelector('[type="submit"]'), async () => {
        try {
          if (form.id.value) await App.api('PUT', 'api/budgets.php', { body: Object.assign({ id: Number(form.id.value) }, body) });
          else await App.api('POST', 'api/budgets.php', { body });
          App.modal.close('budgetModal');
          App.toast('success', 'Presupuesto guardado', `${body.categoria}: ${money(cents)} al mes`);
          App.dataChanged('budgets');
        } catch (err) { App.formError($('#budgetError'), err); }
      });
    },
    async remove() {
      const form = $('#budgetForm');
      const ok = await App.confirm(`¿Eliminar el presupuesto de ${form.categoria.value}? Tus movimientos no se verán afectados.`, { title: 'Eliminar presupuesto', confirmLabel: 'Eliminar' });
      if (!ok) return;
      try {
        await App.api('DELETE', 'api/budgets.php?id=' + Number(form.id.value));
        App.modal.close('budgetModal');
        App.toast('success', 'Presupuesto eliminado');
        App.dataChanged('budgets');
      } catch (err) { App.formError($('#budgetError'), err); }
    },
  };

  /* =============================== METAS =============================== */
  const Goals = {
    data: null,
    async load() {
      const box = $('#goalsContent');
      try {
        const res = await App.api('GET', `api/goals.php?tipgasxx=${App.state.account}${$('#goalsShowArchived').checked ? '&include_archived=1' : ''}`, { key: 'goals' });
        this.data = res.data;
        this.render();
      } catch (err) {
        if (App.isAbort(err)) return;
        box.innerHTML = err.status === 503 ? migrationNotice('metas') : errorState(err, 'reload-goals');
      }
    },
    render() {
      const { goals, totals } = this.data;
      const box = $('#goalsContent');
      if (!goals.length) {
        box.innerHTML = `<article class="card">${emptyState('bi-piggy-bank', 'Crea tu primera meta', 'Un buen comienzo es un fondo de emergencia equivalente a 3 meses de gastos. Te diremos cuánto apartar cada mes.', ['new-goal', 'Crear meta'])}</article>`;
        return;
      }
      const pct = totals.target > 0 ? Math.min(100, Math.round((totals.saved / totals.target) * 1000) / 10) : 0;
      box.innerHTML = `
        <div class="kpi-grid" style="grid-template-columns:repeat(auto-fit,minmax(200px,1fr));margin-bottom:16px">
          <article class="card kpi"><span class="kpi-label">Apartado en metas activas</span><span class="kpi-value" style="color:var(--savings)">${money(totals.saved)}</span><span class="kpi-meta">Sigue siendo parte de tu saldo</span></article>
          <article class="card kpi"><span class="kpi-label">Objetivo total</span><span class="kpi-value">${money(totals.target)}</span><span class="kpi-meta">${pct.toLocaleString('es-CO')} % logrado</span></article>
        </div>
        <div class="goal-grid">${goals.map((g) => this.card(g)).join('')}</div>`;
    },
    card(g) {
      const p = g.plan;
      const archived = g.state === 'ARCHIVADA';
      let planText = '';
      if (g.completed) planText = '<span class="badge badge-success"><i class="bi bi-trophy" aria-hidden="true"></i>¡Meta cumplida!</span>';
      else if (p.overdue) planText = `<span class="badge badge-warning">La fecha pasó · faltan ${money(p.missing)}</span>`;
      else if (p.per_month) planText = `Para llegar a tiempo: <strong class="num">${money(p.per_month)}</strong>/mes o <strong class="num">${money(p.per_week)}</strong>/semana`;
      else planText = `Faltan ${money(p.missing)}. Agrega una fecha para calcular cuánto ahorrar.`;
      return `<article class="card goal-card" ${archived ? 'style="opacity:.7"' : ''}>
        <div class="goal-top">
          <div style="min-width:0"><div class="goal-name">${esc(g.name)}</div>
            <div class="text-xs muted">${g.target_date ? 'Para el ' + F.formatDate(g.target_date, 'long') + (p.days_left > 0 ? ` · ${p.days_left} días` : '') : 'Sin fecha límite'}${archived ? ' · Archivada' : ''}</div></div>
          <div class="ring" style="--value:${p.pct};position:relative" role="img" aria-label="${p.pct} % completado"><span>${Math.round(p.pct)}%</span></div>
        </div>
        <div class="goal-amounts"><strong class="num">${money(g.saved)}</strong><span>de ${money(g.target)}</span></div>
        <div class="progress savings" aria-hidden="true"><span style="--value:${p.pct}%"></span></div>
        <p class="text-sm">${planText}</p>
        <p class="text-xs muted"><span class="tag-estimate">Proyección</span> Cálculo simple con lo que falta y el tiempo disponible; no considera intereses.</p>
        <div class="flex gap-2 wrap">
          ${archived ? `<button class="btn-secondary btn-sm" type="button" data-goal-state="${g.id}" data-state="ACTIVA">Reactivar</button>` : `
          <button class="btn-primary btn-sm" type="button" data-goal-move="${g.id}" data-tipo="APORTE"><i class="bi bi-plus-lg" aria-hidden="true"></i>Aportar</button>
          <button class="btn-secondary btn-sm" type="button" data-goal-move="${g.id}" data-tipo="RETIRO" ${g.saved <= 0 ? 'disabled' : ''}>Retirar</button>`}
          <button class="btn-ghost btn-sm" type="button" data-goal-history="${g.id}">Historial</button>
          ${archived ? '' : `<button class="btn-ghost btn-sm" type="button" data-goal-edit="${g.id}" aria-label="Editar meta ${esc(g.name)}"><i class="bi bi-pencil" aria-hidden="true"></i></button>
          <button class="btn-ghost btn-sm" type="button" data-goal-state="${g.id}" data-state="ARCHIVADA" aria-label="Archivar meta ${esc(g.name)}" title="Archivar"><i class="bi bi-archive" aria-hidden="true"></i></button>`}
        </div>
      </article>`;
    },
    find(id) { return this.data && this.data.goals.find((g) => g.id === Number(id)); },
  };

  const GoalModal = {
    key: null,
    open(g) {
      const form = $('#goalForm');
      form.reset();
      App.formError($('#goalError'), null);
      this.key = App.uid();
      $('#goalModalTitle').textContent = g ? 'Editar meta' : 'Nueva meta';
      form.id.value = g ? g.id : '';
      form.nombre.value = g ? g.name : '';
      App.setMoney(form.objetivo, g ? g.target : null);
      form.fecha.value = g && g.target_date ? g.target_date : '';
      form.fecha.min = App.today();
      this.preview();
      App.modal.open('goalModal', { focus: '#goalName' });
    },
    preview() {
      const form = $('#goalForm');
      const target = F.parseAmount(form.objetivo.value);
      const date = form.fecha.value;
      const hint = $('#goalPlanHint');
      if (!target || !date || date <= App.today()) { hint.textContent = ''; return; }
      const g = form.id.value ? Goals.find(form.id.value) : null;
      const missing = Math.max(0, target - (g ? g.saved : 0));
      const days = F.daysBetween(App.today(), date);
      const up = (c) => Math.ceil(c / 100) * 100;
      hint.innerHTML = `<span class="tag-estimate">Estimación</span> Necesitarías apartar unos <strong>${money(up(missing / Math.max(1, days / 30.4375)))}</strong> al mes (${money(up(missing / Math.max(1, days / 7)))} por semana).`;
    },
    async submit(e) {
      e.preventDefault();
      const form = $('#goalForm');
      const cents = F.parseAmount(form.objetivo.value);
      const errors = [];
      if (!form.nombre.value.trim()) errors.push('Ponle un nombre a la meta.');
      if (!cents || cents <= 0) errors.push('El objetivo debe ser mayor que cero.');
      if (form.fecha.value && !form.id.value && form.fecha.value < App.today()) errors.push('La fecha deseada debe ser futura.');
      if (errors.length) { App.formError($('#goalError'), { errors }); return; }
      const body = { tipgasxx: App.state.account, nombre: form.nombre.value.trim(), objetivo: F.centsToDecimal(cents), fecha: form.fecha.value || null };
      await App.busy(form.querySelector('[type="submit"]'), async () => {
        try {
          if (form.id.value) await App.api('PUT', 'api/goals.php', { body: Object.assign({ id: Number(form.id.value) }, body) });
          else await App.api('POST', 'api/goals.php', { body: Object.assign({ action: 'create' }, body), idempotent: this.key });
          App.modal.close('goalModal');
          App.toast('success', form.id.value ? 'Meta actualizada' : 'Meta creada', body.nombre);
          App.dataChanged('goals');
        } catch (err) { App.formError($('#goalError'), err); }
      });
    },
  };

  const GoalMoveModal = {
    key: null,
    goal: null,
    open(g, tipo) {
      const form = $('#goalMoveForm');
      form.reset();
      App.formError($('#goalMoveError'), null);
      this.key = App.uid();
      this.goal = g;
      form.meta_id.value = g.id;
      form.tipo.value = tipo;
      form.fecha.value = App.today();
      form.fecha.max = App.today();
      this.labels();
      App.modal.open('goalMoveModal', { focus: '#goalMoveAmount' });
    },
    labels() {
      const form = $('#goalMoveForm');
      const ret = form.tipo.value === 'RETIRO';
      $('#goalMoveTitle').textContent = (ret ? 'Retirar de ' : 'Aportar a ') + this.goal.name;
      $('#goalMoveSub').textContent = `Llevas ${money(this.goal.saved)} de ${money(this.goal.target)}.` + (ret ? ' El retiro libera ese dinero en tu saldo disponible.' : ' El aporte no es un gasto: queda reservado dentro de tu saldo.');
    },
    async submit(e) {
      e.preventDefault();
      const form = $('#goalMoveForm');
      const cents = F.parseAmount(form.monto.value);
      const errors = [];
      if (!cents || cents <= 0) errors.push('Escribe un monto mayor que cero.');
      if (form.tipo.value === 'RETIRO' && cents > this.goal.saved) errors.push(`No puedes retirar más de lo ahorrado (${money(this.goal.saved)}).`);
      if (!F.isValidISODate(form.fecha.value) || form.fecha.value > App.today()) errors.push('La fecha no puede ser futura.');
      if (errors.length) { App.formError($('#goalMoveError'), { errors }); return; }
      const body = { action: 'movement', meta_id: Number(form.meta_id.value), tipo: form.tipo.value, monto: F.centsToDecimal(cents), fecha: form.fecha.value, descripcion: form.descripcion.value.trim() };
      await App.busy(form.querySelector('[type="submit"]'), async () => {
        try {
          const res = await App.api('POST', 'api/goals.php', { body, idempotent: this.key });
          App.modal.close('goalMoveModal');
          App.toast('success', res.message || 'Registrado', `Ahora llevas ${money(res.data.saved)} en «${this.goal.name}».`);
          App.dataChanged('goals');
        } catch (err) { App.formError($('#goalMoveError'), err); }
      });
    },
  };

  async function goalHistory(id) {
    try {
      const res = await App.api('GET', 'api/goals.php?id=' + Number(id));
      const g = res.data.goal;
      $('#goalDetailTitle').textContent = g.name;
      $('#goalDetailBody').innerHTML = `<p class="text-sm" style="margin-bottom:12px">${money(g.saved)} de ${money(g.target)} · creada el ${F.formatDate(g.created)}</p>` +
        (res.data.movements.length ? `<div class="list">${res.data.movements.map((m) => `
          <div class="list-row"><span class="list-icon ${m.cents > 0 ? 'savings' : 'expense'}"><i class="bi ${m.cents > 0 ? 'bi-piggy-bank' : 'bi-box-arrow-up-right'}" aria-hidden="true"></i></span>
          <span class="list-body"><span class="list-title">${m.cents > 0 ? 'Aporte' : 'Retiro'}${m.description ? ' · ' + esc(m.description) : ''}</span><span class="list-sub">${F.formatDate(m.date, 'long')}</span></span>
          <span class="list-end amount ${m.cents > 0 ? 'positive' : 'negative'}">${money(m.cents, { signed: true })}</span></div>`).join('')}</div>`
          : emptyState('bi-clock-history', 'Sin movimientos', 'Registra tu primer aporte.')) +
        `<div class="flex gap-2 mt-4"><button class="btn-ghost btn-sm" type="button" data-goal-delete="${g.id}" style="color:var(--danger)"><i class="bi bi-trash" aria-hidden="true"></i>Eliminar meta</button></div>`;
      App.modal.open('goalDetailModal');
    } catch (err) { App.toastError(err); }
  }

  /* ============================ PERFIL Y AJUSTES ============================ */
  const Profile = {
    async load() {
      App.theme.set(App.theme.get());
      try {
        const res = await App.api('GET', 'api/profile.php', { key: 'profile' });
        const p = res.data;
        $('#pfCedula').value = p.cedula;
        $('#pfNombre').value = p.nombre || '';
        $('#pfEmail').value = p.email || '';
        $('#pfCelular').value = p.celular || '';
      } catch (err) { App.toastError(err, 'No se pudo cargar tu perfil'); }
      if (App.config.isAdmin) this.migrations();
    },
    async migrations() {
      const box = $('#migrationStatus');
      try {
        const res = await App.api('GET', 'api/migrate.php');
        const pending = res.data.pending;
        box.innerHTML = res.data.migrations.map((m) => `<div><i class="bi ${m.applied ? 'bi-check-circle text-success' : 'bi-circle'}" aria-hidden="true"></i> ${esc(m.description)} — ${m.applied ? 'aplicada' : '<strong>pendiente</strong>'}</div>`).join('');
        $('[data-action="run-migrations"]').disabled = pending === 0;
      } catch (err) { box.textContent = err.message; }
    },
    async runMigrations(btn) {
      const ok = await App.confirm('Se crearán las tablas de presupuestos y metas si no existen. No se modifica ni se borra ningún dato existente. Recomendado: tener una copia de seguridad reciente.', { title: 'Aplicar migraciones', confirmLabel: 'Aplicar', danger: false });
      if (!ok) return;
      await App.busy(btn, async () => {
        try {
          const res = await App.api('POST', 'api/migrate.php');
          App.toast('success', 'Migraciones aplicadas', (res.data.details || []).join(' '));
          this.migrations();
          App.dataChanged('all');
        } catch (err) { App.toastError(err, 'No se pudieron aplicar'); }
      });
    },
    async saveProfile(e) {
      e.preventDefault();
      const form = $('#profileForm');
      const body = { nombre: $('#pfNombre').value.trim(), email: $('#pfEmail').value.trim(), celular: $('#pfCelular').value.trim() };
      if (!body.nombre || !$('#pfEmail').checkValidity() || !body.email) { App.toast('error', 'Revisa tus datos', 'Nombre y un email válido son obligatorios.'); return; }
      await App.busy(form.querySelector('[type="submit"]'), async () => {
        try {
          await App.api('PUT', 'api/profile.php', { body });
          App.toast('success', 'Perfil actualizado');
          const nameEl = $('.user-card-name');
          if (nameEl) nameEl.textContent = body.nombre;
        } catch (err) { App.toastError(err, 'No se pudo guardar'); }
      });
    },
    async savePassword(e) {
      e.preventDefault();
      const form = $('#passwordForm');
      const oldP = $('#pwOld').value;
      const n1 = $('#pwNew').value;
      const n2 = $('#pwNew2').value;
      if (n1.length < 8) { App.toast('error', 'Contraseña muy corta', 'Usa al menos 8 caracteres.'); return; }
      if (n1 !== n2) { App.toast('error', 'Las contraseñas no coinciden'); return; }
      await App.busy(form.querySelector('[type="submit"]'), async () => {
        try {
          await App.api('PUT', 'api/profile.php', { body: { nombre: $('#pfNombre').value.trim(), email: $('#pfEmail').value.trim(), celular: $('#pfCelular').value.trim(), old_password: oldP, new_password: n1 } });
          form.reset();
          App.toast('success', 'Contraseña actualizada', 'Úsala la próxima vez que inicies sesión.');
        } catch (err) { App.toastError(err, 'No se pudo cambiar'); }
      });
    },
  };

  /* ============================== EVENTOS ============================== */
  document.addEventListener('click', (e) => {
    const by = (s) => e.target.closest(s);
    let el;
    if ((el = by('[data-budget-edit]'))) { BudgetModal.open(Budgets.find(el.dataset.budgetEdit)); return; }
    if ((el = by('[data-budget-suggest]'))) { BudgetModal.open(null, { category: el.dataset.budgetSuggest, spent: Number(el.dataset.spent) }); return; }
    if ((el = by('[data-goal-move]'))) { GoalMoveModal.open(Goals.find(el.dataset.goalMove), el.dataset.tipo); return; }
    if ((el = by('[data-goal-edit]'))) { GoalModal.open(Goals.find(el.dataset.goalEdit)); return; }
    if ((el = by('[data-goal-history]'))) { goalHistory(el.dataset.goalHistory); return; }
    if ((el = by('[data-goal-state]'))) {
      const id = Number(el.dataset.goalState);
      const state = el.dataset.state;
      App.api('PUT', 'api/goals.php', { body: { id, estado: state } })
        .then((r) => { App.toast('success', r.message || 'Meta actualizada', state === 'ARCHIVADA' ? 'Lo ahorrado sigue apartado; reactívala cuando quieras.' : ''); App.dataChanged('goals'); })
        .catch((err) => App.toastError(err));
      return;
    }
    if ((el = by('[data-goal-delete]'))) {
      const id = Number(el.dataset.goalDelete);
      App.confirm('Se eliminará la meta y su historial de aportes. El dinero apartado volverá a figurar como disponible libre. Esta acción no se puede deshacer desde la aplicación.', { title: 'Eliminar meta', confirmLabel: 'Eliminar' }).then(async (ok) => {
        if (!ok) return;
        try { await App.api('DELETE', 'api/goals.php?id=' + id); App.modal.close('goalDetailModal'); App.toast('success', 'Meta eliminada'); App.dataChanged('goals'); } catch (err) { App.toastError(err); }
      });
      return;
    }
    if ((el = by('[data-theme-value]'))) { App.theme.set(el.dataset.themeValue); return; }
    const action = (el = by('[data-action]')) ? el.dataset.action : null;
    switch (action) {
      case 'new-budget': BudgetModal.open(null); break;
      case 'budget-delete': BudgetModal.remove(); break;
      case 'budget-prev': case 'budget-next': {
        const d = F.parseISODate(Budgets.month + '-01');
        d.setMonth(d.getMonth() + (action === 'budget-prev' ? -1 : 1));
        Budgets.month = F.toISODate(d).slice(0, 7);
        Budgets.load();
        break;
      }
      case 'reload-budgets': Budgets.load(); break;
      case 'new-goal': GoalModal.open(null); break;
      case 'reload-goals': Goals.load(); break;
      case 'run-migrations': Profile.runMigrations(el); break;
      default: break;
    }
  });

  document.addEventListener('DOMContentLoaded', () => {
    $('#budgetForm').addEventListener('submit', (e) => BudgetModal.submit(e));
    $('#budgetAlert').addEventListener('input', (e) => { $('#budgetAlertVal').textContent = e.target.value; });
    $('#goalForm').addEventListener('submit', (e) => GoalModal.submit(e));
    $('#goalForm').addEventListener('input', App.debounce(() => GoalModal.preview(), 200));
    $('#goalMoveForm').addEventListener('submit', (e) => GoalMoveModal.submit(e));
    $('#goalMoveForm').addEventListener('change', (e) => { if (e.target.name === 'tipo') GoalMoveModal.labels(); });
    $('#goalsShowArchived').addEventListener('change', () => Goals.load());
    $('#profileForm').addEventListener('submit', (e) => Profile.saveProfile(e));
    $('#passwordForm').addEventListener('submit', (e) => Profile.savePassword(e));
    ['#budgetAmount', '#goalTarget', '#goalMoveAmount'].forEach((s) => App.moneyInput($(s)));
  });

  App.views = Object.assign(App.views || {}, { presupuestos: Budgets, metas: Goals, perfil: Profile });
})();

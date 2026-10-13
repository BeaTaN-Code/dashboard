/* dashboard/js/finance.js
 * Vistas financieras: Resumen, Movimientos, Deudas, reporte, exportación, notificaciones y calendario.
 * Las cifras siempre vienen del servidor; la interfaz nunca muestra un saldo como guardado
 * si la operación falló.
 */
(function () {
  'use strict';
  const App = window.App;
  const F = window.FinanceCore;
  const { esc, $, $$, money } = App;

  const CATEGORY_DEFAULTS = {
    PERSONAL: ['Alimentacion', 'Transporte', 'Vivienda', 'Servicios', 'Salud', 'Educacion', 'Entretenimiento', 'Ropa', 'Mascotas', 'Salario', 'Freelance', 'Inversiones', 'Otros'],
    BEATAN: ['Ventas', 'Servicios', 'Salarios', 'Marketing', 'Infraestructura', 'Operaciones', 'Impuestos', 'Inversiones', 'Otros'],
  };
  const CAT_ICONS = {
    alimentacion: 'bi-basket', transporte: 'bi-bus-front', vivienda: 'bi-house', servicios: 'bi-lightning-charge', salud: 'bi-heart-pulse',
    educacion: 'bi-mortarboard', entretenimiento: 'bi-film', salario: 'bi-briefcase', freelance: 'bi-laptop', inversiones: 'bi-graph-up-arrow',
    deuda: 'bi-credit-card', ventas: 'bi-shop', salarios: 'bi-people', marketing: 'bi-megaphone', infraestructura: 'bi-hdd-network',
    impuestos: 'bi-bank', ropa: 'bi-bag', mascotas: 'bi-emoji-smile', varios: 'bi-three-dots',
  };
  const normCat = (c) => String(c || '').toLowerCase().normalize('NFD').replace(/[̀-ͯ]/g, '');
  App.catIcon = (cat, cents) => CAT_ICONS[normCat(cat)] || (cents > 0 ? 'bi-arrow-down-left' : 'bi-arrow-up-right');
  App.categories = { known: new Set() };
  App.refreshCategoryOptions = function (extra) {
    (extra || []).forEach((c) => c && App.categories.known.add(c));
    const all = new Set([...CATEGORY_DEFAULTS[App.state.account], ...App.categories.known]);
    const sorted = [...all].sort((a, b) => a.localeCompare(b, 'es'));
    const dl = $('#categoryOptions');
    if (dl) dl.innerHTML = sorted.map((c) => `<option value="${esc(c)}"></option>`).join('');
    const sel = $('#txCategory');
    if (sel) {
      const cur = sel.value;
      sel.innerHTML = '<option value="">Todas</option>' + sorted.map((c) => `<option value="${esc(c)}">${esc(c)}</option>`).join('');
      sel.value = sorted.includes(cur) ? cur : '';
    }
  };

  const normalizeTx = (t) => ({
    id: Number(t.FINCTIDX),
    date: String(t.FINCFECX).slice(0, 10),
    cents: Number(t.MONTO_CENTS !== undefined ? t.MONTO_CENTS : F.parseAmount(String(t.MONTGASX))),
    cat: t.CATGASXX || '',
    desc: t.DESGASXX || '',
    debt: t.DEUDIDXX ? Number(t.DEUDIDXX) : null,
    debtName: t.DESDEUDX || '',
    account: t.TIPGASXX,
    owner: t.USRIDXXX,
    created: [t.REGFECXX, t.REGHORXX].filter(Boolean).join(' '),
    modified: [t.REGFECMX, t.REGHORMX].filter(Boolean).join(' '),
  });

  const deltaBadge = (pct, goodWhenUp) => {
    if (pct === null || pct === undefined) return '<span class="delta flat" title="Sin datos del periodo anterior para comparar">sin comparación</span>';
    if (Math.abs(pct) < 0.5) return '<span class="delta flat">sin cambio</span>';
    const up = pct > 0;
    const good = goodWhenUp ? up : !up;
    return `<span class="delta ${good ? 'good' : 'bad'}"><i class="bi bi-arrow-${up ? 'up' : 'down'}-right" aria-hidden="true"></i>${up ? '+' : '−'}${Math.abs(pct).toLocaleString('es-CO')} %<span class="sr-only"> frente al periodo anterior</span></span>`;
  };

  const emptyState = (icon, title, text, action) => `
    <div class="empty compact"><i class="bi ${icon}" aria-hidden="true"></i><h3>${esc(title)}</h3><p>${esc(text)}</p>
    ${action ? `<button class="btn-primary btn-sm" type="button" data-action="${action[0]}">${esc(action[1])}</button>` : ''}</div>`;
  const errorState = (err, retry) => `
    <div class="empty compact error"><i class="bi bi-exclamation-octagon" aria-hidden="true"></i><h3>No pudimos cargar esta información</h3>
    <p>${esc(err && err.message ? err.message : 'Error desconocido')}</p>
    <button class="btn-secondary btn-sm" type="button" data-action="${retry}">Reintentar</button></div>`;
  App.ui = { emptyState, errorState, deltaBadge };

  const txListRow = (t, today) => {
    const scheduled = t.date > today;
    return `<button type="button" class="list-row" data-tx="${t.id}">
      <span class="list-icon ${t.cents > 0 ? 'income' : 'expense'}"><i class="bi ${App.catIcon(t.cat, t.cents)}" aria-hidden="true"></i></span>
      <span class="list-body"><span class="list-title">${esc(t.desc || t.cat)}</span>
        <span class="list-sub">${esc(t.cat)} · ${F.formatDate(t.date, 'short')}</span></span>
      <span class="list-end"><span class="amount ${t.cents > 0 ? 'positive' : 'negative'}">${money(t.cents, { signed: true })}</span>${scheduled ? '<span class="list-sub">Programado</span>' : ''}</span>
    </button>`;
  };

  /* =============================== RESUMEN =============================== */
  const Overview = {
    data: null,
    async load() {
      const grid = $('#kpiGrid');
      grid.setAttribute('aria-busy', 'true');
      try {
        const res = await App.api('GET', `api/summary.php?tipgasxx=${App.state.account}&${App.periodQuery()}`, { key: 'summary' });
        this.data = res.data;
        this.render(res.data);
      } catch (err) {
        if (App.isAbort(err)) return;
        $('#overviewNotice').innerHTML = `<div class="alert alert-error mt-2" role="alert" style="margin-bottom:16px"><i class="bi bi-exclamation-octagon" aria-hidden="true"></i><div><strong>No pudimos cargar tu resumen.</strong> ${esc(err.message)} <button class="btn-ghost btn-sm" type="button" data-action="reload-overview">Reintentar</button></div></div>`;
        grid.innerHTML = '';
      } finally {
        grid.setAttribute('aria-busy', 'false');
      }
    },
    render(d) {
      App.setPeriodLabel(d.period);
      App.refreshCategoryOptions(d.categories.map((c) => c.category));
      $('#overviewNotice').innerHTML = (!d.goals.available || !d.budgets.available) && App.config.isAdmin
        ? '<div class="alert alert-info" style="margin-bottom:16px"><i class="bi bi-database-gear" aria-hidden="true"></i><div>Los módulos de <strong>presupuestos y metas</strong> requieren aplicar la migración v3. <a href="#perfil">Ir a Perfil y ajustes</a>.</div></div>'
        : '';
      this.renderKpis(d);
      this.renderBalanceChart(d);
      this.renderCategories(d);
      this.renderRecent(d);
      this.renderMonthly(d);
      this.renderInsights(d);
      this.renderProjection(d);
      this.renderUpcoming(d);
      this.renderBudgets(d);
      this.renderGoals(d);
      this.renderDebts(d);
      App.calendar.setPayments(d.upcoming.items);
    },
    renderKpis(d) {
      const t = d.totals;
      const periodName = d.period.label;
      const compare = d.period.in_progress ? 'vs. mismo tramo del periodo anterior' : 'vs. periodo anterior';
      $('#kpiGrid').innerHTML = `
        <article class="card kpi hero">
          <span class="kpi-label">Saldo disponible hoy ${App.helpButton('balance', '¿Qué es el saldo disponible?')}</span>
          <span class="kpi-value" data-kpi="balance">${money(d.balance.today)}</span>
          <span class="kpi-meta">${d.goals.saved > 0 ? `Libre: <strong>${money(d.balance.free)}</strong> · apartado en metas ${money(d.goals.saved)} ${App.helpButton('free', '¿Qué es disponible libre?')}` : 'Incluye todos tus movimientos hasta hoy'}</span>
        </article>
        <article class="card kpi">
          <span class="kpi-label"><span class="kpi-dot" style="color:var(--chart-income)" aria-hidden="true"></span>Ingresos · ${esc(periodName)} ${App.helpButton('income', '¿Cómo se calculan los ingresos?')}</span>
          <span class="kpi-value" data-kpi="income">${money(t.income)}</span>
          <span class="kpi-meta">${deltaBadge(d.variation.income, true)} ${compare}</span>
        </article>
        <article class="card kpi">
          <span class="kpi-label"><span class="kpi-dot" style="color:var(--chart-expense)" aria-hidden="true"></span>Gastos · ${esc(periodName)} ${App.helpButton('expense', '¿Cómo se calculan los gastos?')}</span>
          <span class="kpi-value" data-kpi="expense">${money(t.expense)}</span>
          <span class="kpi-meta">${deltaBadge(d.variation.expense, false)} ${compare}</span>
        </article>
        <article class="card kpi">
          <span class="kpi-label">Flujo neto ${App.helpButton('net', '¿Qué es el flujo neto?')}</span>
          <span class="kpi-value ${t.net < 0 ? 'negative' : ''}" data-kpi="net">${money(t.net, { signed: true })}</span>
          <span class="kpi-meta">${t.count} movimiento${t.count === 1 ? '' : 's'} realizado${t.count === 1 ? '' : 's'}</span>
        </article>`;
      const prev = this.prevKpis || {};
      ['balance', 'income', 'expense', 'net'].forEach((k) => {
        const el = $(`[data-kpi="${k}"]`);
        const val = k === 'balance' ? d.balance.today : t[k];
        if (prev[k] !== undefined) { el.dataset.value = prev[k]; App.animateValue(el, val, k === 'net' ? (c) => money(c, { signed: true }) : undefined); }
        else el.dataset.value = val;
      });
      this.prevKpis = { balance: d.balance.today, income: t.income, expense: t.expense, net: t.net };
      App.fitText($$('#kpiGrid .kpi-value'));
    },
    renderBalanceChart(d) {
      const pts = d.series.points;
      const legend = $('#chartBalanceLegend');
      legend.innerHTML = '<span><i class="swatch line" style="background:var(--chart-line)"></i>Saldo real</span>' +
        (d.projection.available ? '<span style="color:var(--chart-line)"><i class="swatch dashed"></i><span style="color:var(--text-2)">Proyección</span></span>' : '');
      $('#chartBalanceSubtitle').textContent = d.series.granularity === 'month' ? 'Saldo acumulado al cierre de cada mes' : 'Saldo acumulado día a día en el periodo';
      const box = $('#chartBalance').parentElement;
      if (!pts.length) {
        box.style.display = 'none';
        if (!$('#chartBalanceEmpty')) box.insertAdjacentHTML('afterend', `<div id="chartBalanceEmpty">${emptyState('bi-graph-up', 'Sin datos para graficar', 'Este periodo todavía no empieza o no tiene movimientos.')}</div>`);
        $('#chartBalanceSummary').textContent = 'Sin datos en el periodo.';
        return;
      }
      box.style.display = '';
      const e = $('#chartBalanceEmpty');
      if (e) e.remove();
      App.charts.balance('chartBalance', d);
      const first = pts[0].balance;
      const last = pts[pts.length - 1].balance;
      $('#chartBalanceSummary').textContent = `El saldo pasó de ${money(first)} a ${money(last)} entre ${F.formatDate(pts[0].date, 'long')} y ${F.formatDate(pts[pts.length - 1].date, 'long')}.` +
        (d.projection.available ? ` Proyección al cierre: ${money(d.projection.projected)}.` : '');
    },
    renderCategories(d) {
      const box = $('#categoryBars');
      if (!d.categories.length) { box.innerHTML = emptyState('bi-pie-chart', 'Sin gastos en el periodo', 'Cuando registres gastos verás aquí en qué categorías se concentran.', ['new-transaction', 'Registrar gasto']); return; }
      const top = d.categories.slice(0, 6);
      const rest = d.categories.slice(6);
      if (rest.length) top.push({ category: 'Otras (' + rest.length + ')', cents: rest.reduce((s, c) => s + c.cents, 0), share: Math.round(rest.reduce((s, c) => s + c.share, 0) * 10) / 10 });
      const max = top[0].cents;
      box.innerHTML = `<div class="cat-bars" role="list">${top.map((c) => `
        <div class="cat-bar" role="listitem">
          <span class="name">${esc(c.category)}</span>
          <span class="val num">${money(c.cents)}<small>${c.share.toLocaleString('es-CO')} %</small></span>
          <span class="bar" aria-hidden="true"><span style="--value:${Math.max(2, (c.cents / max) * 100)}%"></span></span>
        </div>`).join('')}</div>`;
    },
    renderRecent(d) {
      const box = $('#recentList');
      if (!d.recent.length) { box.innerHTML = emptyState('bi-receipt', 'Aún no tienes movimientos', 'Empieza registrando tu ingreso principal y tus gastos fijos (arriendo, servicios).', ['new-transaction', 'Registrar el primero']); return; }
      box.innerHTML = '<div class="list">' + d.recent.map((r) => txListRow({ id: r.id, date: r.date, cents: r.cents, cat: r.category, desc: r.description }, d.today)).join('') + '</div>';
    },
    renderMonthly(d) {
      App.charts.monthly('chartMonthly', d.monthly);
      $('#chartMonthlySummary').textContent = d.monthly.map((m) => `${F.monthLabel(m.month)}: ingresos ${money(m.income)}, gastos ${money(m.expense)}`).join('. ');
    },
    renderInsights(d) {
      const icons = { positive: 'bi-emoji-smile', warning: 'bi-exclamation-triangle', danger: 'bi-exclamation-octagon', info: 'bi-lightbulb' };
      $('#insightsList').innerHTML = d.insights.map((i) => `
        <div class="insight ${i.severity}">
          <i class="bi ${icons[i.severity] || 'bi-info-circle'}" aria-hidden="true"></i>
          <div>
            <div class="insight-title">${esc(i.title)}</div>
            <div class="insight-detail">${esc(i.detail)}</div>
            ${i.evidence ? `<div class="insight-evidence">${esc(i.evidence)}</div>` : ''}
          </div>
        </div>`).join('') || emptyState('bi-stars', 'Sin observaciones', 'Registra movimientos para recibir análisis.');
    },
    renderProjection(d) {
      const p = d.projection;
      const box = $('#projectionBox');
      if (!p.available) { box.innerHTML = `<p class="text-sm">${esc(p.reason)}</p>`; return; }
      const min = Math.min(p.low, 0);
      const max = Math.max(p.high, d.balance.today, 1);
      const pos = (v) => ((v - min) / (max - min)) * 100;
      box.innerHTML = `<div class="projection">
        <span class="kpi-label">Saldo estimado al ${F.formatDate(d.period.to, 'short')}</span>
        <span class="kpi-value ${p.projected < 0 ? 'negative' : ''}">${money(p.projected)}</span>
        <div class="projection-range" role="img" aria-label="Rango estimado entre ${money(p.low)} y ${money(p.high)}">
          <span class="band" style="left:${pos(p.low)}%;width:${Math.max(1, pos(p.high) - pos(p.low))}%"></span>
          ${min < 0 ? `<span class="zero" style="left:${pos(0)}%" title="Cero"></span>` : ''}
          <span class="point" style="left:${pos(p.projected)}%"></span>
        </div>
        <div class="projection-scale"><span>Peor caso ${money(p.low)}</span><span>Mejor caso ${money(p.high)}</span></div>
        <dl class="dl mt-3">
          <dt>Saldo hoy</dt><dd class="num">${money(d.balance.today)}</dd>
          <dt>Programado</dt><dd class="num">${money(p.scheduled_net, { signed: true })}</dd>
          <dt>Gasto estimado</dt><dd class="num">−${money(p.expected_spend)} <span class="muted">en ${p.days_remaining} días</span></dd>
          <dt>Confianza</dt><dd>${p.confidence === 'media' ? 'Media' : 'Baja'}</dd>
        </dl>
        <details class="mt-2 text-sm"><summary class="card-link" style="cursor:pointer">Supuestos</summary><ul class="mt-2" style="padding-left:18px;color:var(--text-2)">${p.assumptions.map((a) => `<li>${esc(a)}</li>`).join('')}</ul></details>
      </div>`;
    },
    renderUpcoming(d) {
      const box = $('#upcomingList');
      const items = d.upcoming.items;
      if (!items.length) { box.innerHTML = '<p class="text-sm">No tienes pagos programados en los próximos 30 días. Las cuotas de tus deudas aparecerán aquí automáticamente.</p>'; return; }
      box.innerHTML = `<p class="text-sm" style="margin-bottom:8px">${d.upcoming.count} programado${d.upcoming.count === 1 ? '' : 's'} · salen <strong class="num">${money(d.upcoming.expense)}</strong>${d.upcoming.income ? `, entran <strong class="num">${money(d.upcoming.income)}</strong>` : ''}</p>
        <div class="list">${items.slice(0, 5).map((u) => `
          <button type="button" class="list-row" data-tx="${u.id}">
            <span class="list-icon ${u.debt_id ? 'debt' : u.cents > 0 ? 'income' : 'expense'}"><i class="bi ${u.debt_id ? 'bi-credit-card' : App.catIcon(u.category, u.cents)}" aria-hidden="true"></i></span>
            <span class="list-body"><span class="list-title">${esc(u.description || u.category)}</span><span class="list-sub">${F.formatDate(u.date, 'day')} · ${F.relativeDay(u.date, d.today)}</span></span>
            <span class="list-end amount ${u.cents > 0 ? 'positive' : 'negative'}">${money(u.cents, { signed: true })}</span>
          </button>`).join('')}</div>`;
    },
    renderBudgets(d) {
      const box = $('#budgetsMini');
      if (!d.budgets.available) { box.innerHTML = '<p class="text-sm">Módulo pendiente de activación.</p>'; return; }
      if (!d.budgets.items.length) { box.innerHTML = emptyState('bi-pie-chart', 'Sin presupuestos', 'Define cuánto quieres gastar como máximo en tus categorías principales.', ['new-budget', 'Crear presupuesto']); return; }
      box.innerHTML = '<div class="budget-list">' + d.budgets.items.slice(0, 4).map(App.budgetRow).join('') + '</div>';
    },
    renderGoals(d) {
      const box = $('#goalsMini');
      if (!d.goals.available) { box.innerHTML = '<p class="text-sm">Módulo pendiente de activación.</p>'; return; }
      if (!d.goals.items.length) { box.innerHTML = emptyState('bi-piggy-bank', 'Sin metas', 'Ahorrar con un objetivo concreto es más fácil. Empieza con un fondo de emergencia.', ['new-goal', 'Crear meta']); return; }
      box.innerHTML = '<div class="budget-list">' + d.goals.items.map((g) => {
        const pct = g.plan.pct;
        return `<div class="budget-item">
          <div class="budget-head"><span class="budget-name"><span>${esc(g.name)}</span></span><span class="num text-sm fw-600">${pct.toLocaleString('es-CO')} %</span></div>
          <div class="progress savings" role="progressbar" aria-label="Avance de ${esc(g.name)}" aria-valuemin="0" aria-valuemax="100" aria-valuenow="${pct}"><span style="--value:${pct}%"></span></div>
          <div class="budget-meta"><span>${money(g.saved)} de ${money(g.target)}</span><span>${g.plan.per_month ? 'Sugerido: ' + money(g.plan.per_month) + '/mes' : g.plan.overdue ? 'Fecha vencida' : ''}</span></div>
        </div>`;
      }).join('') + '</div>';
    },
    renderDebts(d) {
      const box = $('#debtsMini');
      box.innerHTML = `<dl class="dl">
          <dt>Debes</dt><dd class="num amount negative">${money(d.debts.payable)}</dd>
          <dt>Te deben</dt><dd class="num amount positive">${money(d.debts.receivable)}</dd>
          <dt>Patrimonio estimado ${App.helpButton('networth', '¿Qué es el patrimonio estimado?')}</dt><dd class="num fw-600">${money(d.balance.net_worth)}</dd>
        </dl>` + (d.debts.items.length ? `<div class="list mt-3">${d.debts.items.slice(0, 3).map((x) => `
          <div class="list-row" style="flex-wrap:wrap">
            <span class="list-body"><span class="list-title">${esc(x.description)}</span><span class="list-sub">${x.type === 'EN CONTRA' ? 'Debes' : 'Te deben'} · faltan ${money(x.remaining)}</span></span>
            <span class="list-end text-sm num">${x.pct.toLocaleString('es-CO')} %</span>
            <div class="progress debt" style="width:100%" aria-hidden="true"><span style="--value:${x.pct}%"></span></div>
          </div>`).join('')}</div>` : '<p class="text-sm mt-3">No tienes deudas activas registradas.</p>');
    },
  };

  /** Fila de presupuesto (reutilizada en resumen y en la vista de presupuestos). */
  App.budgetRow = function (b) {
    const labels = { ok: ['En control', 'badge-success'], alerta: ['Cerca del límite', 'badge-warning'], riesgo: ['Podría excederse', 'badge-warning'], excedido: ['Excedido', 'badge-danger'] };
    const [label, cls] = labels[b.status] || ['', 'badge'];
    const pct = b.pct === null ? 0 : b.pct;
    const remaining = b.remaining;
    return `<div class="budget-item">
      <div class="budget-head">
        <span class="budget-name"><i class="bi ${App.catIcon(b.category, -1)} muted" aria-hidden="true"></i><span>${esc(b.category)}</span></span>
        <span class="badge ${cls}"><i class="bi ${b.status === 'ok' ? 'bi-check2' : 'bi-exclamation-triangle'}" aria-hidden="true"></i>${label}</span>
      </div>
      <div class="progress ${b.status}" role="progressbar" aria-label="Gastado en ${esc(b.category)}" aria-valuemin="0" aria-valuemax="100" aria-valuenow="${Math.min(100, pct)}" aria-valuetext="${money(b.spent)} de ${money(b.limit)}">
        <span style="--value:${Math.min(100, pct)}%"></span>
        ${b.alert_pct ? `<span class="marker" style="left:${b.alert_pct}%" title="Umbral de alerta ${b.alert_pct} %"></span>` : ''}
      </div>
      <div class="budget-meta">
        <span class="num">${money(b.spent)} de ${money(b.limit)} (${pct.toLocaleString('es-CO')} %)</span>
        <span class="num">${remaining >= 0 ? 'Quedan ' + money(remaining) : 'Te pasaste ' + money(-remaining)}</span>
      </div>
      ${b.status === 'riesgo' ? `<div class="budget-meta"><span><span class="tag-estimate">Estimación</span> al ritmo actual cerrarías en ${money(b.forecast)}</span></div>` : ''}
    </div>`;
  };

  /* ============================= MOVIMIENTOS ============================= */
  const Tx = {
    rows: [],
    today: App.today(),
    page: 1,
    sort: { key: 'date', dir: 'desc' },
    pageSize: 25,
    loadedKey: null,
    filters() {
      return {
        q: $('#txSearch').value.trim(),
        type: $('#txType').value,
        category: $('#txCategory').value,
        min: $('#txMin').value.trim() ? Math.abs(F.parseAmount($('#txMin').value) || 0) : null,
        max: $('#txMax').value.trim() ? Math.abs(F.parseAmount($('#txMax').value) || 0) : null,
      };
    },
    async load() {
      const p = App.currentPeriod();
      $('#txPeriodLabel').textContent = p.label + ' · ' + F.formatDate(p.from) + ' a ' + F.formatDate(p.to);
      const box = $('#txTable');
      if (!this.rows.length) box.innerHTML = '<div class="card-body">' + '<span class="skeleton sk-line"></span>'.repeat(6) + '</div>';
      try {
        const res = await App.api('GET', `api/transactions.php?tipgasxx=${App.state.account}&from=${p.from}&to=${p.to}`, { key: 'tx' });
        this.rows = res.data.map(normalizeTx);
        this.today = res.today || App.today();
        App.refreshCategoryOptions(res.categories || []);
        this.page = 1;
        this.render();
      } catch (err) {
        if (App.isAbort(err)) return;
        box.innerHTML = errorState(err, 'reload-tx');
        $('#txSummary').innerHTML = '';
        $('#txPagination').innerHTML = '';
      }
    },
    visible() {
      return F.sortTransactions(F.filterTransactions(this.rows, this.filters()), this.sort.key, this.sort.dir);
    },
    render() {
      const f = this.filters();
      const active = ['type', 'category', 'min', 'max'].filter((k) => f[k] !== '' && f[k] !== null).length;
      const fc = $('#txFilterCount');
      fc.hidden = active === 0;
      fc.textContent = active;
      const list = this.visible();
      const realized = list.filter((t) => t.date <= this.today);
      const inc = realized.filter((t) => t.cents > 0).reduce((s, t) => s + t.cents, 0);
      const exp = realized.filter((t) => t.cents < 0).reduce((s, t) => s - t.cents, 0);
      const scheduled = list.length - realized.length;
      $('#txCount').textContent = list.length;
      $('#txSummary').innerHTML = list.length ? `<span>Ingresos <strong class="num">${money(inc)}</strong></span><span>Gastos <strong class="num">${money(exp)}</strong></span><span>Neto <strong class="num">${money(inc - exp, { signed: true })}</strong></span>${scheduled ? `<span>${scheduled} programado${scheduled === 1 ? '' : 's'} (no suman al saldo aún)</span>` : ''}` : '';
      const box = $('#txTable');
      if (!list.length) {
        const filtered = this.rows.length > 0;
        box.innerHTML = filtered
          ? emptyState('bi-funnel', 'Ningún movimiento coincide', 'Prueba con otros filtros o límpialos para ver todo el periodo.', ['tx-clear-filters', 'Limpiar filtros'])
          : emptyState('bi-receipt', 'Sin movimientos en este periodo', 'Registra un ingreso o un gasto, o importa tu extracto bancario.', ['new-transaction', 'Nuevo movimiento']);
        $('#txPagination').innerHTML = '';
        return;
      }
      const pg = F.paginate(list, this.page, this.pageSize);
      this.page = pg.page;
      box.innerHTML = App.isMobile() ? this.renderList(pg.items) : this.renderTable(pg.items);
      $('#txPagination').innerHTML = pg.pages > 1 ? `
        <span>Mostrando ${(pg.page - 1) * this.pageSize + 1}–${Math.min(pg.page * this.pageSize, pg.total)} de ${pg.total}</span>
        <span class="pages">
          <button class="btn-secondary btn-sm" type="button" data-page="${pg.page - 1}" ${pg.page === 1 ? 'disabled' : ''} aria-label="Página anterior"><i class="bi bi-chevron-left" aria-hidden="true"></i></button>
          <span style="align-self:center;padding:0 8px">Página ${pg.page} de ${pg.pages}</span>
          <button class="btn-secondary btn-sm" type="button" data-page="${pg.page + 1}" ${pg.page === pg.pages ? 'disabled' : ''} aria-label="Página siguiente"><i class="bi bi-chevron-right" aria-hidden="true"></i></button>
        </span>` : `<span>${pg.total} movimiento${pg.total === 1 ? '' : 's'}</span>`;
    },
    sortHeader(key, label, cls) {
      const active = this.sort.key === key;
      const aria = active ? (this.sort.dir === 'asc' ? 'ascending' : 'descending') : 'none';
      const icon = active ? (this.sort.dir === 'asc' ? 'bi-sort-up' : 'bi-sort-down') : 'bi-arrow-down-up';
      return `<th scope="col" class="${cls || ''}" aria-sort="${aria}"><button type="button" class="th-sort ${active ? 'is-sorted' : ''}" data-sort="${key}">${label}<i class="bi ${icon}" aria-hidden="true"></i></button></th>`;
    },
    renderTable(items) {
      return `<div class="table-wrap"><table class="table">
        <caption class="sr-only">Movimientos del periodo. Usa los encabezados para ordenar.</caption>
        <thead><tr>${this.sortHeader('date', 'Fecha')}${this.sortHeader('description', 'Descripción')}${this.sortHeader('category', 'Categoría')}${this.sortHeader('amount', 'Monto', 't-right')}<th scope="col"><span class="sr-only">Acciones</span></th></tr></thead>
        <tbody>${items.map((t) => {
          const scheduled = t.date > this.today;
          return `<tr class="${scheduled ? 'row-scheduled' : ''}">
            <td class="num" style="white-space:nowrap">${F.formatDate(t.date)}${scheduled ? '<br><span class="badge badge-info">Programado</span>' : ''}</td>
            <td class="cell-desc"><button type="button" class="th-sort" data-tx="${t.id}" style="text-transform:none;letter-spacing:0;font-size:inherit;color:var(--text);text-align:left;display:block;max-width:100%"><span class="main">${esc(t.desc || '—')}</span></button>${t.debtName ? `<span class="sub"><i class="bi bi-link-45deg" aria-hidden="true"></i> ${esc(t.debtName)}</span>` : ''}</td>
            <td><span class="badge ${t.cents > 0 ? 'badge-income' : 'badge-outline'}">${esc(t.cat)}</span></td>
            <td class="t-right"><span class="amount ${t.cents > 0 ? 'positive' : 'negative'}">${money(t.cents, { signed: true })}</span></td>
            <td class="t-right"><div class="action-buttons">
              <button class="btn-icon" type="button" data-tx-edit="${t.id}" aria-label="Editar ${esc(t.desc || t.cat)}" title="Editar"><i class="bi bi-pencil" aria-hidden="true"></i></button>
              <button class="btn-icon" type="button" data-tx-dup="${t.id}" aria-label="Duplicar ${esc(t.desc || t.cat)}" title="Duplicar"><i class="bi bi-copy" aria-hidden="true"></i></button>
              <button class="btn-icon danger" type="button" data-tx-del="${t.id}" aria-label="Eliminar ${esc(t.desc || t.cat)}" title="Eliminar"><i class="bi bi-trash" aria-hidden="true"></i></button>
            </div></td>
          </tr>`;
        }).join('')}</tbody></table></div>`;
    },
    renderList(items) {
      let html = '<div class="card-body" style="padding-top:0"><div class="list">';
      let lastDate = null;
      items.forEach((t) => {
        if (t.date !== lastDate) { html += `<div class="list-group-title">${F.formatDate(t.date, 'day')}</div>`; lastDate = t.date; }
        html += txListRow(t, this.today);
      });
      return html + '</div></div>';
    },
    find(id) { return this.rows.find((t) => t.id === Number(id)); },
  };

  /* ===================== Modal de movimiento ===================== */
  const TxModal = {
    mode: 'create',
    key: null,
    original: null,
    async open(opts) {
      const o = opts || {};
      const form = $('#txForm');
      form.reset();
      App.formError($('#txError'), null);
      $('#txDuplicateWarn').hidden = true;
      this.mode = o.mode || 'create';
      this.original = o.tx || null;
      this.key = App.uid(); // misma clave en reintentos: el servidor no duplicará el registro
      const t = o.tx;
      $('#txModalTitle').textContent = { create: 'Nuevo movimiento', edit: 'Editar movimiento', duplicate: 'Duplicar movimiento' }[this.mode];
      $('#txModalHint').textContent = this.mode === 'duplicate' ? 'Revisa la fecha y guarda: se creará un movimiento nuevo.' : this.mode === 'edit' ? 'Los cambios se reflejan en tus saldos al guardar.' : 'Registra un ingreso o un gasto.';
      form.id.value = this.mode === 'edit' ? t.id : '';
      const accField = $('#txAccountField');
      accField.hidden = !(App.config.isAdmin && this.mode !== 'edit');
      form.tipgasxx.value = (t && t.account) || o.account || App.state.account;
      form.tipo.value = t ? (t.cents > 0 ? 'INGRESO' : 'GASTO') : (o.tipo || 'GASTO');
      App.setMoney(form.monto, t ? Math.abs(t.cents) : (o.cents || null));
      form.fecha.value = t && this.mode === 'edit' ? t.date : (o.fecha || App.today());
      form.categoria.value = t ? t.cat : (o.categoria || '');
      form.descripcion.value = t ? t.desc : (o.descripcion || '');
      this.updateHints();
      App.modal.open('txModal', { focus: '#txAmount' });
      // La lista de deudas carga en segundo plano: el formulario se puede usar de inmediato.
      this.loadDebts(form.tipgasxx.value, t ? t.debt : o.debtId);
    },
    async loadDebts(account, selected) {
      const sel = $('#txDebt');
      sel.innerHTML = '<option value="">Ninguna</option>';
      try {
        const res = await App.api('GET', 'api/deudas_select.php?tipperxx=' + account, { key: 'debt-select' });
        res.data.forEach((d) => {
          const op = document.createElement('option');
          op.value = d.DEUDIDXX;
          op.textContent = (d.TIPDEUDX === 'A FAVOR' ? 'Me deben · ' : 'Debo · ') + d.DESDEUDX;
          sel.appendChild(op);
        });
        if (selected) {
          if (![...sel.options].some((op) => op.value === String(selected))) {
            const op = document.createElement('option');
            op.value = selected;
            op.textContent = 'Deuda #' + selected + ' (cuotas)';
            sel.appendChild(op);
          }
          sel.value = String(selected);
        }
      } catch (e) { /* el selector queda con "Ninguna" */ }
    },
    updateHints() {
      const form = $('#txForm');
      $('#txFutureHint').hidden = !(form.fecha.value && form.fecha.value > App.today());
      const cents = F.parseAmount(form.monto.value);
      const warn = $('#txDuplicateWarn');
      warn.hidden = true;
      if (!cents || !form.fecha.value) return;
      const signed = form.tipo.value === 'GASTO' ? -Math.abs(cents) : Math.abs(cents);
      const editingId = this.mode === 'edit' && this.original ? this.original.id : null;
      const dup = Tx.rows.find((t) => t.date === form.fecha.value && t.cents === signed && t.id !== editingId);
      if (dup) {
        warn.innerHTML = `<i class="bi bi-exclamation-triangle" aria-hidden="true"></i><div>Ya registraste ${money(signed, { signed: true })} el ${F.formatDate(dup.date)}${dup.desc ? ' («' + esc(dup.desc) + '»)' : ''}. Si es un movimiento distinto, puedes guardarlo igual.</div>`;
        warn.hidden = false;
      }
    },
    async submit(e) {
      e.preventDefault();
      const form = $('#txForm');
      const errBox = $('#txError');
      const cents = F.parseAmount(form.monto.value);
      const errors = [];
      if (cents === null || cents === 0) errors.push('Escribe un monto válido mayor que cero.');
      if (!F.isValidISODate(form.fecha.value)) errors.push('Elige una fecha válida.');
      if (!form.categoria.value.trim()) errors.push('Indica una categoría.');
      form.monto.setAttribute('aria-invalid', String(cents === null || cents === 0));
      form.categoria.setAttribute('aria-invalid', String(!form.categoria.value.trim()));
      if (errors.length) { App.formError(errBox, { errors }); return; }
      App.formError(errBox, null);
      const body = {
        tipgasxx: form.tipgasxx.value,
        tipo: form.tipo.value,
        monto: F.centsToDecimal(Math.abs(cents)),
        fecha: form.fecha.value,
        categoria: form.categoria.value.trim(),
        descripcion: form.descripcion.value.trim(),
        deudidxx: form.deudidxx.value || null,
      };
      await App.busy($('#txSubmit'), async () => {
        try {
          if (this.mode === 'edit') await App.api('PUT', 'api/transactions.php', { body: Object.assign({ id: Number(form.id.value) }, body) });
          else await App.api('POST', 'api/transactions.php', { body, idempotent: this.key });
          App.modal.close('txModal');
          App.toast('success', this.mode === 'edit' ? 'Movimiento actualizado' : 'Movimiento guardado', `${body.tipo === 'GASTO' ? 'Gasto' : 'Ingreso'} de ${money(Math.abs(cents))} · ${body.categoria}`);
          App.categories.known.add(body.categoria);
          App.dataChanged('transactions');
        } catch (err) {
          App.formError(errBox, err);
        }
      });
    },
  };
  App.openTxModal = (opts) => TxModal.open(opts);

  /* ===================== Detalle y borrado ===================== */
  async function findTx(id) {
    let t = Tx.find(id);
    if (t) return t;
    // Movimiento fuera del periodo cargado (p. ej. un pago programado): se busca por cuenta.
    const res = await App.api('GET', `api/transactions.php?tipgasxx=${App.state.account}`);
    const row = res.data.find((x) => Number(x.FINCTIDX) === Number(id));
    return row ? normalizeTx(row) : null;
  }
  let detailTx = null;
  async function openDetail(id) {
    try {
      const t = await findTx(id);
      if (!t) { App.toast('warning', 'No encontrado', 'Ese movimiento ya no existe.'); return; }
      detailTx = t;
      const scheduled = t.date > App.today();
      $('#txDetailBody').innerHTML = `
        <div class="flex items-center gap-3" style="margin-bottom:16px">
          <span class="list-icon ${t.cents > 0 ? 'income' : 'expense'}"><i class="bi ${App.catIcon(t.cat, t.cents)}" aria-hidden="true"></i></span>
          <div><div class="kpi-value amount ${t.cents > 0 ? 'positive' : 'negative'}">${money(t.cents, { signed: true })}</div>
          <div class="muted text-sm">${t.cents > 0 ? 'Ingreso' : 'Gasto'} · ${scheduled ? 'Programado (aún no afecta el saldo)' : 'Realizado'}</div></div>
        </div>
        <dl class="dl">
          <dt>Fecha</dt><dd>${F.formatDate(t.date, 'long')}</dd>
          <dt>Categoría</dt><dd>${esc(t.cat)}</dd>
          <dt>Descripción</dt><dd>${esc(t.desc || '—')}</dd>
          <dt>Cuenta</dt><dd>${t.account === 'BEATAN' ? 'Empresa (BeaTaN)' : 'Personal'}</dd>
          ${t.debt ? `<dt>Deuda vinculada</dt><dd>${esc(t.debtName || '#' + t.debt)}</dd>` : ''}
          ${t.created ? `<dt>Registrado</dt><dd>${esc(t.created)}</dd>` : ''}
          ${t.modified ? `<dt>Última edición</dt><dd>${esc(t.modified)}</dd>` : ''}
        </dl>`;
      App.modal.open('txDetailModal');
    } catch (err) { App.toastError(err); }
  }
  async function deleteTx(id) {
    const t = Tx.find(id) || detailTx;
    const ok = await App.confirm(t ? `Se eliminará «${t.desc || t.cat}» por ${money(t.cents, { signed: true })}. Podrás deshacerlo durante unos segundos.` : '¿Eliminar este movimiento?', { title: 'Eliminar movimiento', confirmLabel: 'Eliminar' });
    if (!ok) return;
    try {
      await App.api('DELETE', 'api/transactions.php?id=' + Number(id));
      App.modal.close('txDetailModal');
      App.dataChanged('transactions');
      App.toast('success', 'Movimiento eliminado', t ? (t.desc || t.cat) : '', {
        action: {
          label: 'Deshacer',
          onClick: async () => {
            try {
              await App.api('POST', 'api/transactions.php', { body: { action: 'restore', id: Number(id) } });
              App.toast('info', 'Movimiento restaurado');
              App.dataChanged('transactions');
            } catch (err) { App.toastError(err, 'No se pudo deshacer'); }
          },
        },
      });
    } catch (err) { App.toastError(err, 'No se pudo eliminar'); }
  }

  /* =============================== DEUDAS =============================== */
  const Debts = {
    rows: [],
    async load() {
      const box = $('#debtList');
      const state = $('#debtState').value;
      try {
        const q = `tipperxx=${App.state.account}&regestxx=${encodeURIComponent(state)}&tipdeudx=${encodeURIComponent($('#debtType').value)}&search=${encodeURIComponent($('#debtSearch').value.trim())}`;
        const res = await App.api('GET', 'api/debts.php?' + q, { key: 'debts' });
        this.rows = res.data;
        this.render(state);
      } catch (err) {
        if (App.isAbort(err)) return;
        box.innerHTML = errorState(err, 'reload-debts');
      }
    },
    render(state) {
      const rows = this.rows;
      const active = state === 'ACTIVO';
      const payable = rows.filter((d) => d.TIPDEUDX === 'EN CONTRA').reduce((s, d) => s + d.RESTANTE_CENTS, 0);
      const receivable = rows.filter((d) => d.TIPDEUDX === 'A FAVOR').reduce((s, d) => s + d.RESTANTE_CENTS, 0);
      $('#debtSummary').innerHTML = active ? `
        <article class="card kpi"><span class="kpi-label">Debes en total</span><span class="kpi-value amount negative">${money(payable)}</span><span class="kpi-meta">Saldo pendiente de deudas en contra</span></article>
        <article class="card kpi"><span class="kpi-label">Te deben en total</span><span class="kpi-value amount positive">${money(receivable)}</span><span class="kpi-meta">Saldo pendiente de préstamos a favor</span></article>
        <article class="card kpi"><span class="kpi-label">Balance de deudas</span><span class="kpi-value">${money(receivable - payable, { signed: true })}</span><span class="kpi-meta">Lo que te deben menos lo que debes</span></article>
        <article class="card kpi"><span class="kpi-label">Deudas activas</span><span class="kpi-value">${rows.length}</span><span class="kpi-meta">${rows.filter((d) => d.PROXIMO_PAGO).length} con cuotas programadas</span></article>` : '';
      App.fitText($$('#debtSummary .kpi-value'));
      const box = $('#debtList');
      if (!rows.length) {
        box.innerHTML = active
          ? emptyState('bi-credit-card-2-front', 'No tienes deudas activas', 'Registra tus créditos, préstamos o lo que te deben para ver cuánto falta y cuándo pagar.', ['new-debt', 'Registrar deuda'])
          : emptyState('bi-inbox', 'Nada por aquí', 'No hay deudas con este filtro.');
        return;
      }
      const today = App.today();
      box.innerHTML = '<div class="budget-list">' + rows.map((d) => {
        const total = d.TOTAL_CENTS;
        const paid = d.PAGADO_CENTS;
        const pct = total > 0 ? Math.min(100, Math.round((paid / total) * 1000) / 10) : 0;
        const favor = d.TIPDEUDX === 'A FAVOR';
        const cuotas = d.NUMCUOTX !== null && d.NUMCUOTX !== '';
        const editable = d.REGESTXX === 'ACTIVO';
        return `<article class="card" style="padding:16px">
          <div class="budget-head" style="flex-wrap:wrap">
            <div class="budget-name" style="min-width:0;flex:1">
              <span class="list-icon ${favor ? 'income' : 'debt'}"><i class="bi ${favor ? 'bi-person-check' : 'bi-credit-card'}" aria-hidden="true"></i></span>
              <span style="min-width:0"><span class="list-title" style="display:block">${esc(d.DESDEUDX)}</span>
              <span class="list-sub" style="display:block">${favor ? 'Te deben' : 'Debes'} · ${cuotas ? `${Number(d.NUMCUOTX)} cuotas de ${money(F.parseAmount(String(d.MONCUOTX)) || 0)} · día ${esc(d.FECCUOTX)}` : 'Abonos libres'}${d.PROXIMO_PAGO ? ` · próxima cuota ${F.formatDate(d.PROXIMO_PAGO, 'short')} (${F.relativeDay(d.PROXIMO_PAGO, today)})` : ''}</span></span>
            </div>
            <div class="list-end">
              <div class="num fw-600">${money(d.RESTANTE_CENTS)}</div>
              <div class="text-xs muted">pendiente de ${money(total)}</div>
            </div>
          </div>
          <div class="progress ${favor ? 'ok' : 'debt'} mt-3" role="progressbar" aria-label="Pagado de ${esc(d.DESDEUDX)}" aria-valuemin="0" aria-valuemax="100" aria-valuenow="${pct}" aria-valuetext="${money(paid)} pagado de ${money(total)}"><span style="--value:${pct}%"></span></div>
          <div class="budget-meta mt-2"><span>${money(paid)} pagado (${pct.toLocaleString('es-CO')} %)</span><span>${d.REGESTXX === 'PAGADO' ? '<span class="badge badge-success">Pagada</span>' : d.REGESTXX === 'INACTIVO' ? '<span class="badge badge-outline">Eliminada</span>' : ''}</span></div>
          <div class="flex gap-2 wrap mt-3">
            ${editable && !cuotas ? `<button class="btn-secondary btn-sm" type="button" data-debt-pay="${d.DEUDIDXX}"><i class="bi bi-cash-coin" aria-hidden="true"></i>${favor ? 'Registrar cobro' : 'Registrar pago'}</button>` : ''}
            <button class="btn-ghost btn-sm" type="button" data-debt-history="${d.DEUDIDXX}"><i class="bi bi-clock-history" aria-hidden="true"></i>Historial</button>
            ${editable ? `<button class="btn-ghost btn-sm" type="button" data-debt-edit="${d.DEUDIDXX}"><i class="bi bi-pencil" aria-hidden="true"></i>Editar</button>
            <button class="btn-ghost btn-sm" type="button" data-debt-paid="${d.DEUDIDXX}"><i class="bi bi-check2-circle" aria-hidden="true"></i>Marcar pagada</button>` : ''}
            ${d.REGESTXX !== 'INACTIVO' ? `<button class="btn-ghost btn-sm" type="button" data-debt-del="${d.DEUDIDXX}" style="color:var(--danger)"><i class="bi bi-trash" aria-hidden="true"></i>Eliminar</button>` : ''}
          </div>
        </article>`;
      }).join('') + '</div>';
    },
    find(id) { return this.rows.find((d) => Number(d.DEUDIDXX) === Number(id)); },
  };

  const DebtModal = {
    key: null,
    open(d) {
      const form = $('#debtForm');
      form.reset();
      App.formError($('#debtError'), null);
      this.key = App.uid();
      $('#debtModalTitle').textContent = d ? 'Editar deuda' : 'Nueva deuda';
      form.id.value = d ? d.DEUDIDXX : '';
      if (d) {
        form.tipdeudx.value = d.TIPDEUDX;
        form.desdeudx.value = d.DESDEUDX;
        App.setMoney(form.mondeudx, d.TOTAL_CENTS);
        const cuotas = d.NUMCUOTX !== null && d.NUMCUOTX !== '';
        form.modo.value = cuotas ? 'cuotas' : 'abonos';
        form.numcuotx.value = cuotas ? d.NUMCUOTX : '';
        form.feccuotx.value = d.FECCUOTX || '';
        App.setMoney(form.moncuotx, cuotas ? F.parseAmount(String(d.MONCUOTX)) : null);
      }
      this.toggleMode();
      App.modal.open('debtModal', { focus: '#debtDesc' });
    },
    toggleMode() {
      const form = $('#debtForm');
      const cuotas = form.modo.value === 'cuotas';
      $('#debtCuotasFields').hidden = !cuotas;
      $('#debtModeHint').textContent = cuotas
        ? 'Cada mes se programará automáticamente una cuota como gasto (o ingreso, si te deben) en el día indicado.'
        : 'Registra cada pago con el botón «Registrar pago»: el avance se actualiza solo.';
    },
    autoCuota() {
      const form = $('#debtForm');
      const total = F.parseAmount(form.mondeudx.value);
      const n = parseInt(form.numcuotx.value, 10);
      if (total && n > 0 && !form.moncuotx.dataset.touched) App.setMoney(form.moncuotx, Math.ceil(total / n));
    },
    async submit(e) {
      e.preventDefault();
      const form = $('#debtForm');
      const cuotas = form.modo.value === 'cuotas';
      const total = F.parseAmount(form.mondeudx.value);
      const errors = [];
      if (!form.desdeudx.value.trim()) errors.push('Describe la deuda.');
      if (!total || total <= 0) errors.push('El monto total debe ser mayor que cero.');
      if (cuotas) {
        const n = parseInt(form.numcuotx.value, 10);
        const day = parseInt(form.feccuotx.value, 10);
        if (!(n >= 1 && n <= 600)) errors.push('Indica entre 1 y 600 cuotas.');
        if (!(day >= 1 && day <= 31)) errors.push('El día de pago debe estar entre 1 y 31.');
      }
      if (errors.length) { App.formError($('#debtError'), { errors }); return; }
      const cuota = cuotas ? F.parseAmount(form.moncuotx.value) : null;
      const body = {
        tipperxx: App.state.account,
        tipdeudx: form.tipdeudx.value,
        desdeudx: form.desdeudx.value.trim(),
        mondeudx: F.centsToDecimal(total),
        numcuotx: cuotas ? parseInt(form.numcuotx.value, 10) : null,
        feccuotx: cuotas ? parseInt(form.feccuotx.value, 10) : null,
        moncuotx: cuotas && cuota ? F.centsToDecimal(cuota) : null,
      };
      const btn = form.querySelector('[type="submit"]');
      await App.busy(btn, async () => {
        try {
          if (form.id.value) await App.api('PUT', 'api/debts.php', { body: Object.assign({ id: Number(form.id.value) }, body) });
          else await App.api('POST', 'api/debts.php', { body, idempotent: this.key });
          App.modal.close('debtModal');
          App.toast('success', form.id.value ? 'Deuda actualizada' : 'Deuda registrada', body.desdeudx + (cuotas ? ' · las cuotas se programarán en la próxima ejecución automática.' : ''));
          App.dataChanged('debts');
        } catch (err) { App.formError($('#debtError'), err); }
      });
    },
  };

  async function debtHistory(id) {
    const d = Debts.find(id);
    try {
      const res = await App.api('GET', `api/transactions.php?tipgasxx=${App.state.account}`);
      const rows = res.data.map(normalizeTx).filter((t) => t.debt === Number(id)).sort((a, b) => (a.date < b.date ? 1 : -1));
      const today = App.today();
      $('#goalDetailTitle').textContent = d ? d.DESDEUDX : 'Historial de la deuda';
      $('#goalDetailBody').innerHTML = rows.length
        ? `<p class="text-sm" style="margin-bottom:8px">${rows.length} movimiento(s) vinculado(s). Los programados aún no cuentan como pagados.</p><div class="list">${rows.map((t) => txListRow(t, today)).join('')}</div>`
        : emptyState('bi-clock-history', 'Sin pagos registrados', 'Cuando registres pagos o se generen cuotas, aparecerán aquí.');
      App.modal.open('goalDetailModal');
    } catch (err) { App.toastError(err); }
  }

  /* ============================ EXPORTACIÓN ============================ */
  async function exportCsv() {
    const p = App.currentPeriod();
    try {
      const res = await fetch(`api/export.php?tipgasxx=${App.state.account}&from=${p.from}&to=${p.to}`, { credentials: 'same-origin' });
      if (!res.ok) {
        let msg = 'No se pudo exportar.';
        try { msg = (await res.json()).error || msg; } catch (e) { /* respuesta no JSON */ }
        throw new Error(msg);
      }
      const blob = await res.blob();
      const url = URL.createObjectURL(blob);
      const a = document.createElement('a');
      a.href = url;
      a.download = `movimientos_${App.state.account.toLowerCase()}_${p.from}_a_${p.to}.csv`;
      document.body.appendChild(a);
      a.click();
      a.remove();
      setTimeout(() => URL.revokeObjectURL(url), 1000);
      App.toast('success', 'Exportación lista', 'Se descargó el CSV del periodo ' + p.label + '. Guárdalo en un lugar seguro: contiene datos financieros.');
    } catch (err) { App.toastError(err, 'No se pudo exportar'); }
  }

  /* ============================== REPORTE ============================== */
  const Report = {
    data: null,
    open() {
      const p = App.currentPeriod();
      $('#reportMonth').value = p.from.slice(0, 7);
      App.modal.open('reportModal');
      this.load();
    },
    async load() {
      const month = $('#reportMonth').value || App.today().slice(0, 7);
      $('#reportTitle').textContent = '· ' + (App.state.account === 'BEATAN' ? 'Empresa' : 'Personal') + ' · ' + F.monthLabel(month);
      const box = $('#reportContent');
      box.innerHTML = '<div class="loading"><div class="spinner" role="status" aria-label="Cargando"></div></div>';
      try {
        const res = await App.api('GET', `api/report.php?tipgasxx=${App.state.account}&month=${month}`, { key: 'report' });
        this.data = res;
        this.render(res);
      } catch (err) { if (!App.isAbort(err)) box.innerHTML = errorState(err, 'report-reload'); }
    },
    render(data) {
      const r = data.resumen;
      const c = (v) => Math.round(Number(v) * 100);
      const kpi = (cls, icon, label, value, varPct, goodUp) => `
        <div class="report-kpi ${cls}"><div class="kpi-icon"><i class="bi ${icon}" aria-hidden="true"></i></div>
        <div class="kpi-body"><span class="kpi-label">${label}</span><span class="kpi-value">${value}</span>${varPct !== undefined ? `<span class="kpi-var">${deltaBadge(varPct, goodUp)} vs. mes anterior</span>` : ''}</div></div>`;
      const rows = (list, fn, empty) => (list.length ? list.map(fn).join('') : `<tr><td colspan="4" class="muted">${empty}</td></tr>`);
      $('#reportContent').innerHTML = `
        <div class="report-kpis">
          ${kpi('income', 'bi-arrow-down-left', 'Ingresos', money(c(r.ingresos)), r.varIngresos, true)}
          ${kpi('expense', 'bi-arrow-up-right', 'Gastos', money(c(r.gastos)), r.varGastos, false)}
          ${kpi('balance', 'bi-wallet2', 'Balance neto', money(c(r.balance), { signed: true }), r.varBalance, true)}
          ${kpi('movs', 'bi-list-check', 'Movimientos', r.movimientos)}
        </div>
        <p class="hint mt-2">Incluye movimientos programados con fecha dentro del mes.</p>
        <div class="report-grid">
          <div class="card report-card"><h4><i class="bi bi-tags" aria-hidden="true"></i>Por categoría</h4><div class="report-scroll">
            <table class="table"><thead><tr><th>Categoría</th><th class="t-right">Ingresos</th><th class="t-right">Gastos</th><th class="t-right">Movs.</th></tr></thead>
            <tbody>${rows(data.categorias, (x) => `<tr><td>${esc(x.CATGASXX)}</td><td class="t-right num">${money(c(x.ingresos))}</td><td class="t-right num">${money(c(x.gastos))}</td><td class="t-right num">${x.movimientos}</td></tr>`, 'Sin datos')}</tbody></table></div></div>
          <div class="card report-card"><h4><i class="bi bi-credit-card" aria-hidden="true"></i>Deudas activas</h4><div class="report-scroll">
            <table class="table"><thead><tr><th>Descripción</th><th>Tipo</th><th class="t-right">Monto</th></tr></thead>
            <tbody>${data.deudas.activas.length ? data.deudas.activas.map((x) => `<tr><td>${esc(x.DESDEUDX)}</td><td>${x.TIPDEUDX === 'A FAVOR' ? 'Me deben' : 'Debo'}</td><td class="t-right num">${money(F.parseAmount(String(x.MONDEUDX)) || 0)}</td></tr>`).join('') : '<tr><td colspan="3" class="muted">Sin deudas activas</td></tr>'}</tbody></table></div>
            <p class="text-sm mt-2">Debo: <strong class="num">${money(c(data.deudas.totalContra))}</strong> · Me deben: <strong class="num">${money(c(data.deudas.totalFavor))}</strong></p></div>
        </div>
        <div class="card report-card"><h4><i class="bi bi-receipt" aria-hidden="true"></i>Movimientos del mes</h4><div class="report-scroll" style="max-height:320px">
          <table class="table"><thead><tr><th>Fecha</th><th>Categoría</th><th>Descripción</th><th class="t-right">Monto</th></tr></thead>
          <tbody>${rows(data.movimientos, (m) => { const v = F.parseAmount(String(m.MONTGASX)) || 0; return `<tr><td class="num">${F.formatDate(m.FINCFECX)}</td><td>${esc(m.CATGASXX)}</td><td>${esc(m.DESGASXX || '—')}</td><td class="t-right"><span class="amount ${v > 0 ? 'positive' : 'negative'}">${money(v, { signed: true })}</span></td></tr>`; }, 'Sin movimientos')}</tbody></table></div></div>`;
    },
    async excel() {
      const data = this.data;
      if (!data || !data.movimientos.length) { App.toast('warning', 'Nada que exportar', 'El mes no tiene movimientos.'); return; }
      try {
        const ExcelJS = await App.lib.exceljs();
        const wb = new ExcelJS.Workbook();
        const ws = wb.addWorksheet('Reporte');
        const month = data.periodo.month;
        ws.addRow([`Reporte ${data.periodo.tipgasxx === 'BEATAN' ? 'Empresa' : 'Personal'} — ${F.monthLabel(month)}`]).font = { bold: true, size: 14 };
        ws.addRow([]);
        ws.addRow(['Ingresos', Number(data.resumen.ingresos)]);
        ws.addRow(['Gastos', Number(data.resumen.gastos)]);
        ws.addRow(['Balance', Number(data.resumen.balance)]);
        ws.addRow([]);
        const header = ws.addRow(['Fecha', 'Categoría', 'Descripción', 'Monto']);
        header.font = { bold: true };
        data.movimientos.forEach((m) => {
          // F.csvSafe evita que una descripción como "=HYPERLINK(...)" se interprete como fórmula
          ws.addRow([m.FINCFECX, F.csvSafe(m.CATGASXX), F.csvSafe(m.DESGASXX || ''), Number(m.MONTGASX)]);
        });
        [3, 4, 5].forEach((i) => { ws.getCell('B' + i).numFmt = '"$"#,##0.00'; });
        ws.getColumn(4).numFmt = '"$"#,##0.00';
        ws.columns.forEach((col, i) => { col.width = [14, 22, 46, 16][i] || 14; });
        const buf = await wb.xlsx.writeBuffer();
        const url = URL.createObjectURL(new Blob([buf], { type: 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' }));
        const a = document.createElement('a');
        a.href = url;
        a.download = `Reporte_${data.periodo.tipgasxx}_${month}.xlsx`;
        a.click();
        setTimeout(() => URL.revokeObjectURL(url), 1000);
      } catch (err) { App.toastError(err, 'No se pudo generar el Excel'); }
    },
  };

  /* =========================== NOTIFICACIONES =========================== */
  const Notifications = {
    items: [],
    hidden: new Set(JSON.parse(App.store.get('beatan_notif_hidden', '[]') || '[]')),
    async load() {
      if (document.hidden) return;
      try {
        const res = await App.api('GET', 'api/notifications.php', { key: 'notif' });
        this.items = res.data || [];
        this.render();
      } catch (e) { /* silencioso: no es crítico */ }
    },
    render() {
      const visible = this.items.filter((n) => !this.hidden.has(n.id));
      const badge = $('#notificationCount');
      badge.hidden = visible.length === 0;
      badge.textContent = visible.length > 9 ? '9+' : String(visible.length);
      $('#notificationBell').setAttribute('aria-label', `Notificaciones${visible.length ? ' (' + visible.length + ' sin revisar)' : ''}`);
      const icons = { danger: 'bi-exclamation-octagon', urgent: 'bi-alarm', warning: 'bi-clock', info: 'bi-envelope' };
      $('#notificationPanelBody').innerHTML = visible.length ? visible.map((n) => `
        <button type="button" class="notif-item" data-notif="${esc(n.id)}" data-notif-type="${esc(n.type)}">
          <span class="notif-icon ${esc(n.urgency)}"><i class="bi ${icons[n.urgency] || 'bi-bell'}" aria-hidden="true"></i></span>
          <span style="min-width:0"><span class="notif-title" style="display:block">${esc(n.title)}</span>
          <span class="notif-text" style="display:block">${esc(n.message)}${n.detail ? ' · ' + esc(n.detail) : ''}</span>
          <span class="notif-text" style="display:block">${esc(n.date)}</span></span>
        </button>`).join('') : emptyState('bi-bell-slash', 'Todo al día', 'No tienes avisos pendientes.');
    },
    toggle(force) {
      const panel = $('#notificationPanel');
      const open = force !== undefined ? force : !panel.classList.contains('active');
      panel.classList.toggle('active', open);
      $('#notificationBell').setAttribute('aria-expanded', String(open));
    },
    clear() {
      this.items.forEach((n) => this.hidden.add(n.id));
      App.store.set('beatan_notif_hidden', JSON.stringify([...this.hidden].slice(-200)));
      this.render();
    },
  };

  /* ============================= CALENDARIO ============================= */
  App.calendar = {
    date: new Date(),
    payments: [],
    cache: {},
    setPayments(items) { this.payments = items || []; if ($('#calendarDetails') && $('#calendarDetails').open) this.render(); },
    async holidays(y, m) {
      const k = y + '-' + m;
      if (!this.cache[k]) {
        try { this.cache[k] = (await App.api('GET', `api/holidays.php?year=${y}&month=${m}`)).data || []; } catch (e) { this.cache[k] = []; }
      }
      return this.cache[k];
    },
    async render() {
      const y = this.date.getFullYear();
      const m = this.date.getMonth();
      $('#calendarMonth').textContent = F.monthLabel(y + '-' + String(m + 1).padStart(2, '0'));
      const hol = await this.holidays(y, m + 1);
      const today = App.today();
      const first = new Date(y, m, 1).getDay();
      const total = new Date(y, m + 1, 0).getDate();
      let html = ['D', 'L', 'M', 'M', 'J', 'V', 'S'].map((d, i) => `<div class="calendar-day-header" aria-hidden="true" title="${['domingo', 'lunes', 'martes', 'miércoles', 'jueves', 'viernes', 'sábado'][i]}">${d}</div>`).join('');
      for (let i = 0; i < first; i++) html += '<div class="calendar-day other-month" aria-hidden="true"></div>';
      for (let d = 1; d <= total; d++) {
        const iso = `${y}-${String(m + 1).padStart(2, '0')}-${String(d).padStart(2, '0')}`;
        const h = hol.find((x) => x.fecha === iso);
        const pays = this.payments.filter((p) => p.date === iso);
        const label = F.formatDate(iso, 'long') + (h ? '. Festivo: ' + h.nombre : '') + (pays.length ? `. ${pays.length} pago(s) programado(s)` : '');
        html += `<button type="button" class="calendar-day ${iso === today ? 'today' : ''} ${h ? 'holiday' : ''}" data-cal="${iso}" aria-label="${esc(label)}">${d}${h ? '<span class="holiday-dot"></span>' : ''}${pays.length ? '<span class="pay-dot"></span>' : ''}</button>`;
      }
      $('#calendarGrid').innerHTML = html;
    },
    async select(iso) {
      const y = +iso.slice(0, 4);
      const m = +iso.slice(5, 7);
      const h = (await this.holidays(y, m)).find((x) => x.fecha === iso);
      const pays = this.payments.filter((p) => p.date === iso);
      const info = $('#calendarInfo');
      if (!h && !pays.length) { info.hidden = true; return; }
      info.innerHTML = `<h5>${F.formatDate(iso, 'long')}</h5>${h ? `<p class="text-sm">Festivo: ${esc(h.nombre)}</p>` : ''}${pays.map((p) => `<p class="text-sm">${esc(p.description || p.category)} · <span class="amount ${p.cents > 0 ? 'positive' : 'negative'}">${money(p.cents, { signed: true })}</span></p>`).join('')}`;
      info.hidden = false;
    },
  };

  /* ============================== EVENTOS ============================== */
  document.addEventListener('click', (e) => {
    const t = e.target;
    const by = (sel) => t.closest(sel);
    let el;
    if ((el = by('[data-tx-edit]'))) { const tx = Tx.find(el.dataset.txEdit); if (tx) TxModal.open({ mode: 'edit', tx }); return; }
    if ((el = by('[data-tx-dup]'))) { const tx = Tx.find(el.dataset.txDup); if (tx) TxModal.open({ mode: 'duplicate', tx: Object.assign({}, tx, { date: App.today() }) }); return; }
    if ((el = by('[data-tx-del]'))) { deleteTx(el.dataset.txDel); return; }
    if ((el = by('[data-tx]'))) { openDetail(el.dataset.tx); return; }
    if ((el = by('[data-sort]'))) {
      const k = el.dataset.sort;
      Tx.sort = { key: k, dir: Tx.sort.key === k && Tx.sort.dir === 'desc' ? 'asc' : 'desc' };
      Tx.render();
      const again = $(`[data-sort="${k}"]`);
      if (again) again.focus();
      return;
    }
    if ((el = by('[data-page]'))) { Tx.page = Number(el.dataset.page); Tx.render(); $('#txTable').scrollIntoView({ block: 'start', behavior: 'smooth' }); return; }
    if ((el = by('[data-debt-pay]'))) {
      const d = Debts.find(el.dataset.debtPay);
      if (d) TxModal.open({ tipo: d.TIPDEUDX === 'A FAVOR' ? 'INGRESO' : 'GASTO', debtId: d.DEUDIDXX, categoria: 'Deudas', descripcion: (d.TIPDEUDX === 'A FAVOR' ? 'Cobro: ' : 'Pago: ') + d.DESDEUDX, cents: null, account: App.state.account });
      return;
    }
    if ((el = by('[data-debt-edit]'))) { DebtModal.open(Debts.find(el.dataset.debtEdit)); return; }
    if ((el = by('[data-debt-history]'))) { debtHistory(el.dataset.debtHistory); return; }
    if ((el = by('[data-debt-paid]'))) {
      const d = Debts.find(el.dataset.debtPaid);
      App.confirm(`Marcar «${d.DESDEUDX}» como pagada la quitará de tus deudas activas aunque falten ${money(d.RESTANTE_CENTS)} por registrar.`, { title: 'Marcar como pagada', confirmLabel: 'Marcar pagada', danger: false }).then(async (ok) => {
        if (!ok) return;
        try { await App.api('PUT', 'api/debts.php', { body: { id: Number(d.DEUDIDXX), regestxx: 'PAGADO' } }); App.toast('success', 'Deuda marcada como pagada'); App.dataChanged('debts'); } catch (err) { App.toastError(err); }
      });
      return;
    }
    if ((el = by('[data-debt-del]'))) {
      const d = Debts.find(el.dataset.debtDel);
      App.confirm(`Se eliminará «${d.DESDEUDX}». Los movimientos ya registrados se conservan.`, { title: 'Eliminar deuda', confirmLabel: 'Eliminar' }).then(async (ok) => {
        if (!ok) return;
        try { await App.api('DELETE', 'api/debts.php?id=' + Number(d.DEUDIDXX)); App.toast('success', 'Deuda eliminada'); App.dataChanged('debts'); } catch (err) { App.toastError(err); }
      });
      return;
    }
    if ((el = by('[data-notif]'))) {
      Notifications.toggle(false);
      const type = el.dataset.notifType;
      if (type === 'message') App.go('mensajes');
      else if (type === 'expense_beatan') { App.setAccount('BEATAN'); App.go('resumen'); }
      else App.go('resumen');
      return;
    }
    if ((el = by('[data-cal]'))) { App.calendar.select(el.dataset.cal); return; }
    if (by('#notificationBell')) { Notifications.toggle(); return; }
    if (!by('#notificationPanel')) Notifications.toggle(false);

    const action = (el = by('[data-action]')) ? el.dataset.action : null;
    switch (action) {
      case 'new-transaction': TxModal.open({}); break;
      case 'tx-edit': App.modal.close('txDetailModal'); TxModal.open({ mode: 'edit', tx: detailTx }); break;
      case 'tx-duplicate': App.modal.close('txDetailModal'); TxModal.open({ mode: 'duplicate', tx: Object.assign({}, detailTx, { date: App.today() }) }); break;
      case 'tx-delete': deleteTx(detailTx.id); break;
      case 'tx-clear-filters': $('#txFilters').reset(); Tx.render(); break;
      case 'toggle-filters': {
        const f = $('#txFilters');
        const open = f.dataset.collapsed === 'true';
        f.dataset.collapsed = String(!open);
        el.setAttribute('aria-expanded', String(open));
        if (open) $('#txType').focus();
        break;
      }
      case 'reload-tx': Tx.load(); break;
      case 'reload-overview': Overview.load(); break;
      case 'reload-debts': Debts.load(); break;
      case 'new-debt': DebtModal.open(null); break;
      case 'export-csv': exportCsv(); break;
      case 'open-report': Report.open(); break;
      case 'report-reload': Report.load(); break;
      case 'report-print': window.print(); break;
      case 'report-excel': Report.excel(); break;
      case 'clear-notifications': Notifications.clear(); break;
      case 'cal-prev': App.calendar.date.setMonth(App.calendar.date.getMonth() - 1); App.calendar.render(); break;
      case 'cal-next': App.calendar.date.setMonth(App.calendar.date.getMonth() + 1); App.calendar.render(); break;
      default: break;
    }
  });

  document.addEventListener('DOMContentLoaded', () => {
    $('#txForm').addEventListener('submit', (e) => TxModal.submit(e));
    ['#txAmount', '#debtTotal', '#debtCuota', '#txMin', '#txMax'].forEach((s) => App.moneyInput($(s)));
    $('#txForm').addEventListener('input', App.debounce(() => TxModal.updateHints(), 200));
    $('#txForm').addEventListener('change', (e) => {
      if (e.target.name === 'tipgasxx') TxModal.loadDebts(e.target.value, null);
      TxModal.updateHints();
    });
    $('#debtForm').addEventListener('submit', (e) => DebtModal.submit(e));
    $('#debtForm').addEventListener('change', (e) => { if (e.target.name === 'modo') DebtModal.toggleMode(); });
    $('#debtForm').addEventListener('input', (e) => {
      if (e.target.name === 'moncuotx') e.target.dataset.touched = '1';
      if (e.target.name === 'mondeudx' || e.target.name === 'numcuotx') DebtModal.autoCuota();
    });
    $('#txFilters').addEventListener('input', App.debounce(() => { Tx.page = 1; Tx.render(); }, 180));
    $('#txFilters').addEventListener('change', () => { Tx.page = 1; Tx.render(); });
    $('#txFilters').addEventListener('reset', () => setTimeout(() => { Tx.page = 1; Tx.render(); }, 0));
    $('#txFilters').addEventListener('submit', (e) => e.preventDefault());
    $('#debtFilters').addEventListener('change', () => Debts.load());
    $('#debtFilters').addEventListener('input', App.debounce(() => Debts.load(), 300));
    $('#debtFilters').addEventListener('submit', (e) => e.preventDefault());
    $('#reportMonth').addEventListener('change', () => Report.load());
    $('#calendarDetails').addEventListener('toggle', (e) => { if (e.target.open) App.calendar.render(); });
    window.matchMedia('(max-width: 767px)').addEventListener('change', () => { if (App.state.view === 'movimientos') Tx.render(); });
    Notifications.load();
    setInterval(() => Notifications.load(), 60000);
    document.addEventListener('visibilitychange', () => { if (!document.hidden) Notifications.load(); });
  });

  App.views = Object.assign(App.views || {}, {
    resumen: Overview,
    movimientos: Tx,
    deudas: Debts,
  });
  App.Tx = Tx;
  App.DebtModal = DebtModal;
  App.Notifications = Notifications;
})();

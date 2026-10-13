/* dashboard/js/charts.js
 * Gráficas financieras sobre Chart.js. Los colores salen de los tokens CSS (claro/oscuro)
 * y la paleta ingresos/gastos está validada para daltonismo. Un único eje por gráfica.
 */
(function () {
  'use strict';
  const App = window.App;
  const F = window.FinanceCore;
  const instances = {};
  const css = (name) => getComputedStyle(document.documentElement).getPropertyValue(name).trim();
  const reduce = () => window.matchMedia('(prefers-reduced-motion: reduce)').matches;

  function baseOptions() {
    return {
      responsive: true,
      maintainAspectRatio: false,
      animation: reduce() ? false : { duration: 450, easing: 'easeOutCubic' },
      interaction: { mode: 'index', intersect: false },
      layout: { padding: { top: 6, right: 6 } },
      plugins: {
        legend: { display: false },
        tooltip: {
          backgroundColor: css('--surface-inverse'),
          titleColor: css('--text-inverse'),
          bodyColor: css('--text-inverse'),
          borderWidth: 0,
          padding: 10,
          cornerRadius: 8,
          boxPadding: 4,
          usePointStyle: true,
          titleFont: { family: css('--font-sans'), weight: '600' },
          bodyFont: { family: css('--font-sans') },
        },
      },
      scales: {
        x: {
          grid: { display: false },
          border: { display: false },
          ticks: { color: css('--chart-text'), font: { size: 11, family: css('--font-sans') }, maxRotation: 0, autoSkip: true, autoSkipPadding: 14 },
        },
        y: {
          grid: { color: css('--chart-grid'), drawTicks: false },
          border: { display: false },
          ticks: { color: css('--chart-text'), font: { size: 11, family: css('--font-sans') }, padding: 8, maxTicksLimit: 5, callback: (v) => F.formatCompact(Math.round(v * 100)) },
        },
      },
    };
  }

  function mount(id, config) {
    if (typeof window.Chart === 'undefined') return null;
    const canvas = document.getElementById(id);
    if (!canvas) return null;
    if (instances[id]) instances[id].destroy();
    instances[id] = new window.Chart(canvas, config);
    instances[id]._factory = config._factory;
    return instances[id];
  }

  /** Línea del saldo acumulado + tramo proyectado (discontinuo) hasta el cierre. */
  function balance(id, data) {
    const pts = data.series.points || [];
    const monthly = data.series.granularity === 'month';
    const labels = pts.map((p) => (monthly ? F.monthLabel(p.date) : F.formatDate(p.date, 'short')));
    const real = pts.map((p) => p.balance / 100);
    const proj = data.projection && data.projection.available ? data.projection : null;
    const projected = real.map(() => null);
    if (proj && pts.length) {
      // El eje sigue día a día hasta el cierre para no comprimir el tiempo restante.
      projected[projected.length - 1] = real[real.length - 1];
      const last = pts[pts.length - 1].date;
      const remaining = monthly ? 0 : F.daysBetween(last, data.period.to);
      for (let i = 1; i <= remaining; i++) {
        labels.push(F.formatDate(F.addDays(last, i), 'short'));
        real.push(null);
        projected.push(i === remaining ? proj.projected / 100 : null);
      }
      if (remaining === 0) { labels.push('Cierre'); real.push(null); projected.push(proj.projected / 100); }
    }
    const line = css('--chart-line');
    const surface = css('--surface');
    const cfg = {
      type: 'line',
      data: {
        labels,
        datasets: [
          {
            label: 'Saldo real', data: real, borderColor: line, borderWidth: 2, tension: 0.25, spanGaps: false,
            pointRadius: 0, pointHoverRadius: 5, pointHoverBorderWidth: 2, pointHoverBorderColor: surface, pointBackgroundColor: line,
            fill: { target: 'origin', above: line + '14', below: css('--danger') + '14' },
          },
          {
            label: 'Proyección (estimación)', data: projected, borderColor: line, borderWidth: 2, borderDash: [5, 5],
            pointRadius: (ctx) => (ctx.dataIndex === projected.length - 1 ? 4 : 0), pointBackgroundColor: surface, pointBorderColor: line, pointBorderWidth: 2,
            fill: false, spanGaps: true,
          },
        ],
      },
      options: baseOptions(),
      _factory: () => balance(id, data),
    };
    cfg.options.plugins.tooltip.callbacks = {
      label: (ctx) => (ctx.raw === null ? null : ' ' + ctx.dataset.label + ': ' + F.formatMoney(Math.round(ctx.raw * 100))),
    };
    cfg.options.plugins.tooltip.filter = (item) => item.raw !== null;
    return mount(id, cfg);
  }

  /** Barras agrupadas de ingresos y gastos por mes. */
  function monthly(id, months) {
    const surface = css('--surface');
    const ds = (label, key, color) => ({
      label, data: months.map((m) => m[key] / 100), backgroundColor: color, borderColor: surface, borderWidth: 2,
      borderRadius: 4, borderSkipped: 'bottom', maxBarThickness: 22, categoryPercentage: 0.7, barPercentage: 0.9,
    });
    const cfg = {
      type: 'bar',
      data: {
        labels: months.map((m) => F.monthLabel(m.month).split(' ')[0].slice(0, 3)),
        datasets: [ds('Ingresos', 'income', css('--chart-income')), ds('Gastos', 'expense', css('--chart-expense'))],
      },
      options: baseOptions(),
      _factory: () => monthly(id, months),
    };
    cfg.options.interaction = { mode: 'index', intersect: false };
    cfg.options.plugins.tooltip.callbacks = {
      title: (items) => F.monthLabel(months[items[0].dataIndex].month),
      label: (ctx) => ' ' + ctx.dataset.label + ': ' + F.formatMoney(Math.round(ctx.raw * 100)),
      footer: (items) => 'Neto: ' + F.formatMoney(months[items[0].dataIndex].net),
    };
    return mount(id, cfg);
  }

  // Repintar con los colores del nuevo tema
  document.addEventListener('app:theme', () => {
    Object.keys(instances).forEach((id) => {
      const f = instances[id] && instances[id]._factory;
      if (f) f();
    });
  });

  App.charts = { balance, monthly, instances };
})();

<?php defined('APP_PAGE') || exit; ?>
<section class="view" id="view-resumen" data-view="resumen" data-title="Resumen" data-subtitle="Tu situación financiera de un vistazo" data-period="1" hidden>
  <div id="overviewNotice"></div>

  <h2 class="sr-only">Indicadores principales</h2>
  <div class="kpi-grid" id="kpiGrid" aria-busy="true">
    <?php for ($i = 0; $i < 4; $i++): ?>
      <div class="card kpi"><span class="skeleton sk-line" style="width:40%"></span><span class="skeleton sk-kpi"></span><span class="skeleton sk-line" style="width:60%"></span></div>
    <?php endfor; ?>
  </div>

  <div class="grid grid-overview section-gap">
    <div class="stack">
      <article class="card" aria-labelledby="chartBalanceTitle">
        <div class="card-header">
          <div>
            <h3 class="card-title" id="chartBalanceTitle">Evolución del saldo</h3>
            <p class="card-subtitle" id="chartBalanceSubtitle">Saldo acumulado día a día en el periodo</p>
          </div>
          <div class="chart-legend" id="chartBalanceLegend" aria-hidden="true"></div>
        </div>
        <div class="card-body">
          <div class="chart-box"><canvas id="chartBalance" role="img" aria-label="Gráfica de evolución del saldo" aria-describedby="chartBalanceSummary"></canvas></div>
          <p class="sr-only" id="chartBalanceSummary"></p>
        </div>
      </article>

      <div class="grid grid-2">
        <article class="card" aria-labelledby="catTitle">
          <div class="card-header">
            <div>
              <h3 class="card-title" id="catTitle">¿En qué se va tu dinero?</h3>
              <p class="card-subtitle">Gastos realizados por categoría</p>
            </div>
          </div>
          <div class="card-body" id="categoryBars"><span class="skeleton sk-block" style="height:180px"></span></div>
        </article>

        <article class="card" aria-labelledby="recentTitle">
          <div class="card-header">
            <h3 class="card-title" id="recentTitle">Movimientos recientes</h3>
            <a class="card-link" href="#movimientos">Ver todos</a>
          </div>
          <div class="card-body" id="recentList"><span class="skeleton sk-block" style="height:180px"></span></div>
        </article>
      </div>

      <article class="card" aria-labelledby="chartMonthlyTitle">
        <div class="card-header">
          <div>
            <h3 class="card-title" id="chartMonthlyTitle">Ingresos y gastos por mes</h3>
            <p class="card-subtitle">Últimos 6 meses, solo movimientos realizados</p>
          </div>
          <div class="chart-legend" aria-hidden="true">
            <span><i class="swatch" style="background:var(--chart-income)"></i>Ingresos</span>
            <span><i class="swatch" style="background:var(--chart-expense)"></i>Gastos</span>
          </div>
        </div>
        <div class="card-body">
          <div class="chart-box sm"><canvas id="chartMonthly" role="img" aria-label="Gráfica de ingresos y gastos por mes" aria-describedby="chartMonthlySummary"></canvas></div>
          <p class="sr-only" id="chartMonthlySummary"></p>
        </div>
      </article>
    </div>

    <div class="stack side">
      <article class="card" aria-labelledby="insightsTitle">
        <div class="card-header">
          <div>
            <h3 class="card-title" id="insightsTitle"><i class="bi bi-stars" aria-hidden="true"></i>Lo que dicen tus números</h3>
            <p class="card-subtitle">Observaciones automáticas basadas en tus datos</p>
          </div>
        </div>
        <div class="card-body">
          <div class="insights" id="insightsList" aria-live="polite"><span class="skeleton sk-line"></span><span class="skeleton sk-line"></span><span class="skeleton sk-line" style="width:70%"></span></div>
          <p class="disclaimer mt-3"><i class="bi bi-info-circle" aria-hidden="true"></i>Son orientaciones calculadas con tus registros, no asesoría financiera profesional.</p>
        </div>
      </article>

      <article class="card" aria-labelledby="projectionTitle">
        <div class="card-header">
          <h3 class="card-title" id="projectionTitle">Proyección al cierre <span class="tag-estimate">Estimación</span></h3>
          <button class="help" type="button" data-help="projection" aria-label="¿Cómo se calcula la proyección?"><i class="bi bi-question-circle" aria-hidden="true"></i></button>
        </div>
        <div class="card-body" id="projectionBox"><span class="skeleton sk-kpi"></span></div>
      </article>

      <article class="card" aria-labelledby="upcomingTitle">
        <div class="card-header">
          <div>
            <h3 class="card-title" id="upcomingTitle">Próximos pagos</h3>
            <p class="card-subtitle">Movimientos programados para los próximos 30 días</p>
          </div>
        </div>
        <div class="card-body">
          <div id="upcomingList"><span class="skeleton sk-line"></span><span class="skeleton sk-line"></span></div>
          <details class="mt-3" id="calendarDetails">
            <summary class="card-link" style="cursor:pointer">Ver calendario</summary>
            <div class="mt-3">
              <div class="calendar-header">
                <h4 class="sr-only">Calendario</h4>
                <div class="calendar-nav">
                  <button type="button" data-action="cal-prev" aria-label="Mes anterior"><i class="bi bi-chevron-left" aria-hidden="true"></i></button>
                  <span class="calendar-month" id="calendarMonth" aria-live="polite"></span>
                  <button type="button" data-action="cal-next" aria-label="Mes siguiente"><i class="bi bi-chevron-right" aria-hidden="true"></i></button>
                </div>
              </div>
              <div class="calendar-grid" id="calendarGrid"></div>
              <div class="calendar-legend">
                <span class="legend-item"><span class="legend-dot today"></span>Hoy</span>
                <span class="legend-item"><span class="legend-dot holiday"></span>Festivo</span>
                <span class="legend-item"><span class="legend-dot pay"></span>Pago programado</span>
              </div>
              <div class="holiday-info" id="calendarInfo" hidden></div>
            </div>
          </details>
        </div>
      </article>

      <article class="card" aria-labelledby="budgetsMiniTitle">
        <div class="card-header">
          <h3 class="card-title" id="budgetsMiniTitle">Presupuestos del mes</h3>
          <a class="card-link" href="#presupuestos">Gestionar</a>
        </div>
        <div class="card-body" id="budgetsMini"><span class="skeleton sk-line"></span><span class="skeleton sk-line"></span></div>
      </article>

      <article class="card" aria-labelledby="goalsMiniTitle">
        <div class="card-header">
          <h3 class="card-title" id="goalsMiniTitle">Metas de ahorro</h3>
          <a class="card-link" href="#metas">Ver metas</a>
        </div>
        <div class="card-body" id="goalsMini"><span class="skeleton sk-line"></span></div>
      </article>

      <article class="card" aria-labelledby="debtsMiniTitle">
        <div class="card-header">
          <h3 class="card-title" id="debtsMiniTitle">Deudas y patrimonio</h3>
          <a class="card-link" href="#deudas">Ver deudas</a>
        </div>
        <div class="card-body" id="debtsMini"><span class="skeleton sk-line"></span></div>
      </article>
    </div>
  </div>
</section>

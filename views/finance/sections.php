<?php defined('APP_PAGE') || exit; ?>

<!-- MOVIMIENTOS ======================================================== -->
<section class="view" id="view-movimientos" data-view="movimientos" data-title="Movimientos" data-subtitle="Registra, busca y corrige tus ingresos y gastos" data-period="1" hidden>
  <article class="card">
    <div class="card-header">
      <div>
        <h2 class="card-title">Movimientos <span class="badge badge-outline" id="txCount" aria-live="polite">—</span></h2>
        <p class="card-subtitle" id="txPeriodLabel"></p>
      </div>
      <div class="card-actions">
        <button class="btn-secondary btn-sm" type="button" data-action="open-import"><i class="bi bi-upload" aria-hidden="true"></i><span>Importar</span></button>
        <button class="btn-secondary btn-sm" type="button" data-action="export-csv"><i class="bi bi-filetype-csv" aria-hidden="true"></i><span>Exportar CSV</span></button>
        <button class="btn-secondary btn-sm" type="button" data-action="open-report"><i class="bi bi-file-earmark-bar-graph" aria-hidden="true"></i><span>Reporte</span></button>
        <button class="btn-primary btn-sm" type="button" data-action="new-transaction"><i class="bi bi-plus-lg" aria-hidden="true"></i><span>Nuevo</span></button>
      </div>
    </div>

    <form class="filters mt-4" id="txFilters" role="search" aria-label="Filtrar movimientos" data-collapsed="true">
      <div class="field grow">
        <label for="txSearch">Buscar</label>
        <div class="flex gap-2">
          <input class="input" type="search" id="txSearch" placeholder="Descripción o categoría" autocomplete="off">
          <button class="btn-secondary hide-desktop" type="button" data-action="toggle-filters" aria-expanded="false" aria-controls="txFilters"><i class="bi bi-funnel" aria-hidden="true"></i><span>Filtros</span><span class="badge badge-info" id="txFilterCount" hidden></span></button>
        </div>
      </div>
      <div class="field">
        <label for="txType">Tipo</label>
        <select class="select" id="txType">
          <option value="">Todos</option>
          <option value="INGRESO">Ingresos</option>
          <option value="GASTO">Gastos</option>
        </select>
      </div>
      <div class="field">
        <label for="txCategory">Categoría</label>
        <select class="select" id="txCategory"><option value="">Todas</option></select>
      </div>
      <div class="field">
        <label for="txMin">Monto desde</label>
        <input class="input" type="text" inputmode="decimal" id="txMin" placeholder="0" autocomplete="off">
      </div>
      <div class="field">
        <label for="txMax">Monto hasta</label>
        <input class="input" type="text" inputmode="decimal" id="txMax" placeholder="Sin límite" autocomplete="off">
      </div>
      <div class="field" style="flex:0 0 auto">
        <span class="label" aria-hidden="true">&nbsp;</span>
        <button class="btn-ghost" type="reset" id="txReset">Limpiar</button>
      </div>
    </form>
    <div class="filters-summary" id="txSummary" aria-live="polite"></div>
    <div id="txTable"><div class="loading"><div class="spinner" role="status" aria-label="Cargando"></div></div></div>
    <nav class="pagination" id="txPagination" aria-label="Paginación"></nav>
  </article>
</section>

<!-- PRESUPUESTOS ======================================================== -->
<section class="view" id="view-presupuestos" data-view="presupuestos" data-title="Presupuestos" data-subtitle="Pon un límite mensual a cada categoría y controla cuánto te queda" hidden>
  <div class="view-header">
    <div>
      <h2>Presupuesto mensual</h2>
      <p>Un presupuesto es el máximo que quieres gastar en una categoría cada mes. Te avisamos cuando te acercas al límite.</p>
    </div>
    <div class="flex gap-2 wrap items-center">
      <div class="calendar-nav" role="group" aria-label="Mes">
        <button type="button" data-action="budget-prev" aria-label="Mes anterior"><i class="bi bi-chevron-left" aria-hidden="true"></i></button>
        <span class="calendar-month" id="budgetMonthLabel" aria-live="polite"></span>
        <button type="button" data-action="budget-next" aria-label="Mes siguiente"><i class="bi bi-chevron-right" aria-hidden="true"></i></button>
      </div>
      <button class="btn-primary" type="button" data-action="new-budget"><i class="bi bi-plus-lg" aria-hidden="true"></i>Nuevo presupuesto</button>
    </div>
  </div>
  <div id="budgetsContent"><div class="loading"><div class="spinner" role="status" aria-label="Cargando"></div></div></div>
</section>

<!-- METAS =============================================================== -->
<section class="view" id="view-metas" data-view="metas" data-title="Metas de ahorro" data-subtitle="Aparta dinero para lo que quieres lograr" hidden>
  <div class="view-header">
    <div>
      <h2>Tus metas</h2>
      <p>Apartar dinero para una meta no es un gasto: sigue siendo tuyo y forma parte de tu saldo, solo queda reservado.</p>
    </div>
    <div class="flex gap-2 wrap">
      <label class="checkbox"><input type="checkbox" id="goalsShowArchived"> Mostrar archivadas</label>
      <button class="btn-primary" type="button" data-action="new-goal"><i class="bi bi-plus-lg" aria-hidden="true"></i>Nueva meta</button>
    </div>
  </div>
  <div id="goalsContent"><div class="loading"><div class="spinner" role="status" aria-label="Cargando"></div></div></div>
</section>

<!-- DEUDAS ============================================================== -->
<section class="view" id="view-deudas" data-view="deudas" data-title="Deudas" data-subtitle="Lo que debes y lo que te deben, con su avance real" hidden>
  <div id="debtSummary" class="kpi-grid" aria-live="polite"></div>
  <article class="card section-gap">
    <div class="card-header">
      <div>
        <h2 class="card-title">Deudas y préstamos</h2>
        <p class="card-subtitle">El avance se calcula con los pagos registrados hasta hoy.</p>
      </div>
      <button class="btn-primary btn-sm" type="button" data-action="new-debt"><i class="bi bi-plus-lg" aria-hidden="true"></i>Nueva deuda</button>
    </div>
    <form class="filters mt-4" id="debtFilters" aria-label="Filtrar deudas">
      <div class="field">
        <label for="debtState">Estado</label>
        <select class="select" id="debtState">
          <option value="ACTIVO">Activas</option>
          <option value="PAGADO">Pagadas</option>
          <option value="INACTIVO">Eliminadas</option>
        </select>
      </div>
      <div class="field">
        <label for="debtType">Tipo</label>
        <select class="select" id="debtType">
          <option value="">Todas</option>
          <option value="EN CONTRA">Yo debo</option>
          <option value="A FAVOR">Me deben</option>
        </select>
      </div>
      <div class="field grow">
        <label for="debtSearch">Buscar</label>
        <input class="input" type="search" id="debtSearch" placeholder="Descripción" autocomplete="off">
      </div>
    </form>
    <div id="debtList" class="card-body"><div class="loading"><div class="spinner" role="status" aria-label="Cargando"></div></div></div>
  </article>
</section>

<!-- PERFIL Y AJUSTES ==================================================== -->
<section class="view" id="view-perfil" data-view="perfil" data-title="Perfil y ajustes" data-subtitle="Tus datos, seguridad y preferencias" hidden>
  <div class="grid grid-2">
    <article class="card">
      <div class="card-header"><h2 class="card-title"><i class="bi bi-person" aria-hidden="true"></i>Datos personales</h2></div>
      <form class="card-body stack" id="profileForm" novalidate>
        <div class="field"><label for="pfCedula">Cédula</label><input class="input" id="pfCedula" disabled></div>
        <div class="field"><label for="pfNombre">Nombre</label><input class="input" id="pfNombre" name="nombre" maxlength="50" required autocomplete="name"></div>
        <div class="field"><label for="pfEmail">Email</label><input class="input" id="pfEmail" name="email" type="email" maxlength="150" required autocomplete="email"></div>
        <div class="field"><label for="pfCelular">Celular</label><input class="input" id="pfCelular" name="celular" type="tel" inputmode="tel" maxlength="20" autocomplete="tel"></div>
        <div><button class="btn-primary" type="submit">Guardar cambios</button></div>
      </form>
    </article>
    <div class="stack">
      <article class="card">
        <div class="card-header"><h2 class="card-title"><i class="bi bi-shield-lock" aria-hidden="true"></i>Cambiar contraseña</h2></div>
        <form class="card-body stack" id="passwordForm" novalidate>
          <input type="text" name="username" autocomplete="username" value="<?= htmlspecialchars((string) $user['id']) ?>" hidden>
          <div class="field"><label for="pwOld">Contraseña actual</label><input class="input" id="pwOld" type="password" autocomplete="current-password" required></div>
          <div class="field"><label for="pwNew">Nueva contraseña</label><input class="input" id="pwNew" type="password" minlength="8" autocomplete="new-password" required><span class="hint">Mínimo 8 caracteres. Usa una frase que no utilices en otros sitios.</span></div>
          <div class="field"><label for="pwNew2">Repite la nueva contraseña</label><input class="input" id="pwNew2" type="password" minlength="8" autocomplete="new-password" required></div>
          <div><button class="btn-secondary" type="submit">Actualizar contraseña</button></div>
        </form>
      </article>
      <article class="card">
        <div class="card-header"><h2 class="card-title"><i class="bi bi-sliders" aria-hidden="true"></i>Preferencias</h2></div>
        <div class="card-body stack">
          <div class="field">
            <span class="label" id="themeLabel">Tema</span>
            <div class="segmented" role="group" aria-labelledby="themeLabel" id="themeSwitch">
              <button type="button" data-theme-value="system">Automático</button>
              <button type="button" data-theme-value="light">Claro</button>
              <button type="button" data-theme-value="dark">Oscuro</button>
            </div>
          </div>
          <div class="field">
            <span class="label">Asistente de IA</span>
            <p class="text-sm">Para responder, el asistente envía un resumen de tus movimientos a Google Gemini. Puedes usar tu propia clave o la del servidor, si está configurada.</p>
            <div class="flex gap-2 wrap">
              <button class="btn-secondary btn-sm" type="button" data-action="ai-key">Configurar clave propia</button>
              <button class="btn-ghost btn-sm" type="button" data-action="ai-forget">Olvidar clave y permiso</button>
            </div>
          </div>
        </div>
      </article>
      <?php if ($user['is_admin']): ?>
        <article class="card">
          <div class="card-header"><h2 class="card-title"><i class="bi bi-database-gear" aria-hidden="true"></i>Base de datos</h2></div>
          <div class="card-body stack">
            <p class="text-sm">Las migraciones crean las tablas nuevas (presupuestos y metas). Son seguras: no modifican ni borran datos existentes.</p>
            <div id="migrationStatus" class="text-sm muted">Consultando estado…</div>
            <div><button class="btn-secondary btn-sm" type="button" data-action="run-migrations">Aplicar migraciones pendientes</button></div>
          </div>
        </article>
      <?php endif; ?>
    </div>
  </div>
</section>

<?php defined('APP_PAGE') || exit; ?>

<!-- Movimiento: crear / editar / duplicar -->
<div class="modal-overlay" id="txModal" role="dialog" aria-modal="true" aria-labelledby="txModalTitle">
  <div class="modal">
    <div class="modal-header">
      <div>
        <h3 id="txModalTitle">Nuevo movimiento</h3>
        <p id="txModalHint">Registra un ingreso o un gasto.</p>
      </div>
      <button class="modal-close" type="button" data-close aria-label="Cerrar">&times;</button>
    </div>
    <form id="txForm" novalidate>
      <div class="modal-body stack">
        <input type="hidden" name="id">
        <fieldset class="field" style="border:0">
          <legend class="label">Tipo</legend>
          <div class="type-selector">
            <label class="type-option"><input type="radio" name="tipo" value="GASTO" checked><span class="type-pill expense"><i class="bi bi-arrow-up-right" aria-hidden="true"></i>Gasto</span></label>
            <label class="type-option"><input type="radio" name="tipo" value="INGRESO"><span class="type-pill income"><i class="bi bi-arrow-down-left" aria-hidden="true"></i>Ingreso</span></label>
          </div>
        </fieldset>
        <div class="form-grid">
          <div class="field span-2">
            <label for="txAmount">Monto</label>
            <div class="input-group">
              <span class="prefix" aria-hidden="true">$</span>
              <input class="input input-money" id="txAmount" name="monto" inputmode="decimal" autocomplete="off" placeholder="0" required aria-describedby="txAmountHint">
            </div>
            <span class="hint" id="txAmountHint">Escribe solo números. Usa coma para centavos (ej. 25.000,50).</span>
          </div>
          <div class="field">
            <label for="txDate">Fecha</label>
            <input class="input" id="txDate" name="fecha" type="date" required>
          </div>
          <div class="field">
            <label for="txCat">Categoría</label>
            <input class="input" id="txCat" name="categoria" list="categoryOptions" maxlength="30" required autocomplete="off" placeholder="Ej. Alimentación">
          </div>
          <div class="field span-2">
            <label for="txDesc">Descripción <span class="muted">(opcional)</span></label>
            <input class="input" id="txDesc" name="descripcion" maxlength="150" autocomplete="off" placeholder="Ej. Mercado de la semana">
          </div>
          <div class="field" id="txAccountField" hidden>
            <label for="txAccount">Cuenta</label>
            <select class="select" id="txAccount" name="tipgasxx">
              <option value="PERSONAL">Personal</option>
              <option value="BEATAN">Empresa (BeaTaN)</option>
            </select>
          </div>
          <div class="field">
            <label for="txDebt">Abono a deuda <span class="muted">(opcional)</span></label>
            <select class="select" id="txDebt" name="deudidxx"><option value="">Ninguna</option></select>
          </div>
        </div>
        <p class="hint" id="txFutureHint" hidden><i class="bi bi-clock" aria-hidden="true"></i> Fecha futura: quedará como <strong>programado</strong> y no afectará tu saldo hasta ese día.</p>
        <div class="alert alert-warning" id="txDuplicateWarn" hidden></div>
        <div class="alert alert-error" id="txError" role="alert" hidden></div>
      </div>
      <div class="modal-footer">
        <button class="btn-secondary" type="button" data-close>Cancelar</button>
        <button class="btn-primary" type="submit" id="txSubmit">Guardar</button>
      </div>
    </form>
  </div>
</div>
<datalist id="categoryOptions"></datalist>

<!-- Detalle de movimiento -->
<div class="modal-overlay" id="txDetailModal" role="dialog" aria-modal="true" aria-labelledby="txDetailTitle">
  <div class="modal">
    <div class="modal-header">
      <div>
        <h3 id="txDetailTitle">Detalle del movimiento</h3>
      </div>
      <button class="modal-close" type="button" data-close aria-label="Cerrar">&times;</button>
    </div>
    <div class="modal-body" id="txDetailBody"></div>
    <div class="modal-footer">
      <button class="btn-ghost left" type="button" data-action="tx-delete" style="color:var(--danger)"><i class="bi bi-trash" aria-hidden="true"></i>Eliminar</button>
      <button class="btn-secondary" type="button" data-action="tx-duplicate"><i class="bi bi-copy" aria-hidden="true"></i>Duplicar</button>
      <button class="btn-primary" type="button" data-action="tx-edit"><i class="bi bi-pencil" aria-hidden="true"></i>Editar</button>
    </div>
  </div>
</div>

<!-- Confirmación genérica -->
<div class="modal-overlay" id="confirmModal" role="alertdialog" aria-modal="true" aria-labelledby="confirmTitle" aria-describedby="confirmText">
  <div class="modal" style="max-width:440px">
    <div class="modal-header"><h3 id="confirmTitle">¿Confirmar?</h3></div>
    <div class="modal-body"><p id="confirmText"></p></div>
    <div class="modal-footer">
      <button class="btn-secondary" type="button" data-confirm="no">Cancelar</button>
      <button class="btn-danger" type="button" data-confirm="yes" id="confirmYes">Confirmar</button>
    </div>
  </div>
</div>

<!-- Deuda -->
<div class="modal-overlay" id="debtModal" role="dialog" aria-modal="true" aria-labelledby="debtModalTitle">
  <div class="modal">
    <div class="modal-header">
      <div>
        <h3 id="debtModalTitle">Nueva deuda</h3>
        <p>Registra dinero que debes o que te deben.</p>
      </div>
      <button class="modal-close" type="button" data-close aria-label="Cerrar">&times;</button>
    </div>
    <form id="debtForm" novalidate>
      <div class="modal-body stack">
        <input type="hidden" name="id">
        <fieldset class="field" style="border:0">
          <legend class="label">¿Quién le debe a quién?</legend>
          <div class="type-selector">
            <label class="type-option"><input type="radio" name="tipdeudx" value="EN CONTRA" checked><span class="type-pill expense">Yo debo</span></label>
            <label class="type-option"><input type="radio" name="tipdeudx" value="A FAVOR"><span class="type-pill income">Me deben</span></label>
          </div>
        </fieldset>
        <div class="field">
          <label for="debtDesc">Descripción</label>
          <input class="input" id="debtDesc" name="desdeudx" maxlength="150" required placeholder="Ej. Crédito celular, préstamo a Ana">
        </div>
        <div class="field">
          <label for="debtTotal">Monto total</label>
          <div class="input-group"><span class="prefix" aria-hidden="true">$</span><input class="input input-money" id="debtTotal" name="mondeudx" inputmode="decimal" required autocomplete="off"></div>
        </div>
        <fieldset class="field" style="border:0">
          <legend class="label">¿Cómo se paga? <button class="help" type="button" data-help="debt_mode" aria-label="Ayuda sobre modalidades"><i class="bi bi-question-circle" aria-hidden="true"></i></button></legend>
          <div class="type-selector">
            <label class="type-option"><input type="radio" name="modo" value="abonos" checked><span class="type-pill">Abonos libres</span></label>
            <label class="type-option"><input type="radio" name="modo" value="cuotas"><span class="type-pill">Cuotas fijas</span></label>
          </div>
        </fieldset>
        <div class="form-grid" id="debtCuotasFields" hidden>
          <div class="field"><label for="debtNum">Número de cuotas</label><input class="input" id="debtNum" name="numcuotx" type="number" min="1" max="600" inputmode="numeric"></div>
          <div class="field"><label for="debtDay">Día de pago</label><input class="input" id="debtDay" name="feccuotx" type="number" min="1" max="31" inputmode="numeric"><span class="hint">Si el mes es más corto, se usa su último día.</span></div>
          <div class="field span-2"><label for="debtCuota">Valor de cada cuota</label><div class="input-group"><span class="prefix" aria-hidden="true">$</span><input class="input" id="debtCuota" name="moncuotx" inputmode="decimal" autocomplete="off"></div><span class="hint" id="debtCuotaHint">Se calcula automáticamente; puedes ajustarlo si incluye intereses.</span></div>
        </div>
        <p class="hint" id="debtModeHint">Registra cada pago como un movimiento vinculado a esta deuda.</p>
        <div class="alert alert-error" id="debtError" role="alert" hidden></div>
      </div>
      <div class="modal-footer">
        <button class="btn-secondary" type="button" data-close>Cancelar</button>
        <button class="btn-primary" type="submit">Guardar</button>
      </div>
    </form>
  </div>
</div>

<!-- Presupuesto -->
<div class="modal-overlay" id="budgetModal" role="dialog" aria-modal="true" aria-labelledby="budgetModalTitle">
  <div class="modal" style="max-width:480px">
    <div class="modal-header">
      <div><h3 id="budgetModalTitle">Nuevo presupuesto</h3><p>Se aplica cada mes a la categoría elegida.</p></div>
      <button class="modal-close" type="button" data-close aria-label="Cerrar">&times;</button>
    </div>
    <form id="budgetForm" novalidate>
      <div class="modal-body stack">
        <input type="hidden" name="id">
        <div class="field"><label for="budgetCat">Categoría</label><input class="input" id="budgetCat" name="categoria" list="categoryOptions" maxlength="30" required autocomplete="off"></div>
        <div class="field"><label for="budgetAmount">Límite mensual</label><div class="input-group"><span class="prefix" aria-hidden="true">$</span><input class="input input-money" id="budgetAmount" name="monto" inputmode="decimal" required autocomplete="off"></div><span class="hint" id="budgetSuggest"></span></div>
        <div class="field">
          <label for="budgetAlert">Avisarme al llegar al <strong id="budgetAlertVal">80</strong> %</label>
          <input id="budgetAlert" name="alerta" type="range" min="50" max="100" step="5" value="80" style="accent-color:var(--primary)">
        </div>
        <div class="alert alert-error" id="budgetError" role="alert" hidden></div>
      </div>
      <div class="modal-footer">
        <button class="btn-ghost left" type="button" data-action="budget-delete" style="color:var(--danger)" hidden>Eliminar</button>
        <button class="btn-secondary" type="button" data-close>Cancelar</button>
        <button class="btn-primary" type="submit">Guardar</button>
      </div>
    </form>
  </div>
</div>

<!-- Meta -->
<div class="modal-overlay" id="goalModal" role="dialog" aria-modal="true" aria-labelledby="goalModalTitle">
  <div class="modal" style="max-width:480px">
    <div class="modal-header">
      <div><h3 id="goalModalTitle">Nueva meta</h3><p>Ponle nombre, cuánto necesitas y para cuándo.</p></div>
      <button class="modal-close" type="button" data-close aria-label="Cerrar">&times;</button>
    </div>
    <form id="goalForm" novalidate>
      <div class="modal-body stack">
        <input type="hidden" name="id">
        <div class="field"><label for="goalName">Nombre</label><input class="input" id="goalName" name="nombre" maxlength="80" required placeholder="Ej. Fondo de emergencia"></div>
        <div class="field"><label for="goalTarget">Monto objetivo</label><div class="input-group"><span class="prefix" aria-hidden="true">$</span><input class="input input-money" id="goalTarget" name="objetivo" inputmode="decimal" required autocomplete="off"></div></div>
        <div class="field"><label for="goalDate">Fecha deseada <span class="muted">(opcional)</span></label><input class="input" id="goalDate" name="fecha" type="date"></div>
        <p class="hint" id="goalPlanHint" aria-live="polite"></p>
        <div class="alert alert-error" id="goalError" role="alert" hidden></div>
      </div>
      <div class="modal-footer">
        <button class="btn-secondary" type="button" data-close>Cancelar</button>
        <button class="btn-primary" type="submit">Guardar</button>
      </div>
    </form>
  </div>
</div>

<!-- Aporte / retiro de meta -->
<div class="modal-overlay" id="goalMoveModal" role="dialog" aria-modal="true" aria-labelledby="goalMoveTitle">
  <div class="modal" style="max-width:480px">
    <div class="modal-header">
      <div><h3 id="goalMoveTitle">Registrar aporte</h3><p id="goalMoveSub"></p></div>
      <button class="modal-close" type="button" data-close aria-label="Cerrar">&times;</button>
    </div>
    <form id="goalMoveForm" novalidate>
      <div class="modal-body stack">
        <input type="hidden" name="meta_id">
        <fieldset class="field" style="border:0">
          <legend class="label">Movimiento</legend>
          <div class="type-selector">
            <label class="type-option"><input type="radio" name="tipo" value="APORTE" checked><span class="type-pill income">Aportar</span></label>
            <label class="type-option"><input type="radio" name="tipo" value="RETIRO"><span class="type-pill expense">Retirar</span></label>
          </div>
        </fieldset>
        <div class="field"><label for="goalMoveAmount">Monto</label><div class="input-group"><span class="prefix" aria-hidden="true">$</span><input class="input input-money" id="goalMoveAmount" name="monto" inputmode="decimal" required autocomplete="off"></div></div>
        <div class="field"><label for="goalMoveDate">Fecha</label><input class="input" id="goalMoveDate" name="fecha" type="date" required></div>
        <div class="field"><label for="goalMoveNote">Nota <span class="muted">(opcional)</span></label><input class="input" id="goalMoveNote" name="descripcion" maxlength="150"></div>
        <div class="alert alert-error" id="goalMoveError" role="alert" hidden></div>
      </div>
      <div class="modal-footer">
        <button class="btn-secondary" type="button" data-close>Cancelar</button>
        <button class="btn-primary" type="submit">Registrar</button>
      </div>
    </form>
  </div>
</div>

<!-- Historial de meta -->
<div class="modal-overlay" id="goalDetailModal" role="dialog" aria-modal="true" aria-labelledby="goalDetailTitle">
  <div class="modal">
    <div class="modal-header">
      <div><h3 id="goalDetailTitle">Meta</h3></div>
      <button class="modal-close" type="button" data-close aria-label="Cerrar">&times;</button>
    </div>
    <div class="modal-body" id="goalDetailBody"></div>
  </div>
</div>

<!-- Importación -->
<div class="modal-overlay" id="importModal" role="dialog" aria-modal="true" aria-labelledby="importTitle">
  <div class="modal modal-lg">
    <div class="modal-header">
      <div><h3 id="importTitle">Importar movimientos</h3><p>Desde un archivo CSV, un extracto en PDF o una foto del extracto.</p></div>
      <button class="modal-close" type="button" data-close aria-label="Cerrar">&times;</button>
    </div>
    <div class="modal-body">
      <div class="steps" aria-hidden="true">
        <span data-step="1" class="active"><b>1</b>Archivo</span>
        <span data-step="2"><b>2</b>Revisión</span>
        <span data-step="3"><b>3</b>Resultado</span>
      </div>
      <div id="importStep1" class="stack">
        <div class="form-grid">
          <div class="field" id="importAccountField" hidden>
            <label for="importAccount">Cuenta</label>
            <select class="select" id="importAccount"><option value="PERSONAL">Personal</option><option value="BEATAN">Empresa (BeaTaN)</option></select>
          </div>
          <div class="field"><label for="importYear">Año del extracto</label><select class="select" id="importYear"></select><span class="hint">Para extractos que muestran fechas sin año.</span></div>
          <div class="field"><label for="importCategory">Categoría por defecto</label><input class="input" id="importCategory" value="Varios" list="categoryOptions" maxlength="30"></div>
          <div class="field"><label for="importPdfPassword">Clave del PDF <span class="muted">(si tiene)</span></label><input class="input" id="importPdfPassword" type="password" autocomplete="off"><span class="hint">Muchos bancos usan tu número de cédula.</span></div>
        </div>
        <button class="dropzone" type="button" id="importDropzone" aria-describedby="importFormats">
          <i class="bi bi-cloud-arrow-up" aria-hidden="true"></i>
          <strong>Arrastra tu archivo aquí o haz clic para elegirlo</strong>
          <span id="importFormats">CSV, PDF, PNG, JPG o WebP · máximo 10 MB. El archivo se procesa en tu navegador.</span>
        </button>
        <input type="file" id="importFile" accept=".csv,text/csv,.pdf,application/pdf,image/png,image/jpeg,image/webp" hidden>
        <details class="text-sm">
          <summary class="card-link" style="cursor:pointer">¿Qué formato debe tener el CSV?</summary>
          <p class="mt-2">La primera fila debe tener los nombres de columna. Reconocemos <strong>fecha</strong>, <strong>descripción</strong>, <strong>categoría</strong> y el importe como <strong>monto</strong> (negativo = gasto) o como columnas separadas <strong>débito/crédito</strong>. Separador coma o punto y coma. Ejemplo:</p>
          <pre class="mt-2" style="background:var(--surface-2);padding:8px;border-radius:8px;overflow:auto;font-size:12px">fecha;descripcion;categoria;monto
2026-09-02;Mercado;Alimentacion;-185000
2026-09-01;Nómina;Salario;4200000</pre>
        </details>
        <div class="loading" id="importLoading" hidden><div class="spinner" role="status" aria-label="Procesando"></div><span class="muted" id="importLoadingText" style="margin-left:12px">Procesando archivo…</span></div>
        <div class="alert alert-error" id="importError" role="alert" hidden></div>
      </div>
      <div id="importStep2" hidden>
        <div class="import-summary">
          <div class="flex gap-2 wrap" id="importBadges" aria-live="polite"></div>
          <div class="flex gap-2">
            <button class="btn-ghost btn-sm" type="button" data-action="import-select-all">Marcar válidos</button>
            <button class="btn-ghost btn-sm" type="button" data-action="import-select-none">Desmarcar todos</button>
          </div>
        </div>
        <div class="import-table-wrap"><table class="table" id="importTable"></table></div>
        <p class="hint mt-2">Los posibles duplicados (misma fecha y monto que un movimiento existente) vienen desmarcados. Márcalos solo si son movimientos distintos.</p>
      </div>
      <div id="importStep3" hidden></div>
    </div>
    <div class="modal-footer">
      <button class="btn-secondary" type="button" data-close id="importCancel">Cancelar</button>
      <button class="btn-secondary" type="button" data-action="import-back" id="importBack" hidden><i class="bi bi-arrow-left" aria-hidden="true"></i>Elegir otro archivo</button>
      <button class="btn-primary" type="button" data-action="import-confirm" id="importConfirm" hidden>Importar <span id="importSelectedCount">0</span> movimientos</button>
    </div>
  </div>
</div>

<!-- Reporte mensual -->
<div class="modal-overlay" id="reportModal" role="dialog" aria-modal="true" aria-labelledby="reportTitleH">
  <div class="modal modal-lg">
    <div class="modal-header">
      <div><h3 id="reportTitleH">Reporte mensual <span id="reportTitle" class="muted"></span></h3></div>
      <button class="modal-close" type="button" data-close aria-label="Cerrar">&times;</button>
    </div>
    <div class="modal-body">
      <div class="flex gap-2 wrap items-center" style="margin-bottom:16px">
        <div class="field" style="max-width:200px"><label for="reportMonth">Mes</label><input class="input" type="month" id="reportMonth"></div>
        <div class="flex gap-2" style="margin-left:auto;align-self:flex-end">
          <button class="btn-secondary btn-sm" type="button" data-action="report-print"><i class="bi bi-printer" aria-hidden="true"></i>Imprimir</button>
          <button class="btn-secondary btn-sm" type="button" data-action="report-excel"><i class="bi bi-file-earmark-excel" aria-hidden="true"></i>Excel</button>
        </div>
      </div>
      <div id="reportContent"></div>
    </div>
  </div>
</div>

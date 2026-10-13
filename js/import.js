/* dashboard/js/import.js
 * Importación de movimientos desde CSV, extractos PDF o imágenes (OCR).
 * El archivo se procesa en el navegador; al servidor solo llegan las filas confirmadas.
 */
(function () {
  'use strict';
  const App = window.App;
  const F = window.FinanceCore;
  const { esc, $, $$, money } = App;
  const MAX_BYTES = 10 * 1024 * 1024;

  const Import = {
    items: [],
    clientRejected: [],
    key: null,
    open() {
      const acc = $('#importAccountField');
      acc.hidden = !App.config.isAdmin;
      $('#importAccount').value = App.state.account;
      const year = new Date().getFullYear();
      $('#importYear').innerHTML = Array.from({ length: 6 }, (_, i) => `<option value="${year - i}">${year - i}</option>`).join('');
      this.step(1);
      App.modal.open('importModal', { focus: '#importDropzone' });
    },
    step(n) {
      [1, 2, 3].forEach((i) => { $('#importStep' + i).hidden = i !== n; });
      $$('#importModal .steps span').forEach((s) => s.classList.toggle('active', Number(s.dataset.step) === n));
      $('#importBack').hidden = n !== 2;
      $('#importConfirm').hidden = n !== 2;
      $('#importCancel').textContent = n === 3 ? 'Cerrar' : 'Cancelar';
      if (n === 1) {
        this.items = [];
        $('#importFile').value = '';
        $('#importLoading').hidden = true;
        $('#importDropzone').hidden = false;
        App.formError($('#importError'), null);
      }
    },
    loading(text) {
      $('#importDropzone').hidden = !!text;
      $('#importLoading').hidden = !text;
      if (text) $('#importLoadingText').textContent = text;
    },
    async handleFile(file) {
      App.formError($('#importError'), null);
      if (!file) return;
      const name = file.name.toLowerCase();
      const isCsv = name.endsWith('.csv') || file.type === 'text/csv';
      const isPdf = name.endsWith('.pdf') || file.type === 'application/pdf';
      const isImg = /^image\/(png|jpe?g|webp)$/.test(file.type);
      if (!isCsv && !isPdf && !isImg) { App.formError($('#importError'), new Error('Formato no soportado. Usa CSV, PDF, PNG, JPG o WebP.')); return; }
      if (file.size > MAX_BYTES) { App.formError($('#importError'), new Error('El archivo supera 10 MB. Divide el extracto o reduce la imagen.')); return; }
      const year = Number($('#importYear').value);
      const category = $('#importCategory').value.trim() || 'Varios';
      try {
        let parsed = [];
        this.clientRejected = [];
        if (isCsv) {
          this.loading('Leyendo el CSV…');
          const r = F.csvToTransactions(await file.text(), { defaultCategory: category, defaultYear: year });
          if (r.errors.length && !r.items.length) throw new Error(r.errors.join(' '));
          parsed = r.items;
          this.clientRejected = r.rejected;
          if (r.errors.length) App.toast('warning', 'Aviso del CSV', r.errors.join(' '));
        } else if (isPdf) {
          this.loading('Leyendo el PDF…');
          parsed = F.parseStatementLines(await this.pdfLines(file), year, category);
        } else {
          this.loading('Reconociendo el texto de la imagen (puede tardar un poco)…');
          parsed = F.parseStatementLines(await this.ocrLines(file), year, category);
        }
        if (!parsed.length) throw new Error(this.clientRejected.length ? 'Ninguna fila es válida. Revisa las fechas y los montos.' : 'No encontramos movimientos con fecha y monto en el archivo. Si es un PDF escaneado, prueba con una foto nítida o exporta un CSV desde tu banco.');
        this.loading('Comparando con tus movimientos existentes…');
        const existing = await this.existing(parsed);
        this.items = F.markDuplicates(parsed, existing);
        this.key = App.uid();
        this.renderPreview();
        this.step(2);
      } catch (err) {
        this.loading(null);
        App.formError($('#importError'), err);
      }
    },
    async pdfLines(file) {
      const pdfjs = await App.lib.pdf();
      const task = pdfjs.getDocument({ data: await file.arrayBuffer(), password: $('#importPdfPassword').value || undefined });
      task.onPassword = (update, reason) => {
        const msg = reason === 2 ? 'La clave no es correcta. Escríbela de nuevo:' : 'El PDF está protegido. Escribe la clave (muchos bancos usan tu cédula):';
        const p = window.prompt(msg);
        if (p) update(p);
        else task.destroy();
      };
      let pdf;
      try { pdf = await task.promise; } catch (e) { throw new Error(/password/i.test(e.message || '') ? 'No se pudo abrir el PDF: falta la clave o es incorrecta.' : 'No se pudo leer el PDF.'); }
      const lines = [];
      for (let i = 1; i <= pdf.numPages; i++) {
        const content = await (await pdf.getPage(i)).getTextContent();
        const items = content.items.map((it) => ({ text: it.str, x: it.transform[4], y: it.transform[5] })).filter((it) => it.text.trim());
        items.sort((a, b) => b.y - a.y || a.x - b.x);
        let current = [];
        let lastY = null;
        for (const it of items) {
          if (lastY === null || Math.abs(it.y - lastY) < 4) current.push(it.text);
          else { lines.push(current.join(' ')); current = [it.text]; }
          lastY = it.y;
        }
        if (current.length) lines.push(current.join(' '));
      }
      return lines;
    },
    async ocrLines(file) {
      const T = await App.lib.tesseract();
      const worker = await T.createWorker('spa');
      try {
        const r = await worker.recognize(file);
        return r.data.text.split('\n').map((l) => l.trim()).filter(Boolean);
      } finally { await worker.terminate(); }
    },
    async existing(items) {
      const dates = items.map((i) => i.fecha).sort();
      const acc = $('#importAccount').value;
      try {
        const res = await App.api('GET', `api/transactions.php?tipgasxx=${acc}&from=${dates[0]}&to=${dates[dates.length - 1]}`);
        return res.data.map((t) => ({ date: String(t.FINCFECX).slice(0, 10), cents: Number(t.MONTO_CENTS) }));
      } catch (e) {
        App.toast('warning', 'No se pudo verificar duplicados', 'Revisa la lista con cuidado antes de importar.');
        return [];
      }
    },
    renderPreview() {
      const dupes = this.items.filter((i) => i.duplicate).length;
      $('#importBadges').innerHTML = `<span class="badge badge-info">${this.items.length} detectados</span>
        ${dupes ? `<span class="badge badge-warning">${dupes} posibles duplicados</span>` : ''}
        ${this.clientRejected.length ? `<span class="badge badge-danger">${this.clientRejected.length} filas con errores</span>` : ''}`;
      const rows = this.items.map((it, i) => `
        <tr>
          <td><input type="checkbox" data-import-row="${i}" ${it.selected ? 'checked' : ''} aria-label="Importar fila ${i + 1}: ${esc(it.descripcion)}"></td>
          <td class="num" style="white-space:nowrap">${F.formatDate(it.fecha)}</td>
          <td>${esc(it.descripcion)}</td>
          <td><input class="input" style="min-height:32px;padding:4px 8px" value="${esc(it.categoria)}" maxlength="30" list="categoryOptions" data-import-cat="${i}" aria-label="Categoría de la fila ${i + 1}"></td>
          <td class="t-right"><span class="amount ${it.cents > 0 ? 'positive' : 'negative'}">${money(it.cents, { signed: true })}</span></td>
          <td>${it.duplicate ? '<span class="badge badge-warning">Posible duplicado</span>' : '<span class="badge badge-success">Nuevo</span>'}</td>
        </tr>`).join('');
      const rejected = this.clientRejected.map((r) => `
        <tr class="is-rejected"><td><i class="bi bi-x-circle" style="color:var(--danger)" aria-label="Rechazada"></i></td><td colspan="4">Fila ${r.line}: ${esc(r.raw)}</td><td><span class="badge badge-danger">${esc(r.reasons.join(', '))}</span></td></tr>`).join('');
      $('#importTable').innerHTML = `<thead><tr><th scope="col"><span class="sr-only">Importar</span></th><th scope="col">Fecha</th><th scope="col">Descripción</th><th scope="col">Categoría</th><th scope="col" class="t-right">Monto</th><th scope="col">Estado</th></tr></thead><tbody>${rows}${rejected}</tbody>`;
      this.count();
    },
    count() {
      const n = this.items.filter((i) => i.selected).length;
      $('#importSelectedCount').textContent = n;
      $('#importConfirm').disabled = n === 0;
    },
    async confirm(btn) {
      const selected = this.items.filter((i) => i.selected);
      if (!selected.length) return;
      const acc = $('#importAccount').value;
      const total = selected.reduce((s, i) => s + i.cents, 0);
      const ok = await App.confirm(`Se guardarán ${selected.length} movimientos en tu cuenta ${acc === 'BEATAN' ? 'de la empresa' : 'personal'} (efecto neto ${money(total, { signed: true })}).`, { title: 'Confirmar importación', confirmLabel: 'Importar', danger: false });
      if (!ok) return;
      await App.busy(btn, async () => {
        try {
          const res = await App.api('POST', 'api/transactions.php', {
            idempotent: this.key,
            body: {
              action: 'bulk_import',
              items: selected.map((i) => ({ tipgasxx: acc, tipo: i.tipo, monto: F.centsToDecimal(i.cents), categoria: i.categoria, descripcion: i.descripcion, fecha: i.fecha })),
            },
          });
          this.result(res, selected);
          App.dataChanged('transactions');
        } catch (err) {
          const rejected = err.payload && err.payload.rejected;
          App.toastError(err, 'No se importó nada');
          if (rejected) this.result({ count: 0, rejected, possible_duplicates: 0 }, selected);
        }
      });
    },
    result(res, sent) {
      const rejected = (res.rejected || []).map((r) => ({ item: sent[r.index], reasons: r.reasons }));
      $('#importStep3').innerHTML = `
        <div class="empty" style="padding-top:8px">
          <i class="bi ${res.count ? 'bi-check2-circle' : 'bi-exclamation-octagon'}" aria-hidden="true" style="${res.count ? 'background:var(--success-soft);color:var(--success)' : ''}"></i>
          <h3>${res.count ? `Se guardaron ${res.count} movimiento${res.count === 1 ? '' : 's'}` : 'No se guardó ningún movimiento'}</h3>
          <p>${res.count ? 'El servidor confirmó el guardado. Ya aparecen en tus movimientos y saldos.' : 'Revisa los motivos y vuelve a intentarlo.'}</p>
        </div>
        <dl class="dl" style="max-width:420px;margin:0 auto">
          <dt>Enviados</dt><dd>${sent.length}</dd>
          <dt>Guardados</dt><dd>${res.count}</dd>
          <dt>Rechazados por el servidor</dt><dd>${rejected.length}</dd>
          <dt>Filas inválidas del archivo</dt><dd>${this.clientRejected.length}</dd>
          ${res.possible_duplicates ? `<dt>Posibles duplicados guardados</dt><dd>${res.possible_duplicates} (los marcaste tú)</dd>` : ''}
        </dl>
        ${rejected.length ? `<div class="mt-4"><h4 class="text-sm">Filas rechazadas</h4><ul class="text-sm mt-2" style="padding-left:18px">${rejected.map((r) => `<li>${r.item ? esc(r.item.fecha + ' · ' + r.item.descripcion) : 'Fila'}: ${esc(r.reasons.join(', '))}</li>`).join('')}</ul></div>` : ''}`;
      this.step(3);
    },
  };

  document.addEventListener('click', (e) => {
    const el = e.target.closest('[data-action]');
    if (!el) return;
    switch (el.dataset.action) {
      case 'open-import': Import.open(); break;
      case 'import-back': Import.step(1); break;
      case 'import-confirm': Import.confirm(el); break;
      case 'import-select-all': Import.items.forEach((i) => { i.selected = !i.duplicate; }); Import.renderPreview(); break;
      case 'import-select-none': Import.items.forEach((i) => { i.selected = false; }); Import.renderPreview(); break;
      default: break;
    }
  });

  document.addEventListener('DOMContentLoaded', () => {
    const dz = $('#importDropzone');
    const input = $('#importFile');
    dz.addEventListener('click', () => input.click());
    input.addEventListener('change', () => Import.handleFile(input.files[0]));
    ['dragenter', 'dragover'].forEach((ev) => dz.addEventListener(ev, (e) => { e.preventDefault(); dz.classList.add('is-over'); }));
    ['dragleave', 'drop'].forEach((ev) => dz.addEventListener(ev, (e) => { e.preventDefault(); dz.classList.remove('is-over'); }));
    dz.addEventListener('drop', (e) => Import.handleFile(e.dataTransfer.files[0]));
    $('#importTable').addEventListener('change', (e) => {
      if (e.target.dataset.importRow !== undefined) { Import.items[Number(e.target.dataset.importRow)].selected = e.target.checked; Import.count(); }
      if (e.target.dataset.importCat !== undefined) Import.items[Number(e.target.dataset.importCat)].categoria = e.target.value.trim().slice(0, 30) || 'Varios';
    });
  });

  App.Import = Import;
})();

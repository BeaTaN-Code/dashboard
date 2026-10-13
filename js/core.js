/* dashboard/js/core.js
 * Infraestructura de la interfaz: API, estado, avisos, modales, ayudas, tema y utilidades.
 */
(function () {
  'use strict';
  const F = window.FinanceCore;
  const cfg = window.APP_CONFIG || {};
  const reduceMotion = window.matchMedia('(prefers-reduced-motion: reduce)');

  const App = (window.App = window.App || {});
  App.config = cfg;
  App.F = F;

  /* ------------------------------ Utilidades ------------------------------ */
  const escapeMap = { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' };
  App.esc = (v) => String(v === null || v === undefined ? '' : v).replace(/[&<>"']/g, (c) => escapeMap[c]);
  App.$ = (sel, root) => (root || document).querySelector(sel);
  App.$$ = (sel, root) => Array.from((root || document).querySelectorAll(sel));
  App.debounce = (fn, ms) => {
    let t;
    return (...a) => { clearTimeout(t); t = setTimeout(() => fn(...a), ms); };
  };
  App.uid = () => (window.crypto && crypto.randomUUID ? crypto.randomUUID() : Date.now().toString(36) + Math.random().toString(36).slice(2));
  App.today = () => F.toISODate(new Date());
  App.money = (cents, opts) => F.formatMoney(cents, opts);
  App.store = {
    get(key, fallback) { try { const v = localStorage.getItem(key); return v === null ? fallback : v; } catch (e) { return fallback; } },
    set(key, value) { try { localStorage.setItem(key, value); } catch (e) { /* almacenamiento no disponible */ } },
    remove(key) { try { localStorage.removeItem(key); } catch (e) { /* noop */ } },
  };

  /* --------------------------------- API --------------------------------- */
  class ApiError extends Error {
    constructor(message, status, payload) {
      super(message);
      this.status = status;
      this.payload = payload || {};
      this.errors = (payload && payload.errors) || [];
    }
  }
  App.ApiError = ApiError;
  const inflight = new Map();

  /**
   * Solicitud JSON al backend.
   *  - Añade el token CSRF en operaciones de escritura.
   *  - opts.key: cancela la solicitud anterior con la misma clave (evita que respuestas viejas
   *    sobrescriban resultados recientes al cambiar filtros rápido).
   *  - opts.idempotent: envía Idempotency-Key para que un reintento no duplique registros.
   */
  App.api = async function api(method, url, opts) {
    const o = opts || {};
    const headers = { Accept: 'application/json' };
    if (method !== 'GET') headers['X-CSRF-Token'] = cfg.csrf || '';
    if (o.idempotent) headers['Idempotency-Key'] = typeof o.idempotent === 'string' ? o.idempotent : App.uid();
    let body;
    if (o.body !== undefined) {
      headers['Content-Type'] = 'application/json';
      body = JSON.stringify(o.body);
    }
    let controller;
    if (o.key) {
      if (inflight.has(o.key)) inflight.get(o.key).abort();
      controller = new AbortController();
      inflight.set(o.key, controller);
    }
    let res;
    try {
      res = await fetch(url, { method, headers, body, credentials: 'same-origin', signal: controller && controller.signal });
    } catch (err) {
      if (err.name === 'AbortError') throw err;
      throw new ApiError('No hay conexión con el servidor. Revisa tu internet e inténtalo de nuevo.', 0);
    } finally {
      if (o.key && inflight.get(o.key) === controller) inflight.delete(o.key);
    }
    let data = null;
    const text = await res.text();
    try { data = text ? JSON.parse(text) : {}; } catch (e) { data = null; }

    if (res.status === 401) {
      App.toast('warning', 'Tu sesión terminó', 'Vuelve a iniciar sesión para continuar.');
      setTimeout(() => { window.location.href = 'login.php'; }, 1500);
      throw new ApiError('Sesión expirada', 401, data);
    }
    if (!res.ok || !data || data.success === false) {
      const msg = (data && data.error) || (res.status >= 500 ? 'El servidor tuvo un problema. Inténtalo de nuevo en unos minutos.' : 'No se pudo completar la operación.');
      throw new ApiError(msg, res.status, data);
    }
    return data;
  };
  App.isAbort = (e) => e && e.name === 'AbortError';

  // Compatibilidad: el código heredado usa fetch() directo. Se añade el token CSRF a las
  // escrituras hacia /api del mismo origen.
  const nativeFetch = window.fetch.bind(window);
  window.fetch = function (input, init) {
    try {
      const url = typeof input === 'string' ? input : input.url;
      const method = ((init && init.method) || 'GET').toUpperCase();
      const sameOrigin = !/^https?:\/\//i.test(url) || url.startsWith(window.location.origin);
      if (sameOrigin && method !== 'GET' && /(^|\/)api\//.test(url)) {
        init = Object.assign({}, init);
        const h = new Headers(init.headers || {});
        if (!h.has('X-CSRF-Token')) h.set('X-CSRF-Token', cfg.csrf || '');
        init.headers = h;
        init.credentials = init.credentials || 'same-origin';
      }
    } catch (e) { /* sin cambios */ }
    return nativeFetch(input, init);
  };

  /* -------------------------------- Avisos -------------------------------- */
  const ICONS = { success: 'bi-check-circle-fill', error: 'bi-x-circle-fill', warning: 'bi-exclamation-triangle-fill', info: 'bi-info-circle-fill' };
  App.toast = function toast(type, title, text, opts) {
    const o = opts || {};
    const region = document.getElementById('toastRegion');
    if (!region) return;
    const el = document.createElement('div');
    el.className = 'toast ' + type;
    el.setAttribute('role', type === 'error' ? 'alert' : 'status');
    el.innerHTML = `<i class="bi ${ICONS[type] || ICONS.info}" aria-hidden="true"></i>
      <div class="toast-body"><div class="toast-title"></div>${text ? '<div class="toast-text"></div>' : ''}</div>
      ${o.action ? '<button type="button" class="toast-action"></button>' : ''}
      <button type="button" class="toast-close" aria-label="Cerrar aviso">&times;</button>`;
    el.querySelector('.toast-title').textContent = title;
    if (text) el.querySelector('.toast-text').textContent = text;
    const remove = () => {
      if (!el.isConnected) return;
      el.classList.add('is-leaving');
      setTimeout(() => el.remove(), 220);
    };
    if (o.action) {
      const btn = el.querySelector('.toast-action');
      btn.textContent = o.action.label;
      btn.addEventListener('click', () => { remove(); o.action.onClick(); });
    }
    el.querySelector('.toast-close').addEventListener('click', remove);
    region.appendChild(el);
    while (region.children.length > 4) region.firstElementChild.remove();
    const ms = o.duration || (o.action ? 8000 : type === 'error' ? 7000 : 4500);
    let timer = setTimeout(remove, ms);
    el.addEventListener('mouseenter', () => clearTimeout(timer));
    el.addEventListener('mouseleave', () => { timer = setTimeout(remove, 2500); });
    return remove;
  };
  App.toastError = (err, fallback) => {
    if (App.isAbort(err)) return;
    App.toast('error', fallback || 'No se pudo completar', err && err.message ? err.message : String(err));
  };

  /* -------------------------------- Modales ------------------------------- */
  const FOCUSABLE = 'a[href],button:not([disabled]),input:not([disabled]):not([type="hidden"]),select:not([disabled]),textarea:not([disabled]),[tabindex]:not([tabindex="-1"])';
  const stack = [];
  App.modal = {
    open(id, opts) {
      const overlay = document.getElementById(id);
      if (!overlay) return;
      const o = opts || {};
      overlay.classList.remove('is-closing');
      overlay.classList.add('active');
      if (!overlay.hasAttribute('role')) { overlay.setAttribute('role', 'dialog'); overlay.setAttribute('aria-modal', 'true'); }
      stack.push({ id, returnFocus: document.activeElement, onClose: o.onClose });
      document.body.style.overflow = 'hidden';
      requestAnimationFrame(() => {
        const target = (o.focus && overlay.querySelector(o.focus)) || overlay.querySelector('[autofocus]') ||
          overlay.querySelector('.modal-body ' + FOCUSABLE) || overlay.querySelector(FOCUSABLE);
        if (target) target.focus({ preventScroll: true });
      });
    },
    close(id) {
      const idx = id ? stack.map((s) => s.id).lastIndexOf(id) : stack.length - 1;
      const overlay = document.getElementById(id || (stack[idx] && stack[idx].id));
      if (!overlay || !overlay.classList.contains('active')) return;
      const entry = idx >= 0 ? stack.splice(idx, 1)[0] : null;
      const finish = () => {
        overlay.classList.remove('active', 'is-closing');
        if (!stack.length) document.body.style.overflow = '';
      };
      if (reduceMotion.matches) finish();
      else { overlay.classList.add('is-closing'); setTimeout(finish, 180); }
      if (entry) {
        if (entry.returnFocus && entry.returnFocus.focus) entry.returnFocus.focus({ preventScroll: true });
        if (entry.onClose) entry.onClose();
      }
    },
    top() { return stack.length ? stack[stack.length - 1].id : null; },
  };
  // API global usada por los módulos heredados
  window.openModal = (id) => App.modal.open(id);
  window.closeModal = (id) => App.modal.close(id);

  document.addEventListener('click', (e) => {
    const closeBtn = e.target.closest('[data-close]');
    if (closeBtn) {
      const ov = closeBtn.closest('.modal-overlay');
      if (ov) App.modal.close(ov.id);
      return;
    }
    if (e.target.classList && e.target.classList.contains('modal-overlay') && e.target.classList.contains('active')) {
      if (e.target.id !== 'confirmModal') App.modal.close(e.target.id);
    }
  });
  document.addEventListener('keydown', (e) => {
    const top = App.modal.top();
    if (!top) return;
    const overlay = document.getElementById(top);
    if (e.key === 'Escape') {
      e.preventDefault();
      if (top === 'confirmModal') App.$('[data-confirm="no"]', overlay).click();
      else App.modal.close(top);
    } else if (e.key === 'Tab') {
      const items = App.$$(FOCUSABLE, overlay).filter((el) => el.offsetParent !== null);
      if (!items.length) return;
      const first = items[0];
      const last = items[items.length - 1];
      if (e.shiftKey && document.activeElement === first) { e.preventDefault(); last.focus(); }
      else if (!e.shiftKey && document.activeElement === last) { e.preventDefault(); first.focus(); }
    }
  });

  /** Diálogo de confirmación accesible. Resuelve true/false. */
  App.confirm = function confirm(text, opts) {
    const o = opts || {};
    const ov = document.getElementById('confirmModal');
    if (!ov) return Promise.resolve(window.confirm(text));
    App.$('#confirmTitle').textContent = o.title || '¿Confirmar?';
    App.$('#confirmText').textContent = text;
    const yes = App.$('#confirmYes');
    yes.textContent = o.confirmLabel || 'Confirmar';
    yes.className = o.danger === false ? 'btn-primary' : 'btn-danger';
    return new Promise((resolve) => {
      const handler = (e) => {
        const b = e.target.closest('[data-confirm]');
        if (!b) return;
        ov.removeEventListener('click', handler);
        App.modal.close('confirmModal');
        resolve(b.dataset.confirm === 'yes');
      };
      ov.addEventListener('click', handler);
      App.modal.open('confirmModal', { focus: o.danger === false ? '#confirmYes' : '[data-confirm="no"]' });
    });
  };

  /** Muestra errores de validación dentro de un formulario. */
  App.formError = function formError(box, err) {
    if (!box) return;
    if (!err) { box.hidden = true; box.textContent = ''; return; }
    const list = err.errors && err.errors.length ? err.errors : [err.message || String(err)];
    box.innerHTML = list.length > 1
      ? '<ul style="padding-left:18px">' + list.map((m) => '<li>' + App.esc(m) + '</li>').join('') + '</ul>'
      : App.esc(list[0]);
    box.hidden = false;
  };

  /** Bloquea un botón mientras dura una operación (evita dobles envíos). */
  App.busy = async function busy(button, fn) {
    if (!button) return fn();
    if (button.getAttribute('aria-busy') === 'true') return undefined;
    const html = button.innerHTML;
    button.setAttribute('aria-busy', 'true');
    button.disabled = true;
    button.innerHTML = '<span class="spinner" aria-hidden="true"></span><span>Guardando…</span>';
    try { return await fn(); } finally {
      button.removeAttribute('aria-busy');
      button.disabled = false;
      button.innerHTML = html;
    }
  };

  /* ---------------------------- Campos de dinero --------------------------- */
  /** Formatea mientras se escribe con separadores de miles colombianos, sin alterar el valor. */
  App.moneyInput = function moneyInput(input) {
    if (!input || input.dataset.money) return;
    input.dataset.money = '1';
    input.addEventListener('blur', () => {
      const c = F.parseAmount(input.value);
      if (input.value.trim() === '') { input.removeAttribute('aria-invalid'); return; }
      if (c === null) { input.setAttribute('aria-invalid', 'true'); return; }
      input.removeAttribute('aria-invalid');
      input.value = (Math.abs(c) / 100).toLocaleString('es-CO', { minimumFractionDigits: c % 100 ? 2 : 0, maximumFractionDigits: 2 });
    });
    input.addEventListener('input', () => input.removeAttribute('aria-invalid'));
  };
  App.readMoney = (input) => F.parseAmount(input.value);
  App.setMoney = (input, cents) => {
    input.value = cents === null || cents === undefined ? '' : (Math.abs(cents) / 100).toLocaleString('es-CO', { minimumFractionDigits: cents % 100 ? 2 : 0, maximumFractionDigits: 2 });
  };

  /* ------------------------- Animación de cifras -------------------------- */
  /** Anima una cifra hasta su valor final. El valor real se fija de inmediato para lectores de pantalla. */
  App.animateValue = function animateValue(el, cents, format) {
    if (!el) return;
    const fmt = format || ((c) => F.formatMoney(c));
    const finalText = fmt(cents);
    el.setAttribute('aria-label', finalText);
    const from = Number(el.dataset.value || 0);
    el.dataset.value = String(cents);
    if (reduceMotion.matches || from === cents || document.hidden) { el.textContent = finalText; return; }
    const start = performance.now();
    const dur = 450;
    const step = (now) => {
      const t = Math.min(1, (now - start) / dur);
      const eased = 1 - Math.pow(1 - t, 3);
      el.textContent = t < 1 ? fmt(Math.round(from + (cents - from) * eased)) : finalText;
      if (t < 1) requestAnimationFrame(step);
    };
    requestAnimationFrame(step);
  };

  /** Reduce el tamaño de una cifra hasta que quepa en su contenedor (importes muy grandes). */
  App.fitText = function fitText(els) {
    requestAnimationFrame(() => (els || []).forEach((el) => {
      el.style.fontSize = '';
      let size = parseFloat(getComputedStyle(el).fontSize);
      while (el.scrollWidth > el.clientWidth + 1 && size > 13) {
        size -= 1;
        el.style.fontSize = size + 'px';
      }
      if (el.scrollWidth > el.clientWidth + 1) el.style.whiteSpace = 'normal';
    }));
  };
  let fitTimer;
  window.addEventListener('resize', () => {
    clearTimeout(fitTimer);
    fitTimer = setTimeout(() => App.fitText(App.$$('.kpi-value')), 150);
  });

  /* --------------------------- Carga diferida ----------------------------- */
  const scripts = {};
  App.loadScript = function loadScript(src) {
    if (!scripts[src]) {
      scripts[src] = new Promise((resolve, reject) => {
        const s = document.createElement('script');
        s.src = src;
        s.async = true;
        s.onload = resolve;
        s.onerror = () => { delete scripts[src]; reject(new Error('No se pudo cargar un componente necesario. Revisa tu conexión.')); };
        document.head.appendChild(s);
      });
    }
    return scripts[src];
  };
  App.lib = {
    async pdf() {
      await App.loadScript('https://cdnjs.cloudflare.com/ajax/libs/pdf.js/3.11.174/pdf.min.js');
      window.pdfjsLib.GlobalWorkerOptions.workerSrc = 'https://cdnjs.cloudflare.com/ajax/libs/pdf.js/3.11.174/pdf.worker.min.js';
      return window.pdfjsLib;
    },
    async tesseract() { await App.loadScript('https://cdn.jsdelivr.net/npm/tesseract.js@5.1.0/dist/tesseract.min.js'); return window.Tesseract; },
    async exceljs() {
      // ExcelJS trae regenerator-runtime antiguo: si esta global no existe recurre a Function(),
      // que la CSP bloquea (sin 'unsafe-eval'). Predefinirla evita esa ruta.
      if (!('regeneratorRuntime' in window)) window.regeneratorRuntime = undefined;
      await App.loadScript('https://cdnjs.cloudflare.com/ajax/libs/exceljs/4.3.0/exceljs.min.js');
      if (!window.ExcelJS) throw new Error('No se pudo iniciar el generador de Excel.');
      return window.ExcelJS;
    },
    async html2pdf() { await App.loadScript('https://cdnjs.cloudflare.com/ajax/libs/html2pdf.js/0.10.1/html2pdf.bundle.min.js'); return window.html2pdf; },
  };

  /* --------------------------------- Tema --------------------------------- */
  App.theme = {
    get() { return App.store.get('beatan_theme', 'system'); },
    set(value) {
      if (value === 'light' || value === 'dark') document.documentElement.setAttribute('data-theme', value);
      else document.documentElement.removeAttribute('data-theme');
      App.store.set('beatan_theme', value);
      App.$$('#themeSwitch [data-theme-value]').forEach((b) => b.setAttribute('aria-pressed', String(b.dataset.themeValue === value)));
      const icon = App.$('#themeToggle i');
      if (icon) icon.className = 'bi ' + (value === 'light' ? 'bi-sun' : value === 'dark' ? 'bi-moon-stars' : 'bi-circle-half');
      const btn = App.$('#themeToggle');
      if (btn) btn.setAttribute('aria-label', 'Tema: ' + ({ light: 'claro', dark: 'oscuro', system: 'automático' }[value] || 'automático') + '. Cambiar tema');
      document.dispatchEvent(new CustomEvent('app:theme'));
    },
    isDark() {
      const v = App.theme.get();
      return v === 'dark' || (v === 'system' && window.matchMedia('(prefers-color-scheme: dark)').matches);
    },
  };
  window.matchMedia('(prefers-color-scheme: dark)').addEventListener('change', () => document.dispatchEvent(new CustomEvent('app:theme')));

  /* ---------------------------- Ayuda contextual --------------------------- */
  const HELP = {
    balance: ['Saldo disponible', 'Todo lo que has ingresado menos todo lo que has gastado, contando solo movimientos con fecha de hoy o anterior. Los movimientos programados a futuro no se incluyen todavía.'],
    free: ['Disponible libre', 'Tu saldo menos el dinero que apartaste para metas de ahorro. Es lo que puedes usar sin tocar tus metas.'],
    income: ['Ingresos del periodo', 'Suma de los ingresos registrados en el periodo elegido, hasta hoy. La comparación es contra el mismo número de días del periodo anterior.'],
    expense: ['Gastos del periodo', 'Suma de los gastos registrados en el periodo elegido, hasta hoy. Incluye cuotas de deudas ya pagadas.'],
    net: ['Flujo neto', 'Ingresos menos gastos del periodo. Si es positivo, ahorraste; si es negativo, gastaste más de lo que entró.'],
    networth: ['Patrimonio estimado', 'Tu saldo + lo que te deben − lo que debes. Es una estimación basada solo en lo registrado aquí (no incluye bienes como casa o carro).'],
    projection: ['¿Cómo se calcula?', 'Partimos de tu saldo real de hoy, sumamos los movimientos ya programados hasta el cierre y restamos lo que normalmente gastas en los días que faltan (según tus meses anteriores). El rango muestra el mejor y el peor caso de tu historial. Es una estimación, no una garantía.'],
    debt_mode: ['Abonos libres o cuotas', 'Con abonos libres registras cada pago cuando lo hagas. Con cuotas fijas, el sistema programa automáticamente una cuota cada mes en el día indicado.'],
    budget: ['Presupuesto', 'El máximo que quieres gastar en una categoría cada mes. Lo comparamos con lo que ya gastaste y con tu ritmo para avisarte a tiempo.'],
  };
  let openHelp = null;
  function closeHelp() {
    if (openHelp) { openHelp.btn.setAttribute('aria-expanded', 'false'); openHelp.pop.remove(); openHelp = null; }
  }
  document.addEventListener('click', (e) => {
    const btn = e.target.closest('[data-help]');
    if (!btn) { if (openHelp && !e.target.closest('.help-pop')) closeHelp(); return; }
    e.preventDefault();
    const same = openHelp && openHelp.btn === btn;
    closeHelp();
    if (same) return;
    const [title, text] = HELP[btn.dataset.help] || ['Ayuda', ''];
    const pop = document.createElement('span');
    pop.className = 'help-pop';
    pop.setAttribute('role', 'tooltip');
    pop.id = 'help-' + btn.dataset.help;
    pop.innerHTML = '<strong></strong><span></span>';
    pop.firstChild.textContent = title;
    pop.lastChild.textContent = text;
    btn.appendChild(pop);
    const r = pop.getBoundingClientRect();
    if (r.right > window.innerWidth - 8) pop.style.left = 'auto', pop.style.right = '-8px', pop.style.transform = 'none';
    if (r.left < 8) pop.style.left = '-8px', pop.style.transform = 'none';
    btn.setAttribute('aria-expanded', 'true');
    btn.setAttribute('aria-describedby', pop.id);
    openHelp = { btn, pop };
  });
  document.addEventListener('keydown', (e) => { if (e.key === 'Escape') closeHelp(); });
  App.helpButton = (key, label) => `<button class="help" type="button" data-help="${key}" aria-label="${App.esc(label || 'Más información')}" aria-expanded="false"><i class="bi bi-question-circle" aria-hidden="true"></i></button>`;

  /* ------------------------------ Estado global ----------------------------- */
  const listeners = {};
  App.on = (evt, fn) => { (listeners[evt] = listeners[evt] || []).push(fn); };
  App.emit = (evt, data) => { (listeners[evt] || []).forEach((fn) => { try { fn(data); } catch (e) { console.error(e); } }); };
  App.state = {
    account: 'PERSONAL',
    period: { preset: 'month', from: null, to: null },
    view: 'resumen',
  };
  /** Avisar a todas las vistas de que los datos cambiaron (tras guardar). */
  App.dataChanged = (what) => App.emit('data', what || 'all');

  App.periodQuery = function periodQuery() {
    const p = App.state.period;
    let q = 'period=' + encodeURIComponent(p.preset);
    if (p.preset === 'custom') q += '&from=' + encodeURIComponent(p.from) + '&to=' + encodeURIComponent(p.to);
    return q;
  };

  App.isMobile = () => window.matchMedia('(max-width: 767px)').matches;
})();

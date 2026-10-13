/* dashboard/js/finance-core.js
 * Funciones puras (sin DOM) usadas por la interfaz y por las pruebas de Node (tests/js).
 * Todos los importes se manejan en CENTAVOS enteros para evitar errores de punto flotante.
 */
(function (root, factory) {
  const api = factory();
  if (typeof module === 'object' && module.exports) module.exports = api;
  else root.FinanceCore = api;
})(typeof self !== 'undefined' ? self : this, function () {
  'use strict';

  const MAX_CENTS = 999999999999; // DECIMAL(12,2)

  /* ----------------------------- Dinero ----------------------------- */

  /**
   * Interpreta un importe escrito por una persona (formato colombiano o internacional):
   * "25.000", "25.000,50", "25,000.50", "$ 1.234.567", "1234,5", "-500". Devuelve centavos o null.
   */
  function parseAmount(input) {
    if (input === null || input === undefined) return null;
    if (typeof input === 'number') {
      if (!Number.isFinite(input)) return null;
      const c = Math.round(input * 100);
      return Math.abs(c) <= MAX_CENTS ? c : null;
    }
    let s = String(input).trim().replace(/[\s $]/g, '').replace(/COP/i, '');
    if (s === '') return null;
    let negative = false;
    if (/^\(.*\)$/.test(s)) { negative = true; s = s.slice(1, -1); }
    if (s.startsWith('-')) { negative = true; s = s.slice(1); }
    else if (s.startsWith('+')) s = s.slice(1);
    if (s.endsWith('-')) { negative = true; s = s.slice(0, -1); }
    if (!/^[\d.,]+$/.test(s) || !/\d/.test(s)) return null;

    const lastDot = s.lastIndexOf('.');
    const lastComma = s.lastIndexOf(',');
    let intPart = s;
    let frac = '';
    if (lastDot !== -1 && lastComma !== -1) {
      const decSep = lastDot > lastComma ? '.' : ',';
      const idx = Math.max(lastDot, lastComma);
      intPart = s.slice(0, idx);
      frac = s.slice(idx + 1);
      const thouSep = decSep === '.' ? ',' : '.';
      if (intPart.includes(decSep)) return null; // separador decimal repetido
      intPart = intPart.split(thouSep).join('');
    } else if (lastDot !== -1 || lastComma !== -1) {
      const sep = lastDot !== -1 ? '.' : ',';
      const parts = s.split(sep);
      const last = parts[parts.length - 1];
      const groupedThousands = parts.length > 1 && parts.slice(1).every((p) => p.length === 3) && parts[0].length >= 1 && parts[0].length <= 3;
      if (parts.length === 2 && last.length >= 1 && last.length <= 2) {
        intPart = parts[0];
        frac = last; // "1234,5" / "19.99"
      } else if (groupedThousands) {
        intPart = parts.join(''); // "25.000" / "1,234,567"
      } else {
        return null;
      }
    }
    if (!/^\d*$/.test(intPart) || !/^\d{0,2}$/.test(frac)) return null;
    if (intPart === '') intPart = '0';
    const cents = parseInt(intPart, 10) * 100 + parseInt((frac + '00').slice(0, 2), 10);
    if (!Number.isFinite(cents) || cents > MAX_CENTS) return null;
    return negative ? -cents : cents;
  }

  /** Centavos a string decimal para enviar al servidor: 123456 => "1234.56". */
  function centsToDecimal(cents) {
    const sign = cents < 0 ? '-' : '';
    const abs = Math.abs(Math.trunc(cents));
    return sign + Math.floor(abs / 100) + '.' + String(abs % 100).padStart(2, '0');
  }

  /** Centavos a texto en pesos colombianos. Muestra centavos solo si existen. */
  function formatMoney(cents, opts) {
    const o = opts || {};
    if (cents === null || cents === undefined || !Number.isFinite(cents)) return '—';
    const hasCents = Math.abs(cents) % 100 !== 0;
    const decimals = o.decimals !== undefined ? o.decimals : (hasCents ? 2 : 0);
    const value = Math.abs(cents) / 100;
    const body = value.toLocaleString('es-CO', { minimumFractionDigits: decimals, maximumFractionDigits: decimals });
    let sign = '';
    if (cents < 0) sign = '−';
    else if (o.signed && cents > 0) sign = '+';
    return sign + '$' + body;
  }

  /** Formato compacto para ejes: 1.250.000 => "$1,3 M". */
  function formatCompact(cents) {
    const v = Math.abs(cents) / 100;
    const sign = cents < 0 ? '−' : '';
    if (v >= 1e9) return sign + '$' + (v / 1e9).toLocaleString('es-CO', { maximumFractionDigits: 1 }) + ' mil M';
    if (v >= 1e6) return sign + '$' + (v / 1e6).toLocaleString('es-CO', { maximumFractionDigits: 1 }) + ' M';
    if (v >= 1e3) return sign + '$' + Math.round(v / 1e3).toLocaleString('es-CO') + ' mil';
    return sign + '$' + Math.round(v).toLocaleString('es-CO');
  }

  /** Variación porcentual segura: null si la base es 0 (evita porcentajes engañosos). */
  function pctChange(current, previous) {
    if (!previous) return null;
    return Math.round(((current - previous) / Math.abs(previous)) * 1000) / 10;
  }

  /* ----------------------------- Fechas ----------------------------- */

  const pad = (n) => String(n).padStart(2, '0');
  /** Fecha local YYYY-MM-DD (no UTC: evita que después de las 19:00 en Colombia salga "mañana"). */
  function toISODate(d) {
    return d.getFullYear() + '-' + pad(d.getMonth() + 1) + '-' + pad(d.getDate());
  }
  function parseISODate(s) {
    const m = /^(\d{4})-(\d{2})-(\d{2})$/.exec(String(s || ''));
    if (!m) return null;
    const d = new Date(+m[1], +m[2] - 1, +m[3], 12);
    return d.getMonth() === +m[2] - 1 && d.getDate() === +m[3] ? d : null;
  }
  function isValidISODate(s) { return parseISODate(s) !== null; }
  function addDays(iso, n) {
    const d = parseISODate(iso);
    d.setDate(d.getDate() + n);
    return toISODate(d);
  }
  function daysBetween(a, b) {
    return Math.round((parseISODate(b) - parseISODate(a)) / 86400000);
  }
  const MONTHS = ['enero', 'febrero', 'marzo', 'abril', 'mayo', 'junio', 'julio', 'agosto', 'septiembre', 'octubre', 'noviembre', 'diciembre'];
  const MONTHS_SHORT = ['ene', 'feb', 'mar', 'abr', 'may', 'jun', 'jul', 'ago', 'sep', 'oct', 'nov', 'dic'];
  const WEEKDAYS = ['dom', 'lun', 'mar', 'mié', 'jue', 'vie', 'sáb'];
  function formatDate(iso, style) {
    const d = parseISODate(iso);
    if (!d) return '—';
    if (style === 'long') return WEEKDAYS[d.getDay()] + ', ' + d.getDate() + ' de ' + MONTHS[d.getMonth()] + ' de ' + d.getFullYear();
    if (style === 'short') return d.getDate() + ' ' + MONTHS_SHORT[d.getMonth()];
    if (style === 'day') return WEEKDAYS[d.getDay()] + ' ' + d.getDate() + ' ' + MONTHS_SHORT[d.getMonth()];
    return pad(d.getDate()) + '/' + pad(d.getMonth() + 1) + '/' + d.getFullYear();
  }
  function monthLabel(ym) {
    const m = /^(\d{4})-(\d{2})$/.exec(ym || '');
    if (!m) return '';
    const name = MONTHS[+m[2] - 1];
    return name.charAt(0).toUpperCase() + name.slice(1) + ' ' + m[1];
  }
  function relativeDay(iso, today) {
    const n = daysBetween(today, iso);
    if (n === 0) return 'hoy';
    if (n === 1) return 'mañana';
    if (n === -1) return 'ayer';
    if (n > 1) return 'en ' + n + ' días';
    return 'hace ' + (-n) + ' días';
  }

  /** Fechas flexibles de CSV/extractos: 2026-09-02, 02/09/2026, 2/9/26, 02-09-2026 (día primero). */
  function parseFlexibleDate(value, defaultYear) {
    const s = String(value || '').trim();
    let m = /^(\d{4})[-/.](\d{1,2})[-/.](\d{1,2})/.exec(s);
    let y, mo, d;
    if (m) { y = +m[1]; mo = +m[2]; d = +m[3]; }
    else if ((m = /^(\d{1,2})[-/.](\d{1,2})[-/.](\d{2,4})/.exec(s))) {
      d = +m[1]; mo = +m[2]; y = +m[3];
      if (y < 100) y += 2000;
    } else if ((m = /^(\d{1,2})[-/.](\d{1,2})$/.exec(s)) && defaultYear) {
      d = +m[1]; mo = +m[2]; y = +defaultYear;
    } else return null;
    const iso = y + '-' + pad(mo) + '-' + pad(d);
    return isValidISODate(iso) ? iso : null;
  }

  /** Mismo cálculo de periodos que lib/finance.php (fin_period). */
  function resolvePeriod(preset, from, to, today) {
    const d = parseISODate(today);
    const y = d.getFullYear();
    const m = d.getMonth();
    const lastDay = (yy, mm) => new Date(yy, mm + 1, 0).getDate();
    const iso = (yy, mm, dd) => toISODate(new Date(yy, mm, dd, 12));
    switch (preset) {
      case 'week': {
        const dow = (d.getDay() + 6) % 7; // lunes = 0
        const start = addDays(today, -dow);
        return { preset, from: start, to: addDays(start, 6), label: 'Esta semana' };
      }
      case 'prev_month': {
        const pm = m === 0 ? 11 : m - 1;
        const py = m === 0 ? y - 1 : y;
        return { preset, from: iso(py, pm, 1), to: iso(py, pm, lastDay(py, pm)), label: monthLabel(py + '-' + pad(pm + 1)) };
      }
      case 'year':
        return { preset, from: y + '-01-01', to: y + '-12-31', label: 'Año ' + y };
      case 'last30':
        return { preset, from: addDays(today, -29), to: today, label: 'Últimos 30 días' };
      case 'custom':
        if (!isValidISODate(from) || !isValidISODate(to) || from > to || daysBetween(from, to) > 366 * 3) return null;
        return { preset, from, to, label: formatDate(from) + ' – ' + formatDate(to) };
      default:
        return { preset: 'month', from: iso(y, m, 1), to: iso(y, m, lastDay(y, m)), label: monthLabel(y + '-' + pad(m + 1)) };
    }
  }

  /* ------------------------------ CSV ------------------------------- */

  /** Parser CSV (RFC 4180) con detección de separador y soporte de comillas y saltos de línea. */
  function parseCSV(text) {
    let src = String(text || '').replace(/^﻿/, '');
    const firstLine = src.split(/\r?\n/, 1)[0] || '';
    const counts = { ';': 0, ',': 0, '\t': 0 };
    let inQ = false;
    for (const ch of firstLine) {
      if (ch === '"') inQ = !inQ;
      else if (!inQ && ch in counts) counts[ch]++;
    }
    const delim = Object.keys(counts).sort((a, b) => counts[b] - counts[a])[0] || ',';
    const rows = [];
    let row = [];
    let cell = '';
    let quoted = false;
    for (let i = 0; i < src.length; i++) {
      const ch = src[i];
      if (quoted) {
        if (ch === '"') {
          if (src[i + 1] === '"') { cell += '"'; i++; } else quoted = false;
        } else cell += ch;
      } else if (ch === '"') quoted = true;
      else if (ch === delim) { row.push(cell); cell = ''; }
      else if (ch === '\n' || ch === '\r') {
        if (ch === '\r' && src[i + 1] === '\n') i++;
        row.push(cell); cell = '';
        if (row.some((c) => c.trim() !== '')) rows.push(row);
        row = [];
      } else cell += ch;
    }
    row.push(cell);
    if (row.some((c) => c.trim() !== '')) rows.push(row);
    return { delimiter: delim, rows };
  }

  const norm = (s) => String(s || '').toLowerCase().normalize('NFD').replace(/[̀-ͯ]/g, '').replace(/[^a-z0-9]/g, '');
  const COLUMN_ALIASES = {
    date: ['fecha', 'date', 'fechamovimiento', 'fechaoperacion', 'fechatransaccion', 'dia'],
    description: ['descripcion', 'description', 'concepto', 'detalle', 'referencia', 'movimiento', 'nombre', 'comercio'],
    category: ['categoria', 'category', 'rubro', 'tipo_gasto', 'clasificacion'],
    amount: ['monto', 'valor', 'importe', 'amount', 'total', 'cantidad'],
    debit: ['debito', 'debit', 'cargo', 'retiro', 'salida', 'egreso', 'gasto', 'debitos'],
    credit: ['credito', 'credit', 'abono', 'deposito', 'entrada', 'ingreso', 'creditos'],
    type: ['tipo', 'type', 'naturaleza'],
  };
  /** Detecta qué columna contiene cada dato. Devuelve índices (o -1). */
  function mapColumns(headers) {
    const h = headers.map(norm);
    const map = {};
    for (const key of Object.keys(COLUMN_ALIASES)) {
      const aliases = COLUMN_ALIASES[key].map(norm);
      let idx = h.findIndex((x) => aliases.includes(x));
      if (idx === -1) idx = h.findIndex((x) => aliases.some((a) => a.length > 3 && x.startsWith(a)));
      map[key] = idx;
    }
    return map;
  }

  /**
   * Convierte filas CSV en movimientos validados. Devuelve {items, rejected, mapping, errors}.
   * Cada rechazo lleva el número de fila (contando la cabecera como fila 1) y el motivo.
   */
  function csvToTransactions(text, options) {
    const opt = Object.assign({ defaultCategory: 'Varios', maxRows: 1000 }, options || {});
    const { rows } = parseCSV(text);
    if (rows.length < 2) return { items: [], rejected: [], mapping: null, errors: ['El archivo no tiene filas de datos.'] };
    const mapping = mapColumns(rows[0]);
    const errors = [];
    if (mapping.date === -1) errors.push('No se encontró una columna de fecha.');
    if (mapping.amount === -1 && mapping.debit === -1 && mapping.credit === -1) errors.push('No se encontró una columna de monto (o débito/crédito).');
    if (errors.length) return { items: [], rejected: [], mapping, errors };
    if (rows.length - 1 > opt.maxRows) errors.push('El archivo tiene más de ' + opt.maxRows + ' filas; se procesarán las primeras ' + opt.maxRows + '.');

    const items = [];
    const rejected = [];
    rows.slice(1, opt.maxRows + 1).forEach((r, i) => {
      const line = i + 2;
      const reasons = [];
      const date = parseFlexibleDate(r[mapping.date], opt.defaultYear);
      if (!date) reasons.push('fecha inválida');
      let cents = null;
      if (mapping.amount !== -1 && String(r[mapping.amount] || '').trim() !== '') {
        cents = parseAmount(r[mapping.amount]);
        const t = mapping.type !== -1 ? norm(r[mapping.type]) : '';
        if (cents !== null && cents > 0 && /^(gasto|debito|egreso|cargo|retiro|d)$/.test(t)) cents = -cents;
      } else {
        const deb = mapping.debit !== -1 ? parseAmount(r[mapping.debit] || '') : null;
        const cre = mapping.credit !== -1 ? parseAmount(r[mapping.credit] || '') : null;
        if (deb) cents = -Math.abs(deb);
        else if (cre) cents = Math.abs(cre);
      }
      if (cents === null) reasons.push('monto no reconocido');
      else if (cents === 0) reasons.push('monto en cero');
      const description = String(mapping.description !== -1 ? r[mapping.description] || '' : '').trim().slice(0, 150);
      const category = (String(mapping.category !== -1 ? r[mapping.category] || '' : '').trim() || opt.defaultCategory).slice(0, 30);
      if (reasons.length) rejected.push({ line, reasons, raw: r.join(' | ').slice(0, 200) });
      else items.push({ fecha: date, cents, descripcion: description || 'Importado de CSV', categoria: category, tipo: cents < 0 ? 'GASTO' : 'INGRESO', line });
    });
    return { items, rejected, mapping, errors };
  }

  /* ------------------------ Extractos (PDF/OCR) ------------------------ */

  const MONTH_MAP = { ene: 1, enero: 1, feb: 2, febrero: 2, mar: 3, marzo: 3, abr: 4, abril: 4, may: 5, mayo: 5, jun: 6, junio: 6, jul: 7, julio: 7, ago: 8, agosto: 8, sep: 9, sept: 9, septiembre: 9, set: 9, oct: 10, octubre: 10, nov: 11, noviembre: 11, dic: 12, diciembre: 12 };

  /** Importe al final de una línea de extracto (admite signos, $ y separadores colombianos). */
  function parseStatementAmount(raw) {
    const s = String(raw || '').replace(/\s/g, '');
    return parseAmount(s);
  }

  /**
   * Detecta movimientos en líneas de texto de extractos (Nu, Bancolombia y similares).
   * Correcciones frente a la versión anterior: "1,234,567" ya no se lee como 1.234,
   * se rechazan fechas imposibles (31/02) y los importes se manejan en centavos.
   */
  function parseStatementLines(lines, defaultYear, defaultCategory) {
    const out = [];
    const cat = defaultCategory || 'Varios';
    for (let line of lines || []) {
      line = String(line).trim();
      if (line.length < 5) continue;
      if (/^(movimientos|fecha|concepto|descripci[oó]n|monto|balance|resumen|saldo|p[aá]gina|vistas|vigilado)/i.test(line)) continue;

      let date = null;
      let rest = '';
      let m;
      if ((m = /^(\d{1,2})\s+(?:de\s+)?([a-záéíóú]{3,10})\.?(?:\s+(?:de\s+)?(\d{4}))?\s+(.*)$/i.exec(line))) {
        const mon = MONTH_MAP[m[2].toLowerCase()] || MONTH_MAP[m[2].toLowerCase().slice(0, 3)];
        if (mon) {
          const iso = (m[3] || defaultYear) + '-' + pad(mon) + '-' + pad(+m[1]);
          if (isValidISODate(iso)) { date = iso; rest = m[4]; }
        }
      } else if ((m = /^(\d{1,4}[/.-]\d{1,2}(?:[/.-]\d{2,4})?)\s+(.*)$/.exec(line))) {
        date = parseFlexibleDate(m[1], defaultYear);
        rest = m[2];
      }
      if (!date) continue;

      const am = /([+-]?\s*\$?\s*\d{1,3}(?:[.,]\d{3})*(?:[.,]\d{1,2})?-?|[+-]?\s*\$?\s*\d+(?:[.,]\d{1,2})?-?)\s*$/.exec(rest);
      if (!am) continue;
      const rawAmount = am[1];
      let cents = parseStatementAmount(rawAmount);
      if (cents === null || cents === 0) continue;
      const desc = rest.slice(0, am.index).trim();

      const explicitSign = /[-+]/.test(rawAmount);
      if (!explicitSign) {
        if (/^(compra|pago|retiro|enviaste|transferencia enviada|d[eé]bito|cuota|comisi[oó]n|iva|gmf|4x1000)/i.test(desc)) cents = -Math.abs(cents);
        else cents = Math.abs(cents);
      }
      out.push({ fecha: date, descripcion: (desc || 'Movimiento de extracto').slice(0, 150), cents, categoria: cat, tipo: cents < 0 ? 'GASTO' : 'INGRESO' });
    }
    return out;
  }

  /** Marca posibles duplicados frente a movimientos existentes (misma fecha y monto). */
  function markDuplicates(items, existing) {
    const keys = new Map();
    for (const e of existing || []) {
      const k = e.date + '|' + e.cents;
      keys.set(k, (keys.get(k) || 0) + 1);
    }
    const seen = new Map();
    return items.map((it) => {
      const k = it.fecha + '|' + it.cents;
      const already = keys.get(k) || 0;
      const inFile = (seen.get(k) || 0);
      seen.set(k, inFile + 1);
      return Object.assign({}, it, { duplicate: already > inFile, selected: already <= inFile });
    });
  }

  /* -------------------------- Listados --------------------------- */

  /** Filtra movimientos normalizados {date, cents, cat, desc}. Importes en centavos absolutos. */
  function filterTransactions(list, f) {
    const q = norm(f && f.q);
    return list.filter((t) => {
      if (f.type === 'INGRESO' && t.cents <= 0) return false;
      if (f.type === 'GASTO' && t.cents >= 0) return false;
      if (f.category && t.cat !== f.category) return false;
      const abs = Math.abs(t.cents);
      if (f.min !== null && f.min !== undefined && abs < f.min) return false;
      if (f.max !== null && f.max !== undefined && abs > f.max) return false;
      if (q && !norm(t.desc + ' ' + t.cat).includes(q)) return false;
      return true;
    });
  }

  function sortTransactions(list, key, dir) {
    const mul = dir === 'asc' ? 1 : -1;
    const get = {
      date: (t) => t.date + String(t.id).padStart(10, '0'),
      amount: (t) => t.cents,
      category: (t) => norm(t.cat),
      description: (t) => norm(t.desc),
    }[key] || ((t) => t.date);
    return list.slice().sort((a, b) => {
      const x = get(a);
      const y = get(b);
      return (x < y ? -1 : x > y ? 1 : 0) * mul;
    });
  }

  function paginate(list, page, size) {
    const pages = Math.max(1, Math.ceil(list.length / size));
    const p = Math.min(Math.max(1, page), pages);
    return { page: p, pages, items: list.slice((p - 1) * size, p * size), total: list.length };
  }

  /** Neutraliza fórmulas en celdas exportadas (CSV/Excel). */
  function csvSafe(value) {
    const s = String(value === null || value === undefined ? '' : value);
    return /^[=+\-@\t\r]/.test(s) ? "'" + s : s;
  }

  return {
    MAX_CENTS, parseAmount, centsToDecimal, formatMoney, formatCompact, pctChange,
    toISODate, parseISODate, isValidISODate, addDays, daysBetween, formatDate, monthLabel, relativeDay, parseFlexibleDate, resolvePeriod,
    parseCSV, mapColumns, csvToTransactions, parseStatementLines, parseStatementAmount, markDuplicates,
    filterTransactions, sortTransactions, paginate, csvSafe,
  };
});

// dashboard/tests/js/finance-core.test.js — node --test tests/js
const test = require('node:test');
const assert = require('node:assert/strict');
const F = require('../../js/finance-core.js');

test('parseAmount: formatos colombianos e internacionales', () => {
  const cases = [
    ['25.000', 2500000], ['25.000,50', 2500050], ['25,000.50', 2500050], ['$ 1.234.567', 123456700],
    ['1,234,567', 123456700], ['1234,5', 123450], ['19.99', 1999], ['-500', -50000], ['(1.000)', -100000],
    ['1.000-', -100000], ['0', 0], ['7', 700], [0.1 + 0.2, 30], ['$0,01', 1],
  ];
  for (const [input, expected] of cases) assert.equal(F.parseAmount(input), expected, String(input));
});

test('parseAmount: rechaza entradas ambiguas o inválidas', () => {
  for (const bad of ['', 'abc', '1.2.3,4,5', '12,34,5', '1234.567', '1e5', null, undefined, NaN, '99999999999']) {
    assert.equal(F.parseAmount(bad), null, String(bad));
  }
});

test('centsToDecimal y formatMoney', () => {
  assert.equal(F.centsToDecimal(123456), '1234.56');
  assert.equal(F.centsToDecimal(-5), '-0.05');
  assert.equal(F.centsToDecimal(0), '0.00');
  assert.equal(F.formatMoney(123456700), '$1.234.567');
  assert.equal(F.formatMoney(150), '$1,50');
  assert.equal(F.formatMoney(-50000), '−$500');
  assert.equal(F.formatMoney(50000, { signed: true }), '+$500');
  assert.equal(F.formatMoney(null), '—');
  assert.equal(F.formatMoney(999999999999), '$9.999.999.999,99');
});

test('pctChange evita división por cero', () => {
  assert.equal(F.pctChange(10, 0), null);
  assert.equal(F.pctChange(150, 100), 50);
  assert.equal(F.pctChange(50, 100), -50);
});

test('fechas locales y validación de calendario', () => {
  assert.equal(F.toISODate(new Date(2026, 9, 13, 23, 30)), '2026-10-13'); // no se corre al día siguiente
  assert.equal(F.isValidISODate('2024-02-29'), true);
  assert.equal(F.isValidISODate('2025-02-29'), false);
  assert.equal(F.isValidISODate('2025-04-31'), false);
  assert.equal(F.addDays('2025-01-31', 1), '2025-02-01');
  assert.equal(F.addDays('2024-02-28', 1), '2024-02-29');
  assert.equal(F.daysBetween('2025-03-01', '2025-03-31'), 30);
  assert.equal(F.monthLabel('2025-09'), 'Septiembre 2025');
  assert.equal(F.relativeDay('2025-03-14', '2025-03-13'), 'mañana');
});

test('parseFlexibleDate: día primero', () => {
  assert.equal(F.parseFlexibleDate('02/09/2026'), '2026-09-02');
  assert.equal(F.parseFlexibleDate('2/9/26'), '2026-09-02');
  assert.equal(F.parseFlexibleDate('2026-09-02'), '2026-09-02');
  assert.equal(F.parseFlexibleDate('31/02/2026'), null);
  assert.equal(F.parseFlexibleDate('15/10', 2026), '2026-10-15');
  assert.equal(F.parseFlexibleDate('hola'), null);
});

test('parseCSV: comillas, separador ; y saltos de línea dentro de celdas', () => {
  const { delimiter, rows } = F.parseCSV('﻿fecha;descripcion;monto\r\n2026-09-02;"Mercado; ""Éxito""";-185.000\n2026-09-03;"Línea\nmultiple";1000\n\n');
  assert.equal(delimiter, ';');
  assert.equal(rows.length, 3);
  assert.equal(rows[1][1], 'Mercado; "Éxito"');
  assert.equal(rows[2][1], 'Línea\nmultiple');
});

test('csvToTransactions: acepta, rechaza con motivo y usa débito/crédito', () => {
  const csv = [
    'Fecha,Concepto,Débito,Crédito,Categoría',
    '01/09/2026,Nómina,,"4.200.000",Salario',
    '02/09/2026,Mercado,"185.000",,',
    '31/02/2026,Fecha imposible,"1.000",,',
    '03/09/2026,Sin monto,,,',
  ].join('\n');
  const r = F.csvToTransactions(csv, { defaultCategory: 'Varios' });
  assert.deepEqual(r.errors, []);
  assert.equal(r.items.length, 2);
  assert.equal(r.items[0].cents, 420000000);
  assert.equal(r.items[1].cents, -18500000);
  assert.equal(r.items[1].categoria, 'Varios');
  assert.equal(r.rejected.length, 2);
  assert.equal(r.rejected[0].line, 4);
  assert.match(r.rejected[0].reasons[0], /fecha/);
  assert.match(r.rejected[1].reasons[0], /monto/);
});

test('csvToTransactions: informa columnas faltantes', () => {
  const r = F.csvToTransactions('nombre,valor\nx,1');
  assert.equal(r.items.length, 0);
  assert.ok(r.errors.some((e) => /fecha/.test(e)));
});

test('parseStatementLines: corrige el bug de miles con comas y detecta signos', () => {
  const lines = [
    'Fecha Concepto Monto',
    '10 jul Compra Éxito $1,234,567',
    '11 jul Recibiste de Ana +$50.000',
    '31/02/2026 Fecha imposible 1.000',
    '12/07/2026 Retiro cajero 200.000,00',
    '13 julio 2025 Abono nómina 4.200.000',
  ];
  const r = F.parseStatementLines(lines, 2026, 'Varios');
  assert.equal(r.length, 4);
  assert.deepEqual([r[0].fecha, r[0].cents], ['2026-07-10', -123456700]);
  assert.deepEqual([r[1].fecha, r[1].cents], ['2026-07-11', 5000000]);
  assert.deepEqual([r[2].fecha, r[2].cents], ['2026-07-12', -20000000]);
  assert.deepEqual([r[3].fecha, r[3].cents], ['2025-07-13', 420000000]);
});

test('markDuplicates respeta repeticiones legítimas dentro del archivo', () => {
  const existing = [{ date: '2026-09-02', cents: -4590000 }];
  const items = [
    { fecha: '2026-09-02', cents: -4590000 },
    { fecha: '2026-09-02', cents: -4590000 },
    { fecha: '2026-09-03', cents: -100 },
  ];
  const r = F.markDuplicates(items, existing);
  assert.deepEqual(r.map((x) => x.duplicate), [true, false, false]);
  assert.deepEqual(r.map((x) => x.selected), [false, true, true]);
});

test('filtrar, ordenar y paginar', () => {
  const list = [
    { id: 1, date: '2026-09-01', cents: 100000, cat: 'Salario', desc: 'Nómina' },
    { id: 2, date: '2026-09-02', cents: -5000, cat: 'Alimentación', desc: 'Café' },
    { id: 3, date: '2026-09-03', cents: -90000, cat: 'Alimentación', desc: 'Mercado Éxito' },
  ];
  assert.deepEqual(F.filterTransactions(list, { type: 'GASTO' }).map((t) => t.id), [2, 3]);
  assert.deepEqual(F.filterTransactions(list, { q: 'exito' }).map((t) => t.id), [3]);
  assert.deepEqual(F.filterTransactions(list, { min: 10000, max: 95000 }).map((t) => t.id), [3]);
  assert.deepEqual(F.sortTransactions(list, 'amount', 'asc').map((t) => t.id), [3, 2, 1]);
  assert.deepEqual(F.sortTransactions(list, 'date', 'desc').map((t) => t.id), [3, 2, 1]);
  const p = F.paginate(list, 5, 2);
  assert.equal(p.page, 2);
  assert.equal(p.items.length, 1);
  assert.equal(F.paginate([], 1, 10).pages, 1);
});

test('csvSafe neutraliza fórmulas', () => {
  assert.equal(F.csvSafe('=SUM(A1)'), "'=SUM(A1)");
  assert.equal(F.csvSafe('+57 300'), "'+57 300");
  assert.equal(F.csvSafe('@cmd'), "'@cmd");
  assert.equal(F.csvSafe('Mercado'), 'Mercado');
});

test('resolvePeriod coincide con el servidor', () => {
  assert.deepEqual(F.resolvePeriod('month', null, null, '2025-02-13'), { preset: 'month', from: '2025-02-01', to: '2025-02-28', label: 'Febrero 2025' });
  assert.equal(F.resolvePeriod('month', null, null, '2024-02-10').to, '2024-02-29');
  const w = F.resolvePeriod('week', null, null, '2025-03-13');
  assert.deepEqual([w.from, w.to], ['2025-03-10', '2025-03-16']);
  const sunday = F.resolvePeriod('week', null, null, '2025-03-16');
  assert.deepEqual([sunday.from, sunday.to], ['2025-03-10', '2025-03-16']);
  const pm = F.resolvePeriod('prev_month', null, null, '2025-01-15');
  assert.deepEqual([pm.from, pm.to], ['2024-12-01', '2024-12-31']);
  assert.equal(F.resolvePeriod('custom', '2025-02-01', '2025-01-01', '2025-03-13'), null);
  assert.equal(F.resolvePeriod('year', null, null, '2025-03-13').to, '2025-12-31');
});

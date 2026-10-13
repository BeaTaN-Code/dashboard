/* dashboard/js/assistant.js
 * Asistente financiero con IA (Google Gemini).
 *  - Pide consentimiento antes de enviar datos al proveedor externo.
 *  - Sanea el Markdown de la respuesta (DOMPurify): la IA puede repetir texto de los movimientos.
 *  - Las acciones propuestas por la IA se muestran como tarjetas y solo se guardan si el usuario confirma.
 */
(function () {
  'use strict';
  const App = window.App;
  const F = window.FinanceCore;
  const { esc, $, money } = App;
  const KEY_STORE = 'beatan_gemini_key';
  const CONSENT = 'beatan_ai_consent';
  const history = [];
  let busy = false;
  let proposals = [];

  function render(content, role) {
    if (role === 'user' || typeof window.marked === 'undefined' || typeof window.DOMPurify === 'undefined') {
      return '<p>' + esc(content).replace(/\n/g, '<br>') + '</p>';
    }
    const html = window.marked.parse(String(content), { mangle: false, headerIds: false });
    return window.DOMPurify.sanitize(html, {
      ALLOWED_TAGS: ['p', 'br', 'strong', 'em', 'b', 'i', 'ul', 'ol', 'li', 'code', 'pre', 'blockquote', 'h3', 'h4', 'table', 'thead', 'tbody', 'tr', 'th', 'td', 'a', 'hr'],
      ALLOWED_ATTR: ['href'],
      ALLOWED_URI_REGEXP: /^https:\/\//i,
    });
  }

  function append(role, content, extraHtml) {
    const box = $('#aiChatMessages');
    const div = document.createElement('div');
    div.className = 'ai-message ' + role;
    div.innerHTML = `<div class="ai-msg-avatar" aria-hidden="true"><i class="bi ${role === 'user' ? 'bi-person' : 'bi-stars'}"></i></div><div class="ai-msg-content">${render(content, role)}${extraHtml || ''}</div>`;
    div.querySelectorAll('a').forEach((a) => { a.target = '_blank'; a.rel = 'noopener noreferrer'; });
    box.appendChild(div);
    box.scrollTop = box.scrollHeight;
    return div;
  }

  function proposalCard(p, idx) {
    const x = p.params;
    if (p.type === 'add_transaction') {
      return `<div class="ai-action-card" data-proposal="${idx}">
        <strong class="text-sm">¿Registrar este ${x.tipo === 'INGRESO' ? 'ingreso' : 'gasto'}?</strong>
        <dl class="dl"><dt>Monto</dt><dd class="num">${money(Math.round(x.monto * 100))}</dd><dt>Fecha</dt><dd>${F.formatDate(x.fecha)}</dd>
        <dt>Categoría</dt><dd>${esc(x.categoria)}</dd><dt>Descripción</dt><dd>${esc(x.descripcion || '—')}</dd>
        <dt>Cuenta</dt><dd>${x.tipgasxx === 'BEATAN' ? 'Empresa' : 'Personal'}</dd></dl>
        <div class="row"><button class="btn-primary btn-sm" type="button" data-ai-confirm="${idx}">Confirmar y guardar</button>
        <button class="btn-ghost btn-sm" type="button" data-ai-edit="${idx}">Revisar en el formulario</button>
        <button class="btn-ghost btn-sm" type="button" data-ai-dismiss="${idx}">Descartar</button></div></div>`;
    }
    return `<div class="ai-action-card" data-proposal="${idx}">
      <strong class="text-sm">¿Registrar esta deuda?</strong>
      <dl class="dl"><dt>Tipo</dt><dd>${x.tipdeudx === 'A FAVOR' ? 'Me deben' : 'Debo'}</dd><dt>Descripción</dt><dd>${esc(x.desdeudx)}</dd>
      <dt>Total</dt><dd class="num">${money(Math.round(x.mondeudx * 100))}</dd>${x.numcuotx ? `<dt>Cuotas</dt><dd>${x.numcuotx} de ${money(Math.round(x.moncuotx * 100))} (día ${x.feccuotx})</dd>` : ''}</dl>
      <div class="row"><button class="btn-primary btn-sm" type="button" data-ai-confirm="${idx}">Confirmar y guardar</button>
      <button class="btn-ghost btn-sm" type="button" data-ai-dismiss="${idx}">Descartar</button></div></div>`;
  }

  async function ensureConsent() {
    if (App.store.get(CONSENT) === '1') return true;
    const ok = await App.confirm('Para responder, el asistente envía a Google Gemini un resumen de tus movimientos, categorías y deudas (sin tu correo ni tu cédula). Las respuestas son orientativas y no constituyen asesoría financiera profesional. ¿Deseas continuar?', { title: 'Antes de usar el asistente', confirmLabel: 'Acepto', danger: false });
    if (ok) App.store.set(CONSENT, '1');
    return ok;
  }

  async function send(text) {
    const message = String(text || '').trim();
    if (!message || busy) return;
    if (!(await ensureConsent())) return;
    busy = true;
    $('#aiChatInput').value = '';
    const quick = $('.ai-quick-prompts');
    if (quick) quick.hidden = true;
    append('user', message);
    $('#aiTypingIndicator').hidden = false;
    const apiKey = App.store.get(KEY_STORE, '');
    try {
      const res = await App.api('POST', 'api/ai_chat.php', { body: { message, tipgasxx: App.state.account, history: history.slice(-8), apiKey } });
      if (res.reply) {
        const start = proposals.length;
        (res.pendingActions || []).forEach((p) => proposals.push(p));
        const cards = (res.pendingActions || []).map((p, i) => proposalCard(p, start + i)).join('');
        append('assistant', res.reply, cards);
        history.push({ role: 'user', content: message }, { role: 'assistant', content: res.reply });
      } else if (res.systemPrompt && apiKey) {
        const reply = await directGemini(res.systemPrompt, message, apiKey);
        append('assistant', reply);
        history.push({ role: 'user', content: message }, { role: 'assistant', content: reply });
      } else {
        append('assistant', 'Para activar el asistente, configura una clave de **Google Gemini** con el botón de la llave (es gratuita en Google AI Studio) o pide al administrador que configure una en el servidor.');
      }
    } catch (err) {
      if (!App.isAbort(err)) append('assistant', '⚠️ ' + (err.message || 'No pude procesar la consulta.'));
    } finally {
      busy = false;
      $('#aiTypingIndicator').hidden = true;
    }
  }

  /** Llamada directa desde el navegador cuando el servidor no tiene clave (usa la clave del usuario). */
  async function directGemini(systemPrompt, message, apiKey) {
    const key = encodeURIComponent(apiKey.trim());
    let model = 'gemini-3.6-flash';
    try {
      const list = await (await fetch('https://generativelanguage.googleapis.com/v1beta/models?key=' + key)).json();
      if (list.error) throw new Error(list.error.message || 'Clave de IA no válida');
      const m = (list.models || []).map((x) => x.name.replace('models/', '')).filter((n) => /flash/.test(n));
      model = m.find((n) => n.includes('3.6-flash')) || m[0] || model;
    } catch (e) { if (/key|clave|API/i.test(e.message)) throw e; }
    const contents = history.map((h) => ({ role: h.role === 'user' ? 'user' : 'model', parts: [{ text: h.content }] }));
    contents.push({ role: 'user', parts: [{ text: message }] });
    const r = await fetch(`https://generativelanguage.googleapis.com/v1beta/models/${encodeURIComponent(model)}:generateContent?key=${key}`, {
      method: 'POST', headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ systemInstruction: { parts: [{ text: systemPrompt }] }, contents, generationConfig: { temperature: 0.3, maxOutputTokens: 2048 } }),
    });
    const data = await r.json();
    if (!r.ok || data.error) throw new Error((data.error && data.error.message) || 'Error del proveedor de IA');
    const text = data.candidates && data.candidates[0] && data.candidates[0].content.parts.map((p) => p.text || '').join('');
    if (!text) throw new Error('La IA no devolvió respuesta.');
    return text.trim();
  }

  async function confirmProposal(idx, btn) {
    const p = proposals[idx];
    if (!p) return;
    await App.busy(btn, async () => {
      try {
        if (p.type === 'add_transaction') {
          await App.api('POST', 'api/transactions.php', { body: Object.assign({}, p.params, { monto: String(p.params.monto) }), idempotent: 'ai-' + idx + '-' + (p.key = p.key || App.uid()) });
        } else {
          await App.api('POST', 'api/debts.php', { body: Object.assign({}, p.params, { mondeudx: String(p.params.mondeudx), moncuotx: p.params.moncuotx !== null ? String(p.params.moncuotx) : null }), idempotent: 'ai-' + idx + '-' + (p.key = p.key || App.uid()) });
        }
        const card = document.querySelector(`[data-proposal="${idx}"]`);
        if (card) card.innerHTML = '<span class="badge badge-success"><i class="bi bi-check2" aria-hidden="true"></i>Guardado</span>';
        App.toast('success', 'Registrado', 'El asistente guardó lo que confirmaste.');
        App.dataChanged('all');
      } catch (err) { App.toastError(err, 'No se pudo guardar'); }
    });
  }

  function toggle(open) {
    const win = $('#aiChatWindow');
    const show = open !== undefined ? open : win.hidden;
    win.hidden = !show;
    $('#aiChatToggle').setAttribute('aria-expanded', String(show));
    if (show) setTimeout(() => $('#aiChatInput').focus(), 50);
    else $('#aiChatToggle').focus();
  }

  function configureKey() {
    const current = App.store.get(KEY_STORE, '');
    const value = window.prompt('Pega tu clave de Google Gemini (se guarda solo en este navegador). Déjalo vacío para borrarla.', current);
    if (value === null) return;
    const v = value.trim();
    if (v && !/^[A-Za-z0-9_-]{20,100}$/.test(v)) { App.toast('error', 'Clave no válida', 'Revisa que la copiaste completa.'); return; }
    if (v) { App.store.set(KEY_STORE, v); App.toast('success', 'Clave guardada', 'Se usará solo desde este navegador.'); }
    else { App.store.remove(KEY_STORE); App.toast('info', 'Clave eliminada'); }
  }

  document.addEventListener('click', (e) => {
    let el;
    if ((el = e.target.closest('[data-ai-confirm]'))) { confirmProposal(Number(el.dataset.aiConfirm), el); return; }
    if ((el = e.target.closest('[data-ai-dismiss]'))) { const c = el.closest('.ai-action-card'); if (c) c.innerHTML = '<span class="muted text-sm">Acción descartada.</span>'; return; }
    if ((el = e.target.closest('[data-ai-edit]'))) {
      const p = proposals[Number(el.dataset.aiEdit)];
      if (p) App.openTxModal({ tipo: p.params.tipo, cents: Math.round(p.params.monto * 100), fecha: p.params.fecha, categoria: p.params.categoria, descripcion: p.params.descripcion, account: p.params.tipgasxx });
      return;
    }
    if ((el = e.target.closest('[data-prompt]'))) { send(el.dataset.prompt); return; }
    if (e.target.closest('#aiChatToggle')) { toggle(); return; }
    const action = (el = e.target.closest('[data-action]')) ? el.dataset.action : null;
    if (action === 'ai-close') toggle(false);
    else if (action === 'ai-key') configureKey();
    else if (action === 'ai-forget') { App.store.remove(KEY_STORE); App.store.remove(CONSENT); App.toast('info', 'Listo', 'Se borró tu clave y el permiso del asistente en este navegador.'); }
  });
  document.addEventListener('keydown', (e) => { if (e.key === 'Escape' && !$('#aiChatWindow').hidden && !App.modal.top()) toggle(false); });
  document.addEventListener('DOMContentLoaded', () => {
    $('#aiChatForm').addEventListener('submit', (e) => { e.preventDefault(); send($('#aiChatInput').value); });
  });
})();

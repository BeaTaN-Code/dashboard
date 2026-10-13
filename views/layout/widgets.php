<?php defined('APP_PAGE') || exit; ?>
<!-- Navegación inferior (móvil) -->
<nav class="bottom-nav" aria-label="Navegación rápida">
  <a href="#resumen" data-nav="resumen"><i class="bi bi-grid-1x2" aria-hidden="true"></i>Resumen</a>
  <a href="#movimientos" data-nav="movimientos"><i class="bi bi-arrow-left-right" aria-hidden="true"></i>Movimientos</a>
  <button type="button" class="fab" data-action="new-transaction" aria-label="Nuevo movimiento"><i class="bi bi-plus-lg" aria-hidden="true"></i></button>
  <a href="#presupuestos" data-nav="presupuestos"><i class="bi bi-pie-chart" aria-hidden="true"></i>Presupuestos</a>
  <button type="button" data-action="open-menu" aria-controls="sidebar" aria-label="Más opciones"><i class="bi bi-three-dots" aria-hidden="true"></i>Más</button>
</nav>

<!-- Avisos -->
<div class="toast-region" id="toastRegion" aria-live="polite" aria-atomic="false"></div>

<!-- Asistente de IA -->
<button id="aiChatToggle" class="ai-chat-toggle" type="button" aria-controls="aiChatWindow" aria-expanded="false">
  <i class="bi bi-stars" aria-hidden="true"></i><span class="ai-toggle-label">Asistente</span>
</button>
<section id="aiChatWindow" class="ai-chat-window" role="dialog" aria-label="Asistente financiero" hidden>
  <div class="ai-chat-header">
    <div class="ai-header-info">
      <div class="ai-avatar" aria-hidden="true"><i class="bi bi-stars"></i></div>
      <div>
        <h4>Asistente financiero</h4>
        <span class="ai-status-text">Responde con tus datos · orientativo</span>
      </div>
    </div>
    <div class="flex gap-2">
      <button class="btn-icon" type="button" data-action="ai-key" aria-label="Configurar clave de IA" title="Configurar clave de IA"><i class="bi bi-key" aria-hidden="true"></i></button>
      <button class="btn-icon" type="button" data-action="ai-close" aria-label="Cerrar asistente"><i class="bi bi-x-lg" aria-hidden="true"></i></button>
    </div>
  </div>
  <div class="ai-chat-body" id="aiChatMessages" aria-live="polite">
    <div class="ai-message assistant">
      <div class="ai-msg-avatar" aria-hidden="true"><i class="bi bi-stars"></i></div>
      <div class="ai-msg-content">
        <p>Hola. Puedo explicarte tus números, resumir tus gastos o preparar un registro por ti (tú lo confirmas antes de guardarlo).</p>
      </div>
    </div>
    <div class="ai-quick-prompts">
      <button type="button" data-prompt="¿En qué categoría gasté más este mes y cuánto?">¿En qué gasto más?</button>
      <button type="button" data-prompt="Resume mis ingresos y gastos de este mes comparados con el mes pasado">Comparar con el mes pasado</button>
      <button type="button" data-prompt="Dame 3 ideas concretas para ahorrar según mis movimientos recientes">Ideas para ahorrar</button>
    </div>
  </div>
  <div id="aiTypingIndicator" class="ai-typing-indicator" hidden>
    <div class="ai-typing-dots" aria-hidden="true"><span></span><span></span><span></span></div>
    <span style="margin-left:8px">Analizando tus datos…</span>
  </div>
  <form class="ai-chat-footer" id="aiChatForm">
    <label class="sr-only" for="aiChatInput">Escribe tu pregunta</label>
    <input class="input" type="text" id="aiChatInput" maxlength="2000" placeholder="Pregunta sobre tus finanzas…" autocomplete="off">
    <button class="btn-primary" type="submit" aria-label="Enviar"><i class="bi bi-send-fill" aria-hidden="true"></i></button>
  </form>
</section>

<!-- Paleta de comandos (Ctrl + K) -->
<div class="command-palette-backdrop" id="commandPaletteModal" role="dialog" aria-modal="true" aria-label="Buscar y ejecutar comandos">
  <div class="command-palette-modal">
    <div class="command-search-header">
      <i class="bi bi-search" aria-hidden="true"></i>
      <input type="text" id="commandPaletteInput" class="command-search-input" placeholder="Buscar sección o acción…" aria-label="Buscar" aria-controls="commandPaletteResults" autocomplete="off">
      <span class="command-shortcut-badge">Esc</span>
    </div>
    <div class="command-results-list" id="commandPaletteResults" role="listbox"></div>
  </div>
</div>

// content.js — runs on web.whatsapp.com
// 1) Auto-capture: unread chats whose title is a phone number (i.e. not a saved contact) → new CRM lead.
// 2) Panel: shows the open chat's CRM status and lets staff add the contact as a lead.
// All reading of WhatsApp's page lives in the WA object below. If WhatsApp changes its layout,
// that is the only part that needs updating (use the popup's "Check this page" to diagnose).

(() => {
  if (window.__clinicCrmLoaded) return;
  window.__clinicCrmLoaded = true;

  const SCAN_MS = 2500;
  const MAX_AUTO_PER_SCAN = 5;
  const SEEN_TTL_DAYS = 30;

  // ─────────────────────────── WhatsApp page reading ───────────────────────────
  const WA = {
    digits: (s) => (s || '').replace(/\D/g, ''),

    /** "+971 50 123 4567" → "+971501234567", or null if it isn't a phone number. */
    asPhone(text) {
      if (!text) return null;
      const t = text.replace(/[‎‏‪-‮]/g, '').trim();
      if (!/^\+?[\d\s\-().]{8,22}$/.test(t)) return null;
      const d = WA.digits(t);
      return d.length >= 8 && d.length <= 15 ? '+' + d : null;
    },

    chatList() { return document.querySelector('#pane-side'); },

    chatRows() {
      const pane = WA.chatList();
      if (!pane) return [];
      const rows = pane.querySelectorAll('[role="listitem"], [role="row"]');
      // Keep outermost rows only
      return [...rows].filter((r) => !r.parentElement.closest('#pane-side [role="listitem"], #pane-side [role="row"]'));
    },

    /** Reads one chat-list row. */
    readRow(row) {
      const titled = [...row.querySelectorAll('span[title]')];
      const title = titled[0]?.getAttribute('title') || '';
      const preview = titled.slice(1).map((s) => s.getAttribute('title')).filter((t) => t && t !== title).pop() || '';
      const unreadEl = row.querySelector('[aria-label*="unread" i]');
      const unread = unreadEl ? parseInt(WA.digits(unreadEl.getAttribute('aria-label') || unreadEl.textContent), 10) || 1 : 0;
      const isGroup = !!row.querySelector('[data-icon*="group" i]');
      return { title, phone: WA.asPhone(title), preview, unread, isGroup };
    },

    /** The chat currently open on the right. */
    openChat() {
      const main = document.querySelector('#main');
      if (!main) return null;
      const header = main.querySelector('header');
      if (!header) return null;
      const titleEl = header?.querySelector('span[dir="auto"][title], span[dir="auto"]');
      const title = (titleEl?.getAttribute('title') || titleEl?.textContent || '').trim();
      let phone = WA.asPhone(title);
      if (!phone) {
        // Message ids look like "false_971501234567@c.us_3EB0…" and contain the number even for saved contacts
        for (const el of main.querySelectorAll('[data-id*="@c.us"]')) {
          const m = el.getAttribute('data-id').match(/(\d{8,15})@c\.us/);
          if (m) { phone = '+' + m[1]; break; }
        }
      }
      const isGroup = !!main.querySelector('[data-id*="@g.us"]');
      const incoming = main.querySelectorAll('.message-in');
      const lastIn = incoming.length ? incoming[incoming.length - 1] : null;
      const lastMessage = (lastIn?.querySelector('.selectable-text, [data-pre-plain-text] span')?.innerText || '').trim();
      return { title, name: phone && WA.asPhone(title) ? '' : title, phone, isGroup, lastMessage };
    },

    diagnose() {
      const rows = WA.chatRows().map(WA.readRow);
      return {
        chatListFound: !!WA.chatList(),
        rows: rows.length,
        unknownNumberRows: rows.filter((r) => r.phone && !r.isGroup).length,
        unreadRows: rows.filter((r) => r.unread).length,
        wouldCapture: rows.filter((r) => r.phone && r.unread && !r.isGroup).map((r) => r.phone),
        openChat: WA.openChat(),
      };
    },
  };
  window.__clinicCrmWA = WA; // for debugging from DevTools

  // ─────────────────────────── CRM calls (via background) ───────────────────────────
  const api = (action, opts = {}) =>
    new Promise((resolve) => {
      try {
        chrome.runtime.sendMessage({ type: 'api', action, ...opts }, (r) =>
          resolve(r || { ok: false, data: { error: chrome.runtime.lastError?.message || 'No response' } })
        );
      } catch (e) {
        resolve({ ok: false, data: { error: 'Extension was updated. Reload this page.' } });
      }
    });
  const getSettings = () => new Promise((r) => chrome.runtime.sendMessage({ type: 'settings' }, r));

  // ─────────────────────────── Seen numbers (avoid re-sending) ───────────────────────────
  let seen = {};
  chrome.storage.local.get('seen').then(({ seen: s = {} }) => {
    const cutoff = Date.now() - SEEN_TTL_DAYS * 864e5;
    seen = Object.fromEntries(Object.entries(s).filter(([, t]) => t > cutoff));
  });
  const markSeen = (phone) => { seen[phone] = Date.now(); chrome.storage.local.set({ seen }); };

  // ─────────────────────────── Auto-capture ───────────────────────────
  let busy = false;
  async function autoCapture(cfg) {
    if (busy || !cfg.autoCapture || !cfg.apiKey) return;
    busy = true;
    try {
      const candidates = WA.chatRows().map(WA.readRow)
        .filter((r) => r.phone && r.unread && !r.isGroup && !seen[r.phone])
        .slice(0, MAX_AUTO_PER_SCAN);
      for (const c of candidates) {
        const r = await api('capture', { body: { phone: c.phone, name: '', message: c.preview, auto: true } });
        if (r.status === 401 || r.data?.error === 'not_configured') { panel.setError('API key rejected — check the extension settings.'); break; }
        if (!r.ok && r.status !== 422) continue; // network hiccup: retry next scan
        markSeen(c.phone);
        if (r.data?.created) toast(`New lead added: ${c.phone}`, r.data.lead?.url);
      }
    } finally {
      busy = false;
    }
  }

  // ─────────────────────────── Panel (shadow DOM) ───────────────────────────
  const host = document.createElement('div');
  host.id = 'clinic-crm-panel';
  host.style.cssText = 'position:fixed;right:16px;bottom:16px;z-index:2147483000;';
  const root = host.attachShadow({ mode: 'open' });
  root.innerHTML = `
    <style>
      :host { all: initial; }
      * { box-sizing: border-box; font-family: 'Segoe UI', system-ui, -apple-system, sans-serif; }
      .card { width: 252px; background: #fff; color: #1F1D1A; border: 1px solid #E9E4DA; border-radius: 12px;
              box-shadow: 0 10px 28px rgba(31,29,26,.16); overflow: hidden; font-size: 12.5px; }
      .head { display: flex; align-items: center; gap: 7px; padding: 6px 8px 6px 10px; background: #FAF8F4; border-bottom: 1px solid #E9E4DA;
              cursor: grab; user-select: none; touch-action: none; }
      :host(.dragging) .head { cursor: grabbing; }
      :host(.dragging) .card { box-shadow: 0 16px 36px rgba(31,29,26,.26); }
      .logo { width: 20px; height: 20px; border-radius: 6px; background: linear-gradient(145deg,#C9A46C,#8A6934); color: #fff; display: grid; place-items: center; font-size: 11px; font-weight: 700; flex-shrink: 0; }
      .head b { font-size: 12.5px; flex: 1; white-space: nowrap; }
      .grip { color: #B0A99D; font-size: 12px; letter-spacing: -2px; line-height: 1; }
      .min { width: 20px; height: 20px; border: 0; border-radius: 5px; background: transparent; color: #8A8378; font-size: 15px; line-height: 1; cursor: pointer; padding: 0; display: grid; place-items: center; }
      .min:hover { background: #EFEAE1; color: #1F1D1A; }
      .dot { width: 8px; height: 8px; border-radius: 50%; background: #B0A99D; flex-shrink: 0; }
      .dot.on { background: #2F7A52; } .dot.err { background: #B23A3A; }
      .body { padding: 10px; max-height: 60vh; overflow-y: auto; }
      /* Minimised: a small pill with the logo and status dot */
      .collapsed { width: auto; border-radius: 16px; }
      .collapsed .head { border-bottom: 0; padding: 5px 9px 5px 6px; gap: 6px; }
      .collapsed .body, .collapsed .foot, .collapsed .grip, .collapsed .min { display: none; }
      .collapsed .head b { font-size: 12px; }
      .muted { color: #8A8378; font-size: 11.5px; }
      .row { display: flex; align-items: center; gap: 8px; }
      .name { font-weight: 600; font-size: 13px; }
      .chip { display: inline-flex; align-items: center; height: 18px; padding: 0 7px; border-radius: 9px; font-size: 10.5px; font-weight: 600; background: #EEF0F3; color: #5B6472; }
      .chip.new{background:#EEF0F3;color:#5B6472}.chip.contacted{background:#EAF0F8;color:#33609E}.chip.qualified{background:#F1ECF8;color:#6A4AA0}
      .chip.booked{background:#FCF2E0;color:#9A6512}.chip.converted{background:#E9F4EE;color:#2F7A52}.chip.lost{background:#FAEBEB;color:#B23A3A}.chip.cold{background:#E6F2F1;color:#2C7470}
      label { display: block; font-size: 11px; font-weight: 600; color: #57524A; margin: 6px 0 3px; }
      input, textarea { width: 100%; border: 1px solid #D9D1C3; border-radius: 7px; padding: 5px 8px; font-size: 12.5px; color: #1F1D1A; background: #fff; outline: none; }
      input:focus, textarea:focus { border-color: #B8925A; box-shadow: 0 0 0 3px rgba(184,146,90,.2); }
      textarea { resize: vertical; min-height: 40px; }
      .btn { display: inline-flex; align-items: center; justify-content: center; gap: 6px; width: 100%; margin-top: 8px; padding: 6px 10px; border-radius: 7px; border: 1px solid #B8925A;
             background: #B8925A; color: #fff; font-weight: 600; font-size: 12.5px; cursor: pointer; text-decoration: none; }
      .btn:hover { background: #A27E48; }
      .btn.ghost { background: #fff; color: #1F1D1A; border-color: #D9D1C3; }
      .btn.ghost:hover { background: #FAF8F4; }
      .btn[disabled] { opacity: .6; cursor: default; }
      .err { color: #B23A3A; font-size: 11.5px; margin-top: 6px; }
      .foot { padding: 4px 10px; border-top: 1px solid #E9E4DA; background: #FAF8F4; font-size: 11px; color: #8A8378; }
      .foot:empty, .foot:has(#auto:empty) { display: none; }
      .toast { position: absolute; right: 0; bottom: calc(100% + 8px); width: 260px; background: #1F1D1A; color: #fff; padding: 9px 11px; border-radius: 10px; font-size: 12px;
               box-shadow: 0 8px 24px rgba(0,0,0,.2); opacity: 0; transform: translateY(6px); transition: opacity .2s, transform .2s; pointer-events: none; }
      .toast.below { bottom: auto; top: calc(100% + 8px); transform: translateY(-6px); }
      .toast.left { right: auto; left: 0; }
      .toast.show { opacity: 1; transform: none; pointer-events: auto; }
      .toast a { color: #E6D6BA; margin-left: 6px; }
    </style>
    <div class="toast" id="toast"></div>
    <div class="card" id="card">
      <div class="head" id="head"><span class="logo">C</span><b>Clinic CRM</b><span class="dot" id="dot" title="Status"></span><span class="grip">⋮⋮</span><button class="min" id="collapse" title="Minimise">–</button></div>
      <div class="body" id="body"><span class="muted">Loading…</span></div>
      <div class="foot"><span id="auto"></span></div>
    </div>`;
  document.documentElement.appendChild(host);

  const $ = (id) => root.getElementById(id);
  const esc = (s) => String(s ?? '').replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));

  const panel = {
    collapsed: false,
    setError(msg) { $('dot').className = 'dot err'; $('body').innerHTML = `<div class="err">${esc(msg)}</div>`; },
    render(html) { $('body').innerHTML = html; },
  };
  // Minimise to a small pill (remembered)
  const setCollapsed = (on, save) => {
    panel.collapsed = on;
    $('card').classList.toggle('collapsed', on);
    $('head').title = on ? 'Click to open · drag to move' : 'Click to minimise · drag to move';
    if (save) chrome.storage.local.set({ panelCollapsed: on });
  };
  chrome.storage.local.get('panelCollapsed').then(({ panelCollapsed }) => setCollapsed(!!panelCollapsed, false));
  $('collapse').addEventListener('click', () => setCollapsed(true, true));

  // Drag the panel anywhere by its header. It stays pinned to the nearest screen corner, so in the
  // top half it opens downwards from the header and in the bottom half it opens upwards (the default).
  const EDGE = 16;
  const pos = { h: 'right', x: EDGE, v: 'bottom', y: EDGE }; // x/y = distance from the pinned edges
  const clamp = (n, max) => Math.round(Math.min(Math.max(n, 0), Math.max(max, 0)));
  const place = () => {
    pos.x = clamp(pos.x, innerWidth - host.offsetWidth);
    pos.y = clamp(pos.y, innerHeight - host.offsetHeight);
    host.style.left = host.style.right = host.style.top = host.style.bottom = 'auto';
    host.style[pos.h] = pos.x + 'px';
    host.style[pos.v] = pos.y + 'px';
  };
  chrome.storage.local.get('panelPos').then(({ panelPos: p }) => {
    if (p && ['left', 'right'].includes(p.h) && ['top', 'bottom'].includes(p.v) && Number.isFinite(p.x) && Number.isFinite(p.y)) Object.assign(pos, p);
    place();
  });
  new ResizeObserver(place).observe($('card')); // stay on screen when it opens or its content grows
  addEventListener('resize', place);

  let drag = null;
  $('head').addEventListener('pointerdown', (e) => {
    if (e.button !== 0 || e.target.closest('#collapse')) return;
    const r = host.getBoundingClientRect();
    drag = { id: e.pointerId, x: e.clientX, y: e.clientY, left: r.left, top: r.top, moved: false };
    $('head').setPointerCapture(e.pointerId);
  });
  $('head').addEventListener('pointermove', (e) => {
    if (!drag || e.pointerId !== drag.id) return;
    const dx = e.clientX - drag.x, dy = e.clientY - drag.y;
    if (!drag.moved && Math.hypot(dx, dy) < 4) return; // a small wobble still counts as a click
    drag.moved = true;
    host.classList.add('dragging');
    Object.assign(pos, { h: 'left', x: drag.left + dx, v: 'top', y: drag.top + dy });
    place();
  });
  const endDrag = (e) => {
    if (!drag || e.pointerId !== drag.id) return;
    const moved = drag.moved;
    drag = null;
    host.classList.remove('dragging');
    if (moved) {
      // Re-pin to the nearest corner
      const r = host.getBoundingClientRect();
      const right = r.left + r.width / 2 > innerWidth / 2, bottom = r.top + r.height / 2 > innerHeight / 2;
      Object.assign(pos, { h: right ? 'right' : 'left', x: right ? innerWidth - r.right : r.left,
                           v: bottom ? 'bottom' : 'top', y: bottom ? innerHeight - r.bottom : r.top });
      place();
      chrome.storage.local.set({ panelPos: { ...pos } });
    } else if (e.type === 'pointerup') setCollapsed(!panel.collapsed, true);
  };
  $('head').addEventListener('pointerup', endDrag);
  $('head').addEventListener('pointercancel', endDrag);

  let toastTimer;
  function toast(text, url) {
    const t = $('toast');
    const r = host.getBoundingClientRect();
    t.classList.toggle('below', r.top < 90);   // no room above the panel → show it underneath
    t.classList.toggle('left', r.right < 270); // near the left edge → line up with the panel's left side
    t.innerHTML = esc(text) + (url ? `<a href="${esc(url)}" target="_blank" rel="noopener">Open</a>` : '');
    t.classList.add('show');
    clearTimeout(toastTimer);
    toastTimer = setTimeout(() => t.classList.remove('show'), 5000);
  }

  // Render the open chat's state
  let currentKey = '';
  const lookupCache = new Map();

  async function showChat(chat, cfg) {
    if (!cfg.apiKey) {
      $('dot').className = 'dot err';
      panel.render(`<div class="muted">Not connected to the CRM yet.</div><button class="btn" id="openOpts">Open settings</button>`);
      $('openOpts').addEventListener('click', () => chrome.runtime.sendMessage({ type: 'openOptions' }));
      return;
    }
    $('dot').className = 'dot on';
    if (!chat) return panel.render(`<div class="muted">Open a chat to see if the contact is in the CRM.</div>`);
    if (chat.isGroup) return panel.render(`<div class="name">${esc(chat.title)}</div><div class="muted">Group chat — not a lead.</div>`);

    if (chat.phone) {
      let r = lookupCache.get(chat.phone);
      if (!r) {
        panel.render(`<div class="name">${esc(chat.title || chat.phone)}</div><div class="muted">Checking CRM…</div>`);
        r = await api('lookup', { params: { phone: chat.phone } });
        if (r.ok) lookupCache.set(chat.phone, r);
      }
      if (!r.ok) return panel.setError(r.data?.error || 'CRM error');
      if (r.data.found) {
        let l = r.data.lead;
        const waName = (chat.name || '').trim();
        const remember = (lead) => lookupCache.set(chat.phone, { ok: true, data: { found: true, lead } });

        // Contact saved in the phone → replace a placeholder CRM name automatically
        if (waName && waName !== l.name && l.placeholder) {
          const u = await api('rename', { body: { phone: chat.phone, name: waName } });
          if (u.ok && u.data?.updated) { l = u.data.lead; remember(l); toast(`Name updated to ${l.name}`, l.url); }
        }
        // Lead already has a different real name → offer, don't overwrite
        const offer = waName && waName !== l.name && !l.placeholder;

        panel.render(`
          <div class="row" style="justify-content:space-between"><span class="name">${esc(l.name)}</span><span class="chip ${esc(l.stage)}">${esc(l.stage)}</span></div>
          <div class="muted" style="margin-top:2px">${esc(chat.phone)}${l.owner ? ' · ' + esc(l.owner) : ''}</div>
          ${offer ? `<div class="muted" style="margin-top:8px">WhatsApp name: <b>${esc(waName)}</b> · <a href="#" id="renameBtn" style="color:#8A6934">Use this name</a></div>` : ''}
          <a class="btn ghost" href="${esc(l.url)}" target="_blank" rel="noopener">Open in CRM ↗</a>`);
        if (offer) {
          $('renameBtn').addEventListener('click', async (e) => {
            e.preventDefault();
            const u = await api('rename', { body: { phone: chat.phone, name: waName, force: true } });
            if (u.ok && u.data?.lead) { remember(u.data.lead); toast(`Name updated to ${u.data.lead.name}`, u.data.lead.url); }
            else toast(u.data?.error || 'Could not update the name.');
            currentKey = ''; // re-render
          });
        }
        return;
      }
    }

    // Not in CRM (or number unknown) → add form
    panel.render(`
      <div class="name">${esc(chat.title || 'New contact')}</div>
      <div class="muted">${chat.phone ? 'Not in the CRM yet.' : 'Couldn’t read the number. Please type it in.'}</div>
      <label>Name</label><input id="fName" value="${esc(chat.name)}" placeholder="Client name">
      <label>Phone</label><input id="fPhone" value="${esc(chat.phone || '')}" placeholder="+971 50 123 4567">
      <label>Message / note</label><textarea id="fMsg" placeholder="What are they asking about?">${esc(chat.lastMessage)}</textarea>
      <button class="btn" id="addBtn">Add as lead</button>
      <div class="err" id="fErr" hidden></div>`);
    $('addBtn').addEventListener('click', async () => {
      const phone = WA.asPhone($('fPhone').value);
      if (!phone) { $('fErr').hidden = false; $('fErr').textContent = 'Enter a valid phone number with country code.'; return; }
      $('addBtn').disabled = true; $('addBtn').textContent = 'Adding…';
      const r = await api('capture', { body: { phone, name: $('fName').value.trim(), message: $('fMsg').value.trim(), auto: false } });
      if (!r.ok && !r.data?.lead) { $('addBtn').disabled = false; $('addBtn').textContent = 'Add as lead'; $('fErr').hidden = false; $('fErr').textContent = r.data?.error || 'Could not add lead.'; return; }
      markSeen(phone);
      let lead = r.data.lead;
      // Existing lead with a placeholder name → take the name typed / shown in WhatsApp
      const typedName = $('fName').value.trim();
      if (!r.data.created && typedName && typedName !== lead.name && lead.placeholder) {
        const u = await api('rename', { body: { phone, name: typedName } });
        if (u.ok && u.data?.updated) lead = u.data.lead;
      }
      lookupCache.set(phone, { ok: true, data: { found: true, lead } });
      toast(r.data.created ? `Added ${lead.name} to the CRM`
        : (lead.name === typedName ? `Name updated to ${lead.name}` : `Already in CRM as ${lead.name}`), lead.url);
      currentKey = ''; // force re-render
    });
  }

  // ─────────────────────────── Main loop ───────────────────────────
  async function tick() {
    let cfg;
    try { cfg = await getSettings(); } catch (e) { return; } // extension reloaded
    if (!cfg) return;
    $('auto').textContent = cfg.apiKey ? `Auto-capture ${cfg.autoCapture ? 'on' : 'off'}` : '';
    autoCapture(cfg);
    const chat = WA.openChat();
    const key = cfg.apiKey ? (chat ? `${chat.title}|${chat.phone}|${chat.isGroup}` : 'none') : 'nokey';
    if (key !== currentKey) {
      currentKey = key;
      showChat(chat, cfg);
    }
  }

  chrome.storage.onChanged.addListener(() => { currentKey = ''; lookupCache.clear(); });
  chrome.runtime.onMessage.addListener((msg, _s, sendResponse) => {
    if (msg.type === 'diagnose') sendResponse(WA.diagnose());
  });
  setInterval(tick, SCAN_MS);
  tick();
})();

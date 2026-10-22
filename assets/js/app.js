// assets/js/app.js

// ─── Shared palette (keep in sync with app.css tokens) ───
const CRM = {
  colors: {
    gold: '#B8925A', goldLight: '#F5EDDF', green: '#2F7A52', amber: '#C98A1E', orange: '#C8662B',
    red: '#B23A3A', blue: '#4A74B0', violet: '#7E5CB5', slate: '#7A8392', teal: '#3A8F8A',
    tx2: '#57524A', tx3: '#8A8378', border: '#E9E4DA', surface: '#FFFFFF',
  },
  // Categorical order for charts with many series (sources etc.)
  series: ['#B8925A', '#4A74B0', '#3A8F8A', '#7E5CB5', '#C8662B', '#2F7A52', '#B25A7A', '#7A8392', '#9C8B6A', '#5C8FB8'],
  stage: {
    new: '#7A8392', contacted: '#4A74B0', qualified: '#7E5CB5', booked: '#C98A1E',
    converted: '#2F7A52', lost: '#B23A3A', cold: '#3A8F8A',
  },
};

// ─── Sidebar (mobile off-canvas) ───
document.getElementById('sidebarToggle')?.addEventListener('click', () => document.body.classList.add('sidebar-open'));
document.getElementById('sidebarBackdrop')?.addEventListener('click', () => document.body.classList.remove('sidebar-open'));
document.addEventListener('keydown', e => {
  if (e.key === 'Escape') document.body.classList.remove('sidebar-open');
  // "/" focuses global search (unless typing in a field)
  if (e.key === '/' && !/^(INPUT|TEXTAREA|SELECT)$/.test(document.activeElement.tagName) && !document.activeElement.isContentEditable) {
    const s = document.getElementById('globalSearch');
    if (s) { e.preventDefault(); s.focus(); s.select(); }
  }
});

// ─── Nav counters (hot leads + due follow-ups) and critical alert ───
async function loadNavCounters() {
  try {
    const r = await fetch('/pages/api.php?action=nav_counts');
    const d = await r.json();
    const hotEl = document.getElementById('nav-hot-count');
    const dueEl = document.getElementById('nav-due-count');
    if (hotEl && d.hot_leads > 0) hotEl.textContent = d.hot_leads;
    if (dueEl && d.due_followups > 0) dueEl.textContent = d.due_followups;
    const apptEl = document.getElementById('nav-appt-count');
    if (apptEl && (d.appointments_today > 0 || d.appointments_overdue > 0)) {
      apptEl.textContent = d.appointments_today || '!';
      if (d.appointments_overdue > 0) { apptEl.classList.add('danger'); apptEl.title = d.appointments_overdue + ' past treatment(s) still need completing'; }
    }
    const invEl = document.getElementById('nav-inv-count');
    if (invEl && d.inventory_alerts > 0) { invEl.textContent = d.inventory_alerts; invEl.title = T('Products short, low or expired'); }

    if (d.critical_leads > 0) {
      const alert = document.getElementById('aging-alert');
      if (alert) {
        alert.classList.remove('d-none');
        document.getElementById('aging-alert-count').textContent = d.critical_leads + ' lead' + (d.critical_leads == 1 ? '' : 's');
      }
    }
  } catch (e) {}
}
loadNavCounters();

// ─── Score bar colour ───
function scoreColor(score) {
  if (score >= 86) return CRM.colors.violet;
  if (score >= 61) return CRM.colors.orange;
  if (score >= 31) return CRM.colors.amber;
  return CRM.colors.slate;
}

document.querySelectorAll('.score-bar-fill[data-score]').forEach(el => {
  const score = parseInt(el.dataset.score || 0);
  el.style.width = score + '%';
  el.style.background = scoreColor(score);
});

// ─── Auto-dismiss alerts ───
setTimeout(() => {
  document.querySelectorAll('.alert-auto').forEach(el => {
    el.style.transition = 'opacity .4s';
    el.style.opacity = '0';
    setTimeout(() => el.remove(), 400);
  });
}, 3500);

// ─── Confirm destructive actions ───
document.querySelectorAll('[data-confirm]').forEach(el => {
  el.addEventListener('click', e => {
    if (!confirm(el.dataset.confirm || T('Are you sure?'))) { e.preventDefault(); e.stopPropagation(); }
  });
});

// ─── Clickable table rows ───
document.addEventListener('click', e => {
  const row = e.target.closest('tr[data-href]');
  if (!row || e.target.closest('a, button, input, select, label')) return;
  window.location = row.dataset.href;
});

// ─── Chart.js defaults ───
if (window.Chart) {
  Chart.defaults.font.family = "'DM Sans', system-ui, sans-serif";
  Chart.defaults.font.size = 12;
  Chart.defaults.color = CRM.colors.tx3;
  Chart.defaults.borderColor = CRM.colors.border;
  Chart.defaults.plugins.legend.labels.boxWidth = 10;
  Chart.defaults.plugins.legend.labels.boxHeight = 10;
  Chart.defaults.plugins.legend.labels.useBorderRadius = true;
  Chart.defaults.plugins.legend.labels.borderRadius = 3;
  Chart.defaults.plugins.tooltip.backgroundColor = '#1F1D1A';
  Chart.defaults.plugins.tooltip.padding = 10;
  Chart.defaults.plugins.tooltip.cornerRadius = 8;
  Chart.defaults.plugins.tooltip.titleFont = { weight: '600' };
  Chart.defaults.elements.bar.borderRadius = 4;
  Chart.defaults.elements.line.borderWidth = 2;
  Chart.defaults.elements.point.radius = 0;
  Chart.defaults.elements.point.hoverRadius = 4;
  Chart.defaults.scale.grid.color = '#F0ECE4';
  Chart.defaults.scale.border = { display: false };
}

// ─── Day / night theme ───
// theme_head.php sets data-theme before paint; this keeps the menu, charts and saved choice in sync.
const Theme = {
  light: { tx2: '#57524A', tx3: '#8A8378', border: '#E9E4DA', surface: '#FFFFFF', grid: '#F0ECE4', tip: '#1F1D1A', goldLight: '#F5EDDF' },
  dark:  { tx2: '#BCB4A7', tx3: '#8E8679', border: '#332E28', surface: '#1C1A17', grid: '#2A2621', tip: '#2E2A25', goldLight: '#3A3025' },
  pref() { try { return localStorage.getItem('crm-theme') || 'light'; } catch (e) { return 'light'; } },
  isDark(pref) { return pref === 'dark' || (pref === 'auto' && window.matchMedia?.('(prefers-color-scheme: dark)').matches); },
  apply(pref) {
    const dark = Theme.isDark(pref), root = document.documentElement;
    root.setAttribute('data-theme', dark ? 'dark' : 'light');
    root.setAttribute('data-bs-theme', dark ? 'dark' : 'light');
    root.setAttribute('data-theme-pref', pref);
    document.querySelectorAll('[data-theme-set]').forEach((b) => b.classList.toggle('active', b.dataset.themeSet === pref));
    Theme.paintCharts(dark ? Theme.dark : Theme.light);
  },
  set(pref) {
    try { localStorage.setItem('crm-theme', pref); } catch (e) {}
    Theme.apply(pref);
  },
  paintCharts(p) {
    const old = CRM.colors.surface;
    Object.assign(CRM.colors, { tx2: p.tx2, tx3: p.tx3, border: p.border, surface: p.surface, goldLight: p.goldLight });
    if (!window.Chart) return;
    Chart.defaults.color = p.tx3;
    Chart.defaults.borderColor = p.border;
    Chart.defaults.scale.grid.color = p.grid;
    Chart.defaults.plugins.tooltip.backgroundColor = p.tip;
    Object.values(Chart.instances || {}).forEach((ch) => {
      Object.values(ch.options.scales || {}).forEach((s) => {
        if (s.grid && s.grid.display !== false) s.grid.color = p.grid;
        if (s.ticks) s.ticks.color = p.tx3;
      });
      if (ch.options.plugins?.legend?.labels) ch.options.plugins.legend.labels.color = p.tx3;
      if (ch.options.plugins?.tooltip) ch.options.plugins.tooltip.backgroundColor = p.tip;
      ch.data.datasets.forEach((ds) => { if (ds.borderColor === old) ds.borderColor = p.surface; });
      ch.update('none');
    });
  },
};
Theme.apply(Theme.pref());
document.addEventListener('click', (e) => {
  const b = e.target.closest('[data-theme-set]');
  if (b) Theme.set(b.dataset.themeSet);
});
window.matchMedia?.('(prefers-color-scheme: dark)').addEventListener?.('change', () => {
  if (Theme.pref() === 'auto') Theme.apply('auto');
});

// Screen text in the person's language (window.UI_T comes from includes/i18n.php; English when missing)
const T = (s) => (window.UI_T && window.UI_T[s]) || s;

// ─── DataTable default init ───
function initDataTable(selector, opts = {}) {
  if (typeof $ === 'undefined' || typeof $.fn.DataTable === 'undefined') return;
  return $(selector).DataTable({
    pageLength: 25,
    lengthMenu: [10, 25, 50, 100],
    autoWidth: false,
    language: {
      search: '', searchPlaceholder: T('Filter this list…'),
      lengthMenu: T('Show _MENU_'),
      info: T('_START_–_END_ of _TOTAL_'),
      infoEmpty: T('No records'), infoFiltered: T('(filtered from _MAX_)'),
      emptyTable: T('Nothing here yet'), zeroRecords: T('No matching records'),
      paginate: { previous: '<i class="bi bi-chevron-left"></i>', next: '<i class="bi bi-chevron-right"></i>' },
    },
    ...opts
  });
}

// ─── Leads board (kanban) ───
function initLeadBoard(board) {
  if (!board) return;
  const colOf   = (stage) => board.querySelector(`.kb-col[data-stage="${stage}"]`);
  const listOf  = (col) => col.querySelector('.kb-list');
  const cardsIn = (col) => col.querySelectorAll('.kb-card').length;
  const label   = (stage) => colOf(stage)?.querySelector('.kb-col-title').textContent || stage;

  function refreshCol(col, delta = 0) {
    col.dataset.total = Math.max(0, (+col.dataset.total || 0) + delta);
    col.querySelector('.kb-count').textContent = Number(col.dataset.total).toLocaleString();
    col.querySelector('.kb-empty').hidden = cardsIn(col) > 0;
    col.querySelector('.kb-more').hidden = cardsIn(col) >= +col.dataset.total;
  }

  let toastTimer;
  function toast(msg, isError) {
    let t = document.getElementById('kbToast');
    if (!t) { t = document.createElement('div'); t.id = 'kbToast'; t.className = 'kb-toast'; t.setAttribute('role', 'status'); document.body.appendChild(t); }
    t.textContent = msg;
    t.classList.toggle('error', !!isError);
    t.classList.add('show');
    clearTimeout(toastTimer);
    toastTimer = setTimeout(() => t.classList.remove('show'), 3500);
  }

  // Booking prompt → resolves 'go' (navigating), 'move', {checked} when it has a checkbox, or null when dismissed
  function askAppt(o) {
    const el = document.getElementById('kbApptModal');
    if (!el || typeof bootstrap === 'undefined') return Promise.resolve('move');
    const modal = bootstrap.Modal.getOrCreateInstance(el);
    document.getElementById('kbApptTitle').textContent = o.title;
    document.getElementById('kbApptText').textContent = o.text;
    const go = document.getElementById('kbApptGo'), wrap = document.getElementById('kbApptCheckWrap'), chk = document.getElementById('kbApptCheck');
    const moveBtn = document.getElementById('kbApptMove');
    go.hidden = !o.go; go.textContent = o.go || ''; go.href = o.href || '#';
    wrap.hidden = !o.check; chk.checked = true;
    document.getElementById('kbApptCheckLabel').textContent = o.check || '';
    moveBtn.textContent = o.check ? T('Move') : T('Just move it');
    return new Promise((resolve) => {
      let result = null;
      const onMove = () => { result = o.check ? { checked: chk.checked } : 'move'; modal.hide(); };
      const onGo = () => { result = 'go'; };
      const onHidden = () => {
        moveBtn.removeEventListener('click', onMove);
        go.removeEventListener('click', onGo);
        el.removeEventListener('hidden.bs.modal', onHidden);
        resolve(result);
      };
      moveBtn.addEventListener('click', onMove);
      go.addEventListener('click', onGo);
      el.addEventListener('hidden.bs.modal', onHidden);
      modal.show();
    });
  }

  // Lost reason prompt → resolves to the reason ('' allowed) or null when cancelled
  const lostModalEl = document.getElementById('kbLostModal');
  function askLostReason(name) {
    if (!lostModalEl || typeof bootstrap === 'undefined') return Promise.resolve('');
    const modal = bootstrap.Modal.getOrCreateInstance(lostModalEl);
    const input = document.getElementById('kbLostReason');
    document.getElementById('kbLostName').textContent = name;
    input.value = '';
    return new Promise((resolve) => {
      let result = null;
      const form = document.getElementById('kbLostForm');
      const onSubmit = (e) => { e.preventDefault(); result = input.value.trim(); modal.hide(); };
      const onShown = () => input.focus();
      const onHidden = () => {
        form.removeEventListener('submit', onSubmit);
        lostModalEl.removeEventListener('shown.bs.modal', onShown);
        lostModalEl.removeEventListener('hidden.bs.modal', onHidden);
        resolve(result);
      };
      form.addEventListener('submit', onSubmit);
      lostModalEl.addEventListener('shown.bs.modal', onShown);
      lostModalEl.addEventListener('hidden.bs.modal', onHidden);
      modal.show();
    });
  }

  async function move(card, to) {
    const from = card.dataset.stage;
    if (!to || from === to) return;
    // Treatment bookings: offer to book / complete, or to cancel bookings when the lead is dropped
    const openAppts = +card.dataset.openAppts || 0;
    let cancelAppts = false;
    const ap = document.getElementById('kbApptModal');
    if (ap) {
      const name = card.dataset.name, back = encodeURIComponent(location.pathname + location.search);
      let choice = 'move';
      if (to === 'booked' && !openAppts && ap.dataset.canBook === '1') {
        choice = await askAppt({ title: 'Book the treatment?', text: `Booking ${name}’s treatment reserves the products it needs and moves the card to Booked.`,
          go: 'Book treatment', href: `/pages/appointment.php?lead=${card.dataset.id}&return=${back}` });
      } else if (to === 'converted' && openAppts && ap.dataset.canComplete === '1') {
        choice = await askAppt({ title: 'Complete the treatment?', text: `${name} has a booked treatment. Completing it records the products and batches used, deducts them from stock and converts the lead.`,
          go: 'Complete treatment', href: `/pages/appointment.php?id=${card.dataset.nextAppt}&return=${back}` });
      } else if ((to === 'lost' || to === 'cold') && openAppts) {
        const r = await askAppt({ title: `Move to ${label(to)}?`, text: `${name} still has ${openAppts} booked treatment${openAppts > 1 ? 's' : ''} holding stock.`,
          check: `Cancel ${openAppts > 1 ? 'them' : 'it'} and release the reserved stock`, go: null });
        choice = r ? 'move' : null;
        cancelAppts = !!r && r.checked;
      }
      if (choice === null) return;
      if (choice === 'go') return;   // askAppt navigated to the booking / completion page
    }
    let lostReason = '';
    if (to === 'lost') {
      lostReason = await askLostReason(card.dataset.name);
      if (lostReason === null) return;
    }
    const fromCol = colOf(from), toCol = colOf(to);
    const anchor = card.nextElementSibling;

    // Move right away; put it back if the save fails
    listOf(toCol).prepend(card);
    card.dataset.stage = to;
    card.querySelectorAll('.kb-move').forEach((b) => b.classList.toggle('active', b.dataset.to === to));
    refreshCol(fromCol, -1); refreshCol(toCol, 1);
    card.classList.add('kb-saving');
    listOf(toCol).scrollTop = 0;

    try {
      const body = new URLSearchParams({ action: 'change_stage', lead_id: card.dataset.id, stage: to, lost_reason: lostReason, cancel_appointments: cancelAppts ? '1' : '' });
      const res = await fetch('/pages/api.php', { method: 'POST', body, credentials: 'same-origin', headers: { 'X-CSRF-Token': document.querySelector('meta[name=csrf-token]')?.content || '' } });
      const data = await res.json().catch(() => ({ error: T('Your session may have expired. Refresh the page.') }));
      if (!data.success) throw new Error(data.error === 'Denied' ? T('Only the assigned staff member can move this lead.') : (data.error || 'Could not save.'));
      if (data.score_html) card.querySelector('.kb-card-top > :last-child').outerHTML = data.score_html;
      card.classList.add('kb-flash');
      setTimeout(() => card.classList.remove('kb-flash'), 1200);
      if (data.cancelled_appointments) { card.dataset.openAppts = 0; card.querySelector('.stage-booked')?.remove(); }
      toast(`${card.dataset.name} → ${label(to)}` + (data.cancelled_appointments ? ` · ${data.cancelled_appointments} booking(s) cancelled` : ''));
    } catch (err) {
      listOf(fromCol).insertBefore(card, anchor && anchor.parentElement === listOf(fromCol) ? anchor : fromCol.querySelector('.kb-empty'));
      card.dataset.stage = from;
      card.querySelectorAll('.kb-move').forEach((b) => b.classList.toggle('active', b.dataset.to === from));
      refreshCol(toCol, -1); refreshCol(fromCol, 1);
      toast(err.message, true);
    } finally {
      card.classList.remove('kb-saving');
    }
  }

  // Drag and drop
  let dragged = null;
  board.addEventListener('dragstart', (e) => {
    const card = e.target.closest?.('.kb-card');
    if (!card) return;
    dragged = card;
    e.dataTransfer.effectAllowed = 'move';
    e.dataTransfer.setData('text/plain', card.dataset.id);
    requestAnimationFrame(() => card.classList.add('kb-dragging'));
    board.classList.add('kb-is-dragging');
  });
  board.addEventListener('dragend', () => {
    dragged?.classList.remove('kb-dragging');
    dragged = null;
    board.classList.remove('kb-is-dragging');
    board.querySelectorAll('.kb-drop').forEach((c) => c.classList.remove('kb-drop'));
  });
  board.addEventListener('dragover', (e) => {
    const col = e.target.closest?.('.kb-col');
    if (!dragged || !col) return;
    e.preventDefault();
    e.dataTransfer.dropEffect = 'move';
    if (!col.classList.contains('kb-drop')) {
      board.querySelectorAll('.kb-drop').forEach((c) => c.classList.remove('kb-drop'));
      if (col.dataset.stage !== dragged.dataset.stage) col.classList.add('kb-drop');
    }
  });
  board.addEventListener('dragleave', (e) => {
    const col = e.target.closest?.('.kb-col');
    if (col && !col.contains(e.relatedTarget)) col.classList.remove('kb-drop');
  });
  board.addEventListener('drop', (e) => {
    const col = e.target.closest?.('.kb-col');
    if (!dragged || !col) return;
    e.preventDefault();
    col.classList.remove('kb-drop');
    move(dragged, col.dataset.stage);
  });

  // "Move to" menu, card click → open lead
  board.addEventListener('click', (e) => {
    const mv = e.target.closest('.kb-move');
    if (mv) { move(mv.closest('.kb-card'), mv.dataset.to); return; }
    const card = e.target.closest('.kb-card');
    if (card && !e.target.closest('a, button, .dropdown')) {
      const url = card.querySelector('.kb-name').href;
      if (e.ctrlKey || e.metaKey) window.open(url, '_blank'); else window.location = url;
    }
  });

  // Show more (button, or scrolling to the end of a column)
  async function loadMore(col) {
    const btn = col.querySelector('.kb-more');
    if (btn.hidden || btn.disabled) return;
    btn.disabled = true; btn.textContent = T('Loading…');
    try {
      const url = new URL(board.dataset.moreUrl, window.location.origin);
      url.searchParams.set('more', col.dataset.stage);
      url.searchParams.set('offset', cardsIn(col));
      const res = await fetch(url, { credentials: 'same-origin' });
      if (!res.ok) throw new Error();
      const tpl = document.createElement('template');
      tpl.innerHTML = await res.text();
      const cards = [...tpl.content.querySelectorAll('.kb-card')];
      cards.forEach((c) => {
        if (!board.querySelector(`.kb-card[data-id="${c.dataset.id}"]`)) listOf(col).insertBefore(c, col.querySelector('.kb-empty'));
      });
      if (cards.length < +board.dataset.page) col.dataset.total = cardsIn(col); // reached the end
    } catch (e) {
      toast(T('Could not load more leads.'), true);
    } finally {
      btn.disabled = false; btn.textContent = T('Show more');
      refreshCol(col);
    }
  }
  board.querySelectorAll('.kb-col').forEach((col) => {
    col.querySelector('.kb-more').addEventListener('click', () => loadMore(col));
    listOf(col).addEventListener('scroll', (e) => {
      const l = e.currentTarget;
      if (l.scrollTop + l.clientHeight > l.scrollHeight - 120) loadMore(col);
    }, { passive: true });
  });
}

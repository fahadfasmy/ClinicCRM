// assets/js/scheduler.js — the scheduler page (pages/scheduler.php). Day views (one column per practitioner or room) are
// drawn here; week, month and list use FullCalendar (free edition). All data comes from /pages/scheduler_api.php.
(() => {
  const cfg = JSON.parse(document.getElementById('schedConfig').textContent);
  const csrf = document.querySelector('meta[name=csrf-token]')?.content || '';
  const $ = (id) => document.getElementById(id);
  const esc = (s) => String(s ?? '').replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
  const pad = (n) => String(n).padStart(2, '0');
  const ymd = (d) => d.getFullYear() + '-' + pad(d.getMonth() + 1) + '-' + pad(d.getDate());
  const hm = (mins) => pad(Math.floor(mins / 60)) + ':' + pad(mins % 60);
  const toMins = (t) => { const [h, m] = t.split(':').map(Number); return h * 60 + m; };
  const SLOT = 15, PX = 1.6;   // 15-minute grid, 1.6 px per minute

  const params = new URLSearchParams(location.search);
  const state = { branch: cfg.branch, date: cfg.date || params.get('date') || cfg.today, view: cfg.view, res: null, cal: null, services: null };

  const api = (q) => fetch('/pages/scheduler_api.php?' + new URLSearchParams({ branch: state.branch, ...q }), { credentials: 'same-origin' })
    .then(async (r) => { const j = await r.json().catch(() => ({ error: 'Unexpected answer from the server.' })); if (!r.ok || j.error) throw new Error(j.error || 'Failed'); return j; });
  const post = (data) => {
    const fd = new FormData();
    fd.append('branch', state.branch);
    for (const [k, v] of Object.entries(data)) (Array.isArray(v) ? v : [v]).forEach((x) => fd.append(Array.isArray(v) ? k + '[]' : k, x ?? ''));
    return fetch('/pages/scheduler_api.php', { method: 'POST', body: fd, credentials: 'same-origin', headers: { 'X-CSRF-Token': csrf } })
      .then(async (r) => { const j = await r.json().catch(() => ({ error: r.status === 419 ? 'The page has expired. Refresh and try again.' : 'Unexpected answer from the server.' })); if (!r.ok || j.error) throw new Error(j.error || 'Failed'); return j; });
  };
  const toast = (msg, warnings) => {
    const el = document.createElement('div');
    el.className = 'alert alert-' + (warnings && warnings.length ? 'warning' : 'success') + ' sched-toast';
    el.setAttribute('role', 'status');
    el.innerHTML = '<i class="bi bi-check-circle"></i><div><strong>' + esc(msg) + '</strong>' + (warnings && warnings.length ? '<ul class="mb-0">' + warnings.map((w) => '<li>' + esc(w) + '</li>').join('') + '</ul>' : '') + '</div>';
    document.body.appendChild(el);
    setTimeout(() => el.remove(), warnings && warnings.length ? 9000 : 3500);
  };
  const modal = (id) => bootstrap.Modal.getOrCreateInstance($(id));

  // ── Toolbar ──
  const isDay = () => state.view === 'practitioners' || state.view === 'rooms';
  function syncUrl() {
    const q = new URLSearchParams({ view: state.view, date: state.date, branch: state.branch });
    history.replaceState(null, '', '?' + q.toString());
    document.querySelectorAll('[data-view]').forEach((a) => { a.classList.toggle('active', a.dataset.view === state.view); a.setAttribute('aria-selected', a.dataset.view === state.view); });
    $('sDate').value = state.date;
  }
  function shift(dir) {
    const d = new Date(state.date + 'T12:00');
    if (state.view === 'month') d.setMonth(d.getMonth() + dir); else if (state.view === 'week' || state.view === 'list') d.setDate(d.getDate() + 7 * dir); else d.setDate(d.getDate() + dir);
    state.date = ymd(d); render();
  }
  $('sPrev').onclick = () => shift(-1);
  $('sNext').onclick = () => shift(1);
  $('sToday').onclick = () => { state.date = cfg.today; render(); };
  $('sDate').onchange = (e) => { if (e.target.value) { state.date = e.target.value; render(); } };
  $('sBranch') && ($('sBranch').onchange = (e) => { state.branch = +e.target.value; state.res = null; render(); });
  document.querySelectorAll('[data-view]').forEach((a) => a.addEventListener('click', (e) => { e.preventDefault(); state.view = a.dataset.view; render(); }));

  async function loadResources(date) {
    state.res = await api({ action: 'resources', date: date || state.date });
    return state.res;
  }

  // ── Day view: one column per practitioner or room ──
  async function renderDay() {
    $('schedCal').hidden = true;
    const box = $('schedDay'), msg = $('schedMsg');
    const [res, events] = await Promise.all([loadResources(), api({ action: 'events', start: state.date + 'T00:00', end: state.date + 'T23:59' })]);
    const byPrac = state.view === 'practitioners';
    const cols = byPrac ? res.practitioners : res.rooms;
    $('sLabel').textContent = new Date(state.date + 'T12:00').toLocaleDateString(undefined, { weekday: 'long', day: 'numeric', month: 'long', year: 'numeric' }) + (res.closed ? ' · branch closed' : '');
    if (!cols.length) {
      box.hidden = true; msg.hidden = false;
      msg.innerHTML = '<i class="bi bi-' + (byPrac ? 'heart-pulse' : 'door-open') + '"></i><div class="empty-state-title">' + (byPrac ? 'No bookable practitioners' : 'No rooms at this branch') + '</div>'
        + '<p>' + (byPrac ? 'Give staff the Practitioner or Nurse role, then set their hours in Admin → Practitioners.' : 'Add rooms in Admin → Clinic setup → the branch.') + '</p>';
      return;
    }
    msg.hidden = true; box.hidden = false;
    const startM = Math.min(toMins(res.open[0]), ...events.map((e) => toMins(e.start.slice(11)))), endM = Math.max(toMins(res.open[1]), ...events.map((e) => (e.end.slice(0, 10) > state.date ? 1440 : toMins(e.end.slice(11)))));
    const top = Math.floor(startM / 60) * 60, bottom = Math.min(1440, Math.ceil(endM / 60) * 60), height = (bottom - top) * PX;
    const y = (mins) => (mins - top) * PX;
    const span = (from, to) => [Math.max(top, from), Math.min(bottom, to)];
    let html = '<div class="sched-head"><div class="sched-gutter"></div>' + cols.map((c) => '<div class="sched-colhead" title="' + esc(c.name) + '">'
      + (byPrac ? '<span class="sched-dot" style="background:' + esc(c.color) + '"></span>' : '') + esc(c.name) + (byPrac ? '' : '<span class="fs-12 text-tx-3"> · ' + esc(c.type) + '</span>') + '</div>').join('') + '</div>';
    html += '<div class="sched-body" style="height:' + height + 'px"><div class="sched-gutter">';
    for (let m = top; m < bottom; m += 60) html += '<div class="sched-hour" style="top:' + y(m) + 'px">' + hm(m) + '</div>';
    html += '</div>';
    cols.forEach((c) => {
      html += '<div class="sched-col" data-col="' + c.id + '">';
      for (let m = top; m < bottom; m += SLOT) html += '<div class="sched-line' + (m % 60 === 0 ? ' hour' : '') + '" style="top:' + y(m) + 'px"></div>';
      // Unavailable: outside the practitioner's hours (once set up) and their time off / room blocks
      if (byPrac && c.hasSchedule) {
        let prev = top;
        [...c.hours].sort().forEach(([a, b]) => { const s = toMins(a); if (s > prev) html += off(prev, s, 'Not working'); prev = Math.max(prev, b === '23:59' ? 1440 : toMins(b)); });
        if (prev < bottom) html += off(prev, bottom, 'Not working');
      }
      c.blocks.forEach(([s, e, why]) => { const a = s.slice(0, 10) < state.date ? 0 : toMins(s.slice(11)), b = e.slice(0, 10) > state.date ? 1440 : toMins(e.slice(11)); html += off(a, b, why || 'Blocked'); });
      events.filter((e) => (byPrac ? e.practitionerId : e.roomId) === c.id).forEach((e) => {
        const s = toMins(e.start.slice(11)), en = e.end.slice(0, 10) > state.date ? 1440 : toMins(e.end.slice(11));
        html += '<div class="sched-event status-' + e.status + (e.editable ? ' movable' : '') + '" data-id="' + e.id + '" tabindex="0" role="button" style="top:' + y(s) + 'px;height:' + Math.max(18, (en - s) * PX - 2) + 'px;border-left-color:' + esc(e.color) + '"'
          + ' aria-label="' + esc(e.start.slice(11) + ' ' + e.client + ', ' + e.services + ', ' + e.statusLabel) + '">'
          + '<div class="sched-ev-time">' + e.start.slice(11) + '–' + e.end.slice(11) + ' · ' + esc(e.statusLabel) + '</div><div class="sched-ev-title">' + esc(e.client) + '</div><div class="sched-ev-sub">' + esc(e.services) + (byPrac ? (e.room ? ' · ' + esc(e.room) : '') : (e.practitioner ? ' · ' + esc(e.practitioner) : '')) + '</div></div>';
      });
      html += '</div>';
    });
    if (state.date === cfg.today) { const now = new Date(), nm = now.getHours() * 60 + now.getMinutes(); if (nm >= top && nm <= bottom) html += '<div class="sched-now" style="top:' + y(nm) + 'px"></div>'; }
    html += '</div>';
    // Not-assigned bookings (no practitioner / no room) listed under the grid
    const loose = events.filter((e) => !(byPrac ? e.practitionerId : e.roomId) || !cols.some((c) => c.id === (byPrac ? e.practitionerId : e.roomId)));
    if (loose.length) html += '<div class="sched-loose"><div class="fs-12 fw-600 text-tx-3 mb-1">' + (byPrac ? 'No practitioner yet' : 'No room yet') + '</div>'
      + loose.map((e) => '<button class="chip me-1 mb-1 sched-loose-ev" data-id="' + e.id + '">' + e.start.slice(11) + ' ' + esc(e.client) + '</button>').join('') + '</div>';
    box.innerHTML = html;
    box.style.setProperty('--cols', cols.length);
    function off(a, b, why) { [a, b] = span(a, b); return b > a ? '<div class="sched-off" style="top:' + y(a) + 'px;height:' + (b - a) * PX + 'px" title="' + esc(why) + '"></div>' : ''; }
    wireDay(box, byPrac, top);
  }

  function wireDay(box, byPrac, top) {
    // The slot under the pointer (rounded down to the 15-minute grid)
    const minutesAt = (col, clientY) => top + Math.floor((clientY - col.getBoundingClientRect().top) / PX / SLOT) * SLOT;
    box.querySelectorAll('.sched-col').forEach((col) => col.addEventListener('click', (e) => {
      if (e.target.closest('.sched-event') || !cfg.canBook) return;
      openBook({ date: state.date, time: hm(minutesAt(col, e.clientY)), [byPrac ? 'practitionerId' : 'roomId']: +col.dataset.col });
    }));
    box.querySelectorAll('.sched-loose-ev').forEach((b) => b.addEventListener('click', () => openAppt(+b.dataset.id)));
    box.querySelectorAll('.sched-event').forEach((ev) => {
      ev.addEventListener('keydown', (e) => { if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); openAppt(+ev.dataset.id); } });
      // Drag to move (bookings that haven't started); a short press without moving opens the visit
      ev.addEventListener('pointerdown', (down) => {
        if (down.button !== 0) return;
        const startY = down.clientY, startX = down.clientX, origTop = parseFloat(ev.style.top);
        let moved = false, target = ev.parentElement;
        const onMove = (e) => {
          if (!ev.classList.contains('movable')) return;
          if (!moved && Math.hypot(e.clientX - startX, e.clientY - startY) < 6) return;
          moved = true; ev.classList.add('dragging');
          const dy = Math.round((e.clientY - startY) / PX / SLOT) * SLOT * PX;
          ev.style.top = (origTop + dy) + 'px';
          const over = document.elementsFromPoint(e.clientX, e.clientY).find((x) => x.classList && x.classList.contains('sched-col'));
          if (over && over !== target) { target = over; over.appendChild(ev); }
        };
        const onUp = async () => {
          document.removeEventListener('pointermove', onMove); document.removeEventListener('pointerup', onUp);
          if (!moved) return openAppt(+ev.dataset.id);
          ev.classList.remove('dragging');
          const mins = top + Math.round(parseFloat(ev.style.top) / PX), time = hm(mins);
          const colId = +target.dataset.col, colName = box.querySelector('.sched-colhead:nth-child(' + (Array.from(box.querySelectorAll('.sched-col')).indexOf(target) + 2) + ')')?.textContent || '';
          const appt = await api({ action: 'appointment', id: ev.dataset.id }).catch(() => null);
          if (!confirm('Move ' + (appt ? appt.client : 'this visit') + ' to ' + time + ' with ' + colName.trim() + '?')) return render();
          try {
            // Day by practitioner: the room stays; day by room: the practitioner stays
            const data = byPrac ? { action: 'move', id: ev.dataset.id, date: state.date, time, practitioner_id: colId }
                                : { action: 'move', id: ev.dataset.id, date: state.date, time, room_id: colId, practitioner_id: appt?.practitionerId || '' };
            const r = await post(data);
            toast('Moved to ' + time + '.', r.warnings);
          } catch (err) { alert(err.message); }
          render();
        };
        document.addEventListener('pointermove', onMove); document.addEventListener('pointerup', onUp);
      });
    });
  }

  // ── Week, month, list: FullCalendar ──
  async function renderCal() {
    $('schedDay').hidden = true; $('schedMsg').hidden = true; $('schedCal').hidden = false;
    const res = state.res || await loadResources();
    const fcView = { week: 'timeGridWeek', month: 'dayGridMonth', list: 'listWeek' }[state.view];
    if (!state.cal) {
      state.cal = new FullCalendar.Calendar($('schedCal'), {
        initialView: fcView, initialDate: state.date, headerToolbar: false, height: 'auto', firstDay: 1, nowIndicator: true,
        slotDuration: '00:15:00', slotLabelInterval: '01:00', slotMinTime: res.open[0] + ':00', slotMaxTime: (res.open[1] === '23:59' ? '24:00' : res.open[1]) + ':00',
        allDaySlot: false, eventDurationEditable: false, eventStartEditable: true, dayMaxEvents: 4,
        slotLabelFormat: { hour: '2-digit', minute: '2-digit', hour12: false }, eventTimeFormat: { hour: '2-digit', minute: '2-digit', hour12: false },
        events: (info, ok, fail) => api({ action: 'events', start: info.startStr.slice(0, 16), end: info.endStr.slice(0, 16) })
          .then((list) => ok(list.map((e) => ({ id: String(e.id), title: e.title, start: e.start, end: e.end, editable: e.editable, backgroundColor: e.color, borderColor: e.color,
            classNames: ['status-' + e.status], extendedProps: e })))).catch(fail),
        dateClick: (info) => { if (cfg.canBook) openBook({ date: info.dateStr.slice(0, 10), time: info.dateStr.length > 10 ? info.dateStr.slice(11, 16) : '' }); },
        eventClick: (info) => { info.jsEvent.preventDefault(); openAppt(+info.event.id); },
        eventDrop: async (info) => {
          const e = info.event, s = e.startStr;
          if (!confirm('Move ' + e.extendedProps.client + ' to ' + s.slice(0, 10) + ' ' + s.slice(11, 16) + '?')) return info.revert();
          try { const r = await post({ action: 'move', id: e.id, date: s.slice(0, 10), time: s.slice(11, 16), practitioner_id: e.extendedProps.practitionerId || '' }); toast('Moved.', r.warnings); state.cal.refetchEvents(); }
          catch (err) { alert(err.message); info.revert(); }
        },
        datesSet: (info) => { $('sLabel').textContent = info.view.title; },
      });
      state.cal.render();
    } else {
      state.cal.changeView(fcView, state.date);
      state.cal.refetchEvents();
    }
  }

  async function render() {
    syncUrl();
    try { isDay() ? await renderDay() : await renderCal(); }
    catch (e) { $('schedMsg').hidden = false; $('schedMsg').innerHTML = '<i class="bi bi-exclamation-triangle"></i><div class="empty-state-title">Couldn’t load the schedule</div><p>' + esc(e.message) + '</p>'; }
  }

  // ── Book ──
  let chosen = null, searchTimer = null;
  async function openBook(pre = {}) {
    if (!cfg.canBook) return;
    const res = state.res || await loadResources();
    if (!state.services) state.services = await api({ action: 'services' });
    $('bookError').hidden = true;
    $('bkDate').value = pre.date || state.date;
    $('bkTime').value = pre.time || '';
    $('bkPrac').innerHTML = '<option value="">Not decided yet</option>' + res.practitioners.map((p) => '<option value="' + p.id + '">' + esc(p.name) + '</option>').join('');
    $('bkRoom').innerHTML = '<option value="">No room</option>' + res.rooms.map((r) => '<option value="' + r.id + '">' + esc(r.name) + ' (' + esc(r.type) + ')</option>').join('');
    $('bkType').innerHTML = Object.entries(cfg.visitTypes).map(([k, l]) => '<option value="' + k + '"' + (k === 'treatment' ? ' selected' : '') + '>' + esc(l) + '</option>').join('');
    $('bkSource').innerHTML = '<option value="">—</option>' + Object.entries(cfg.sources).map(([k, l]) => '<option value="' + k + '">' + esc(l) + '</option>').join('');
    $('bkPrac').value = pre.practitionerId || ''; $('bkRoom').value = pre.roomId || '';
    $('bkNotes').value = ''; if ($('bkOutside')) $('bkOutside').checked = false;
    const groups = {};
    state.services.forEach((s) => (groups[s.category] = groups[s.category] || []).push(s));
    $('bkServices').innerHTML = Object.entries(groups).map(([g, list]) => '<div class="sched-svc-group"><div class="fs-12 fw-600 text-tx-3">' + esc(g) + '</div>'
      + list.map((s) => '<label class="form-check"><input class="form-check-input" type="checkbox" value="' + s.id + '" data-mins="' + s.mins + '"> <span class="form-check-label">' + esc(s.name) + ' <span class="text-tx-3">' + s.mins + ' min</span></span></label>').join('') + '</div>').join('')
      || '<div class="text-tx-3 fs-13">No bookable treatments yet (Treatment menu).</div>';
    $('bkServices').querySelectorAll('input').forEach((i) => i.addEventListener('change', sumMins));
    if (pre.service) { const box = $('bkServices').querySelector('input[value="' + pre.service + '"]'); if (box) box.checked = true; }
    sumMins();
    setChosen(pre.patient || null);
    modal('bookModal').show();
    setTimeout(() => (chosen ? $('bkTime') : $('bkSearch')).focus(), 300);
  }
  function sumMins() {
    const mins = [...$('bkServices').querySelectorAll('input:checked')].reduce((t, i) => t + +i.dataset.mins, 0);
    $('bkMins').textContent = mins ? '· ' + mins + ' min in total' : '';
    $('bkLen').value = mins || '';
  }
  function setChosen(c) {
    chosen = c;
    $('bkChosen').hidden = !c; $('bkSearch').hidden = !!c; $('bkResults').hidden = true;
    if (c) {
      $('bkChosen').innerHTML = '<span><i class="bi bi-' + (c.kind === 'lead' ? 'person-plus' : 'person-vcard') + '"></i> ' + esc(c.label) + '</span><button type="button" class="btn btn-ghost btn-sm" aria-label="Change patient">Change</button>';
      $('bkChosen').querySelector('button').onclick = () => { setChosen(null); $('bkSearch').value = ''; $('bkSearch').focus(); };
    }
  }
  $('bkSearch')?.addEventListener('input', (e) => {
    clearTimeout(searchTimer);
    const q = e.target.value.trim();
    if (q.length < 2) { $('bkResults').hidden = true; return; }
    searchTimer = setTimeout(async () => {
      const list = await api({ action: 'search', q }).catch(() => []);
      $('bkResults').innerHTML = list.length ? list.map((r, i) => '<button type="button" class="list-group-item list-group-item-action" role="option" data-i="' + i + '"><div class="fw-600">' + esc(r.label) + '</div><div class="fs-12 text-tx-3">' + esc(r.sub) + '</div></button>').join('')
        : '<div class="list-group-item fs-13 text-tx-3">Nobody found. Register a new patient first (Patients → New patient).</div>';
      $('bkResults').hidden = false;
      $('bkResults').querySelectorAll('[data-i]').forEach((b) => (b.onclick = () => setChosen(list[+b.dataset.i])));
    }, 250);
  });
  $('bookForm')?.addEventListener('submit', async (e) => {
    e.preventDefault();
    const err = (m) => { $('bookError').textContent = m; $('bookError').hidden = false; };
    const services = [...$('bkServices').querySelectorAll('input:checked')].map((i) => i.value);
    if (!chosen) return err('Choose the patient.');
    if (!services.length) return err('Pick at least one treatment.');
    if (!$('bkDate').value || !$('bkTime').value) return err('Choose the date and time.');
    $('bkSave').disabled = true;
    try {
      const r = await post({ action: 'book', [chosen.kind === 'lead' ? 'lead_id' : 'patient_id']: chosen.id, service_ids: services, date: $('bkDate').value, time: $('bkTime').value,
        duration_min: $('bkLen').value, practitioner_id: $('bkPrac').value, room_id: $('bkRoom').value, visit_type: $('bkType').value, source_id: $('bkSource').value,
        notes: $('bkNotes').value, allow_outside_hours: $('bkOutside') && $('bkOutside').checked ? 1 : '', waitlist_id: chosen.waitlist || '' });
      modal('bookModal').hide();
      toast('Booked.', r.warnings);
      state.date = $('bkDate').value;
      render();
    } catch (ex) { err(ex.message); }
    finally { $('bkSave').disabled = false; }
  });
  $('sBook')?.addEventListener('click', () => openBook({}));

  // ── One visit ──
  async function openAppt(id) {
    const a = await api({ action: 'appointment', id }).catch((e) => { alert(e.message); return null; });
    if (!a) return;
    $('apptTitle').textContent = a.client;
    const when = new Date(a.start.replace(' ', 'T'));
    $('apptBody').innerHTML = '<div class="mb-2"><span class="stage-badge">' + esc(a.statusLabel) + '</span> <span class="text-tx-3 fs-13">' + esc(a.visitType) + '</span></div>'
      + '<div class="kv"><span class="kv-label">When</span><span class="kv-value">' + when.toLocaleDateString(undefined, { weekday: 'short', day: 'numeric', month: 'short' }) + ', ' + a.start.slice(11, 16) + '–' + (a.end || '').slice(11, 16) + '</span></div>'
      + '<div class="kv"><span class="kv-label">Treatments</span><span class="kv-value">' + esc(a.services.join(', ')) + '</span></div>'
      + '<div class="kv"><span class="kv-label">With</span><span class="kv-value">' + esc(a.practitioner || 'Not decided') + (a.room ? ' · ' + esc(a.room) : '') + '</span></div>'
      + (a.mrn ? '<div class="kv"><span class="kv-label">File</span><span class="kv-value">' + esc(a.mrn) + '</span></div>' : '')
      + (a.phone ? '<div class="kv"><span class="kv-label">Phone</span><span class="kv-value" dir="ltr">' + esc(a.phone) + '</span></div>' : '')
      + (a.notes ? '<p class="fs-13 text-tx-2 mt-2 mb-0">' + esc(a.notes) + '</p>' : '')
      + '<div class="section-title mt-3">Journey</div><ol class="journey">' + a.journey.map((j) => '<li class="' + (j.at ? 'done' : '') + '"><span class="fw-600">' + esc(j.step) + '</span> <span class="text-tx-3 fs-12">'
        + (j.at ? new Date(j.at.replace(' ', 'T')).toLocaleTimeString([], { hour: '2-digit', minute: '2-digit', hour12: false }) : '—') + (j.by ? ' · ' + esc(j.by) : '') + '</span></li>').join('') + '</ol>'
      + (a.waited !== null ? '<div class="fs-12 text-tx-3">Waiting ' + a.waited + ' min since arrival' + (a.status === 'arrived' || a.status === 'in_triage' ? ' (so far)' : '') + '</div>' : '')
      + (a.canMove ? '<div class="section-title mt-3">Move</div><div class="d-flex flex-wrap gap-2"><input type="date" class="form-control form-control-sm w-auto" id="mvDate" value="' + a.start.slice(0, 10) + '" aria-label="New date">'
        + '<input type="time" class="form-control form-control-sm w-auto" id="mvTime" step="900" value="' + a.start.slice(11, 16) + '" aria-label="New time">'
        + '<select class="form-select form-select-sm w-auto" id="mvPrac" aria-label="Practitioner"><option value="">Not decided</option>' + (state.res?.practitioners || []).map((p) => '<option value="' + p.id + '"' + (p.id === a.practitionerId ? ' selected' : '') + '>' + esc(p.name) + '</option>').join('') + '</select>'
        + '<button class="btn btn-ghost btn-sm" id="mvGo"><i class="bi bi-arrow-right"></i>Move</button></div>' : '');
    $('apptFoot').innerHTML = a.next.map((n) => '<button class="btn btn-ghost btn-sm" data-step="' + n.to + '">' + esc(n.label) + '</button>').join('')
      + '<a class="btn btn-ghost btn-sm" href="/pages/appointment.php?id=' + a.id + '&return=' + encodeURIComponent('/pages/scheduler.php' + location.search) + '"><i class="bi bi-box-arrow-up-right"></i>Open</a>'
      + (a.patientId ? '<a class="btn btn-ghost btn-sm" href="/pages/patient.php?id=' + a.patientId + '">Patient</a>' : '');
    $('apptFoot').querySelectorAll('[data-step]').forEach((b) => (b.onclick = async () => {
      try { await post({ action: 'step', id: a.id, to: b.dataset.step }); modal('apptModal').hide(); toast(b.textContent.trim() + '.'); render(); } catch (e) { alert(e.message); }
    }));
    $('mvGo') && ($('mvGo').onclick = async () => {
      try { const r = await post({ action: 'move', id: a.id, date: $('mvDate').value, time: $('mvTime').value, practitioner_id: $('mvPrac').value }); modal('apptModal').hide(); toast('Moved.', r.warnings); state.date = $('mvDate').value; render(); }
      catch (e) { alert(e.message); }
    });
    modal('apptModal').show();
  }

  // ── Block time ──
  $('sBlock')?.addEventListener('click', async () => {
    const res = state.res || await loadResources();
    $('blockError').hidden = true;
    $('blWho').innerHTML = '<option value="">The whole branch</option><optgroup label="Practitioner">' + res.practitioners.map((p) => '<option value="s' + p.id + '">' + esc(p.name) + '</option>').join('')
      + '</optgroup><optgroup label="Room">' + res.rooms.map((r) => '<option value="r' + r.id + '">' + esc(r.name) + '</option>').join('') + '</optgroup>';
    $('blDate').value = isDay() ? state.date : cfg.today; $('blFrom').value = ''; $('blTo').value = ''; $('blReason').value = '';
    modal('blockModal').show();
  });
  $('blockForm')?.addEventListener('submit', async (e) => {
    e.preventDefault();
    const who = $('blWho').value;
    try {
      await post({ action: 'block', date: $('blDate').value, from: $('blFrom').value, to: $('blTo').value, reason: $('blReason').value,
        staff_id: who.startsWith('s') ? who.slice(1) : '', room_id: who.startsWith('r') ? who.slice(1) : '' });
      modal('blockModal').hide(); toast('Time blocked.'); render();
    } catch (ex) { $('blockError').textContent = ex.message; $('blockError').hidden = false; }
  });

  render().then(() => {
    if (cfg.prefill) openBook({ patient: cfg.prefill, service: cfg.prefill.service, practitionerId: cfg.prefill.practitioner, date: state.date });
  });
  setInterval(() => { if (isDay() && !document.querySelector('.modal.show') && !document.querySelector('.sched-event.dragging')) render(); }, 60000);   // keep the day view fresh
})();

// assets/js/layout-designer.js — the document designer (pages/layout_designer.php). Plain script, no build step.
// The model is window.LD.layout: {page, header[], body[], footer[]}, rows of blocks on a 12-column grid.
// Drag with SortableJS (mouse, touch, pen); resize with the handle on a block's right edge; keyboard: Tab to a block,
// arrows move it, + / - change its width, Delete removes it. Undo/redo keeps 50 steps. Autosave every 30 seconds.
// The server validates everything again; this file only makes editing pleasant.
(function () {
  'use strict';
  var LD = window.LD, M = LD.layout, AREAS = ['header', 'body', 'footer'];
  var AREA_LABEL = { header: 'Header · repeats on every page', body: 'Body', footer: 'Footer · repeats on every page' };
  var sel = null, dirty = false, saving = false, hist = [], fut = [], zoom = 0.9;
  var $ = function (id) { return document.getElementById(id); };
  var esc = function (s) { return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; }); };
  var decode = function (s) { var t = document.createElement('textarea'); t.innerHTML = s || ''; return t.value; };
  var uid = function (p) { return p + Math.random().toString(36).slice(2, 8); };
  var snap = function () { return JSON.stringify(M); };

  // ── History ──
  function commit() { hist.push(lastSnap); if (hist.length > 50) hist.shift(); fut = []; lastSnap = snap(); markDirty(); }
  var lastSnap = snap();
  function undo() { if (!hist.length) return; fut.push(snap()); M = LD.layout = JSON.parse(hist.pop()); lastSnap = snap(); afterRestore(); }
  function redo() { if (!fut.length) return; hist.push(snap()); M = LD.layout = JSON.parse(fut.pop()); lastSnap = snap(); afterRestore(); }
  function afterRestore() { if (sel && !findBlock(sel)) sel = null; markDirty(); render(); }
  function markDirty() { dirty = true; status('Unsaved changes'); $('ldUndo').disabled = !hist.length; $('ldRedo').disabled = !fut.length; schedulePreview(); }
  function status(t, bad) { var s = $('ldStatus'); s.textContent = t; s.classList.toggle('text-danger', !!bad); }

  // ── Model helpers ──
  function rowsOf(a) { return M[a]; }
  function findBlock(id) {
    for (var a = 0; a < AREAS.length; a++) { var rows = M[AREAS[a]]; for (var r = 0; r < rows.length; r++) { var bs = rows[r].blocks; for (var b = 0; b < bs.length; b++) if (bs[b].id === id) return { area: AREAS[a], r: r, b: b, row: rows[r], block: bs[b] }; } }
    return null;
  }
  function findRow(id) { for (var a = 0; a < AREAS.length; a++) { var rows = M[AREAS[a]]; for (var r = 0; r < rows.length; r++) if (rows[r].id === id) return { area: AREAS[a], r: r, row: rows[r] }; } return null; }
  function used(row, except) { return row.blocks.reduce(function (s, b) { return s + (b === except ? 0 : b.w); }, 0); }
  function countType(t) { var n = 0; AREAS.forEach(function (a) { M[a].forEach(function (r) { r.blocks.forEach(function (b) { if (b.type === t) n++; }); }); }); return n; }
  function lockOf(b) { var lk = LD.locked[b.type]; return lk && countType(b.type) <= 1 ? lk : null; }
  function newBlock(type) {
    var def = LD.blocks[type], b = { id: uid(type.slice(0, 4) + '_'), type: type, w: { lines: 12, totals: 5, title: 4, divider: 12, spacer: 12, page_number: 4 }[type] || 6, cond: '', style: { align: 'start' } };
    if (Object.keys(def.fields).length) { b.show = {}; Object.keys(def.fields).forEach(function (f) { b.show[f] = !!def.fields[f]; }); }
    if (def.props.indexOf('text') >= 0) b.text = { en: type === 'title' ? 'Title' : '', ar: '' };
    if (def.props.indexOf('height') >= 0) b.height = type === 'spacer' ? 6 : 18;
    if (def.props.indexOf('asset') >= 0) b.asset = null;
    if (type === 'lines') { b.stripe = false; b.borders = true; b.columns = Object.keys(LD.columns).map(function (k) { var c = LD.columns[k]; return { key: k, on: k !== 'code' && k !== 'practitioner', label: { en: c[0], ar: c[1] }, w: c[2], align: c[3] }; }); }
    return b;
  }
  // Puts a block in a row, shrinking it to the room left, or into a new row below when the row is full
  function place(area, rIndex, pos, b) {
    var row = M[area][rIndex], room = 12 - used(row);
    if (room >= 1) { b.w = Math.min(b.w, room); row.blocks.splice(pos, 0, b); }
    else M[area].splice(rIndex + 1, 0, { id: uid('r'), blocks: [b] });
  }

  // ── Palette ──
  function renderPalette() {
    var p = $('ldPalette'); p.innerHTML = '';
    Object.keys(LD.blocks).forEach(function (t) {
      var d = document.createElement('div'); d.className = 'ld-pal-item'; d.dataset.type = t; d.tabIndex = 0; d.setAttribute('role', 'button');
      d.innerHTML = '<i class="bi ' + LD.blocks[t].icon + '"></i>' + esc(LD.blocks[t].name);
      d.addEventListener('keydown', function (e) { if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); addToSelectedRow(t); } });
      d.addEventListener('dblclick', function () { addToSelectedRow(t); });
      p.appendChild(d);
    });
    new Sortable(p, { group: { name: 'blocks', pull: 'clone', put: false }, sort: false, animation: 120 });
  }
  function addToSelectedRow(type) {
    var f = sel && findBlock(sel), b = newBlock(type);
    if (f) place(f.area, f.r, f.b + 1, b); else { M.body.push({ id: uid('r'), blocks: [] }); place('body', M.body.length - 1, 0, b); }
    sel = b.id; commit(); render(); focusBlock(b.id);
  }

  // ── Canvas ──
  function summary(b) {
    var d = LD.blocks[b.type];
    if (b.type === 'title') return decode(b.text.en || '') + (b.text.ar ? ' / ' + decode(b.text.ar) : '');
    if (b.type === 'lines') return b.columns.filter(function (c) { return c.on; }).map(function (c) { return c.label.en; }).join(' · ');
    if (b.show) return Object.keys(b.show).filter(function (k) { return b.show[k]; }).map(function (k) { return (LD.labels[k] || [k])[0]; }).join(', ');
    if (b.text) return decode(b.text.en || b.text.ar || '').replace(/<[^>]+>/g, '').slice(0, 60) || '(empty)';
    if (b.type === 'spacer') return b.height + ' mm';
    return d.name;
  }
  function render() {
    var paper = $('ldPaper'), sizes = LD.sizes[M.page.size] || 210;
    var widthMm = M.page.orientation === 'landscape' && M.page.size !== 'roll80' ? { A4: 297, A5: 210, Letter: 279 }[M.page.size] : sizes;
    paper.style.width = Math.max(300, widthMm * 3.78) + 'px';
    $('ldPaperWrap').style.transform = 'scale(' + zoom + ')'; $('ldPaperWrap').style.transformOrigin = 'top left';
    $('ldPaperWrap').style.width = (Math.max(300, widthMm * 3.78) + 20) + 'px';
    paper.innerHTML = '';
    AREAS.forEach(function (area) {
      var sec = document.createElement('section'); sec.className = 'ld-area'; sec.dataset.area = area;
      sec.innerHTML = '<div class="ld-area-label">' + AREA_LABEL[area] + '<button type="button" class="btn btn-ghost btn-sm ms-auto py-0" data-addrow="' + area + '"><i class="bi bi-plus"></i>Row</button></div>';
      var rowsEl = document.createElement('div'); rowsEl.className = 'ld-rows'; rowsEl.dataset.area = area;
      M[area].forEach(function (row) {
        var r = document.createElement('div'); r.className = 'ld-row'; r.dataset.rowId = row.id;
        r.innerHTML = '<span class="ld-row-handle" title="Drag the row" aria-hidden="true"><i class="bi bi-grip-vertical"></i></span>';
        var bl = document.createElement('div'); bl.className = 'ld-blocks'; bl.dataset.rowId = row.id;
        row.blocks.forEach(function (b) {
          var e = document.createElement('div'), lk = lockOf(b);
          e.className = 'ld-block' + (b.id === sel ? ' sel' : '') + (b.cond ? ' cond' : ''); e.dataset.id = b.id; e.tabIndex = 0;
          e.style.gridColumn = 'span ' + b.w;
          e.setAttribute('aria-label', LD.blocks[b.type].name + ', width ' + b.w + ' of 12' + (lk ? ', required' : ''));
          var img = (b.asset && LD.assets.filter(function (a) { return a.id === b.asset; })[0]) ? '<img src="' + esc(LD.assets.filter(function (a) { return a.id === b.asset; })[0].url) + '" alt="">' : '';
          e.innerHTML = '<div class="nm"><i class="bi ' + LD.blocks[b.type].icon + '"></i>' + esc(LD.blocks[b.type].name) + (lk ? ' <i class="bi bi-lock-fill lk" title="Required: can be moved and restyled, not removed"></i>' : '') + (b.cond ? ' <i class="bi bi-funnel" title="Shown only sometimes"></i>' : '') + '</div>'
            + (img || '<div class="sm">' + esc(summary(b)) + '</div>') + '<span class="ld-resize" title="Drag to resize"></span>';
          bl.appendChild(e);
        });
        r.appendChild(bl);
        var del = document.createElement('button'); del.type = 'button'; del.className = 'ld-row-del'; del.innerHTML = '<i class="bi bi-x-lg"></i>'; del.title = 'Delete row'; del.setAttribute('aria-label', 'Delete row'); del.dataset.delrow = row.id;
        r.appendChild(del);
        rowsEl.appendChild(r);
      });
      sec.appendChild(rowsEl); paper.appendChild(sec);
    });
    paper.querySelectorAll('.ld-blocks').forEach(function (bl) {
      new Sortable(bl, { group: 'blocks', animation: 120, filter: '.ld-resize', preventOnFilter: false, ghostClass: 'sortable-ghost', onAdd: onBlockAdd, onUpdate: onBlockMove, onRemove: function () {} });
    });
    paper.querySelectorAll('.ld-rows').forEach(function (re) { new Sortable(re, { group: 'rows', handle: '.ld-row-handle', animation: 120, onEnd: onRowMove }); });
    renderProps();
  }
  // A block dragged from the palette or from another row
  function onBlockAdd(ev) {
    var rowId = ev.to.dataset.rowId, fr = findRow(rowId), type = ev.item.dataset.type, b;
    if (type) { b = newBlock(type); ev.item.remove(); }
    else { var f = findBlock(ev.item.dataset.id); b = f.block; f.row.blocks.splice(f.b, 1); }
    fr = findRow(rowId);
    var room = 12 - used(fr.row);
    if (room < 1) { M[fr.area].splice(fr.r + 1, 0, { id: uid('r'), blocks: [b] }); }
    else { b.w = Math.min(b.w, room); fr.row.blocks.splice(Math.min(ev.newIndex, fr.row.blocks.length), 0, b); }
    sel = b.id; commit(); render();
  }
  function onBlockMove(ev) {
    var fr = findRow(ev.to.dataset.rowId), b = fr.row.blocks.splice(ev.oldIndex, 1)[0];
    fr.row.blocks.splice(ev.newIndex, 0, b); commit(); render();
  }
  function onRowMove(ev) {
    var from = ev.from.dataset.area, to = ev.to.dataset.area, row = M[from].splice(ev.oldIndex, 1)[0];
    M[to].splice(ev.newIndex, 0, row); commit(); render();
  }

  // Resize with the handle (pointer events: mouse, touch, pen)
  document.addEventListener('pointerdown', function (e) {
    var h = e.target.closest('.ld-resize'); if (!h) return;
    e.preventDefault(); e.stopPropagation();
    var el = h.parentNode, f = findBlock(el.dataset.id), colW = el.parentNode.getBoundingClientRect().width / 12, x0 = e.clientX, w0 = f.block.w, max = 12 - used(f.row, f.block);
    h.setPointerCapture(e.pointerId);
    var move = function (ev) { var w = Math.max(1, Math.min(max, Math.round(w0 + (ev.clientX - x0) / colW))); el.style.gridColumn = 'span ' + w; el.dataset.w = w; };
    var up = function () { h.removeEventListener('pointermove', move); h.removeEventListener('pointerup', up); var w = +(el.dataset.w || w0); if (w !== w0) { f.block.w = w; commit(); } sel = f.block.id; render(); focusBlock(f.block.id); };
    h.addEventListener('pointermove', move); h.addEventListener('pointerup', up);
  }, true);

  // Select, add/delete rows
  $('ldPaper').addEventListener('click', function (e) {
    var add = e.target.closest('[data-addrow]'); if (add) { M[add.dataset.addrow].push({ id: uid('r'), blocks: [] }); commit(); render(); return; }
    var del = e.target.closest('[data-delrow]');
    if (del) { var fr = findRow(del.dataset.delrow), locked = fr.row.blocks.some(lockOf);
      if (locked) { alert('This row holds a required element. Move it to another row first.'); return; }
      if (fr.row.blocks.length && !confirm('Delete this row and its blocks?')) return;
      M[fr.area].splice(fr.r, 1); commit(); render(); return; }
    var b = e.target.closest('.ld-block'); sel = b ? b.dataset.id : null; render(); if (b) focusBlock(sel);
  });
  function focusBlock(id) { var e = document.querySelector('.ld-block[data-id="' + id + '"]'); if (e) e.focus({ preventScroll: false }); }

  // Keyboard
  $('ldPaper').addEventListener('focusin', function (e) { var b = e.target.closest('.ld-block'); if (b && sel !== b.dataset.id) { sel = b.dataset.id; renderProps(); document.querySelectorAll('.ld-block.sel').forEach(function (x) { x.classList.remove('sel'); }); b.classList.add('sel'); } });
  $('ldPaper').addEventListener('keydown', function (e) {
    var b = e.target.closest('.ld-block'); if (!b) return;
    var k = e.key, done = true;
    if (k === 'ArrowLeft') move('left'); else if (k === 'ArrowRight') move('right'); else if (k === 'ArrowUp') move('up'); else if (k === 'ArrowDown') move('down');
    else if (k === '+' || k === '=') resize(1); else if (k === '-') resize(-1); else if (k === 'Delete' || k === 'Backspace') removeSel(); else done = false;
    if (done) e.preventDefault();
  });
  document.addEventListener('keydown', function (e) {
    if ((e.ctrlKey || e.metaKey) && !e.target.closest('input, textarea')) { if (e.key === 'z' && !e.shiftKey) { e.preventDefault(); undo(); } else if (e.key === 'y' || (e.key === 'z' && e.shiftKey)) { e.preventDefault(); redo(); } }
  });
  function move(dir) {
    var f = findBlock(sel); if (!f) return;
    if (dir === 'left' || dir === 'right') { var to = f.b + (dir === 'left' ? -1 : 1); if (to < 0 || to >= f.row.blocks.length) return; f.row.blocks.splice(f.b, 1); f.row.blocks.splice(to, 0, f.block); }
    else {
      var rows = M[f.area], ai = AREAS.indexOf(f.area), tr = f.r + (dir === 'up' ? -1 : 1), area = f.area;
      if (tr < 0) { if (ai === 0) return; area = AREAS[ai - 1]; tr = M[area].length - 1; if (tr < 0) { M[area].push({ id: uid('r'), blocks: [] }); tr = 0; } }
      else if (tr >= rows.length) { if (ai === AREAS.length - 1) return; area = AREAS[ai + 1]; tr = 0; if (!M[area].length) M[area].push({ id: uid('r'), blocks: [] }); }
      f.row.blocks.splice(f.b, 1);
      var target = M[area][tr], room = 12 - used(target);
      if (room < 1) { M[area].splice(dir === 'up' ? tr + 1 : tr, 0, { id: uid('r'), blocks: [f.block] }); }
      else { f.block.w = Math.min(f.block.w, room); target.blocks.push(f.block); }
    }
    commit(); render(); focusBlock(sel);
  }
  function resize(d) { var f = findBlock(sel); if (!f) return; var w = Math.max(1, Math.min(12 - used(f.row, f.block), f.block.w + d)); if (w === f.block.w) return; f.block.w = w; commit(); render(); focusBlock(sel); }
  function removeSel() {
    var f = findBlock(sel); if (!f) return;
    if (lockOf(f.block)) { alert(LD.blocks[f.block.type].name + ' is required on this document. You can move, resize and restyle it, but not remove it.'); return; }
    f.row.blocks.splice(f.b, 1); sel = null; commit(); render();
  }

  // ── Properties ──
  function contrast(fg, bg) {
    var lum = function (h) { h = h.replace('#', ''); var c = [0, 2, 4].map(function (i) { var v = parseInt(h.substr(i, 2), 16) / 255; return v <= 0.03928 ? v / 12.92 : Math.pow((v + 0.055) / 1.055, 2.4); }); return 0.2126 * c[0] + 0.7152 * c[1] + 0.0722 * c[2]; };
    var a = lum(fg), b = lum(bg); return (Math.max(a, b) + 0.05) / (Math.min(a, b) + 0.05);
  }
  function field(label, html, id) { return '<div class="mb-2"><label class="form-label" for="' + id + '">' + label + '</label>' + html + '</div>'; }
  function select(id, opts, val, attrs) { return '<select class="form-select form-select-sm" id="' + id + '" ' + (attrs || '') + '>' + Object.keys(opts).map(function (k) { return '<option value="' + esc(k) + '"' + (String(val) === String(k) ? ' selected' : '') + '>' + esc(opts[k]) + '</option>'; }).join('') + '</select>'; }
  function renderProps() {
    var p = $('ldProps'), f = sel && findBlock(sel);
    if (!f) return pageProps(p);
    var b = f.block, d = LD.blocks[b.type], lk = lockOf(b), h = '';
    h += '<div class="d-flex align-items-center gap-2 mb-2"><strong><i class="bi ' + d.icon + '"></i> ' + esc(d.name) + '</strong><button type="button" class="btn btn-ghost btn-sm ms-auto" id="pPage">Page settings</button></div>';
    if (lk) h += '<div class="alert alert-info py-1 px-2 fs-12 mb-2"><i class="bi bi-lock-fill"></i> Required on a ' + esc(LD.typeName.toLowerCase()) + ': move, resize and restyle it, but it can’t be removed or hidden.</div>';
    h += '<div class="d-flex flex-wrap gap-1 mb-2" role="group" aria-label="Move block"><button type="button" class="btn btn-ghost btn-sm" data-mv="up" aria-label="Move up"><i class="bi bi-arrow-up"></i></button><button type="button" class="btn btn-ghost btn-sm" data-mv="down" aria-label="Move down"><i class="bi bi-arrow-down"></i></button><button type="button" class="btn btn-ghost btn-sm" data-mv="left" aria-label="Move left"><i class="bi bi-arrow-left"></i></button><button type="button" class="btn btn-ghost btn-sm" data-mv="right" aria-label="Move right"><i class="bi bi-arrow-right"></i></button>'
      + '<button type="button" class="btn btn-ghost btn-sm ms-auto" id="pDel"' + (lk ? ' disabled title="Required"' : '') + '><i class="bi bi-trash"></i>Remove</button></div>';
    h += field('Width (of 12 columns)', '<input type="number" class="form-control form-control-sm" id="pW" min="1" max="' + (12 - used(f.row, b)) + '" value="' + b.w + '">', 'pW');
    if (b.show) { h += '<fieldset class="mb-2"><legend class="form-label">Show</legend>'; Object.keys(b.show).forEach(function (k) { var req = lk && lk.fields.indexOf(k) >= 0;
      h += '<div class="form-check"><input class="form-check-input" type="checkbox" id="ps_' + k + '" data-show="' + k + '"' + (b.show[k] ? ' checked' : '') + (req ? ' disabled' : '') + '><label class="form-check-label" for="ps_' + k + '">' + esc((LD.labels[k] || [k])[0]) + (req ? ' <i class="bi bi-lock-fill text-tx-3" title="Required"></i>' : '') + '</label></div>'; }); h += '</fieldset>'; }
    if (b.text) {
      var mergeOpts = { '': 'Insert a field…' }; Object.keys(LD.merge).forEach(function (k) { mergeOpts['{{' + k + '}}'] = LD.merge[k]; });
      var one = b.type === 'title';
      h += field('English', one ? '<input class="form-control form-control-sm" id="pTen" value="' + esc(decode(b.text.en)) + '">' : '<textarea class="form-control form-control-sm" id="pTen" rows="3">' + esc(decode(b.text.en)) + '</textarea>', 'pTen');
      h += field('Arabic', one ? '<input class="form-control form-control-sm" id="pTar" dir="rtl" lang="ar" value="' + esc(decode(b.text.ar)) + '">' : '<textarea class="form-control form-control-sm" id="pTar" dir="rtl" lang="ar" rows="3">' + esc(decode(b.text.ar)) + '</textarea>', 'pTar');
      if (!one) h += '<div class="d-flex gap-2 align-items-center mb-2">' + select('pMerge', mergeOpts, '', 'aria-label="Insert a field"') + '</div><div class="fs-12 text-tx-3 mb-2">Formatting: &lt;b&gt;bold&lt;/b&gt;, &lt;i&gt;, &lt;u&gt;, lists. Other tags show as text.</div>';
      if (lk && lk.title) h += '<div class="fs-12 text-tx-3 mb-2">Must say “' + esc(lk.title.en) + '” / “' + esc(lk.title.ar) + '”.</div>';
    }
    if (d.props.indexOf('asset') >= 0) {
      var ao = { '': 'No image' }; LD.assets.forEach(function (a) { ao[a.id] = 'Image #' + a.id; });
      h += field('Image', select('pAsset', ao, b.asset || ''), 'pAsset') + '<div class="mb-2"><label class="form-label" for="pUp">Upload (PNG or JPG, 512 KB)</label><input type="file" class="form-control form-control-sm" id="pUp" accept="image/png,image/jpeg"></div>';
    }
    if (d.props.indexOf('height') >= 0) h += field('Height (mm)', '<input type="number" class="form-control form-control-sm" id="pH" min="2" max="80" value="' + b.height + '">', 'pH');
    if (b.type === 'lines') {
      h += '<fieldset class="mb-2"><legend class="form-label">Columns <span class="fw-normal text-tx-3">(drag to reorder)</span></legend><div id="pCols">';
      b.columns.forEach(function (c, i) { var req = lk && lk.columns.indexOf(c.key) >= 0;
        h += '<div class="ld-col" data-i="' + i + '"><span class="h" aria-hidden="true"><i class="bi bi-grip-vertical"></i></span><input class="form-check-input" type="checkbox" data-con="' + i + '" aria-label="Show ' + esc(c.label.en) + '"' + (c.on ? ' checked' : '') + (req ? ' disabled title="Required"' : '') + '>'
          + '<input class="form-control form-control-sm" data-cen="' + i + '" value="' + esc(c.label.en) + '" aria-label="English label"><input class="form-control form-control-sm" dir="rtl" data-car="' + i + '" value="' + esc(c.label.ar) + '" aria-label="Arabic label">'
          + '<input type="number" class="form-control form-control-sm" data-cw="' + i + '" min="2" max="70" value="' + c.w + '" aria-label="Width %">' + select('pca' + i, { start: 'Start', center: 'Centre', end: 'End' }, c.align, 'data-cal="' + i + '" aria-label="Alignment"') + '</div>'; });
      h += '</div></fieldset><div class="form-check"><input class="form-check-input" type="checkbox" id="pStripe"' + (b.stripe ? ' checked' : '') + '><label class="form-check-label" for="pStripe">Striped rows</label></div>'
        + '<div class="form-check mb-2"><input class="form-check-input" type="checkbox" id="pBorders"' + (b.borders ? ' checked' : '') + '><label class="form-check-label" for="pBorders">Lines between rows</label></div>';
    }
    var st = b.style || {};
    h += '<fieldset class="border-top pt-2 mt-2"><legend class="form-label">Style</legend><div class="row g-1">'
      + '<div class="col-7">' + field('Font', select('pFont', Object.assign({ '': 'Page font' }, LD.fonts.reduce(function (o, x) { o[x] = x; return o; }, {})), st.font || ''), 'pFont') + '</div>'
      + '<div class="col-5">' + field('Size (pt)', '<input type="number" class="form-control form-control-sm" id="pSize" min="6" max="32" value="' + (st.size || '') + '" placeholder="Page">', 'pSize') + '</div>'
      + '<div class="col-6">' + field('Text colour', '<input type="color" class="form-control form-control-color form-control-sm w-100" id="pColor" value="' + (st.color || M.page.color) + '">', 'pColor') + '</div>'
      + '<div class="col-6">' + field('Background', '<input type="color" class="form-control form-control-color form-control-sm w-100" id="pBg" value="' + (st.bg || '#ffffff') + '">', 'pBg') + '</div>'
      + '<div class="col-6">' + field('Align', select('pAlign', { start: 'Start', center: 'Centre', end: 'End' }, st.align || 'start'), 'pAlign') + '</div>'
      + '<div class="col-6">' + field('Border', select('pBorder', { none: 'None', box: 'Box', top: 'Top', bottom: 'Bottom' }, st.border || 'none'), 'pBorder') + '</div>'
      + '<div class="col-6">' + field('Padding (mm)', '<input type="number" class="form-control form-control-sm" id="pPad" min="0" max="10" value="' + (st.pad || 0) + '">', 'pPad') + '</div>'
      + '<div class="col-6 d-flex align-items-end"><div class="form-check mb-2"><input class="form-check-input" type="checkbox" id="pBold"' + (st.weight === 'bold' ? ' checked' : '') + '><label class="form-check-label" for="pBold">Bold</label></div></div>'
      + '</div><button type="button" class="btn btn-ghost btn-sm" id="pClearColors">Reset colours</button><div id="pContrast" class="fs-12 text-danger mt-1"></div></fieldset>';
    h += field('Show only if', select('pCond', LD.conditions, b.cond || '', lk ? 'disabled title="Required elements are always shown"' : ''), 'pCond');
    p.innerHTML = h;
    var c = st.color || M.page.color, bg = st.bg || '#ffffff', ratio = contrast(c, bg);
    $('pContrast').textContent = ratio < 4.5 ? 'Low contrast (' + ratio.toFixed(1) + ':1). Text may be hard to read; aim for at least 4.5:1.' : '';
    bindBlockProps(f);
  }
  function bindBlockProps(f) {
    var b = f.block, on = function (id, ev, fn) { var e = $(id); if (e) e.addEventListener(ev, fn); };
    on('pPage', 'click', function () { sel = null; render(); });
    $('ldProps').querySelectorAll('[data-mv]').forEach(function (x) { x.addEventListener('click', function () { move(x.dataset.mv); }); });
    on('pDel', 'click', removeSel);
    on('pW', 'change', function (e) { var w = Math.max(1, Math.min(12 - used(f.row, b), +e.target.value || 1)); b.w = w; commit(); render(); });
    $('ldProps').querySelectorAll('[data-show]').forEach(function (x) { x.addEventListener('change', function () { b.show[x.dataset.show] = x.checked; commit(); render(); }); });
    ['pTen', 'pTar'].forEach(function (id, i) { on(id, 'input', function (e) { b.text[i ? 'ar' : 'en'] = e.target.value; markDirty(); }); on(id, 'change', function () { commit(); renderCanvasOnly(); }); });
    on('pMerge', 'change', function (e) { if (!e.target.value) return; var t = $('pTen'), v = e.target.value, s = t.selectionStart || t.value.length; t.value = t.value.slice(0, s) + v + t.value.slice(s); b.text.en = t.value; e.target.value = ''; commit(); renderCanvasOnly(); });
    on('pAsset', 'change', function (e) { b.asset = e.target.value ? +e.target.value : null; commit(); render(); });
    on('pUp', 'change', function (e) { upload(e.target.files[0], function (a) { b.asset = a.id; commit(); render(); }); });
    on('pH', 'change', function (e) { b.height = Math.max(2, Math.min(80, +e.target.value || 18)); commit(); render(); });
    on('pStripe', 'change', function (e) { b.stripe = e.target.checked; commit(); });
    on('pBorders', 'change', function (e) { b.borders = e.target.checked; commit(); });
    $('ldProps').querySelectorAll('[data-con]').forEach(function (x) { x.addEventListener('change', function () { b.columns[x.dataset.con].on = x.checked; commit(); renderCanvasOnly(); }); });
    $('ldProps').querySelectorAll('[data-cen],[data-car]').forEach(function (x) { x.addEventListener('change', function () { var i = x.dataset.cen || x.dataset.car; b.columns[i].label[x.dataset.cen ? 'en' : 'ar'] = x.value.slice(0, 40); commit(); renderCanvasOnly(); }); });
    $('ldProps').querySelectorAll('[data-cw]').forEach(function (x) { x.addEventListener('change', function () { b.columns[x.dataset.cw].w = Math.max(2, Math.min(70, +x.value || 8)); commit(); }); });
    $('ldProps').querySelectorAll('[data-cal]').forEach(function (x) { x.addEventListener('change', function () { b.columns[x.dataset.cal].align = x.value; commit(); }); });
    if ($('pCols')) new Sortable($('pCols'), { handle: '.h', animation: 120, onEnd: function (ev) { var c = b.columns.splice(ev.oldIndex, 1)[0]; b.columns.splice(ev.newIndex, 0, c); commit(); render(); } });
    var st = b.style = b.style || {};
    on('pFont', 'change', function (e) { st.font = e.target.value; commit(); });
    on('pSize', 'change', function (e) { st.size = e.target.value ? Math.max(6, Math.min(32, +e.target.value)) : 0; commit(); });
    on('pColor', 'change', function (e) { st.color = e.target.value; commit(); renderProps(); });
    on('pBg', 'change', function (e) { st.bg = e.target.value; commit(); renderProps(); });
    on('pClearColors', 'click', function () { st.color = ''; st.bg = ''; commit(); renderProps(); });
    on('pAlign', 'change', function (e) { st.align = e.target.value; commit(); });
    on('pBorder', 'change', function (e) { st.border = e.target.value; commit(); });
    on('pPad', 'change', function (e) { st.pad = Math.max(0, Math.min(10, +e.target.value || 0)); commit(); });
    on('pBold', 'change', function (e) { st.weight = e.target.checked ? 'bold' : 'normal'; commit(); });
    on('pCond', 'change', function (e) { b.cond = e.target.value; commit(); render(); });
  }
  function renderCanvasOnly() { var keep = sel; var active = document.activeElement && document.activeElement.id; render(); sel = keep; if (active && $(active)) $(active).focus(); }
  function pageProps(p) {
    var pg = M.page, h = '<strong class="d-block mb-2"><i class="bi bi-file-earmark"></i> Page</strong>';
    h += field('Layout name', '<input class="form-control form-control-sm" id="gName" maxlength="100" value="' + esc(LD.name) + '">', 'gName');
    if (LD.type === 'invoice') h += field('Use for', select('gIType', { any: 'All invoices', cash: 'Cash invoices', insurance: 'Insurance invoices' }, LD.invoiceType), 'gIType');
    var bo = { 0: 'All branches' }; LD.branches.forEach(function (b) { bo[b.id] = b.name; });
    h += field('Branch', select('gBranch', bo, LD.branchId), 'gBranch');
    h += '<div class="row g-1"><div class="col-6">' + field('Paper', select('gSize', { A4: 'A4', A5: 'A5', Letter: 'Letter', roll80: '80 mm roll' }, pg.size), 'gSize') + '</div>'
      + '<div class="col-6">' + field('Orientation', select('gOr', { portrait: 'Portrait', landscape: 'Landscape' }, pg.orientation), 'gOr') + '</div>'
      + '<div class="col-12">' + field('Language', select('gLang', { both: 'English and Arabic', en: 'English', ar: 'Arabic (right to left)' }, pg.language), 'gLang') + '</div>'
      + '<div class="col-7">' + field('Font', select('gFont', LD.fonts.reduce(function (o, x) { o[x] = x; return o; }, {}), pg.font), 'gFont') + '</div>'
      + '<div class="col-5">' + field('Size (pt)', '<input type="number" class="form-control form-control-sm" id="gFs" min="7" max="14" step="0.5" value="' + pg.font_size + '">', 'gFs') + '</div>'
      + '<div class="col-6">' + field('Text colour', '<input type="color" class="form-control form-control-color form-control-sm w-100" id="gColor" value="' + pg.color + '">', 'gColor') + '</div>'
      + '<div class="col-6">' + field('Brand colour', '<input type="color" class="form-control form-control-color form-control-sm w-100" id="gAccent" value="' + pg.accent + '">', 'gAccent') + '</div></div>';
    h += '<fieldset class="mb-2"><legend class="form-label">Margins (mm)</legend><div class="row g-1">' + ['t', 'r', 'b', 'l'].map(function (k) { return '<div class="col-3"><input type="number" class="form-control form-control-sm" data-mg="' + k + '" min="0" max="30" value="' + pg.margins[k] + '" aria-label="' + { t: 'Top', r: 'Right', b: 'Bottom', l: 'Left' }[k] + ' margin" title="' + { t: 'Top', r: 'Right', b: 'Bottom', l: 'Left' }[k] + '"></div>'; }).join('') + '</div></fieldset>';
    h += field('Watermark text', '<input class="form-control form-control-sm" id="gWm" maxlength="40" value="' + esc(pg.watermark) + '">', 'gWm');
    h += '<div class="form-check"><input class="form-check-input" type="checkbox" id="gStamps"' + (pg.stamps ? ' checked' : '') + '><label class="form-check-label" for="gStamps">Stamp PAID / VOID / DRAFT</label></div>';
    h += '<div class="form-check mb-2"><input class="form-check-input" type="checkbox" id="gPin"' + (pg.footer_pinned ? ' checked' : '') + '><label class="form-check-label" for="gPin">Keep the footer at the bottom of the page</label></div>';
    h += '<div class="fs-12 text-tx-3">Select a block to change it. Keyboard: Tab to a block, arrows move it, + and − change its width, Delete removes it.</div>';
    p.innerHTML = h;
    var on = function (id, fn) { $(id).addEventListener('change', fn); };
    on('gName', function (e) { LD.name = e.target.value; markDirty(); });
    if ($('gIType')) on('gIType', function (e) { LD.invoiceType = e.target.value; markDirty(); });
    on('gBranch', function (e) { LD.branchId = +e.target.value; markDirty(); });
    on('gSize', function (e) { pg.size = e.target.value; commit(); render(); });
    on('gOr', function (e) { pg.orientation = e.target.value; commit(); render(); });
    on('gLang', function (e) { pg.language = e.target.value; commit(); });
    on('gFont', function (e) { pg.font = e.target.value; commit(); });
    on('gFs', function (e) { pg.font_size = Math.max(7, Math.min(14, +e.target.value || 10)); commit(); });
    on('gColor', function (e) { pg.color = e.target.value; commit(); });
    on('gAccent', function (e) { pg.accent = e.target.value; commit(); });
    p.querySelectorAll('[data-mg]').forEach(function (x) { x.addEventListener('change', function () { pg.margins[x.dataset.mg] = Math.max(0, Math.min(30, +x.value || 0)); commit(); }); });
    on('gWm', function (e) { pg.watermark = e.target.value.slice(0, 40); commit(); });
    on('gStamps', function (e) { pg.stamps = e.target.checked; commit(); });
    on('gPin', function (e) { pg.footer_pinned = e.target.checked; commit(); });
  }

  // ── Server ──
  function api(action, body) {
    return fetch('/pages/layout_api.php?action=' + action, { method: 'POST', headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': LD.csrf }, body: JSON.stringify(body), credentials: 'same-origin' });
  }
  function readJson(r) { return r.text().then(function (t) { var j; try { j = JSON.parse(t); } catch (e) { j = { error: r.status === 419 ? 'Your session expired. Reload the page (your changes are kept until you leave).' : 'Unexpected answer from the server.' }; } if (!r.ok && !j.error) j.error = 'Saving failed (' + r.status + ').'; return j; }); }
  function showProblems(list) { var p = $('ldProblems'); if (list && list.length) { p.textContent = 'Before this can be published:\n• ' + list.join('\n• '); p.classList.remove('d-none'); } else p.classList.add('d-none'); }
  function showError(msg) { var e = $('ldError'); if (msg) { e.textContent = msg; e.classList.remove('d-none'); status('Not saved', true); } else e.classList.add('d-none'); }
  function save() {
    if (saving) return Promise.resolve(false);
    saving = true; status('Saving…');
    var sentSnap = snap();
    return api('save', { id: LD.id, layout: M, meta: { name: LD.name, invoice_type: LD.invoiceType, branch_id: LD.branchId } }).then(readJson).then(function (j) {
      saving = false;
      if (j.error) { showError('Couldn’t save: ' + j.error); return false; }
      showError(''); showProblems(j.problems); if (snap() === sentSnap) dirty = false; status(dirty ? 'Unsaved changes' : 'Saved ' + j.saved_at); return true;
    }).catch(function () { saving = false; showError('Couldn’t reach the server. Your changes are still here; try again.'); return false; });
  }
  function publish() {
    save().then(function (ok) { if (!ok) return;
      api('publish', { id: LD.id }).then(readJson).then(function (j) {
        if (j.error) { showProblems(j.error.replace(/^[^\n]*\n?/, '').split('\n').map(function (s) { return s.replace(/^•\s*/, ''); }).filter(Boolean)); showError(j.error.split('\n')[0]); return; }
        showError(''); status('Published as version ' + j.version); LD.version = j.version;
      });
    });
  }
  function upload(file, done) {
    if (!file) return;
    var fd = new FormData(); fd.append('file', file); fd.append('_csrf', LD.csrf);
    fetch('/pages/layout_api.php?action=upload', { method: 'POST', body: fd, credentials: 'same-origin' }).then(readJson).then(function (j) {
      if (j.error) { alert(j.error); return; } LD.assets.unshift({ id: j.id, url: j.url }); done(j);
    });
  }

  // ── Preview ──
  var pvTimer = null, showingPreview = false;
  function schedulePreview() { if (!showingPreview) return; clearTimeout(pvTimer); pvTimer = setTimeout(preview, 600); }
  function preview() {
    var doc = $('ldDoc').value;
    api('preview', { id: LD.id, layout: M, lang: $('ldLang').value, doc: doc && doc !== 's60' ? +doc : 0, lines: doc === 's60' ? 60 : 4 }).then(function (r) { return r.text().then(function (t) { return { ok: r.ok, t: t }; }); }).then(function (x) {
      if (!x.ok) { var j; try { j = JSON.parse(x.t); } catch (e) { j = { error: 'Preview failed.' }; } showError(j.error); return; }
      showError(''); $('ldPreview').srcdoc = x.t;
    });
  }
  function setView(pv) {
    showingPreview = pv; $('ldStage').classList.toggle('d-none', pv); $('ldPreview').classList.toggle('d-none', !pv);
    $('ldTabDesign').classList.toggle('active', !pv); $('ldTabPreview').classList.toggle('active', pv);
    $('ldTabDesign').setAttribute('aria-selected', !pv); $('ldTabPreview').setAttribute('aria-selected', pv);
    if (pv) preview();
  }

  // ── Wiring ──
  $('ldUndo').addEventListener('click', undo); $('ldRedo').addEventListener('click', redo);
  $('ldSave').addEventListener('click', save); $('ldPublish').addEventListener('click', publish);
  $('ldTabDesign').addEventListener('click', function () { setView(false); }); $('ldTabPreview').addEventListener('click', function () { setView(true); });
  $('ldZoom').addEventListener('change', function (e) { zoom = +e.target.value; render(); });
  $('ldLang').addEventListener('change', schedulePreview); $('ldDoc').addEventListener('change', function () { if (showingPreview) preview(); });
  setInterval(function () { if (dirty && !saving) save(); }, 30000);
  window.addEventListener('beforeunload', function (e) { if (dirty) { e.preventDefault(); e.returnValue = ''; } });
  $('ldUndo').disabled = true; $('ldRedo').disabled = true;
  showProblems(LD.problems);
  // Start zoomed to fit the paper in the space available
  var paperMm = { A4: 210, A5: 148, Letter: 216, roll80: 80 }[M.page.size] || 210, fit = ($('ldStage').clientWidth - 40) / (paperMm * 3.78 + 20);
  zoom = [0.5, 0.75, 0.9, 1, 1.25, 1.5].filter(function (z) { return z <= fit; }).pop() || 0.5;
  $('ldZoom').value = String(zoom);
  renderPalette(); render();
})();

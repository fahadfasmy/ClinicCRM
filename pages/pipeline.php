<?php
// pages/pipeline.php — Leads as a board: one column per stage, newest first. Dragging a card to another column
// changes the stage through the same call as the lead page (api.php change_stage), so it is logged as an activity
// and the Meta stage events follow as before. Consultants see their own leads (and unassigned ones) unless they
// have "All leads". Up to 100 cards a column; filter by source or campaign.
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/inventory.php';
requireLogin();

$db = getDB();
$stages = ['new' => 'New', 'contacted' => 'Contacted', 'qualified' => 'Qualified', 'booked' => 'Booked', 'converted' => 'Converted', 'lost' => 'Lost', 'cold' => 'Cold'];
$src = trim((string)($_GET['source'] ?? ''));
$camp = (int)($_GET['campaign'] ?? 0);
$where = ['COALESCE(l.is_duplicate, 0) = 0']; $args = [];
if (!can('leads.all')) { $where[] = '(l.assigned_to = ? OR l.assigned_to IS NULL)'; $args[] = currentStaffId(); }
if ($src !== '') { $where[] = 'l.source = ?'; $args[] = $src; }
if ($camp) { $where[] = 'l.campaign_id = ?'; $args[] = $camp; }
$cols = [];
foreach ($stages as $st => $label) {
    $s = $db->prepare("SELECT l.id, l.name, l.phone, l.source, l.score, l.created_at, l.updated_at, s.name AS owner FROM leads l LEFT JOIN staff s ON s.id = l.assigned_to
                       WHERE l.stage = ? AND " . implode(' AND ', $where) . " ORDER BY l.updated_at DESC LIMIT 100");
    $s->execute([$st, ...$args]);
    $c = $db->prepare("SELECT COUNT(*) FROM leads l WHERE l.stage = ? AND " . implode(' AND ', $where)); $c->execute([$st, ...$args]);
    $cols[$st] = ['label' => $label, 'rows' => $s->fetchAll(), 'total' => (int)$c->fetchColumn()];
}
$sources = $db->query("SELECT slug, name FROM lead_sources WHERE is_active = 1 ORDER BY sort_order")->fetchAll(PDO::FETCH_KEY_PAIR);
$campaigns = $db->query("SELECT id, name FROM campaigns ORDER BY name")->fetchAll(PDO::FETCH_KEY_PAIR);

$pageTitle = 'Pipeline';
$pageSubtitle = 'Drag a lead to move it to another stage';
$pageActions = '<a class="btn btn-ghost" href="/pages/leads.php"><i class="bi bi-list"></i>List</a>';
require_once __DIR__ . '/../includes/header.php';
?>
<style>
  .pl-board { display: grid; grid-auto-flow: column; grid-auto-columns: minmax(230px, 1fr); gap: 10px; overflow-x: auto; padding-bottom: 8px; }
  .pl-col { background: var(--surface-2, #f4f0ea); border-radius: 10px; padding: 8px; min-height: 60vh; }
  .pl-col h2 { font-size: 13px; font-weight: 700; margin: 2px 4px 8px; display: flex; gap: 6px; }
  .pl-list { min-height: 50vh; }
  .pl-card { background: #fff; border: 1px solid var(--border, #e5ded3); border-radius: 8px; padding: 8px 10px; margin-bottom: 6px; cursor: grab; font-size: 13px; touch-action: none; }
  .pl-card a { font-weight: 600; } .pl-card .sm { color: #7a7166; font-size: 12px; }
  .sortable-ghost { opacity: .4; }
</style>
<form class="card-crm page-section mb-3" method="GET"><div class="card-body-crm d-flex flex-wrap gap-2 align-items-end">
  <div><label class="form-label" for="fs">Source</label><select class="form-select" id="fs" name="source"><option value="">All</option><?php foreach ($sources as $k => $n): ?><option value="<?= sanitize($k) ?>" <?= $src === $k ? 'selected' : '' ?>><?= sanitize($n) ?></option><?php endforeach; ?></select></div>
  <div><label class="form-label" for="fc">Campaign</label><select class="form-select" id="fc" name="campaign"><option value="">All</option><?php foreach ($campaigns as $k => $n): ?><option value="<?= (int)$k ?>" <?= $camp === (int)$k ? 'selected' : '' ?>><?= sanitize($n) ?></option><?php endforeach; ?></select></div>
  <button class="btn btn-gold">Show</button></div></form>
<div class="alert alert-danger d-none" id="plError" role="alert"></div>
<div class="pl-board">
  <?php foreach ($cols as $st => $c): ?>
  <section class="pl-col" aria-label="<?= $c['label'] ?>"><h2><?= $c['label'] ?> <span class="chip tone-slate"><?= $c['total'] ?></span></h2>
    <div class="pl-list" data-stage="<?= $st ?>">
      <?php foreach ($c['rows'] as $l): ?><div class="pl-card" data-id="<?= (int)$l['id'] ?>" tabindex="0">
        <a href="/pages/lead_view.php?id=<?= (int)$l['id'] ?>"><?= sanitize($l['name']) ?></a>
        <div class="sm"><?= sanitize($sources[$l['source']] ?? $l['source']) ?> · <?= timeSince($l['created_at']) ?><?= $l['owner'] ? ' · ' . sanitize($l['owner']) : '' ?></div>
        <label class="visually-hidden" for="mv<?= (int)$l['id'] ?>">Move to</label>
        <select class="form-select form-select-sm mt-1 pl-move" id="mv<?= (int)$l['id'] ?>"><?php foreach ($stages as $k => $n): ?><option value="<?= $k ?>" <?= $k === $st ? 'selected' : '' ?>><?= $n ?></option><?php endforeach; ?></select>
      </div><?php endforeach; ?>
    </div></section>
  <?php endforeach; ?>
</div>
<script src="/assets/vendor/sortablejs-1.15.6/Sortable.min.js"></script>
<script>
(function () {
  var token = document.querySelector('meta[name="csrf-token"]').content, err = document.getElementById('plError');
  function move(card, stage, revert) {
    var body = new URLSearchParams({ action: 'change_stage', lead_id: card.dataset.id, stage: stage });
    if (stage === 'lost') { var r = prompt('Why was this lead lost? (optional)'); if (r === null) { revert(); return; } body.append('lost_reason', r); }
    fetch('/pages/api.php', { method: 'POST', headers: { 'X-CSRF-Token': token, 'Content-Type': 'application/x-www-form-urlencoded' }, body: body, credentials: 'same-origin' })
      .then(function (r) { return r.json(); }).then(function (j) {
        if (!j.success) throw new Error(j.error || 'Not saved');
        err.classList.add('d-none'); card.querySelector('.pl-move').value = stage;
      }).catch(function (e) { err.textContent = 'The stage was not changed: ' + e.message; err.classList.remove('d-none'); revert(); });
  }
  document.querySelectorAll('.pl-list').forEach(function (list) {
    new Sortable(list, { group: 'pipeline', animation: 120, filter: 'a, select', preventOnFilter: false, ghostClass: 'sortable-ghost',
      onAdd: function (ev) { var from = ev.from, idx = ev.oldIndex; move(ev.item, ev.to.dataset.stage, function () { from.insertBefore(ev.item, from.children[idx] || null); }); } });
  });
  // Keyboard and screen-reader alternative: the "Move to" list on each card
  document.querySelectorAll('.pl-move').forEach(function (sel) {
    sel.addEventListener('change', function () { var card = sel.closest('.pl-card'), from = card.parentNode, to = document.querySelector('.pl-list[data-stage="' + sel.value + '"]');
      to.insertBefore(card, to.firstChild); move(card, sel.value, function () { from.insertBefore(card, from.firstChild); sel.value = from.dataset.stage; }); });
  });
})();
</script>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>

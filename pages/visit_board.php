<?php
// pages/visit_board.php — Front desk → Visit board: today's visits at one branch in columns by step (expected, waiting,
// triage, with doctor, done) with timers. Reception checks people in, nurses take them to triage, doctors call them in;
// each move is stamped (appointment_status_history) and drives the waiting times. Refreshes itself every minute.
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/scheduler.php';
require_once __DIR__ . '/../includes/inventory.php';   // csrfField()
requirePermission('scheduler.view');

$db = getDB();
$branches = branchList($db);
$hb = $db->prepare("SELECT branch_id FROM staff WHERE id = ?"); $hb->execute([currentStaffId()]);
$branch = (int)($_GET['branch'] ?? 0);
if (!isset($branches[$branch])) $branch = (int)$hb->fetchColumn();
if (!isset($branches[$branch])) $branch = (int)array_key_first($branches);
$own = currentRole() === 'practitioner' ? ' AND a.practitioner_id = ' . currentStaffId() : '';
$s = $db->prepare("SELECT a.*, " . APPT_CLIENT_SQL . " AS client, pt.mrn, st.name AS prac_name, rm.name AS room_name, COALESCE(pp.color, '#b8925a') AS color, (SELECT id FROM encounters en WHERE en.appointment_id = a.id) AS encounter_id,
                          (SELECT GROUP_CONCAT(sv.name ORDER BY sv.name SEPARATOR ', ') FROM appointment_services x JOIN services sv ON sv.id = x.service_id WHERE x.appointment_id = a.id) AS services
                   FROM appointments a LEFT JOIN patients pt ON pt.id = a.patient_id LEFT JOIN leads ld ON ld.id = a.lead_id
                   LEFT JOIN staff st ON st.id = a.practitioner_id LEFT JOIN rooms rm ON rm.id = a.room_id LEFT JOIN practitioner_profiles pp ON pp.staff_id = a.practitioner_id
                   WHERE a.branch_id = ? AND DATE(a.scheduled_at) = CURDATE() AND a.status NOT IN ('cancelled','rescheduled')$own ORDER BY a.scheduled_at");
$s->execute([$branch]);
$cols = [
    'expected' => ['Expected', ['booked', 'confirmed'], 'scheduled_at'],
    'waiting'  => ['Waiting', ['arrived'], 'arrived_at'],
    'triage'   => ['Triage', ['in_triage'], 'triage_at'],
    'doctor'   => ['With doctor', ['with_doctor'], 'doctor_in_at'],
    'done'     => ['Done', ['completed', 'no_show'], 'completed_at'],
];
$board = array_fill_keys(array_keys($cols), []);
foreach ($s->fetchAll() as $a) foreach ($cols as $k => [, $sts]) if (in_array($a['status'], $sts, true)) $board[$k][] = $a;
$waits = array_filter(array_map(fn($a) => $a['arrived_at'] && $a['doctor_in_at'] ? minutesBetween($a['arrived_at'], $a['doctor_in_at']) : null, array_merge(...array_values($board))), fn($m) => $m !== null);

$pageTitle = 'Visit board';
$pageSubtitle = sanitize($branches[$branch]['name'] ?? '') . ' · ' . date('l d M') . ($waits ? ' · average wait ' . round(array_sum($waits) / count($waits)) . ' min from arrival to doctor' : '');
$pageActions = (count($branches) > 1 ? '<select class="form-select form-select-sm w-auto" aria-label="' . t('Branch') . '" onchange="location=\'?branch=\'+this.value">'
    . implode('', array_map(fn($b) => '<option value="' . (int)$b['id'] . '"' . ((int)$b['id'] === $branch ? ' selected' : '') . '>' . sanitize($b['name']) . '</option>', $branches)) . '</select>' : '')
    . '<a href="/pages/scheduler.php?branch=' . $branch . '" class="btn btn-ghost"><i class="bi bi-calendar3"></i>' . t('Scheduler') . '</a>';
require_once __DIR__ . '/../includes/header.php';
?>
<div class="board page-section" aria-live="polite">
  <?php foreach ($cols as $k => [$label, , $since]): ?>
  <section class="board-col" aria-label="<?= $label ?>">
    <h2><?= $label ?> <span class="count"><?= count($board[$k]) ?></span></h2>
    <?php foreach ($board[$k] as $a):
      $next = array_filter(APPT_STEPS, fn($st) => in_array($a['status'], $st[0], true) && can($st[2])); ?>
    <article class="board-card" style="border-left-color:<?= sanitize($a['color']) ?>" data-id="<?= (int)$a['id'] ?>">
      <div class="d-flex justify-content-between gap-2"><strong class="tabular"><?= date('H:i', strtotime($a['scheduled_at'])) ?></strong>
        <?php if ($k === 'expected'): $late = minutesBetween($a['scheduled_at']); ?>
          <span class="board-timer <?= strtotime($a['scheduled_at']) < time() - 600 ? 'late' : '' ?>" data-expected="<?= strtotime($a['scheduled_at']) ?>"></span>
        <?php elseif ($k !== 'done' && $a[$since]): ?>
          <span class="board-timer" data-since="<?= strtotime($a[$since]) ?>" title="<?= t('Time in this step') ?>"></span>
        <?php else: ?><?= apptBadge($a['status']) ?><?php endif; ?></div>
      <div class="fw-600"><a href="<?= $a['patient_id'] ? '/pages/patient.php?id=' . (int)$a['patient_id'] : '/pages/appointment.php?id=' . (int)$a['id'] ?>"><?= sanitize($a['client']) ?></a>
        <?php if ($a['mrn']): ?><span class="text-tx-3 fs-12"><?= sanitize($a['mrn']) ?></span><?php endif; ?></div>
      <div class="fs-12 text-tx-2"><?= sanitize((string)$a['services']) ?></div>
      <div class="fs-12 text-tx-3"><?= sanitize($a['prac_name'] ?? 'No practitioner') ?><?= $a['room_name'] ? ' · ' . sanitize($a['room_name']) : '' ?><?= $a['status'] === 'confirmed' ? ' · confirmed' : '' ?></div>
      <div class="d-flex flex-wrap gap-1 mt-2">
        <?php foreach ($next as $to => $st): ?><button class="btn btn-ghost btn-sm" data-step="<?= $to ?>"><?= APPT_STATUS[$to][0] ?></button><?php endforeach; ?>
        <?php if (in_array($a['status'], APPT_OPEN, true) && can('appointments.complete') && in_array($a['status'], ['arrived', 'in_triage', 'with_doctor'], true)): ?>
          <a class="btn btn-ghost btn-sm" href="/pages/appointment.php?id=<?= (int)$a['id'] ?>&amp;return=<?= urlencode('/pages/visit_board.php?branch=' . $branch) ?>"><i class="bi bi-check2-circle"></i><?= t('Complete') ?></a><?php endif; ?>
        <?php if (can('emr.view') && $a['patient_id'] && $k !== 'expected'): ?>
          <?php if ($a['encounter_id']): ?><a class="btn btn-ghost btn-sm" href="/pages/encounter.php?id=<?= (int)$a['encounter_id'] ?>"><i class="bi bi-clipboard2-pulse"></i><?= t('Note') ?></a>
          <?php elseif (can('emr.write')): ?><form method="POST" action="/pages/encounter.php" class="d-inline"><?= csrfField() ?><input type="hidden" name="action" value="open"><input type="hidden" name="appointment_id" value="<?= (int)$a['id'] ?>"><input type="hidden" name="patient_id" value="<?= (int)$a['patient_id'] ?>"><button class="btn btn-ghost btn-sm"><i class="bi bi-clipboard2-plus"></i><?= t('Note') ?></button></form><?php endif; ?>
        <?php endif; ?>
        <button class="btn btn-ghost btn-sm" data-journey><i class="bi bi-signpost-2"></i><?= t('Journey') ?></button>
      </div>
    </article>
    <?php endforeach; ?>
    <?php if (!$board[$k]): ?><div class="fs-12 text-tx-3 px-1"><?= t('Nobody.') ?></div><?php endif; ?>
  </section>
  <?php endforeach; ?>
</div>

<div class="modal fade" id="journeyModal" tabindex="-1" aria-labelledby="jTitle" aria-hidden="true">
  <div class="modal-dialog"><div class="modal-content">
    <div class="modal-header"><h5 class="modal-title" id="jTitle"><?= t('Journey') ?></h5><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="<?= t('Close') ?>"></button></div>
    <div class="modal-body" id="jBody"></div>
  </div></div>
</div>
<?php
$extraJs = <<<'JS'
const csrf = document.querySelector('meta[name=csrf-token]').content;
const esc = s => String(s ?? '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
function tick() {
  const now = Date.now() / 1000;
  document.querySelectorAll('[data-since]').forEach(t => { const m = Math.floor((now - t.dataset.since) / 60); t.textContent = m + ' min'; t.classList.toggle('late', m >= 20); });
  document.querySelectorAll('[data-expected]').forEach(t => { const m = Math.round((t.dataset.expected - now) / 60); t.textContent = m > 0 ? 'in ' + m + ' min' : (m < -10 ? 'late ' + (-m) + ' min' : 'now'); t.classList.toggle('late', m < -10); });
}
tick(); setInterval(tick, 30000);
document.querySelectorAll('[data-step]').forEach(b => b.addEventListener('click', async () => {
  const id = b.closest('[data-id]').dataset.id, fd = new FormData();
  fd.append('action', 'step'); fd.append('id', id); fd.append('to', b.dataset.step);
  b.disabled = true;
  const r = await fetch('/pages/scheduler_api.php', { method: 'POST', body: fd, credentials: 'same-origin', headers: { 'X-CSRF-Token': csrf } });
  const j = await r.json().catch(() => ({ error: 'Unexpected answer from the server.' }));
  if (!r.ok || j.error) { alert(j.error || 'Failed'); b.disabled = false; return; }
  location.reload();
}));
document.querySelectorAll('[data-journey]').forEach(b => b.addEventListener('click', async () => {
  const id = b.closest('[data-id]').dataset.id;
  const r = await fetch('/pages/scheduler_api.php?action=appointment&id=' + id, { credentials: 'same-origin' });
  const a = await r.json();
  if (a.error) return alert(a.error);
  document.getElementById('jTitle').textContent = a.client;
  const t = s => s ? new Date(s.replace(' ', 'T')).toLocaleTimeString([], { hour: '2-digit', minute: '2-digit', hour12: false }) : '—';
  document.getElementById('jBody').innerHTML = '<ol class="journey">' + a.journey.map(j => '<li class="' + (j.at ? 'done' : '') + '"><span class="fw-600">' + esc(j.step) + '</span> <span class="text-tx-3 fs-12">' + t(j.at) + (j.by ? ' · ' + esc(j.by) : '') + '</span></li>').join('') + '</ol>'
    + (a.waited !== null ? '<div class="fs-13">Waiting time: <strong>' + a.waited + ' min</strong> from arrival' + (a.journey[3].at ? ' to the doctor' : ' so far') + '</div>' : '');
  bootstrap.Modal.getOrCreateInstance(document.getElementById('journeyModal')).show();
}));
setTimeout(() => { if (!document.querySelector('.modal.show')) location.reload(); }, 60000);
JS;
require_once __DIR__ . '/../includes/footer.php';

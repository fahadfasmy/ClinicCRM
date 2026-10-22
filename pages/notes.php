<?php
// pages/notes.php — Clinical → Notes: today's visits with the state of their notes, and notes still waiting to be signed.
// Practitioners see their own (emr.all: everyone's).
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/emr.php';
requirePermission('emr.view');

$db = getDB();
$tab = ($_GET['tab'] ?? '') === 'unsigned' ? 'unsigned' : 'today';
$mine = can('emr.all') ? '' : ' AND (x.practitioner_id = ' . currentStaffId() . ')';
if ($tab === 'today') {
    $rows = $db->query("SELECT a.id AS appt_id, a.scheduled_at, a.status AS appt_status, a.patient_id, a.practitioner_id, e.id AS enc_id, e.status AS enc_status, e.locked_at, " . APPT_CLIENT_SQL . " AS client, pt.mrn, st.name AS prac,
                               (SELECT GROUP_CONCAT(sv.name SEPARATOR ', ') FROM appointment_services s2 JOIN services sv ON sv.id = s2.service_id WHERE s2.appointment_id = a.id) AS services
                        FROM appointments a LEFT JOIN encounters e ON e.appointment_id = a.id LEFT JOIN patients pt ON pt.id = a.patient_id LEFT JOIN leads ld ON ld.id = a.lead_id LEFT JOIN staff st ON st.id = a.practitioner_id
                        WHERE DATE(a.scheduled_at) = CURDATE() AND a.status NOT IN ('cancelled','rescheduled','no_show')" . str_replace('x.', 'a.', $mine) . " ORDER BY a.scheduled_at")->fetchAll();
} else {
    $rows = $db->query("SELECT x.*, x.id AS enc_id, " . APPT_CLIENT_SQL . " AS client, pt.mrn, st.name AS prac, a.scheduled_at
                        FROM encounters x JOIN patients pt ON pt.id = x.patient_id LEFT JOIN leads ld ON ld.id = pt.lead_id LEFT JOIN staff st ON st.id = x.practitioner_id LEFT JOIN appointments a ON a.id = x.appointment_id
                        WHERE x.status = 'open'$mine ORDER BY x.created_at")->fetchAll();
}
$unsigned = (int)$db->query("SELECT COUNT(*) FROM encounters x WHERE x.status = 'open'$mine")->fetchColumn();

$pageTitle = 'Clinical notes';
$pageSubtitle = $unsigned ? $unsigned . ' waiting to be signed' : 'All notes signed';
require_once __DIR__ . '/../includes/header.php';
?>
<div class="toolbar"><div class="segmented" role="tablist" aria-label="<?= t('Show') ?>">
  <a href="?tab=today" class="<?= $tab === 'today' ? 'active' : '' ?>" role="tab" aria-selected="<?= $tab === 'today' ? 'true' : 'false' ?>"><?= t('Today') ?></a>
  <a href="?tab=unsigned" class="<?= $tab === 'unsigned' ? 'active' : '' ?>" role="tab" aria-selected="<?= $tab === 'unsigned' ? 'true' : 'false' ?>"><?= t('Unsigned') ?> <span class="count"><?= $unsigned ?></span></a>
</div></div>
<div class="card-crm">
  <?php if (!$rows): ?><div class="empty-state"><i class="bi bi-clipboard2-check"></i><div class="empty-state-title"><?= $tab === 'today' ? 'No visits today' : 'Nothing waiting to be signed' ?></div></div>
  <?php else: ?><div class="list-group list-group-flush">
    <?php foreach ($rows as $r): $age = minutesBetween($r['created_at'] ?? null); ?>
    <div class="list-group-item d-flex flex-wrap gap-2 align-items-center">
      <span class="tabular fw-600" style="width:48px"><?= $r['scheduled_at'] ? date('H:i', strtotime($r['scheduled_at'])) : '—' ?></span>
      <div class="flex-grow-1"><a href="/pages/patient.php?id=<?= (int)$r['patient_id'] ?>&amp;tab=clinical" class="fw-600"><?= sanitize($r['client']) ?></a> <span class="fs-12 text-tx-3"><?= sanitize((string)$r['mrn']) ?></span>
        <div class="fs-12 text-tx-3"><?= sanitize((string)($r['services'] ?? $r['chief_complaint'] ?? '')) ?><?= $r['prac'] ? ' · ' . sanitize($r['prac']) : '' ?><?= $tab === 'unsigned' && $age !== null ? ' · opened ' . timeSince($r['created_at']) : '' ?></div></div>
      <?php if ($tab === 'today'): ?><?= apptBadge($r['appt_status']) ?><?php endif; ?>
      <?php if ($r['enc_id']): ?>
        <?= $r['enc_status'] === 'open' ? ($r['locked_at'] ? '<span class="chip tone-slate">' . t('Locked') . '</span>' : '<span class="chip tone-blue">' . t('Note open') . '</span>') : '<span class="chip tone-green">' . t('Signed') . '</span>' ?>
        <a class="btn btn-ghost btn-sm" href="/pages/encounter.php?id=<?= (int)$r['enc_id'] ?>"><i class="bi bi-clipboard2-pulse"></i><?= t('Open') ?></a>
      <?php elseif ($r['patient_id'] && can('emr.write')): ?>
        <form method="POST" action="/pages/encounter.php"><?= csrfField() ?><input type="hidden" name="action" value="open"><input type="hidden" name="appointment_id" value="<?= (int)$r['appt_id'] ?>"><input type="hidden" name="patient_id" value="<?= (int)$r['patient_id'] ?>">
          <button class="btn btn-ghost btn-sm"><i class="bi bi-clipboard2-plus"></i><?= t('Start note') ?></button></form>
      <?php endif; ?>
    </div>
    <?php endforeach; ?>
  </div><?php endif; ?>
</div>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>

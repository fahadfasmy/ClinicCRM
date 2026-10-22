<?php
// pages/scheduler.php — Front desk → Scheduler. Day by practitioner and day by room (columns drawn by
// assets/js/scheduler.js), and week, month and list views (FullCalendar, free MIT edition, self-hosted). Click an empty
// slot to book, drag a booking to move it (asks first), block time, open the waiting list. Data: scheduler_api.php.
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/scheduler.php';
requirePermission('scheduler.view');

$db = getDB();
$branches = branchList($db);
$staffBranch = $db->prepare("SELECT branch_id FROM staff WHERE id = ?");
$staffBranch->execute([currentStaffId()]);
$branch = (int)($_GET['branch'] ?? 0);
if (!isset($branches[$branch])) $branch = (int)$staffBranch->fetchColumn();
if (!isset($branches[$branch])) $branch = (int)array_key_first($branches);
$waiting = (int)$db->query("SELECT COUNT(*) FROM waiting_list WHERE resolved_at IS NULL")->fetchColumn();

// Booking straight from a patient, lead or waiting-list entry: ?patient= / ?lead= / ?waitlist=
$prefill = null;
if ($pid = (int)($_GET['patient'] ?? 0)) {
    if ($p = findPatient($db, $pid)) $prefill = ['kind' => 'patient', 'id' => (int)$p['id'], 'label' => patientName($p) . ' · ' . $p['mrn']];
} elseif ($lid = (int)($_GET['lead'] ?? 0)) {
    $l = $db->prepare("SELECT id, name, patient_id FROM leads WHERE id = ?"); $l->execute([$lid]);
    if ($l = $l->fetch()) $prefill = $l['patient_id'] ? ['kind' => 'patient', 'id' => (int)$l['patient_id'], 'label' => $l['name']] : ['kind' => 'lead', 'id' => (int)$l['id'], 'label' => $l['name'] . ' (lead)'];
} elseif ($wid = (int)($_GET['waitlist'] ?? 0)) {
    $w = $db->prepare("SELECT w.*, p.first_name, p.middle_name, p.last_name, p.title, p.mrn FROM waiting_list w JOIN patients p ON p.id = w.patient_id WHERE w.id = ? AND w.resolved_at IS NULL");
    $w->execute([$wid]);
    if ($w = $w->fetch()) $prefill = ['kind' => 'patient', 'id' => (int)$w['patient_id'], 'label' => patientName($w) . ' · ' . $w['mrn'], 'waitlist' => (int)$w['id'],
                                      'service' => $w['service_id'] ? (int)$w['service_id'] : null, 'practitioner' => $w['staff_id'] ? (int)$w['staff_id'] : null];
}
$config = [
    'branch' => $branch, 'today' => (new DateTimeImmutable('now', new DateTimeZone(CLINIC_TZ)))->format('Y-m-d'),
    'view' => in_array($_GET['view'] ?? '', ['practitioners', 'rooms', 'week', 'month', 'list'], true) ? $_GET['view'] : 'practitioners',
    'date' => preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['date'] ?? '') ? $_GET['date'] : null,
    'canBook' => can('scheduler.book'), 'canBlock' => can('scheduler.block_slots'), 'visitTypes' => APPT_VISIT_TYPES, 'prefill' => $prefill,
    'sources' => $db->query("SELECT id, name FROM lead_sources WHERE is_active = 1 ORDER BY sort_order, name")->fetchAll(PDO::FETCH_KEY_PAIR),
];

$pageTitle = 'Scheduler';
$hidePageHead = true;
require_once __DIR__ . '/../includes/header.php';
?>
<script type="application/json" id="schedConfig"><?= json_encode($config, JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE) ?></script>
<div class="sched-toolbar">
  <h1 class="page-title mb-0 me-2"><?= t('Scheduler') ?></h1>
  <?php if (count($branches) > 1): ?>
  <select class="form-select form-select-sm w-auto" id="sBranch" aria-label="<?= t('Branch') ?>"><?php foreach ($branches as $b): ?><option value="<?= (int)$b['id'] ?>" <?= (int)$b['id'] === $branch ? 'selected' : '' ?>><?= sanitize($b['name']) ?></option><?php endforeach; ?></select>
  <?php endif; ?>
  <div class="btn-group btn-group-sm" role="group" aria-label="<?= t('Date') ?>">
    <button class="btn btn-ghost" id="sPrev" aria-label="<?= t('Previous') ?>"><i class="bi bi-chevron-left"></i></button>
    <button class="btn btn-ghost" id="sToday"><?= t('Today') ?></button>
    <button class="btn btn-ghost" id="sNext" aria-label="<?= t('Next') ?>"><i class="bi bi-chevron-right"></i></button>
  </div>
  <input type="date" class="form-control form-control-sm w-auto" id="sDate" aria-label="<?= t('Go to date') ?>">
  <span class="fw-600" id="sLabel" aria-live="polite"></span>
  <div class="segmented ms-auto" role="tablist" aria-label="<?= t('View') ?>">
    <?php foreach (['practitioners' => 'Practitioners', 'rooms' => 'Rooms', 'week' => 'Week', 'month' => 'Month', 'list' => 'List'] as $k => $l): ?>
    <a href="#" data-view="<?= $k ?>" role="tab"><?= $l ?></a>
    <?php endforeach; ?>
  </div>
  <a href="/pages/waiting_list.php" class="btn btn-ghost btn-sm"><i class="bi bi-hourglass-split"></i>Waiting list<?= $waiting ? ' <span class="count">' . $waiting . '</span>' : '' ?></a>
  <?php if (can('scheduler.block_slots')): ?><button class="btn btn-ghost btn-sm" id="sBlock"><i class="bi bi-slash-circle"></i><?= t('Block time') ?></button><?php endif; ?>
  <?php if (can('scheduler.book')): ?><button class="btn btn-gold btn-sm" id="sBook"><i class="bi bi-calendar-plus"></i><?= t('Book') ?></button><?php endif; ?>
</div>

<div class="card-crm sched-card">
  <div id="schedDay" class="sched-day" hidden></div>
  <div id="schedCal" hidden></div>
  <div id="schedMsg" class="empty-state" hidden></div>
</div>
<div class="sched-legend fs-12 text-tx-3 mt-2">
  <?php foreach (['booked', 'confirmed', 'arrived', 'in_triage', 'with_doctor', 'completed', 'no_show'] as $st): ?><?= apptBadge($st) ?> <?php endforeach; ?>
  <span class="ms-2"><span class="sched-swatch sched-off"></span> <?= t('not working / blocked') ?></span>
</div>

<!-- Book -->
<div class="modal fade" id="bookModal" tabindex="-1" aria-labelledby="bookTitle" aria-hidden="true">
  <div class="modal-dialog modal-lg modal-dialog-scrollable">
    <form class="modal-content" id="bookForm" novalidate>
      <div class="modal-header"><h5 class="modal-title" id="bookTitle"><?= t('Book a visit') ?></h5><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="<?= t('Close') ?>"></button></div>
      <div class="modal-body">
        <div id="bookError" class="alert alert-danger" hidden></div>
        <label class="form-label" for="bkSearch"><?= t('Patient') ?> <span class="req">*</span></label>
        <div class="position-relative mb-3">
          <div id="bkChosen" class="sched-chosen" hidden></div>
          <input class="form-control" id="bkSearch" placeholder="<?= t('Name, file number or phone (patients and open leads)') ?>" autocomplete="off" aria-autocomplete="list" aria-controls="bkResults">
          <div class="list-group sched-results" id="bkResults" role="listbox" hidden></div>
        </div>
        <label class="form-label"><?= t('Treatments') ?> <span class="req">*</span> <span class="text-tx-3 fw-normal" id="bkMins"></span></label>
        <div class="sched-services mb-3" id="bkServices"></div>
        <div class="row g-2 mb-3">
          <div class="col-6 col-md-3"><label class="form-label" for="bkDate"><?= t('Date') ?></label><input type="date" class="form-control" id="bkDate" required></div>
          <div class="col-6 col-md-3"><label class="form-label" for="bkTime"><?= t('Time') ?></label><input type="time" class="form-control" id="bkTime" step="900" required></div>
          <div class="col-6 col-md-3"><label class="form-label" for="bkLen"><?= t('Length (min)') ?></label><input type="number" class="form-control" id="bkLen" min="5" max="720" step="5"></div>
          <div class="col-6 col-md-3"><label class="form-label" for="bkType"><?= t('Visit') ?></label><select class="form-select" id="bkType"></select></div>
        </div>
        <div class="row g-2 mb-3">
          <div class="col-md-6"><label class="form-label" for="bkPrac"><?= t('Practitioner') ?></label><select class="form-select" id="bkPrac"></select></div>
          <div class="col-md-6"><label class="form-label" for="bkRoom"><?= t('Room') ?></label><select class="form-select" id="bkRoom"></select></div>
        </div>
        <div class="row g-2">
          <div class="col-md-4"><label class="form-label" for="bkSource"><?= t('How they found us') ?></label><select class="form-select" id="bkSource"></select></div>
          <div class="col-md-8"><label class="form-label" for="bkNotes"><?= t('Remarks') ?></label><input class="form-control" id="bkNotes" maxlength="1000"></div>
        </div>
        <?php if (can('scheduler.block_slots')): ?><label class="form-check mt-3"><input class="form-check-input" type="checkbox" id="bkOutside"> <span class="form-check-label"><?= t('Allow outside working hours / time off') ?></span></label><?php endif; ?>
      </div>
      <div class="modal-footer"><button type="button" class="btn btn-ghost" data-bs-dismiss="modal"><?= t('Cancel') ?></button><button class="btn btn-gold" id="bkSave"><i class="bi bi-calendar-check"></i><?= t('Book') ?></button></div>
    </form>
  </div>
</div>

<!-- One visit -->
<div class="modal fade" id="apptModal" tabindex="-1" aria-labelledby="apptTitle" aria-hidden="true">
  <div class="modal-dialog modal-dialog-scrollable"><div class="modal-content">
    <div class="modal-header"><h5 class="modal-title" id="apptTitle"><?= t('Visit') ?></h5><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="<?= t('Close') ?>"></button></div>
    <div class="modal-body" id="apptBody"></div>
    <div class="modal-footer" id="apptFoot"></div>
  </div></div>
</div>

<!-- Block time -->
<div class="modal fade" id="blockModal" tabindex="-1" aria-labelledby="blockTitle" aria-hidden="true">
  <div class="modal-dialog"><form class="modal-content" id="blockForm">
    <div class="modal-header"><h5 class="modal-title" id="blockTitle"><?= t('Block time') ?></h5><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="<?= t('Close') ?>"></button></div>
    <div class="modal-body">
      <div id="blockError" class="alert alert-danger" hidden></div>
      <label class="form-label" for="blWho"><?= t('For') ?></label><select class="form-select mb-3" id="blWho"></select>
      <div class="row g-2 mb-3">
        <div class="col-5"><label class="form-label" for="blDate"><?= t('Date') ?></label><input type="date" class="form-control" id="blDate" required></div>
        <div class="col-3"><label class="form-label" for="blFrom"><?= t('From') ?></label><input type="time" class="form-control" id="blFrom" step="900" required></div>
        <div class="col-4"><label class="form-label" for="blTo"><?= t('To') ?></label><input type="time" class="form-control" id="blTo" step="900" required></div>
      </div>
      <label class="form-label" for="blReason"><?= t('Reason') ?></label><input class="form-control" id="blReason" maxlength="150" placeholder="<?= t('e.g. Meeting, laser servicing') ?>">
    </div>
    <div class="modal-footer"><button type="button" class="btn btn-ghost" data-bs-dismiss="modal"><?= t('Cancel') ?></button><button class="btn btn-gold"><?= t('Block') ?></button></div>
  </form></div>
</div>

<script src="/assets/vendor/fullcalendar-6.1.15/index.global.min.js" defer></script>
<script src="/assets/js/scheduler.js?v=1" defer></script>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>

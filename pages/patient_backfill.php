<?php
// pages/patient_backfill.php — one-off (Phase 3): creates a patient for every lead that has an appointment or is
// Converted, copies its phones and links its appointments (backfillPatients()). "Preview" does everything and rolls it
// back, so its numbers are exactly what "Create patients" will do. Lead data is never changed; running it again skips
// leads that already have a patient.
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/inventory.php';     // CSRF helpers
require_once __DIR__ . '/../includes/patients.php';
requirePermission('patients.merge');

$db = getDB();
$report = null; $ran = false; $error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCsrf();
    $ran = ($_POST['action'] ?? '') === 'run';
    try {
        $report = backfillPatients($db, !$ran);
        if ($ran) logActivity(currentStaffId(), 'patients_backfilled', null, null, $report['created'] . ' created');
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
}
$waiting = (int)$db->query("SELECT COUNT(*) FROM leads l WHERE l.patient_id IS NULL AND (l.stage = 'converted' OR EXISTS (SELECT 1 FROM appointments a WHERE a.lead_id = l.id))")->fetchColumn();

$pageTitle = 'Patients from existing leads';
$pageSubtitle = $waiting . ' lead' . ($waiting == 1 ? '' : 's') . ' with an appointment or Converted, without a patient record';
$pageBack = ['href' => '/pages/patients.php', 'label' => 'Patients'];
require_once __DIR__ . '/../includes/header.php';
?>
<?php if ($error !== ''): ?><div class="alert alert-danger"><i class="bi bi-exclamation-triangle"></i><div><strong><?= sanitize($error) ?></strong></div></div><?php endif; ?>
<div class="row g-3 page-section">
  <div class="col-xl-5">
    <form method="POST" class="card-crm">
      <?= csrfField() ?>
      <div class="card-header-crm"><span><i class="bi bi-people"></i> Create patient records</span></div>
      <div class="card-body-crm fs-13 text-tx-2">
        <p>Every lead that has booked an appointment or is marked Converted becomes a patient with a file number. Its phone numbers are copied and its appointments are linked to the patient.</p>
        <p class="mb-0">Leads themselves are not changed. Preview first: it shows exactly what will happen without saving anything.</p>
      </div>
      <div class="form-actions">
        <button class="btn btn-ghost me-auto" name="action" value="preview"><i class="bi bi-eye"></i>Preview</button>
        <?php if ($report && !$ran && !$report['errors'] && $report['eligible']): ?>
        <button class="btn btn-gold" name="action" value="run" data-confirm="Create <?= (int)$report['created'] ?> patient records now?"><i class="bi bi-check-lg"></i>Create patients</button>
        <?php endif; ?>
      </div>
    </form>
  </div>
  <?php if ($report): ?>
  <div class="col-xl-7">
    <div class="card-crm">
      <div class="card-header-crm"><span><i class="bi bi-clipboard-data"></i> <?= $ran ? 'Done' : 'Preview (nothing saved)' ?></span></div>
      <div class="card-body-crm">
        <?php foreach (['eligible' => 'Leads to process', 'created' => 'Patients ' . ($ran ? 'created' : 'to create'), 'linked_existing' => 'Already had a patient (just linked)',
                        'phones' => 'Phone numbers copied', 'appointments' => 'Appointments linked'] as $k => $l): ?>
        <div class="kv"><span class="kv-label"><?= $l ?></span><span class="kv-value fw-semibold" data-key="<?= $k ?>"><?= (int)$report[$k] ?></span></div>
        <?php endforeach; ?>
        <?php if ($report['collisions']): ?>
        <h6 class="mt-3">Phone numbers shared with another patient (<?= count($report['collisions']) ?>)</h6>
        <p class="fs-12 text-tx-3">Created anyway. Check whether these are family members or the same person (then merge).</p>
        <ul class="fs-13 mb-0"><?php foreach ($report['collisions'] as $c): ?><li><a href="/pages/lead_view.php?id=<?= (int)$c['lead_id'] ?>"><?= sanitize($c['lead']) ?></a> · <?= sanitize($c['phone']) ?> · also on <?= sanitize($c['patient']) ?></li><?php endforeach; ?></ul>
        <?php endif; ?>
        <?php if ($report['errors']): ?><h6 class="mt-3 text-red">Problems (<?= count($report['errors']) ?>)</h6><ul class="fs-13 mb-0"><?php foreach ($report['errors'] as $e): ?><li><?= sanitize($e) ?></li><?php endforeach; ?></ul><?php endif; ?>
      </div>
    </div>
  </div>
  <?php endif; ?>
</div>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>

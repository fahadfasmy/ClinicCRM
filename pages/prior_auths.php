<?php
// pages/prior_auths.php — Insurance → Prior approvals: requests waiting for the insurer, and the decisions.
// ?patient=<id>[&appointment=<id>] shows the request form for that patient. ?id=<id> shows one request and its decision.
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/insurance.php';
require_once __DIR__ . '/../includes/emr.php';   // icdLookup for the diagnosis names
requirePermission('insurance.view');

$db = getDB();
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCsrf();
    requirePermission('insurance.manage');
    try {
        if (($_POST['action'] ?? '') === 'request') {
            $id = requestPriorAuth($db, (int)$_POST['patient_id'], (int)($_POST['card_id'] ?? 0), (array)($_POST['services'] ?? []), preg_split('/[\s,]+/', (string)($_POST['codes'] ?? ''), -1, PREG_SPLIT_NO_EMPTY),
                                   (int)($_POST['appointment_id'] ?? 0) ?: null, (string)($_POST['notes'] ?? ''));
            $_SESSION['ins_flash'] = ['success', 'Approval request saved. Send it to the insurer, then record their answer here.'];
        } else {
            $id = (int)$_POST['id'];
            decidePriorAuth($db, $id, (string)($_POST['status'] ?? ''), $_POST);
            $_SESSION['ins_flash'] = ['success', 'Decision saved.'];
        }
        header("Location: /pages/prior_auths.php?id=$id"); exit;
    } catch (Throwable $e) {
        $_SESSION['ins_flash'] = ['danger', $e->getMessage()];
        header('Location: ' . (($_POST['action'] ?? '') === 'request' ? '/pages/prior_auths.php?patient=' . (int)$_POST['patient_id'] : '/pages/prior_auths.php?id=' . (int)($_POST['id'] ?? 0))); exit;
    }
}
$flash = $_SESSION['ins_flash'] ?? null;
unset($_SESSION['ins_flash']);
$services = $db->query("SELECT id, name FROM services WHERE is_active = 1 ORDER BY name")->fetchAll(PDO::FETCH_KEY_PAIR);
$one = isset($_GET['id']) ? findPriorAuth($db, (int)$_GET['id']) : null;
$forPatient = !$one && isset($_GET['patient']) ? findPatient($db, (int)$_GET['patient']) : null;
if ($one) logPatientAccess($db, (int)$one['patient_id'], 'prior_auth:' . $one['id']);
$st = isset(PA_STATUS[$_GET['status'] ?? '']) ? $_GET['status'] : '';
$list = !$one && !$forPatient ? $db->query("SELECT pa.*, c.name AS company, CONCAT_WS(' ', pt.first_name, pt.last_name) AS patient, pt.mrn FROM prior_auths pa JOIN insurance_plans p ON p.id = pa.plan_id
    JOIN insurance_companies c ON c.id = p.company_id JOIN patients pt ON pt.id = pa.patient_id WHERE " . ($st ? "pa.status = " . $db->quote($st) : "pa.status IN ('requested','approved','partially_approved')") . " ORDER BY pa.requested_at DESC LIMIT 300")->fetchAll() : [];

$pageTitle = $one ? 'Approval request #' . $one['id'] : ($forPatient ? 'Request approval' : 'Prior approvals');
$pageSubtitle = $forPatient ? sanitize(patientName($forPatient)) . ' · ' . sanitize($forPatient['mrn']) : ($one ? '' : count($list) . ' shown');
$pageBack = $one || $forPatient ? ['href' => '/pages/prior_auths.php', 'label' => 'Prior approvals'] : null;
require_once __DIR__ . '/../includes/header.php';
?>
<?php if ($flash): ?><div class="alert alert-<?= $flash[0] ?>"><i class="bi bi-info-circle"></i><div><strong><?= sanitize($flash[1]) ?></strong></div></div><?php endif; ?>
<?php if ($forPatient): $cards = patientCards($db, (int)$forPatient['id'], true); $appt = (int)($_GET['appointment'] ?? 0);
  $apptSvc = $appt ? $db->query("SELECT service_id FROM appointment_services WHERE appointment_id = $appt")->fetchAll(PDO::FETCH_COLUMN) : []; ?>
  <?php if (!$cards): ?><div class="alert alert-warning">This patient has no valid insurance card. Add it on the patient’s <a href="/pages/patient.php?id=<?= (int)$forPatient['id'] ?>&amp;tab=insurance">Insurance tab</a> first.</div>
  <?php elseif (can('insurance.manage')): ?>
  <form method="POST" class="card-crm" style="max-width:720px"><?= csrfField() ?><input type="hidden" name="action" value="request"><input type="hidden" name="patient_id" value="<?= (int)$forPatient['id'] ?>"><input type="hidden" name="appointment_id" value="<?= $appt ?: '' ?>">
    <div class="card-body-crm">
      <label class="form-label" for="pc">Insurance card</label><select class="form-select mb-2" id="pc" name="card_id"><?php foreach ($cards as $c): ?><option value="<?= (int)$c['id'] ?>"><?= sanitize($c['company'] . ' – ' . $c['plan_name'] . ' · ' . $c['member_no']) ?></option><?php endforeach; ?></select>
      <label class="form-label" for="ps">Treatments</label><select class="form-select mb-2" id="ps" name="services[]" multiple size="6" required><?php foreach ($services as $k => $n): ?><option value="<?= (int)$k ?>" <?= in_array($k, $apptSvc) ? 'selected' : '' ?>><?= sanitize($n) ?></option><?php endforeach; ?></select>
      <label class="form-label" for="pd">Diagnosis codes (ICD-10, separated by spaces)</label><input class="form-control mb-2" id="pd" name="codes" placeholder="L70.0 L90.5">
      <label class="form-label" for="pn">Note</label><input class="form-control" id="pn" name="notes" maxlength="500">
    </div><div class="form-actions"><button class="btn btn-gold ms-auto">Save request</button></div></form>
  <?php endif; ?>
<?php elseif ($one): $sv = json_decode($one['services'], true) ?: []; $codes = json_decode((string)$one['diagnosis_codes'], true) ?: []; [$sl, $tone] = PA_STATUS[$one['status']]; $p = findPatient($db, (int)$one['patient_id']); ?>
<div class="row g-3">
  <div class="col-lg-7"><div class="card-crm"><div class="card-body-crm">
    <div class="kv"><span class="kv-label">Patient</span><span class="kv-value"><a href="/pages/patient.php?id=<?= (int)$p['id'] ?>&amp;tab=insurance"><?= sanitize(patientName($p)) ?></a> · <?= sanitize($p['mrn']) ?></span></div>
    <div class="kv"><span class="kv-label">Insurance</span><span class="kv-value"><?= sanitize($one['company'] . ' – ' . $one['plan_name']) ?> · <?= sanitize((string)$one['member_no']) ?></span></div>
    <div class="kv"><span class="kv-label">Treatments</span><span class="kv-value"><?= sanitize(implode(', ', array_map(fn($i) => $services[$i] ?? '?', $sv))) ?></span></div>
    <div class="kv"><span class="kv-label">Diagnoses</span><span class="kv-value"><?= sanitize(implode(', ', array_map(fn($c) => $c . (($n = icdLookup($c)) ? ' ' . $n['description'] : ''), $codes))) ?: '—' ?></span></div>
    <div class="kv"><span class="kv-label">Status</span><span class="kv-value"><span class="chip <?= $tone ?>"><?= $sl ?></span><?= $one['approval_no'] ? ' · no. ' . sanitize($one['approval_no']) : '' ?><?= $one['approved_amount'] !== null ? ' · ' . money($one['approved_amount']) : '' ?><?= $one['valid_until'] ? ' · until ' . formatDate($one['valid_until']) : '' ?></span></div>
    <div class="kv"><span class="kv-label">Requested</span><span class="kv-value"><?= formatDate($one['requested_at']) ?><?= $one['appointment_id'] ? ' · <a href="/pages/appointment.php?id=' . (int)$one['appointment_id'] . '">appointment</a>' : '' ?></span></div>
    <?php if ($one['notes']): ?><p class="fs-13 text-tx-2 mt-2 mb-0"><?= sanitize($one['notes']) ?></p><?php endif; ?>
  </div></div></div>
  <?php if (can('insurance.manage') && in_array($one['status'], ['requested', 'approved', 'partially_approved'], true)): ?>
  <div class="col-lg-5"><form method="POST" class="card-crm"><?= csrfField() ?><input type="hidden" name="action" value="decide"><input type="hidden" name="id" value="<?= (int)$one['id'] ?>">
    <div class="card-header-crm"><span>The insurer’s answer</span></div><div class="card-body-crm">
      <label class="form-label" for="ds">Decision</label><select class="form-select mb-2" id="ds" name="status"><?php foreach (['approved', 'partially_approved', 'rejected', 'cancelled'] as $k): ?><option value="<?= $k ?>"><?= PA_STATUS[$k][0] ?></option><?php endforeach; ?></select>
      <div class="row g-2 mb-2"><div class="col-6"><label class="form-label" for="dn">Approval number</label><input class="form-control" id="dn" name="approval_no" maxlength="60" value="<?= sanitize((string)$one['approval_no']) ?>"></div>
        <div class="col-6"><label class="form-label" for="da">Approved amount</label><input class="form-control" id="da" name="approved_amount" type="number" min="0" step="0.01"></div></div>
      <label class="form-label" for="du">Valid until</label><input class="form-control mb-2" id="du" name="valid_until" type="date">
      <label class="form-label" for="dno">Note</label><input class="form-control" id="dno" name="note" maxlength="200">
    </div><div class="form-actions"><button class="btn btn-gold ms-auto">Save decision</button></div></form></div>
  <?php endif; ?>
</div>
<?php else: ?>
<form class="card-crm page-section mb-3" method="GET"><div class="card-body-crm d-flex gap-2 align-items-end"><div><label class="form-label" for="fs">Status</label>
  <select class="form-select" id="fs" name="status"><option value="">Open</option><?php foreach (PA_STATUS as $k => [$l]): ?><option value="<?= $k ?>" <?= $st === $k ? 'selected' : '' ?>><?= $l ?></option><?php endforeach; ?></select></div><button class="btn btn-gold">Show</button>
  <span class="ms-auto fs-13 text-tx-3">New requests start from the patient’s Insurance tab or the appointment.</span></div></form>
<div class="card-crm"><?php if (!$list): ?><div class="empty-state"><i class="bi bi-shield-check"></i><div class="empty-state-title">No approval requests</div></div>
  <?php else: ?><div class="list-group list-group-flush"><?php foreach ($list as $r): [$sl, $tone] = PA_STATUS[$r['status']]; ?>
    <a class="list-group-item list-group-item-action d-flex gap-2 align-items-center" href="?id=<?= (int)$r['id'] ?>"><span class="flex-grow-1"><span class="fw-600"><?= sanitize($r['patient']) ?></span> <span class="fs-12 text-tx-3"><?= sanitize($r['mrn']) ?></span>
      <span class="d-block fs-12 text-tx-3"><?= sanitize($r['company']) ?> · <?= sanitize(implode(', ', array_map(fn($i) => $services[$i] ?? '?', json_decode($r['services'], true) ?: []))) ?> · requested <?= formatDate($r['requested_at']) ?></span></span>
      <span class="chip <?= $tone ?>"><?= $sl ?></span></a><?php endforeach; ?></div><?php endif; ?></div>
<?php endif; ?>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>

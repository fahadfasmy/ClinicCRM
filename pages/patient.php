<?php
// pages/patient.php?id= — the patient profile. Tabs: Overview, Appointments, Documents, Consents, Clinical, Photos,
// Invoices & payments (with packages and the patient's balance), Lead history, Audit. Every opening is written to
// patient_access_log; showing the full Emirates ID needs patients.sensitive and is logged separately.
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/inventory.php';     // CSRF helpers
require_once __DIR__ . '/../includes/emr.php';
require_once __DIR__ . '/../includes/consents.php';
require_once __DIR__ . '/../includes/billing.php';
require_once __DIR__ . '/../includes/insurance.php';
require_once __DIR__ . '/../includes/messaging.php';
require_once __DIR__ . '/../includes/countries.php';
requirePermission('patients.view');

$db = getDB();
$id = (int)($_GET['id'] ?? 0);
$p = findPatient($db, $id);
if (!$p) { http_response_code(404); $pageTitle = 'Patient not found'; require __DIR__ . '/../includes/header.php';
    echo '<div class="empty-state"><i class="bi bi-person-x"></i><div class="empty-state-title">' . t('Patient not found') . '</div><a href="/pages/patients.php">' . t('Back to Patients') . '</a></div>';
    require __DIR__ . '/../includes/footer.php'; exit; }
if ((int)$p['id'] !== $id) { header('Location: /pages/patient.php?id=' . (int)$p['id'] . '&merged_from=' . $id); exit; }

$tabs = ['overview' => ['Overview', 'bi-person'], 'appointments' => ['Appointments', 'bi-calendar-check'], 'documents' => ['Documents', 'bi-folder2'],
         'consents' => ['Consents', 'bi-pen'], 'clinical' => ['Clinical', 'bi-clipboard2-pulse'], 'photos' => ['Photos', 'bi-images'], 'billing' => ['Invoices & payments', 'bi-receipt'], 'insurance' => ['Insurance', 'bi-shield-plus'], 'messages' => ['Messages', 'bi-chat-dots'], 'lead' => ['Lead history', 'bi-signpost-split'], 'audit' => ['Audit', 'bi-journal-text']];
if (!can('emr.view')) unset($tabs['clinical']);   // clinical data never reaches front-office, finance or stock roles
if (!can('emr.photos')) unset($tabs['photos']);
if (!can('billing.view')) unset($tabs['billing']);
if (!can('insurance.view') && !can('patients.edit')) unset($tabs['insurance']);
if (!can('messages.view')) unset($tabs['messages']);
if (!can('audit.view')) unset($tabs['audit']);
$tab = isset($tabs[$_GET['tab'] ?? '']) ? $_GET['tab'] : 'overview';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCsrf();
    $action = (string)($_POST['action'] ?? '');
    $back = 'documents';
    try {
        if ($action === 'upload') {
            requirePermission('patients.edit');
            storePatientDocument($db, $id, (string)($_POST['type'] ?? 'other'), $_FILES['file'] ?? []);
            $_SESSION['patient_flash'] = ['success', 'Document added.'];
        } elseif ($action === 'photo_upload') {
            requirePermission('emr.photos'); $back = 'photos';
            $f = $_FILES['photos'] ?? [];
            $n = 0;
            foreach ((array)($f['tmp_name'] ?? []) as $i => $tmp) {
                if (($f['error'][$i] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) continue;
                if ($f['error'][$i] !== UPLOAD_ERR_OK || !is_uploaded_file($tmp)) throw new RuntimeException('A photo could not be uploaded (10 MB at most each).' . ($n ? " $n saved before it." : ''));
                savePatientPhoto($db, $id, (string)file_get_contents($tmp), $_POST);
                $n++;
            }
            if (!$n) throw new RuntimeException('Choose or take a photo.');
            $_SESSION['patient_flash'] = ['success', $n === 1 ? 'Photo added.' : "$n photos added."];
        } elseif ($action === 'photo_remove') {
            requirePermission('emr.photos'); $back = 'photos';
            removePatientPhoto($db, $id, (int)($_POST['photo_id'] ?? 0));
            $_SESSION['patient_flash'] = ['success', 'Photo removed from the gallery.'];
        } elseif ($action === 'consent_revoke') {
            requirePermission('consents.collect'); $back = 'consents';
            $s = $db->prepare("SELECT id FROM patient_consents WHERE id = ? AND patient_id = ?"); $s->execute([(int)($_POST['consent_id'] ?? 0), $id]);
            revokeConsent($db, (int)$s->fetchColumn(), (string)($_POST['reason'] ?? ''));
            $_SESSION['patient_flash'] = ['success', 'Consent marked as withdrawn.'];
        } elseif ($action === 'card_save') {
            if (!can('patients.edit') && !can('insurance.manage')) requirePermission('insurance.manage');
            $back = 'insurance';
            savePatientCard($db, $id, (int)($_POST['card_id'] ?? 0), $_POST);
            $_SESSION['patient_flash'] = ['success', 'Insurance card saved.'];
        } elseif ($action === 'wallet_refund') {
            $back = 'billing';
            refundWallet($db, $id, (int)($p['branch_id'] ?: array_key_first(branchList($db))), $_POST['amount'] ?? 0, (string)($_POST['method'] ?? ''), (string)($_POST['note'] ?? ''));
            $_SESSION['patient_flash'] = ['success', 'Refund recorded.'];
        }
    } catch (Throwable $e) {
        $_SESSION['patient_flash'] = ['danger', $e->getMessage()];
    }
    header("Location: /pages/patient.php?id=$id&tab=$back"); exit;
}
$flash = $_SESSION['patient_flash'] ?? null;
unset($_SESSION['patient_flash']);

$revealEid = isset($_GET['reveal']) && can('patients.sensitive') && $p['eid_enc'];
logPatientAccess($db, $id, $revealEid ? 'eid_view' : 'profile:' . $tab);
if ($revealEid) auditLog($db, 'patient', $id, 'eid_viewed');
$phones = patientPhones($db, $id);
$branch = $p['branch_id'] ? (branchList($db, true)[$p['branch_id']]['name'] ?? '') : '';

$pageTitle = patientName($p);
$pageSubtitle = patientSummary($p) . ($branch ? ' · ' . $branch : '');
$pageBack = ['href' => '/pages/patients.php', 'label' => 'Patients'];
$pageActions = (can('scheduler.book') && $p['status'] === 'active' ? '<a href="/pages/scheduler.php?patient=' . $id . '" class="btn btn-ghost"><i class="bi bi-calendar-plus"></i>' . t('Book') . '</a>' : '')
             . (can('patients.privacy') ? '<a href="/pages/patient_privacy.php?id=' . $id . '" class="btn btn-ghost" title="' . t('Export or anonymise this patient’s data') . '"><i class="bi bi-shield-lock"></i>' . t('Data request') . '</a>' : '')
             . (can('patients.edit') ? '<a href="/pages/patient_edit.php?id=' . $id . '" class="btn btn-gold"><i class="bi bi-pencil"></i>' . t('Edit') . '</a>' : '');
require_once __DIR__ . '/../includes/header.php';
?>
<?php if (isset($_GET['converted'])): ?><div class="alert alert-success alert-auto"><i class="bi bi-check-circle"></i>Lead converted. This is the patient record (<?= sanitize($p['mrn']) ?>).</div><?php endif; ?>
<?php if (isset($_GET['saved'])): ?><div class="alert alert-success alert-auto"><i class="bi bi-check-circle"></i><?= t('Saved.') ?></div><?php endif; ?>
<?php if (isset($_GET['merged_from'])): ?><div class="alert alert-info"><i class="bi bi-intersect"></i><?= t('That record was merged into this one.') ?></div><?php endif; ?>
<?php if ($flash): ?><div class="alert alert-<?= $flash[0] ?>"><i class="bi bi-<?= $flash[0] === 'success' ? 'check-circle' : 'exclamation-triangle' ?>"></i><div><strong><?= sanitize($flash[1]) ?></strong></div></div><?php endif; ?>
<?php if ($p['status'] !== 'active'): ?><div class="alert alert-warning"><i class="bi bi-info-circle"></i><?= t('This record is marked') ?> <strong><?= sanitize($p['status']) ?></strong>.</div><?php endif; ?>

<ul class="nav nav-tabs mb-3 page-section flex-nowrap overflow-auto" role="tablist">
  <?php foreach ($tabs as $k => [$label, $icon]): ?>
  <li class="nav-item"><a class="nav-link text-nowrap <?= $tab === $k ? 'active' : '' ?>" <?= $tab === $k ? 'aria-current="page"' : '' ?> href="?id=<?= $id ?>&amp;tab=<?= $k ?>"><i class="bi <?= $icon ?>"></i> <?= $label ?></a></li>
  <?php endforeach; ?>
</ul>

<?php if ($tab === 'overview'): ?>
<div class="row g-3">
  <div class="col-xl-7">
    <div class="card-crm">
      <div class="card-header-crm"><span><i class="bi bi-person-vcard"></i> <?= t('Details') ?></span></div>
      <div class="card-body-crm">
        <?php $kv = fn($label, $value) => $value !== null && $value !== '' ? '<div class="kv"><span class="kv-label">' . $label . '</span><span class="kv-value">' . $value . '</span></div>' : ''; ?>
        <?= $kv('File number', '<strong>' . sanitize($p['mrn']) . '</strong>') ?>
        <?= $kv('Arabic name', $p['name_ar'] ? '<span dir="rtl" lang="ar">' . sanitize($p['name_ar']) . '</span>' : '') ?>
        <?= $kv('Date of birth', $p['dob'] ? formatDate($p['dob']) : '') ?>
        <?= $kv('Nationality', sanitize(COUNTRIES[$p['nationality']] ?? ($p['nationality'] ?? ''))) ?>
        <?= $kv('Residency', $p['residency'] !== 'unknown' ? PATIENT_RESIDENCY[$p['residency']] . ($p['emirate'] ? ', ' . sanitize($p['emirate']) : '') : '') ?>
        <?php if ($p['eid_last4']): ?>
        <div class="kv"><span class="kv-label"><?= t('Emirates ID') ?></span><span class="kv-value" dir="ltr">
          <?php if ($revealEid): ?><strong><?= sanitize(eidFormat(tenantDecrypt($p['eid_enc']))) ?></strong>
          <?php else: ?>784-••••-•••<?= sanitize(substr($p['eid_last4'], 0, 3)) ?>-<?= sanitize(substr($p['eid_last4'], 3)) ?>
            <?php if (can('patients.sensitive')): ?><a href="?id=<?= $id ?>&amp;reveal=1" class="ms-2 fs-12"><?= t('Show') ?></a><?php endif; ?><?php endif; ?>
          <?= $p['eid_expiry'] ? ' · expires ' . formatDate($p['eid_expiry']) . ($p['eid_expiry'] < date('Y-m-d') ? ' <span class="chip aging-critical">' . t('Expired') . '</span>' : '') : '' ?></span></div>
        <?php endif; ?>
        <?= $kv('Passport', $p['passport_enc'] ? 'On file' : '') ?>
        <?= $kv('Email', $p['email'] ? '<a href="mailto:' . sanitize($p['email']) . '">' . sanitize($p['email']) . '</a>' : '') ?>
        <?php foreach ($phones as $ph): ?><?= $kv($ph['is_primary'] ? 'Mobile' : 'Phone', '<a href="tel:' . sanitize($ph['phone']) . '" dir="ltr">' . sanitize($ph['phone']) . '</a> · <a href="https://wa.me/' . preg_replace('/\D/', '', $ph['phone']) . '" target="_blank" rel="noopener">' . t('WhatsApp') . '</a>') ?><?php endforeach; ?>
        <?= $kv('Emergency contact', $p['emergency_name'] ? sanitize($p['emergency_name'] . ($p['emergency_relation'] ? ' (' . $p['emergency_relation'] . ')' : '') . ($p['emergency_phone'] ? ' · ' . $p['emergency_phone'] : '')) : '') ?>
        <?= $kv('Occupation', sanitize($p['occupation'] ?? '')) ?>
        <?= $kv('Tags', $p['tags'] ? implode(' ', array_map(fn($t) => '<span class="chip">' . sanitize(trim($t)) . '</span>', explode(',', $p['tags']))) : '') ?>
        <?= $kv('Registered', formatDate($p['created_at'])) ?>
        <?php if ($p['notes']): ?><div class="mt-2 fs-13" style="white-space:pre-line"><?= sanitize($p['notes']) ?></div><?php endif; ?>
      </div>
    </div>
  </div>
  <div class="col-xl-5">
    <div class="card-crm mb-3">
      <div class="card-header-crm"><span><i class="bi bi-exclamation-diamond"></i> <?= t('Alerts') ?></span></div>
      <div class="card-body-crm fs-13 text-tx-2">
        <?php $alerts = [];
          if ($p['eid_expiry'] && $p['eid_expiry'] < date('Y-m-d')) $alerts[] = 'Emirates ID expired';
          if (!$p['data_consent_at']) $alerts[] = 'No data-processing consent recorded';
          if (can('emr.view')) foreach (patientAllergies($db, $id) as $al) $alerts[] = '<span class="text-red fw-600">Allergy: ' . sanitize($al['allergen']) . ($al['severity'] === 'severe' ? ' (severe)' : '') . '</span>';
          if (can('billing.view')) {
              $ow = $db->prepare("SELECT * FROM invoices WHERE patient_id = ? AND status IN ('issued','partially_paid')"); $ow->execute([$id]);
              if ($due = array_sum(array_map(fn($x) => max(0, invoiceBalance($x)), $ow->fetchAll()))) $alerts[] = '<a href="?id=' . $id . '&amp;tab=billing">Balance due ' . money($due / 100) . '</a>';
              if (($wb = walletBalance($db, $id)) > 0) $alerts[] = 'Has ' . money($wb / 100) . ' in advance';
              foreach (patientPackages($db, $id, true) as $pk) $alerts[] = 'Package: ' . sanitize($pk['name']) . ' (' . sanitize((string)$pk['sessions']) . ')';
          }
          if (isset($tabs['insurance'])) { foreach (cardAlerts($db, $id) as $ca) $alerts[] = '<a href="?id=' . $id . '&amp;tab=insurance">' . sanitize($ca) . '</a>';
          }
          echo $alerts ? '<ul class="mb-0">' . implode('', array_map(fn($a) => '<li>' . $a . '</li>', $alerts)) . '</ul>' : 'None.'; ?>
      </div>
    </div>
    <div class="card-crm">
      <div class="card-header-crm"><span><i class="bi bi-check2-square"></i> <?= t('Consents') ?></span></div>
      <div class="card-body-crm fs-13">
        <?php foreach (['data_consent_at' => 'Data processing', 'whatsapp_opt_in_at' => 'WhatsApp messages', 'marketing_consent_at' => 'Offers and news'] as $k => $l): ?>
        <div class="kv"><span class="kv-label"><?= $l ?></span><span class="kv-value"><?= $p[$k] ? '<i class="bi bi-check-circle text-green"></i> ' . formatDate($p[$k]) : '<span class="text-tx-3">' . t('No') . '</span>' ?></span></div>
        <?php endforeach; ?>
      </div>
    </div>
  </div>
</div>

<?php elseif ($tab === 'appointments'): $ap = $db->prepare("SELECT a.*, s.name AS staff_name FROM appointments a LEFT JOIN staff s ON s.id = a.practitioner_id WHERE a.patient_id = ? ORDER BY a.scheduled_at DESC LIMIT 100"); $ap->execute([$id]); $ap = $ap->fetchAll(); ?>
<div class="card-crm">
  <?php if (!$ap): ?><div class="empty-state"><i class="bi bi-calendar"></i><div class="empty-state-title"><?= t('No appointments yet') ?></div></div>
  <?php else: ?><div class="table-responsive"><table class="table table-hover mb-0"><thead><tr><th><?= t('When') ?></th><th><?= t('Practitioner') ?></th><th><?= t('Status') ?></th></tr></thead><tbody>
    <?php foreach ($ap as $a): ?><tr><td><?= can('appointments.view') ? '<a href="/pages/appointment.php?id=' . (int)$a['id'] . '">' . formatDate($a['scheduled_at']) . '</a>' : formatDate($a['scheduled_at']) ?></td>
      <td><?= sanitize($a['staff_name'] ?? '') ?></td><td><?= function_exists('apptBadge') ? apptBadge($a['status']) : sanitize($a['status']) ?></td></tr><?php endforeach; ?>
  </tbody></table></div><?php endif; ?>
</div>

<?php elseif ($tab === 'documents'): $docs = $db->prepare("SELECT d.*, s.name AS by_name FROM patient_documents d LEFT JOIN staff s ON s.id = d.uploaded_by WHERE d.patient_id = ? ORDER BY d.id DESC"); $docs->execute([$id]); $docs = $docs->fetchAll(); ?>
<div class="row g-3">
  <div class="col-xl-8"><div class="card-crm">
    <?php if (!$docs): ?><div class="empty-state"><i class="bi bi-folder2"></i><div class="empty-state-title"><?= t('No documents yet') ?></div><p><?= t('ID scans, insurance cards and reports.') ?></p></div>
    <?php else: ?><div class="list-group list-group-flush"><?php foreach ($docs as $d): ?>
      <a class="list-group-item list-group-item-action d-flex gap-2 align-items-center" href="/pages/download.php?doc=<?= (int)$d['id'] ?>" target="_blank" rel="noopener">
        <i class="bi <?= $d['mime'] === 'application/pdf' ? 'bi-file-earmark-pdf' : 'bi-file-earmark-image' ?> fs-5"></i>
        <span><span class="fw-semibold d-block"><?= PATIENT_DOC_TYPES[$d['type']] ?></span><span class="fs-12 text-tx-3"><?= sanitize($d['original_name']) ?> · <?= round($d['size'] / 1024) ?> KB · <?= formatDate($d['created_at']) ?><?= $d['by_name'] ? ' · ' . sanitize($d['by_name']) : '' ?></span></span>
      </a><?php endforeach; ?></div><?php endif; ?>
  </div></div>
  <?php if (can('patients.edit')): ?>
  <div class="col-xl-4"><form method="POST" enctype="multipart/form-data" class="card-crm">
    <?= csrfField() ?><input type="hidden" name="action" value="upload">
    <div class="card-header-crm"><span><i class="bi bi-upload"></i> <?= t('Add a document') ?></span></div>
    <div class="card-body-crm">
      <label class="form-label" for="dType"><?= t('Type') ?></label>
      <select class="form-select mb-3" id="dType" name="type"><?php foreach (PATIENT_DOC_TYPES as $k => $l): ?><option value="<?= $k ?>"><?= $l ?></option><?php endforeach; ?></select>
      <label class="form-label" for="dFile"><?= t('File') ?> <span class="text-tx-3 fw-normal"><?= t('(JPG, PNG or PDF, up to 10 MB)') ?></span></label>
      <input class="form-control" type="file" id="dFile" name="file" accept=".jpg,.jpeg,.png,.pdf,image/jpeg,image/png,application/pdf" required>
    </div>
    <div class="form-actions"><button class="btn btn-gold ms-auto"><i class="bi bi-upload"></i><?= t('Upload') ?></button></div>
  </form></div>
  <?php endif; ?>
</div>

<?php elseif ($tab === 'consents'): $cons = patientConsents($db, $id); $tpls = consentTemplates($db); ?>
<div class="row g-3">
  <div class="col-xl-8"><div class="card-crm"><div class="card-header-crm"><span><i class="bi bi-pen"></i> <?= t('Signed consents') ?></span></div>
    <div class="list-group list-group-flush">
      <?php foreach ($cons as $c): ?><div class="list-group-item d-flex flex-wrap gap-2 align-items-center">
        <span class="flex-grow-1"><a class="fw-600" href="/pages/consent_view.php?id=<?= (int)$c['id'] ?>" target="_blank" rel="noopener"><?= sanitize($c['name']) ?></a>
          <span class="d-block fs-12 text-tx-3">Signed <?= formatDate($c['signed_at']) ?> · version <?= (int)$c['template_version'] ?><?= $c['collected_by_name'] ? ' · handed over by ' . sanitize($c['collected_by_name']) : '' ?><?= $c['witness_name'] ? ' · witness ' . sanitize($c['witness_name']) : '' ?>
          <?= $c['revoked_at'] ? '<br>Withdrawn ' . formatDate($c['revoked_at']) . ': ' . sanitize((string)$c['revoke_reason']) : '' ?></span></span>
        <?= $c['revoked_at'] ? '<span class="chip tone-red">' . t('Withdrawn') . '</span>' : '<span class="chip tone-green">' . t('Valid') . '</span>' ?>
        <?php if (!$c['revoked_at'] && can('consents.collect')): ?><details class="w-100"><summary class="fs-12 text-tx-3"><?= t('Withdraw') ?></summary>
          <form method="POST" class="d-flex gap-2 mt-1"><?= csrfField() ?><input type="hidden" name="action" value="consent_revoke"><input type="hidden" name="consent_id" value="<?= (int)$c['id'] ?>">
            <label class="visually-hidden" for="rv<?= (int)$c['id'] ?>"><?= t('Reason') ?></label><input class="form-control form-control-sm" id="rv<?= (int)$c['id'] ?>" name="reason" placeholder="<?= t('Why the patient withdrew it') ?>" required maxlength="255">
            <button class="btn btn-ghost btn-sm"><?= t('Withdraw') ?></button></form></details><?php endif; ?>
      </div><?php endforeach; ?>
      <?php if (!$cons): ?><div class="list-group-item text-tx-3 fs-13"><?= t('No consents signed yet.') ?></div><?php endif; ?>
    </div></div></div>
  <?php if (can('consents.collect') && $p['status'] === 'active'): ?>
  <div class="col-xl-4"><form method="POST" action="/pages/consent_sign.php" class="card-crm">
    <?= csrfField() ?><input type="hidden" name="action" value="start"><input type="hidden" name="patient_id" value="<?= $id ?>">
    <div class="card-header-crm"><span><i class="bi bi-tablet"></i> <?= t('Collect a consent') ?></span></div>
    <div class="card-body-crm">
      <label class="form-label" for="ctpl"><?= t('Consent form') ?></label>
      <select class="form-select" id="ctpl" name="template_id" required><?php foreach ($tpls as $t): ?><option value="<?= (int)$t['id'] ?>"><?= sanitize($t['name']) ?></option><?php endforeach; ?></select>
      <p class="fs-12 text-tx-3 mt-2 mb-0"><?= t('This device switches to the consent screen for the patient. Unlock it afterwards with your password.') ?></p>
    </div>
    <div class="form-actions"><button class="btn btn-gold ms-auto" <?= $tpls ? '' : 'disabled' ?>><i class="bi bi-tablet"></i><?= t('Hand to patient') ?></button></div>
  </form></div>
  <?php endif; ?>
</div>

<?php elseif ($tab === 'photos'):
  $photos = patientPhotos($db, $id);
  $byId = array_column($photos, null, 'id');
  $cmp = array_slice(array_values(array_filter(array_map('intval', explode(',', (string)($_GET['compare'] ?? ''))), fn($x) => isset($byId[$x]))), 0, 2);
  $encs = $db->prepare("SELECT e.id, COALESCE(a.scheduled_at, e.created_at) AS at FROM encounters e LEFT JOIN appointments a ON a.id = e.appointment_id WHERE e.patient_id = ? ORDER BY at DESC LIMIT 30"); $encs->execute([$id]);
  $encs = $encs->fetchAll(); $areas = array_values(array_unique(array_column($photos, 'area')));
  $stageTone = ['before' => 'tone-slate', 'after' => 'tone-green', 'progress' => 'tone-blue']; ?>
<?php if (count($cmp) === 2): ?>
<div class="card-crm mb-3"><div class="card-header-crm"><span><i class="bi bi-layout-split"></i> <?= t('Compare') ?></span><a class="btn btn-ghost btn-sm ms-auto" href="?id=<?= $id ?>&amp;tab=photos"><?= t('Close') ?></a></div>
  <div class="card-body-crm"><div class="row g-2"><?php foreach ($cmp as $x): $ph = $byId[$x]; ?>
    <div class="col-6"><img class="img-fluid rounded w-100" style="object-fit:contain;max-height:70vh;background:#111" src="/pages/download.php?photo=<?= $x ?>" alt="<?= sanitize($ph['area'] . ', ' . PHOTO_STAGES[$ph['stage']] . ', ' . formatDate($ph['taken_at'])) ?>">
      <div class="fs-13 mt-1"><span class="chip <?= $stageTone[$ph['stage']] ?>"><?= PHOTO_STAGES[$ph['stage']] ?></span> <?= sanitize($ph['area']) ?> · <?= formatDate($ph['taken_at']) ?></div></div>
  <?php endforeach; ?></div></div></div>
<?php endif; ?>
<div class="row g-3">
  <div class="col-xl-8"><div class="card-crm">
    <div class="card-header-crm"><span><i class="bi bi-images"></i> <?= t('Photos') ?></span><span class="fs-12 text-tx-3 ms-auto"><?= t('Tick two to compare side by side') ?></span></div>
    <?php if (!$photos): ?><div class="empty-state"><i class="bi bi-images"></i><div class="empty-state-title"><?= t('No photos yet') ?></div><p><?= t('Before, after and progress photos of treated areas.') ?></p></div>
    <?php else: ?><form method="GET" class="card-body-crm"><input type="hidden" name="id" value="<?= $id ?>"><input type="hidden" name="tab" value="photos"><input type="hidden" name="compare" id="cmpIds">
      <?php foreach ($areas as $area): ?><h3 class="fs-6 fw-600 mt-2"><?= sanitize($area) ?></h3><div class="row g-2 mb-2">
        <?php foreach ($photos as $ph): if ($ph['area'] !== $area) continue; ?><div class="col-6 col-md-4 col-lg-3"><div class="border rounded p-1 h-100">
          <a href="/pages/download.php?photo=<?= (int)$ph['id'] ?>" target="_blank" rel="noopener"><img loading="lazy" class="w-100 rounded" style="aspect-ratio:1;object-fit:cover" src="/pages/download.php?photo=<?= (int)$ph['id'] ?>" alt="<?= sanitize($area . ', ' . PHOTO_STAGES[$ph['stage']] . ', ' . formatDate($ph['taken_at'])) ?>"></a>
          <div class="d-flex align-items-center gap-1 mt-1 fs-12"><input class="form-check-input cmp" type="checkbox" value="<?= (int)$ph['id'] ?>" id="cp<?= (int)$ph['id'] ?>">
            <label for="cp<?= (int)$ph['id'] ?>"><span class="chip <?= $stageTone[$ph['stage']] ?>"><?= PHOTO_STAGES[$ph['stage']] ?></span> <?= formatDate($ph['taken_at']) ?></label></div>
          <?php if ($ph['notes']): ?><div class="fs-12 text-tx-3"><?= sanitize($ph['notes']) ?></div><?php endif; ?>
        </div></div><?php endforeach; ?></div><?php endforeach; ?>
      <button class="btn btn-ghost btn-sm" id="cmpBtn" disabled><i class="bi bi-layout-split"></i><?= t('Compare') ?></button>
    </form>
    <script>
    (function () { var boxes = document.querySelectorAll('.cmp'), btn = document.getElementById('cmpBtn');
      boxes.forEach(function (b) { b.addEventListener('change', function () { var on = [].filter.call(boxes, function (x) { return x.checked; });
        if (on.length > 2) { b.checked = false; on = on.filter(function (x) { return x !== b; }); }
        btn.disabled = on.length !== 2; document.getElementById('cmpIds').value = on.map(function (x) { return x.value; }).join(','); }); }); })();
    </script><?php endif; ?>
  </div>
  <?php if ($photos): ?><details class="mt-2"><summary class="fs-12 text-tx-3"><?= t('Remove a photo taken by mistake') ?></summary>
    <form method="POST" class="d-flex gap-2 mt-1"><?= csrfField() ?><input type="hidden" name="action" value="photo_remove">
      <label class="visually-hidden" for="rmPh"><?= t('Photo') ?></label><select class="form-select form-select-sm" id="rmPh" name="photo_id"><?php foreach ($photos as $ph): ?><option value="<?= (int)$ph['id'] ?>"><?= sanitize($ph['area']) ?> · <?= PHOTO_STAGES[$ph['stage']] ?> · <?= formatDate($ph['taken_at']) ?> (#<?= (int)$ph['id'] ?>)</option><?php endforeach; ?></select>
      <button class="btn btn-ghost btn-sm" onclick="return confirm('Remove this photo from the gallery?')"><?= t('Remove') ?></button></form></details><?php endif; ?>
  </div>
  <div class="col-xl-4"><form method="POST" enctype="multipart/form-data" class="card-crm">
    <?= csrfField() ?><input type="hidden" name="action" value="photo_upload">
    <div class="card-header-crm"><span><i class="bi bi-camera"></i> <?= t('Add photos') ?></span></div>
    <div class="card-body-crm">
      <label class="form-label" for="phFile"><?= t('Photos') ?> <span class="text-tx-3 fw-normal">(take them now or choose; JPG or PNG)</span></label>
      <input class="form-control mb-3" type="file" id="phFile" name="photos[]" accept="image/jpeg,image/png" multiple required>
      <label class="form-label" for="phArea"><?= t('Area') ?></label>
      <input class="form-control mb-3" id="phArea" name="area" list="phAreas" maxlength="80" required placeholder="<?= t('e.g. Forehead, Left cheek') ?>">
      <datalist id="phAreas"><?php foreach (array_unique([...$areas, ...array_column(FACE_ZONES, 0)]) as $a): ?><option value="<?= sanitize($a) ?>"><?php endforeach; ?></datalist>
      <div class="row g-2 mb-3">
        <div class="col-6"><label class="form-label" for="phStage"><?= t('Stage') ?></label><select class="form-select" id="phStage" name="stage"><?php foreach (PHOTO_STAGES as $k => $l): ?><option value="<?= $k ?>"><?= $l ?></option><?php endforeach; ?></select></div>
        <div class="col-6"><label class="form-label" for="phDate"><?= t('Taken') ?></label><input class="form-control" type="date" id="phDate" name="taken_at" value="<?= date('Y-m-d') ?>" max="<?= date('Y-m-d') ?>"></div>
      </div>
      <label class="form-label" for="phEnc"><?= t('Visit') ?> <span class="text-tx-3 fw-normal"><?= t('(optional)') ?></span></label>
      <select class="form-select mb-3" id="phEnc" name="encounter_id"><option value="">—</option><?php foreach ($encs as $en): ?><option value="<?= (int)$en['id'] ?>" <?= (int)($_GET['encounter'] ?? 0) === (int)$en['id'] ? 'selected' : '' ?>><?= formatDate($en['at']) ?></option><?php endforeach; ?></select>
      <label class="form-label" for="phNotes"><?= t('Note') ?> <span class="text-tx-3 fw-normal"><?= t('(optional)') ?></span></label>
      <input class="form-control" id="phNotes" name="notes" maxlength="255">
    </div>
    <div class="form-actions"><button class="btn btn-gold ms-auto"><i class="bi bi-upload"></i><?= t('Add') ?></button></div>
  </form></div>
</div>

<?php elseif ($tab === 'insurance'):
  $cards = patientCards($db, $id); $ec = isset($_GET['card']) ? (array_values(array_filter($cards, fn($c) => (int)$c['id'] === (int)$_GET['card']))[0] ?? null) : null;
  $cardDocs = $db->prepare("SELECT id, original_name, created_at FROM patient_documents WHERE patient_id = ? AND type = 'insurance_card' ORDER BY id DESC"); $cardDocs->execute([$id]); $cardDocs = $cardDocs->fetchAll();
  $pas = $db->prepare("SELECT pa.*, c.name AS company FROM prior_auths pa JOIN insurance_plans p ON p.id = pa.plan_id JOIN insurance_companies c ON c.id = p.company_id WHERE pa.patient_id = ? ORDER BY pa.id DESC LIMIT 20"); $pas->execute([$id]);
  $cls = $db->prepare("SELECT cl.* FROM claims cl JOIN invoices i ON i.id = cl.invoice_id WHERE i.patient_id = ? ORDER BY cl.id DESC LIMIT 20"); $cls->execute([$id]);
  $canEdit = can('patients.edit') || can('insurance.manage'); ?>
<div class="row g-3">
  <div class="col-xl-7">
    <div class="card-crm mb-3"><div class="card-header-crm"><span><i class="bi bi-shield-plus"></i> <?= t('Insurance cards') ?></span></div><div class="list-group list-group-flush">
      <?php foreach ($cards as $c): $dead = !$c['is_active'] || ($c['valid_to'] && $c['valid_to'] < date('Y-m-d')); ?>
      <div class="list-group-item d-flex gap-2 align-items-center <?= $dead ? 'text-tx-3' : '' ?>"><span class="flex-grow-1"><span class="fw-600"><?= sanitize($c['company'] . ' – ' . $c['plan_name']) ?></span><?= $c['is_primary'] ? ' <span class="chip tone-gold">' . t('Primary') . '</span>' : '' ?>
        <span class="d-block fs-12">Member <?= sanitize($c['member_no']) ?><?= $c['policy_no'] ? ' · policy ' . sanitize($c['policy_no']) : '' ?><?= $c['valid_to'] ? ' · until ' . formatDate($c['valid_to']) : '' ?> · co-pay <?= rtrim(rtrim($c['copay_percent'], '0'), '.') ?>%</span></span>
        <?= $dead ? '<span class="chip tone-red">' . t('Not valid') . '</span>' : '' ?><?php if ($canEdit): ?><a class="btn btn-ghost btn-sm" href="?id=<?= $id ?>&amp;tab=insurance&amp;card=<?= (int)$c['id'] ?>"><?= t('Edit') ?></a><?php endif; ?></div>
      <?php endforeach; ?><?php if (!$cards): ?><div class="list-group-item fs-13 text-tx-3"><?= t('No insurance cards.') ?></div><?php endif; ?></div></div>
    <?php if (can('insurance.view')): ?>
    <div class="card-crm mb-3"><div class="card-header-crm"><span><i class="bi bi-shield-check"></i> <?= t('Prior approvals') ?></span><?php if (can('insurance.manage') && $cards): ?><a class="btn btn-ghost btn-sm ms-auto" href="/pages/prior_auths.php?patient=<?= $id ?>"><?= t('Request approval') ?></a><?php endif; ?></div><div class="list-group list-group-flush">
      <?php foreach ($pas as $pa): ?><a class="list-group-item list-group-item-action d-flex fs-13" href="/pages/prior_auths.php?id=<?= (int)$pa['id'] ?>"><span class="flex-grow-1"><?= sanitize($pa['company']) ?> · <?= formatDate($pa['requested_at']) ?><?= $pa['approval_no'] ? ' · ' . sanitize($pa['approval_no']) : '' ?></span><span class="chip <?= PA_STATUS[$pa['status']][1] ?>"><?= PA_STATUS[$pa['status']][0] ?></span></a><?php endforeach; ?>
    </div></div>
    <div class="card-crm"><div class="card-header-crm"><span><i class="bi bi-file-medical"></i> <?= t('Claims') ?></span></div><div class="list-group list-group-flush">
      <?php foreach ($cls as $cl): ?><a class="list-group-item list-group-item-action d-flex fs-13" href="/pages/claim.php?id=<?= (int)$cl['id'] ?>"><span class="flex-grow-1"><?= sanitize($cl['number']) ?> · <?= money($cl['claimed']) ?></span><span class="chip <?= CLAIM_STATUS[$cl['status']][1] ?>"><?= CLAIM_STATUS[$cl['status']][0] ?></span></a><?php endforeach; ?>
    </div></div>
    <?php endif; ?>
  </div>
  <?php if ($canEdit): ?>
  <div class="col-xl-5"><form method="POST" class="card-crm"><?= csrfField() ?><input type="hidden" name="action" value="card_save"><input type="hidden" name="card_id" value="<?= (int)($ec['id'] ?? 0) ?>">
    <div class="card-header-crm"><span><?= $ec ? 'Edit card' : 'Add a card' ?></span></div><div class="card-body-crm">
      <label class="form-label" for="ip"><?= t('Plan') ?></label><select class="form-select mb-2" id="ip" name="plan_id" required><?php foreach (insurancePlans($db, true) as $pl): ?><option value="<?= (int)$pl['id'] ?>" <?= (int)($ec['plan_id'] ?? 0) === (int)$pl['id'] ? 'selected' : '' ?>><?= sanitize($pl['company'] . ' – ' . $pl['name']) ?></option><?php endforeach; ?></select>
      <div class="row g-2 mb-2"><div class="col-6"><label class="form-label" for="im"><?= t('Member no.') ?></label><input class="form-control" id="im" name="member_no" required maxlength="60" value="<?= sanitize((string)($ec['member_no'] ?? '')) ?>"></div>
        <div class="col-6"><label class="form-label" for="ipo"><?= t('Policy no.') ?></label><input class="form-control" id="ipo" name="policy_no" maxlength="60" value="<?= sanitize((string)($ec['policy_no'] ?? '')) ?>"></div>
        <div class="col-6"><label class="form-label" for="ivf"><?= t('Valid from') ?></label><input class="form-control" type="date" id="ivf" name="valid_from" value="<?= sanitize((string)($ec['valid_from'] ?? '')) ?>"></div>
        <div class="col-6"><label class="form-label" for="ivt"><?= t('Valid until') ?></label><input class="form-control" type="date" id="ivt" name="valid_to" value="<?= sanitize((string)($ec['valid_to'] ?? '')) ?>"></div></div>
      <?php foreach (['card_front_doc_id' => 'Card front', 'card_back_doc_id' => 'Card back'] as $k => $lbl): ?>
      <label class="form-label" for="<?= $k ?>"><?= $lbl ?> <span class="fw-normal text-tx-3"><?= t('(upload it under Documents as “Insurance card”)') ?></span></label><select class="form-select mb-2" id="<?= $k ?>" name="<?= $k ?>"><option value="">—</option><?php foreach ($cardDocs as $d): ?><option value="<?= (int)$d['id'] ?>" <?= (int)($ec[$k] ?? 0) === (int)$d['id'] ? 'selected' : '' ?>><?= sanitize($d['original_name'] ?: 'Image') ?> · <?= formatDate($d['created_at']) ?></option><?php endforeach; ?></select>
      <?php endforeach; ?>
      <div class="form-check"><input class="form-check-input" type="checkbox" id="ipr" name="is_primary" value="1" <?= !$ec || $ec['is_primary'] ? 'checked' : '' ?>><label class="form-check-label" for="ipr"><?= t('Primary card') ?></label></div>
      <div class="form-check"><input type="hidden" name="is_active" value="0"><input class="form-check-input" type="checkbox" id="iac" name="is_active" value="1" <?= !$ec || $ec['is_active'] ? 'checked' : '' ?>><label class="form-check-label" for="iac"><?= t('In use') ?></label></div>
    </div><div class="form-actions"><?php if ($ec): ?><a class="btn btn-ghost" href="?id=<?= $id ?>&amp;tab=insurance"><?= t('Cancel') ?></a><?php endif; ?><button class="btn btn-gold ms-auto"><?= t('Save card') ?></button></div></form></div>
  <?php endif; ?>
</div>

<?php elseif ($tab === 'messages'):
  $ms = $db->prepare("SELECT m.*, s.name AS staff FROM messages m LEFT JOIN staff s ON s.id = m.staff_id WHERE m.patient_id = ? OR (m.lead_id IS NOT NULL AND m.lead_id = ?) ORDER BY m.id DESC LIMIT 100");
  $ms->execute([$id, (int)$p['lead_id']]); $ms = $ms->fetchAll(); ?>
<div class="card-crm"><div class="card-header-crm"><span><i class="bi bi-chat-dots"></i> <?= t('Messages') ?></span>
  <span class="ms-auto fs-12 text-tx-3">WhatsApp <?= $p['whatsapp_opt_in'] ? 'opted in' : 'not opted in' ?> · marketing <?= $p['marketing_consent'] ? 'allowed' : 'not allowed' ?></span></div>
  <div class="list-group list-group-flush"><?php foreach ($ms as $m): ?><div class="list-group-item fs-13">
    <div class="d-flex gap-2"><span class="chip <?= $m['direction'] === 'in' ? 'tone-blue' : ($m['status'] === 'failed' || $m['status'] === 'skipped' ? 'tone-amber' : 'tone-slate') ?>"><?= $m['direction'] === 'in' ? 'From patient' : sanitize($m['status']) ?></span>
      <span><?= MSG_CHANNELS[$m['channel']] ?? '' ?> · <?= date('d M Y H:i', strtotime($m['created_at'])) ?><?= $m['automation_key'] ? ' · automatic (' . sanitize($m['automation_key']) . ')' : ($m['staff'] ? ' · ' . sanitize($m['staff']) : '') ?></span>
      <?php if ($m['channel'] !== 'email'): ?><a class="ms-auto" href="/pages/inbox.php?with=<?= urlencode($m['to_from']) ?>"><?= t('Conversation') ?></a><?php endif; ?></div>
    <div class="text-tx-2 mt-1" style="white-space:pre-wrap"><?= sanitize($m['body']) ?><?= $m['error'] ? '<div class="text-danger fs-12">' . sanitize($m['error']) . '</div>' : '' ?></div></div><?php endforeach; ?>
  <?php if (!$ms): ?><div class="list-group-item text-tx-3 fs-13"><?= t('No messages yet.') ?></div><?php endif; ?></div></div>

<?php elseif ($tab === 'lead'): $lead = null; if ($p['lead_id']) { $s = $db->prepare("SELECT * FROM leads WHERE id = ?"); $s->execute([$p['lead_id']]); $lead = $s->fetch(); } ?>
<div class="card-crm">
  <?php if (!$lead): ?><div class="empty-state"><i class="bi bi-signpost-split"></i><div class="empty-state-title"><?= t('Not from a lead') ?></div><p><?= t('This patient was registered directly.') ?></p></div>
  <?php else: $fu = $db->prepare("SELECT f.*, s.name AS staff_name FROM followups f LEFT JOIN staff s ON s.id = f.staff_id WHERE f.lead_id = ? ORDER BY f.id DESC LIMIT 50"); $fu->execute([$lead['id']]); ?>
  <div class="card-body-crm">
    <div class="kv"><span class="kv-label"><?= t('Lead') ?></span><span class="kv-value"><a href="/pages/lead_view.php?id=<?= (int)$lead['id'] ?>"><?= sanitize($lead['name']) ?></a> · <?= stageBadge($lead['stage']) ?></span></div>
    <div class="kv"><span class="kv-label"><?= t('Source') ?></span><span class="kv-value"><?= sourceBadge($lead['source']) ?><?= $lead['source_campaign'] ? ' · ' . sanitize($lead['source_campaign']) : '' ?></span></div>
    <div class="kv"><span class="kv-label"><?= t('Enquired') ?></span><span class="kv-value"><?= formatDate($lead['created_at']) ?></span></div>
  </div>
  <div class="list-group list-group-flush">
    <?php foreach ($fu as $f): ?><div class="list-group-item fs-13"><strong><?= formatDate($f['created_at']) ?></strong> · <?= sanitize($f['staff_name'] ?? '') ?> · <?= channelLabel($f['channel'] ?? null) ?> <?= outcomeLabel($f['outcome'] ?? null) ?>
      <?php if (!empty($f['remarks'])): ?><div class="text-tx-2"><?= sanitize($f['remarks']) ?></div><?php endif; ?></div><?php endforeach; ?>
  </div>
  <?php endif; ?>
</div>

<?php elseif ($tab === 'audit'): $au = $db->prepare("SELECT a.*, s.name AS staff_name FROM audit_log a LEFT JOIN staff s ON s.id = a.staff_id WHERE a.entity = 'patient' AND a.entity_id = ? ORDER BY a.id DESC LIMIT 100"); $au->execute([$id]);
  $acc = $db->prepare("SELECT l.what, l.at, s.name AS staff_name FROM patient_access_log l LEFT JOIN staff s ON s.id = l.staff_id WHERE l.patient_id = ? ORDER BY l.id DESC LIMIT 30"); $acc->execute([$id]); ?>
<div class="row g-3">
  <div class="col-xl-7"><div class="card-crm"><div class="card-header-crm"><span><i class="bi bi-journal-text"></i> <?= t('Changes') ?></span></div><div class="list-group list-group-flush">
    <?php foreach ($au as $a): ?><div class="list-group-item fs-13"><strong><?= sanitize(str_replace('_', ' ', $a['action'])) ?></strong> · <?= formatDate($a['at']) ?> · <?= sanitize($a['staff_name'] ?? 'System') ?>
      <?php if ($a['changes']): foreach ((array)json_decode($a['changes'], true) as $k => $c): ?><div class="text-tx-2"><?= sanitize(str_replace('_', ' ', (string)$k)) ?>: <?= sanitize(is_array($c) && array_key_exists('to', $c) ? (is_array($c['to']) ? implode(', ', $c['to']) : (string)($c['to'] ?? '—')) : (is_array($c) ? json_encode($c, JSON_UNESCAPED_UNICODE) : (string)$c)) ?></div><?php endforeach; endif; ?></div><?php endforeach; ?>
  </div></div></div>
  <div class="col-xl-5"><div class="card-crm"><div class="card-header-crm"><span><i class="bi bi-eye"></i> <?= t('Recently opened by') ?></span></div><div class="list-group list-group-flush">
    <?php foreach ($acc as $a): ?><div class="list-group-item fs-13"><?= sanitize($a['staff_name'] ?? '—') ?> · <?= sanitize($a['what']) ?> · <?= timeSince($a['at']) ?></div><?php endforeach; ?>
  </div></div></div>
</div>

<?php elseif ($tab === 'clinical'):
  $enc = $db->prepare("SELECT e.id, e.status, e.created_at, e.locked_at, e.chief_complaint, e.practitioner_id, e.created_by, a.scheduled_at, s.name AS prac,
                              (SELECT GROUP_CONCAT(CONCAT(icd10_code, ' ', description) SEPARATOR '; ') FROM encounter_diagnoses d WHERE d.encounter_id = e.id) AS dx
                       FROM encounters e LEFT JOIN appointments a ON a.id = e.appointment_id LEFT JOIN staff s ON s.id = e.practitioner_id
                       WHERE e.patient_id = ? ORDER BY COALESCE(a.scheduled_at, e.created_at) DESC LIMIT 100");
  $enc->execute([$id]); $enc = array_filter($enc->fetchAll(), 'canSeeEncounter');   // doctors see their own patients' notes unless they have emr.all
  $hist = patientHistory($db, $id); $alls = patientAllergies($db, $id, false); ?>
<div class="row g-3">
  <div class="col-xl-8"><div class="card-crm">
    <div class="card-header-crm"><span><i class="bi bi-clipboard2-pulse"></i> <?= t('Clinical notes') ?></span>
      <?php if (can('emr.write')): ?><form method="POST" action="/pages/encounter.php" class="ms-auto"><?= csrfField() ?><input type="hidden" name="action" value="open"><input type="hidden" name="patient_id" value="<?= $id ?>">
        <button class="btn btn-gold btn-sm"><i class="bi bi-plus-lg"></i><?= t('Walk-in note') ?></button></form><?php endif; ?></div>
    <div class="list-group list-group-flush">
      <?php foreach ($enc as $x): ?><a class="list-group-item list-group-item-action" href="/pages/encounter.php?id=<?= (int)$x['id'] ?>">
        <div class="d-flex gap-2"><strong><?= formatDate($x['scheduled_at'] ?: $x['created_at']) ?></strong> <span class="text-tx-3"><?= sanitize($x['prac'] ?? '') ?></span>
          <span class="ms-auto"><?= $x['status'] === 'open' ? ($x['locked_at'] ? '<span class="chip tone-slate">' . t('Locked, unsigned') . '</span>' : '<span class="chip tone-blue">' . t('Open') . '</span>') : '<span class="chip tone-green">' . t('Signed') . '</span>' ?></span></div>
        <div class="fs-13 text-tx-2"><?= sanitize(mb_strimwidth((string)($x['dx'] ?: $x['chief_complaint']), 0, 140, '…')) ?></div></a><?php endforeach; ?>
      <?php if (!$enc): ?><div class="list-group-item text-tx-3 fs-13"><?= t('No clinical notes yet. Notes start from an appointment (Open clinical note) or as a walk-in.') ?></div><?php endif; ?>
    </div>
  </div></div>
  <div class="col-xl-4">
    <div class="card-crm mb-3"><div class="card-header-crm"><span><i class="bi bi-exclamation-octagon"></i> <?= t('Allergies') ?></span></div><div class="list-group list-group-flush">
      <?php foreach ($alls as $al): ?><div class="list-group-item fs-13 <?= $al['status'] === 'resolved' ? 'text-tx-3 text-decoration-line-through' : '' ?>"><strong><?= sanitize($al['allergen']) ?></strong> · <?= ALLERGY_SEVERITY[$al['severity']] ?><?= $al['reaction'] ? ' · ' . sanitize($al['reaction']) : '' ?></div><?php endforeach; ?>
      <?php if (!$alls): ?><div class="list-group-item fs-13 text-tx-3"><?= t('None recorded.') ?></div><?php endif; ?></div></div>
    <div class="card-crm"><div class="card-header-crm"><span><i class="bi bi-clock-history"></i> <?= t('History') ?></span></div><div class="list-group list-group-flush">
      <?php foreach ($hist as $h): ?><div class="list-group-item fs-13"><span class="fs-12 fw-600 text-tx-3"><?= HISTORY_TYPES[$h['type']] ?></span><br><?= nl2br(sanitize($h['detail'])) ?></div><?php endforeach; ?>
      <?php if (!$hist): ?><div class="list-group-item fs-13 text-tx-3"><?= t('None recorded.') ?></div><?php endif; ?></div></div>
  </div>
</div>

<?php elseif ($tab === 'billing'):
  $invs = $db->prepare("SELECT * FROM invoices WHERE patient_id = ? ORDER BY COALESCE(issued_at, created_at) DESC LIMIT 100"); $invs->execute([$id]); $invs = $invs->fetchAll();
  $rcts = $db->prepare("SELECT r.*, (SELECT GROUP_CONCAT(DISTINCT m.method) FROM receipt_methods m WHERE m.receipt_id = r.id) AS methods FROM receipts r WHERE r.patient_id = ? ORDER BY r.received_at DESC LIMIT 50"); $rcts->execute([$id]);
  $ledger = $db->prepare("SELECT * FROM patient_wallet WHERE patient_id = ? ORDER BY id DESC LIMIT 30"); $ledger->execute([$id]); $ledger = $ledger->fetchAll();
  $wallet = walletBalance($db, $id); $owed = array_sum(array_map(fn($x) => in_array($x['status'], ['issued', 'partially_paid'], true) ? max(0, invoiceBalance($x)) : 0, $invs));
  $pkgs = patientPackages($db, $id); ?>
<div class="d-flex flex-wrap gap-2 mb-3 align-items-center">
  <span class="chip <?= $owed ? 'tone-amber' : 'tone-green' ?>">Owes <?= money($owed / 100) ?></span>
  <span class="chip tone-slate">Balance <?= money($wallet / 100) ?></span>
  <span class="ms-auto d-flex gap-2"><?php if (can('billing.invoice') && $p['status'] === 'active'): ?>
    <form method="POST" action="/pages/invoice.php"><?= csrfField() ?><input type="hidden" name="action" value="new"><input type="hidden" name="patient_id" value="<?= $id ?>"><button class="btn btn-ghost btn-sm"><i class="bi bi-plus-lg"></i><?= t('New invoice') ?></button></form>
    <a class="btn btn-gold btn-sm" href="/pages/receipt_new.php?patient=<?= $id ?>"><i class="bi bi-cash-coin"></i><?= t('Take payment') ?></a><?php endif; ?></span>
</div>
<div class="row g-3">
  <div class="col-xl-7">
    <div class="card-crm mb-3"><div class="card-header-crm"><span><i class="bi bi-receipt"></i> <?= t('Invoices') ?></span></div><div class="list-group list-group-flush">
      <?php foreach ($invs as $x): [$sl, $tone] = INVOICE_STATUS[$x['status']]; ?><a class="list-group-item list-group-item-action d-flex gap-2 align-items-center" href="/pages/invoice.php?id=<?= (int)$x['id'] ?>">
        <span class="flex-grow-1"><span class="fw-600"><?= sanitize($x['number'] ?? 'Draft') ?></span><span class="d-block fs-12 text-tx-3"><?= formatDate($x['issued_at'] ?? $x['created_at']) ?></span></span>
        <span class="chip <?= $tone ?>"><?= $sl ?></span><span class="tabular"><?= number_format($x['total'], 2) ?></span></a><?php endforeach; ?>
      <?php if (!$invs): ?><div class="list-group-item fs-13 text-tx-3"><?= t('No invoices yet.') ?></div><?php endif; ?></div></div>
    <div class="card-crm"><div class="card-header-crm"><span><i class="bi bi-cash-coin"></i> <?= t('Payments') ?></span></div><div class="list-group list-group-flush">
      <?php foreach ($rcts as $r): ?><a class="list-group-item list-group-item-action d-flex gap-2 <?= $r['status'] === 'void' ? 'text-decoration-line-through text-tx-3' : '' ?>" href="/pages/receipt.php?id=<?= (int)$r['id'] ?>">
        <span class="flex-grow-1"><?= sanitize($r['number']) ?><span class="d-block fs-12 text-tx-3"><?= formatDate($r['received_at']) ?> · <?= sanitize(implode(', ', array_map(fn($m) => PAY_METHODS[$m] ?? $m, explode(',', (string)$r['methods'])))) ?></span></span>
        <span class="tabular"><?= number_format($r['total'], 2) ?></span></a><?php endforeach; ?>
    </div></div>
  </div>
  <div class="col-xl-5">
    <div class="card-crm mb-3"><div class="card-header-crm"><span><i class="bi bi-box2-heart"></i> <?= t('Packages') ?></span></div><div class="list-group list-group-flush">
      <?php foreach ($pkgs as $pk): ?><div class="list-group-item"><div class="d-flex"><span class="fw-600"><?= sanitize($pk['name']) ?></span><span class="ms-auto chip <?= $pk['status'] === 'active' ? 'tone-green' : 'tone-slate' ?>"><?= ucfirst($pk['status']) ?></span></div>
        <div class="fs-12 text-tx-3"><?= sanitize((string)$pk['sessions']) ?> · until <?= formatDate($pk['expires_on']) ?></div></div><?php endforeach; ?>
      <?php if (!$pkgs): ?><div class="list-group-item fs-13 text-tx-3"><?= t('No packages.') ?></div><?php endif; ?></div></div>
    <div class="card-crm"><div class="card-header-crm"><span><i class="bi bi-wallet2"></i> Balance (advance) <?= money($wallet / 100) ?></span></div><div class="list-group list-group-flush">
      <?php foreach ($ledger as $w): ?><div class="list-group-item d-flex fs-13"><span><?= sanitize((string)$w['note'] ?: ucfirst($w['type'])) ?><span class="d-block fs-12 text-tx-3"><?= formatDate($w['at']) ?></span></span><span class="ms-auto tabular <?= (float)$w['amount'] < 0 ? 'text-danger' : '' ?>"><?= number_format($w['amount'], 2) ?></span></div><?php endforeach; ?>
      <?php if (!$ledger): ?><div class="list-group-item fs-13 text-tx-3"><?= t('Nothing kept for this patient.') ?></div><?php endif; ?></div>
      <?php if ($wallet > 0 && can('billing.refund')): ?><details class="card-body-crm border-top"><summary class="fs-13"><?= t('Refund from the balance') ?></summary>
        <form method="POST" class="row g-2 mt-1"><?= csrfField() ?><input type="hidden" name="action" value="wallet_refund">
          <div class="col-5"><label class="visually-hidden" for="wa"><?= t('Amount') ?></label><input class="form-control" id="wa" name="amount" type="number" min="0.01" max="<?= aed($wallet) ?>" step="0.01" placeholder="<?= t('Amount') ?>" required></div>
          <div class="col-7"><label class="visually-hidden" for="wm"><?= t('Method') ?></label><select class="form-select" id="wm" name="method"><?php foreach (REFUND_METHODS as $k => $l): ?><option value="<?= $k ?>"><?= $l ?></option><?php endforeach; ?></select></div>
          <div class="col-12"><label class="visually-hidden" for="wn"><?= t('Note') ?></label><input class="form-control" id="wn" name="note" maxlength="255" placeholder="<?= t('Why') ?>" required></div>
          <div class="col-12"><button class="btn btn-ghost btn-sm" onclick="return confirm('Pay this money back to the patient?')"><?= t('Refund') ?></button></div></form></details><?php endif; ?>
    </div>
  </div>
</div>
<?php endif; ?>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>

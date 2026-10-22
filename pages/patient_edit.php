<?php
// pages/patient_edit.php — register or edit a patient (?id=), and "Convert lead to patient" (POST action=convert from
// lead_view.php). Emirates ID and passport are encrypted; they are typed fresh to change them and never shown here.
// A phone number already on another patient asks for confirmation (families share numbers); a duplicate Emirates ID is refused.
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/inventory.php';     // CSRF helpers
require_once __DIR__ . '/../includes/patients.php';
require_once __DIR__ . '/../includes/countries.php';
requirePermission('patients.edit');

$db = getDB();
$id = (int)($_GET['id'] ?? $_POST['id'] ?? 0);
$p = $id ? findPatient($db, $id) : null;
if ($id && (!$p || (int)$p['id'] !== $id)) { header('Location: /pages/patient.php?id=' . $id); exit; }   // merged or missing
$error = ''; $dupPhones = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCsrf();
    if (($_POST['action'] ?? '') === 'convert') {
        $leadId = (int)($_POST['lead_id'] ?? 0);
        $l = $db->prepare("SELECT assigned_to FROM leads WHERE id = ?"); $l->execute([$leadId]);
        $owner = $l->fetchColumn();
        if ($owner === false || (!can('leads.all') && (int)$owner !== currentStaffId())) { http_response_code(403); exit('You can’t convert this lead.'); }
        try {
            $pid = convertLeadToPatient($db, $leadId);
            header("Location: /pages/patient.php?id=$pid&converted=1"); exit;
        } catch (Throwable $e) {
            $_SESSION['lead_flash'] = 'Couldn’t convert: ' . $e->getMessage();
            header("Location: /pages/lead_view.php?id=$leadId"); exit;
        }
    }
    $f = array_intersect_key($_POST, array_flip(PATIENT_FIELDS)) + [
        'eid' => $_POST['eid'] ?? '', 'passport' => $_POST['passport'] ?? '', 'eid_clear' => !empty($_POST['eid_clear']),
        'marketing_consent' => !empty($_POST['marketing_consent']), 'whatsapp_opt_in' => !empty($_POST['whatsapp_opt_in']), 'data_consent' => !empty($_POST['data_consent']),
    ];
    $phones = array_slice((array)($_POST['phones'] ?? []), 0, 5);
    try {
        $dupPhones = findPatientDuplicates($db, $phones, null, $id)['phone'];
        if ($dupPhones && empty($_POST['confirm_shared_phone'])) throw new RuntimeException('');
        $newId = savePatient($db, $f, $phones, $id);
        header("Location: /pages/patient.php?id=$newId&saved=1"); exit;
    } catch (Throwable $e) {
        $error = $e->getMessage();
        $p = array_merge($p ?? [], $f, ['phones_posted' => $phones]);   // show what they typed (never the ID numbers)
    }
}

if (!$p) { $hb = $db->prepare("SELECT branch_id FROM staff WHERE id = ?"); $hb->execute([currentStaffId()]); $p = ['branch_id' => $hb->fetchColumn() ?: null, 'data_consent' => true]; }   // new: the receptionist's branch
$phones = $p['phones_posted'] ?? ($id ? array_column(patientPhones($db, $id), 'phone') : []);
while (count($phones) < 2) $phones[] = '';
$branches = branchList($db);
$sources = $db->query("SELECT id, name FROM lead_sources WHERE is_active = 1 ORDER BY sort_order, name")->fetchAll(PDO::FETCH_KEY_PAIR);
$val = fn(string $k) => sanitize((string)($p[$k] ?? ''));
$sel = fn(string $k, $v) => (string)($p[$k] ?? '') === (string)$v ? 'selected' : '';
$encOk = encryptionReady();

$pageTitle = $id ? 'Edit ' . patientName($p) : 'New patient';
$pageSubtitle = $id ? $p['mrn'] : 'The file number (MRN) is given when you save';
$pageBack = $id ? ['href' => "/pages/patient.php?id=$id", 'label' => 'Patient'] : ['href' => '/pages/patients.php', 'label' => 'Patients'];
require_once __DIR__ . '/../includes/header.php';
?>
<?php if ($error !== ''): ?><div class="alert alert-danger"><i class="bi bi-exclamation-triangle"></i><div><strong><?= sanitize($error) ?></strong></div></div><?php endif; ?>
<?php if ($dupPhones): ?>
<div class="alert alert-warning"><i class="bi bi-people"></i><div><strong><?= t('This phone number is already on another patient.') ?></strong> <?= t('Family members often share a number. If it is the same person, open that record instead.') ?>
  <ul class="mb-0 mt-1"><?php foreach ($dupPhones as $d): ?><li><a href="/pages/patient.php?id=<?= (int)$d['id'] ?>" target="_blank"><?= sanitize($d['mrn'] . ' · ' . patientName($d)) ?></a> (<?= sanitize($d['phone']) ?>)</li><?php endforeach; ?></ul></div></div>
<?php endif; ?>

<form method="POST" class="row g-3 page-section" autocomplete="off">
  <?= csrfField() ?><input type="hidden" name="id" value="<?= $id ?>">
  <?php if ($dupPhones): ?><input type="hidden" name="confirm_shared_phone" value="1"><?php endif; ?>
  <div class="col-xl-7">
    <div class="card-crm mb-3">
      <div class="card-header-crm"><span><i class="bi bi-person-vcard"></i> <?= t('Personal details') ?></span></div>
      <div class="card-body-crm">
        <div class="row g-2 mb-3">
          <div class="col-md-2"><label class="form-label" for="pTitle"><?= t('Title') ?></label><input class="form-control" id="pTitle" name="title" value="<?= $val('title') ?>" list="titles"></div>
          <div class="col-md-4"><label class="form-label" for="pFirst"><?= t('First name') ?> <span class="req">*</span></label><input class="form-control" id="pFirst" name="first_name" value="<?= $val('first_name') ?>" required maxlength="80"></div>
          <div class="col-md-3"><label class="form-label" for="pMiddle"><?= t('Middle name') ?></label><input class="form-control" id="pMiddle" name="middle_name" value="<?= $val('middle_name') ?>" maxlength="80"></div>
          <div class="col-md-3"><label class="form-label" for="pLast"><?= t('Last name') ?></label><input class="form-control" id="pLast" name="last_name" value="<?= $val('last_name') ?>" maxlength="80"></div>
        </div>
        <datalist id="titles"><option value="Mr"><option value="Mrs"><option value="Ms"><option value="Miss"><option value="Dr"></datalist>
        <label class="form-label" for="pAr"><?= t('Name in Arabic') ?></label>
        <input class="form-control mb-3" id="pAr" name="name_ar" value="<?= $val('name_ar') ?>" dir="rtl" lang="ar" maxlength="160">
        <div class="row g-2 mb-3">
          <div class="col-md-4"><label class="form-label" for="pGender"><?= t('Gender') ?></label>
            <select class="form-select" id="pGender" name="gender"><?php foreach (PATIENT_GENDERS as $k => $l): ?><option value="<?= $k ?>" <?= $sel('gender', $k) ?>><?= $l ?></option><?php endforeach; ?></select></div>
          <div class="col-md-4"><label class="form-label" for="pDob"><?= t('Date of birth') ?></label><input class="form-control" type="date" id="pDob" name="dob" value="<?= $val('dob') ?>" max="<?= date('Y-m-d') ?>"></div>
          <div class="col-md-4"><label class="form-label" for="pNat"><?= t('Nationality') ?></label>
            <select class="form-select" id="pNat" name="nationality"><option value="">—</option><?php foreach (COUNTRIES as $k => $l): ?><option value="<?= $k ?>" <?= $sel('nationality', $k) ?>><?= sanitize($l) ?></option><?php endforeach; ?></select></div>
        </div>
        <div class="row g-2 mb-3">
          <div class="col-md-4"><label class="form-label" for="pRes"><?= t('Residency') ?></label>
            <select class="form-select" id="pRes" name="residency"><?php foreach (PATIENT_RESIDENCY as $k => $l): ?><option value="<?= $k ?>" <?= $sel('residency', $k) ?>><?= $l ?></option><?php endforeach; ?></select></div>
          <div class="col-md-4"><label class="form-label" for="pEmirate"><?= t('Emirate') ?></label>
            <select class="form-select" id="pEmirate" name="emirate"><option value="">—</option><?php foreach (UAE_EMIRATES as $e): ?><option <?= $sel('emirate', $e) ?>><?= $e ?></option><?php endforeach; ?></select></div>
          <div class="col-md-4"><label class="form-label" for="pLang"><?= t('Preferred language') ?></label>
            <select class="form-select" id="pLang" name="preferred_language"><option value="en"><?= t('English') ?></option><option value="ar" <?= $sel('preferred_language', 'ar') ?>>العربية</option></select></div>
        </div>
        <div class="row g-2">
          <div class="col-md-6"><label class="form-label" for="pOcc"><?= t('Occupation') ?></label><input class="form-control" id="pOcc" name="occupation" value="<?= $val('occupation') ?>"></div>
          <div class="col-md-6"><label class="form-label" for="pMar"><?= t('Marital status') ?></label><input class="form-control" id="pMar" name="marital_status" value="<?= $val('marital_status') ?>" list="marital"></div>
          <datalist id="marital"><option value="Single"><option value="Married"><option value="Divorced"><option value="Widowed"></datalist>
        </div>
      </div>
    </div>

    <div class="card-crm">
      <div class="card-header-crm"><span><i class="bi bi-shield-lock"></i> <?= t('Identity documents') ?></span><span class="card-header-sub"><?= t('Stored encrypted') ?></span></div>
      <div class="card-body-crm">
        <?php if (!$encOk): ?><div class="alert alert-warning mb-3"><i class="bi bi-lock"></i><div><?= t('Encryption isn’t set up for this clinic yet, so ID numbers can’t be saved. Everything else can.') ?></div></div><?php endif; ?>
        <div class="row g-2 mb-2">
          <div class="col-md-6"><label class="form-label" for="pEid">Emirates ID <?php if (!empty($p['eid_last4'])): ?><span class="text-tx-3 fw-normal">(saved: ending <?= sanitize($p['eid_last4']) ?>)</span><?php endif; ?></label>
            <input class="form-control" id="pEid" name="eid" inputmode="numeric" placeholder="<?= !empty($p['eid_last4']) ? 'Type a new number to replace it' : '784-1990-1234567-1' ?>" <?= $encOk ? '' : 'disabled' ?> autocomplete="off"></div>
          <div class="col-md-6"><label class="form-label" for="pEidExp"><?= t('Emirates ID expiry') ?></label><input class="form-control" type="date" id="pEidExp" name="eid_expiry" value="<?= $val('eid_expiry') ?>"></div>
        </div>
        <?php if (!empty($p['eid_last4'])): ?><label class="form-check mb-3"><input class="form-check-input" type="checkbox" name="eid_clear" value="1"> <span class="form-check-label"><?= t('Remove the saved Emirates ID') ?></span></label><?php endif; ?>
        <label class="form-label" for="pPass">Passport number <?php if (!empty($p['passport_enc'])): ?><span class="text-tx-3 fw-normal"><?= t('(saved)') ?></span><?php endif; ?></label>
        <input class="form-control" id="pPass" name="passport" placeholder="<?= !empty($p['passport_enc']) ? 'Type a new number to replace it' : '' ?>" <?= $encOk ? '' : 'disabled' ?> autocomplete="off">
      </div>
    </div>
  </div>

  <div class="col-xl-5">
    <div class="card-crm mb-3">
      <div class="card-header-crm"><span><i class="bi bi-telephone"></i> <?= t('Contact') ?></span></div>
      <div class="card-body-crm">
        <?php foreach ($phones as $i => $ph): ?>
        <label class="form-label" for="pPh<?= $i ?>"><?= $i ? 'Other phone' : 'Mobile' ?></label>
        <input class="form-control mb-2" id="pPh<?= $i ?>" name="phones[]" value="<?= sanitize($ph) ?>" type="tel" dir="ltr" placeholder="+971 50 123 4567">
        <?php endforeach; ?>
        <label class="form-label mt-1" for="pEmail"><?= t('Email') ?></label>
        <input class="form-control mb-3" id="pEmail" name="email" type="email" value="<?= $val('email') ?>">
        <div class="row g-2">
          <div class="col-md-6"><label class="form-label" for="pEmN"><?= t('Emergency contact') ?></label><input class="form-control" id="pEmN" name="emergency_name" value="<?= $val('emergency_name') ?>"></div>
          <div class="col-md-6"><label class="form-label" for="pEmR"><?= t('Relation') ?></label><input class="form-control" id="pEmR" name="emergency_relation" value="<?= $val('emergency_relation') ?>"></div>
          <div class="col-12"><label class="form-label" for="pEmP"><?= t('Emergency phone') ?></label><input class="form-control" id="pEmP" name="emergency_phone" type="tel" dir="ltr" value="<?= $val('emergency_phone') ?>"></div>
        </div>
      </div>
    </div>
    <div class="card-crm mb-3">
      <div class="card-header-crm"><span><i class="bi bi-building"></i> <?= t('Clinic') ?></span></div>
      <div class="card-body-crm">
        <div class="row g-2 mb-3">
          <div class="col-md-6"><label class="form-label" for="pBr"><?= t('Branch') ?></label>
            <select class="form-select" id="pBr" name="branch_id"><?php foreach ($branches as $b): ?><option value="<?= (int)$b['id'] ?>" <?= $sel('branch_id', $b['id']) ?>><?= sanitize($b['name']) ?></option><?php endforeach; ?></select></div>
          <div class="col-md-6"><label class="form-label" for="pSrc"><?= t('How they found us') ?></label>
            <select class="form-select" id="pSrc" name="source_id"><option value="">—</option><?php foreach ($sources as $sid => $sn): ?><option value="<?= $sid ?>" <?= $sel('source_id', $sid) ?>><?= sanitize($sn) ?></option><?php endforeach; ?></select></div>
        </div>
        <label class="form-label" for="pTags"><?= t('Tags') ?></label>
        <input class="form-control mb-3" id="pTags" name="tags" value="<?= $val('tags') ?>" placeholder="<?= t('e.g. VIP, sensitive skin') ?>">
        <label class="form-label" for="pNotes"><?= t('Notes') ?></label>
        <textarea class="form-control" id="pNotes" name="notes" rows="2"><?= $val('notes') ?></textarea>
      </div>
    </div>
    <div class="card-crm">
      <div class="card-header-crm"><span><i class="bi bi-check2-square"></i> <?= t('Consents') ?></span></div>
      <div class="card-body-crm">
        <label class="form-check mb-2"><input class="form-check-input" type="checkbox" name="data_consent" value="1" <?= !empty($p['data_consent_at']) || !empty($p['data_consent']) ? 'checked' : '' ?> <?= !empty($p['data_consent_at']) ? 'disabled' : '' ?>>
          <span class="form-check-label">Agreed to us keeping their data<?= !empty($p['data_consent_at']) ? ' · ' . formatDate($p['data_consent_at']) : '' ?></span></label>
        <label class="form-check mb-2"><input class="form-check-input" type="checkbox" name="whatsapp_opt_in" value="1" <?= !empty($p['whatsapp_opt_in']) ? 'checked' : '' ?>> <span class="form-check-label"><?= t('WhatsApp messages (reminders)') ?></span></label>
        <label class="form-check"><input class="form-check-input" type="checkbox" name="marketing_consent" value="1" <?= !empty($p['marketing_consent']) ? 'checked' : '' ?>> <span class="form-check-label"><?= t('Offers and news') ?></span></label>
      </div>
      <div class="form-actions">
        <a href="<?= $id ? "/pages/patient.php?id=$id" : '/pages/patients.php' ?>" class="btn btn-ghost"><?= t('Cancel') ?></a>
        <button class="btn btn-gold"><i class="bi bi-check-lg"></i><?= $dupPhones ? 'Save anyway' : ($id ? 'Save changes' : 'Register patient') ?></button>
      </div>
    </div>
  </div>
</form>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>

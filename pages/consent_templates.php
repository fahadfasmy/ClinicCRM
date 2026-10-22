<?php
// pages/consent_templates.php — Admin → Consent forms: the clinic's consent templates (English and Arabic plain text with
// {patient_name}, {procedure}, {date}, {practitioner}) and which services require each one. Editing the text makes a new
// version; consents already signed keep the exact text the patient saw.
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/inventory.php';     // CSRF helpers
require_once __DIR__ . '/../includes/consents.php';
requirePermission('setup.clinic');

$db = getDB();
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCsrf();
    try {
        $tid = saveConsentTemplate($db, (int)($_POST['id'] ?? 0), $_POST);
        logActivity(currentStaffId(), 'consent_template_saved', null, null, (string)$_POST['name']);
        $_SESSION['setup_flash'] = ['success', 'Consent form saved.'];
        header('Location: /pages/consent_templates.php'); exit;
    } catch (Throwable $e) {
        $_SESSION['setup_flash'] = ['danger', $e->getMessage()];
        header('Location: /pages/consent_templates.php?id=' . (int)($_POST['id'] ?? 0) . (empty($_POST['id']) ? '&new=1' : '')); exit;
    }
}
$flash = $_SESSION['setup_flash'] ?? null;
unset($_SESSION['setup_flash']);
$edit = isset($_GET['new']) ? ['id' => 0, 'name' => '', 'name_ar' => '', 'body_en' => '', 'body_ar' => '', 'is_active' => 1, 'version' => 1] : (isset($_GET['id']) ? findConsentTemplate($db, (int)$_GET['id']) : null);
$templates = consentTemplates($db, false);
$services = $db->query("SELECT id, name, requires_consent_template_id FROM services WHERE is_active = 1 ORDER BY name")->fetchAll();

$pageTitle = 'Consent forms';
$pageSubtitle = 'Signed on a tablet; services can require one';
$pageBack = ['href' => '/pages/settings_clinic.php', 'label' => 'Clinic setup'];
$pageActions = $edit ? '' : '<a class="btn btn-gold" href="?new=1"><i class="bi bi-plus-lg"></i>New consent form</a>';
require_once __DIR__ . '/../includes/header.php';
?>
<?php if ($flash): ?><div class="alert alert-<?= $flash[0] ?> <?= $flash[0] === 'success' ? 'alert-auto' : '' ?>"><i class="bi bi-<?= $flash[0] === 'success' ? 'check-circle' : 'exclamation-triangle' ?>"></i><div><strong><?= sanitize($flash[1]) ?></strong></div></div><?php endif; ?>
<?php if ($edit): ?>
<form method="POST" class="card-crm">
  <?= csrfField() ?><input type="hidden" name="id" value="<?= (int)$edit['id'] ?>">
  <div class="card-header-crm"><span><i class="bi bi-file-earmark-text"></i> <?= $edit['id'] ? 'Edit consent form · version ' . (int)$edit['version'] : 'New consent form' ?></span></div>
  <div class="card-body-crm">
    <p class="fs-13 text-tx-3">Plain text. A blank line starts a new paragraph. These are filled in when the patient signs: <code>{patient_name}</code> <code>{procedure}</code> <code>{date}</code> <code>{practitioner}</code>.
      <?= $edit['id'] ? 'Changing the name or text makes version ' . ((int)$edit['version'] + 1) . '; forms already signed keep their own text.' : '' ?></p>
    <div class="row g-3">
      <div class="col-md-6"><label class="form-label" for="cName">Name (English)</label><input class="form-control" id="cName" name="name" maxlength="150" required value="<?= sanitize($edit['name']) ?>"></div>
      <div class="col-md-6"><label class="form-label" for="cNameAr">Name (Arabic)</label><input class="form-control" id="cNameAr" name="name_ar" maxlength="150" dir="rtl" lang="ar" value="<?= sanitize((string)$edit['name_ar']) ?>"></div>
      <div class="col-lg-6"><label class="form-label" for="cBody">Text (English)</label><textarea class="form-control" id="cBody" name="body_en" rows="14" required><?= sanitize($edit['body_en']) ?></textarea></div>
      <div class="col-lg-6"><label class="form-label" for="cBodyAr">Text (Arabic)</label><textarea class="form-control" id="cBodyAr" name="body_ar" rows="14" dir="rtl" lang="ar"><?= sanitize((string)$edit['body_ar']) ?></textarea></div>
    </div>
    <fieldset class="mt-3"><legend class="form-label">Required before these services</legend>
      <div class="row g-1"><?php foreach ($services as $s): $other = $s['requires_consent_template_id'] && (int)$s['requires_consent_template_id'] !== (int)$edit['id']; ?>
        <div class="col-md-4"><div class="form-check"><input class="form-check-input" type="checkbox" name="services[]" value="<?= (int)$s['id'] ?>" id="sv<?= (int)$s['id'] ?>" <?= $edit['id'] && (int)$s['requires_consent_template_id'] === (int)$edit['id'] ? 'checked' : '' ?>>
          <label class="form-check-label" for="sv<?= (int)$s['id'] ?>"><?= sanitize($s['name']) ?><?= $other ? ' <span class="fs-12 text-tx-3">(another form now)</span>' : '' ?></label></div></div>
      <?php endforeach; ?></div>
      <div class="fs-12 text-tx-3 mt-1">A service requires one form; ticking it here moves it from any other form.</div>
    </fieldset>
    <div class="form-check form-switch mt-3"><input class="form-check-input" type="checkbox" role="switch" id="cActive" name="is_active" value="1" <?= $edit['is_active'] ? 'checked' : '' ?>><label class="form-check-label" for="cActive">In use</label></div>
  </div>
  <div class="form-actions"><a class="btn btn-ghost" href="/pages/consent_templates.php">Cancel</a><button class="btn btn-gold ms-auto"><i class="bi bi-check-lg"></i>Save</button></div>
</form>
<?php else: ?>
<div class="card-crm"><div class="list-group list-group-flush">
  <?php foreach ($templates as $t): ?>
  <a class="list-group-item list-group-item-action d-flex gap-2 align-items-center" href="?id=<?= (int)$t['id'] ?>">
    <i class="bi bi-file-earmark-text fs-5"></i>
    <span class="flex-grow-1"><span class="fw-600"><?= sanitize($t['name']) ?></span><?= $t['name_ar'] ? ' · <bdi lang="ar">' . sanitize($t['name_ar']) . '</bdi>' : '' ?>
      <span class="d-block fs-12 text-tx-3">Version <?= (int)$t['version'] ?><?= $t['body_ar'] ? ' · English and Arabic' : ' · English only' ?><?= $t['required_for'] ? ' · Required for ' . sanitize($t['required_for']) : '' ?></span></span>
    <?= $t['is_active'] ? '' : '<span class="chip tone-slate">Not in use</span>' ?>
  </a>
  <?php endforeach; ?>
  <?php if (!$templates): ?><div class="empty-state"><i class="bi bi-file-earmark-text"></i><div class="empty-state-title">No consent forms yet</div></div><?php endif; ?>
</div></div>
<?php endif; ?>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>

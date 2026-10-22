<?php
// pages/clinical_settings.php — Admin → Clinical settings: note templates (prefill a note's sections), the sections that
// must be filled before a note can be signed (per department), the auto-lock time, and the ICD-10 code import.
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/emr.php';
requirePermission('setup.clinic');

$db = getDB();
$flash = $_SESSION['setup_flash'] ?? null;
unset($_SESSION['setup_flash']);
$departments = $db->query("SELECT id, name FROM departments WHERE is_active = 1 ORDER BY name")->fetchAll(PDO::FETCH_KEY_PAIR);
$services = $db->query("SELECT id, name FROM services WHERE is_active = 1 ORDER BY name")->fetchAll(PDO::FETCH_KEY_PAIR);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCsrf();
    try {
        switch ($_POST['action'] ?? '') {
            case 'template_save':
                $tid = (int)($_POST['id'] ?? 0);
                $name = mb_substr(trim((string)($_POST['name'] ?? '')), 0, 100);
                if ($name === '') throw new RuntimeException('Give the template a name.');
                $fields = [];
                foreach (array_keys(EMR_NOTE_FIELDS) as $k) if (trim((string)($_POST['f'][$k] ?? '')) !== '') $fields[$k] = mb_substr(trim((string)$_POST['f'][$k]), 0, 5000);
                if (!$fields) throw new RuntimeException('Fill at least one section of the template.');
                $dep = isset($departments[(int)($_POST['department_id'] ?? 0)]) ? (int)$_POST['department_id'] : null;
                $svc = isset($services[(int)($_POST['service_id'] ?? 0)]) ? (int)$_POST['service_id'] : null;
                $json = json_encode($fields, JSON_UNESCAPED_UNICODE);
                if ($tid) $db->prepare("UPDATE note_templates SET name = ?, department_id = ?, service_id = ?, fields = ?, is_active = ? WHERE id = ?")->execute([$name, $dep, $svc, $json, isset($_POST['is_active']) ? 1 : 0, $tid]);
                else $db->prepare("INSERT INTO note_templates (name, department_id, service_id, fields) VALUES (?,?,?,?)")->execute([$name, $dep, $svc, $json]);
                logActivity(currentStaffId(), 'note_template_saved', null, null, $name);
                $_SESSION['setup_flash'] = ['success', 'Template saved.'];
                break;
            case 'requirements':
                $dep = (int)($_POST['department_id'] ?? 0);
                if ($dep && !isset($departments[$dep])) throw new RuntimeException('Unknown department.');
                $secs = array_values(array_intersect(array_keys(EMR_SECTIONS), (array)($_POST['sections'] ?? [])));
                $db->beginTransaction();
                $db->prepare("DELETE FROM emr_requirements WHERE department_id = ?")->execute([$dep]);
                $ins = $db->prepare("INSERT INTO emr_requirements (department_id, section) VALUES (?, ?)");
                foreach ($secs as $s) $ins->execute([$dep, $s]);
                $db->commit();
                logActivity(currentStaffId(), 'emr_requirements_changed', null, null, ($dep ? $departments[$dep] : 'Clinic default') . ': ' . implode(', ', $secs));
                $_SESSION['setup_flash'] = ['success', 'Required sections saved' . ($dep && !$secs ? ' (none: this department now uses the clinic default)' : '') . '.'];
                break;
            case 'autolock':
                $h = (int)($_POST['hours'] ?? 24);
                if ($h < 1 || $h > 720) throw new RuntimeException('Choose between 1 and 720 hours.');
                $db->prepare("REPLACE INTO integration_settings (k, v) VALUES ('clinic.emr_autolock_hours', ?)")->execute([(string)$h]);
                logActivity(currentStaffId(), 'emr_autolock_changed', null, null, "$h hours");
                $_SESSION['setup_flash'] = ['success', "Unsigned notes now lock after $h hours."];
                break;
            case 'icd_import':
                if (multiTenant()) throw new RuntimeException('In the multi-clinic setup the code list is shared and loaded by the platform team.');
                $f = $_FILES['file'] ?? [];
                if (($f['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_uploaded_file($f['tmp_name'])) throw new RuntimeException('Choose the ICD-10 file to upload.');
                @set_time_limit(300);
                [$read, $saved] = icdImportFile($db, $f['tmp_name']);
                logActivity(currentStaffId(), 'icd10_imported', null, null, "$read codes");
                $_SESSION['setup_flash'] = ['success', number_format($read) . ' ICD-10 codes imported.'];
                break;
        }
    } catch (Throwable $e) {
        if ($db->inTransaction()) $db->rollBack();
        $_SESSION['setup_flash'] = ['danger', $e->getMessage()];
    }
    header('Location: /pages/clinical_settings.php' . (!empty($_POST['dep']) ? '?dep=' . (int)$_POST['dep'] : '')); exit;
}

$templates = $db->query("SELECT t.*, d.name AS dep_name, s.name AS svc_name FROM note_templates t LEFT JOIN departments d ON d.id = t.department_id LEFT JOIN services s ON s.id = t.service_id ORDER BY t.is_active DESC, t.name")->fetchAll();
$edit = null;
if ($eid = (int)($_GET['template'] ?? 0)) foreach ($templates as $t) if ((int)$t['id'] === $eid) { $edit = $t; $edit['fields'] = json_decode($t['fields'], true) ?: []; }
$reqDep = (int)($_GET['dep'] ?? 0);
if ($reqDep && !isset($departments[$reqDep])) $reqDep = 0;
$cur = $db->prepare("SELECT section FROM emr_requirements WHERE department_id = ?"); $cur->execute([$reqDep]); $cur = $cur->fetchAll(PDO::FETCH_COLUMN);
$icdCount = (int)referenceDb()->query("SELECT COUNT(*) FROM icd10_codes")->fetchColumn();

$pageTitle = 'Clinical settings';
$pageSubtitle = 'Note templates, required sections, auto-lock and ICD-10 codes';
$pageBack = ['href' => '/pages/settings_clinic.php', 'label' => 'Clinic setup'];
require_once __DIR__ . '/../includes/header.php';
?>
<?php if ($flash): ?><div class="alert alert-<?= $flash[0] ?>"><i class="bi bi-<?= $flash[0] === 'success' ? 'check-circle' : 'exclamation-triangle' ?>"></i><div><strong><?= sanitize($flash[1]) ?></strong></div></div><?php endif; ?>
<div class="row g-3 page-section">
  <div class="col-xl-7">
    <div class="card-crm mb-3">
      <div class="card-header-crm"><span><i class="bi bi-file-earmark-text"></i> Note templates</span></div>
      <div class="list-group list-group-flush">
        <?php foreach ($templates as $t): ?><a href="?template=<?= (int)$t['id'] ?>#tpl" class="list-group-item list-group-item-action d-flex gap-2">
          <span class="fw-semibold <?= $t['is_active'] ? '' : 'text-tx-3 text-decoration-line-through' ?>"><?= sanitize($t['name']) ?></span>
          <span class="fs-12 text-tx-3 ms-auto"><?= sanitize(implode(' · ', array_filter([$t['dep_name'], $t['svc_name']])) ?: 'All visits') ?></span></a><?php endforeach; ?>
        <?php if (!$templates): ?><div class="list-group-item text-tx-3 fs-13">No templates yet. A template fills the empty sections of a note, e.g. “Botox follow-up”.</div><?php endif; ?>
      </div>
      <form method="POST" class="card-body-crm border-top" id="tpl">
        <?= csrfField() ?><input type="hidden" name="action" value="template_save"><input type="hidden" name="id" value="<?= (int)($edit['id'] ?? 0) ?>">
        <div class="fw-600 mb-2"><?= $edit ? 'Edit “' . sanitize($edit['name']) . '”' : 'New template' ?></div>
        <div class="row g-2 mb-2">
          <div class="col-md-5"><label class="form-label fs-12" for="tName">Name</label><input class="form-control form-control-sm" id="tName" name="name" value="<?= sanitize($edit['name'] ?? '') ?>" required maxlength="100"></div>
          <div class="col-md-3"><label class="form-label fs-12" for="tDep">Department</label><select class="form-select form-select-sm" id="tDep" name="department_id"><option value="">All</option><?php foreach ($departments as $k => $n): ?><option value="<?= $k ?>" <?= (int)($edit['department_id'] ?? 0) === $k ? 'selected' : '' ?>><?= sanitize($n) ?></option><?php endforeach; ?></select></div>
          <div class="col-md-4"><label class="form-label fs-12" for="tSvc">Treatment</label><select class="form-select form-select-sm" id="tSvc" name="service_id"><option value="">Any</option><?php foreach ($services as $k => $n): ?><option value="<?= $k ?>" <?= (int)($edit['service_id'] ?? 0) === $k ? 'selected' : '' ?>><?= sanitize($n) ?></option><?php endforeach; ?></select></div>
        </div>
        <?php foreach (EMR_NOTE_FIELDS as $k => $l): ?>
        <label class="form-label fs-12" for="tf_<?= $k ?>"><?= $l ?></label>
        <textarea class="form-control form-control-sm mb-2" id="tf_<?= $k ?>" name="f[<?= $k ?>]" rows="2"><?= sanitize((string)($edit['fields'][$k] ?? '')) ?></textarea>
        <?php endforeach; ?>
        <div class="d-flex align-items-center gap-2">
          <?php if ($edit): ?><label class="form-check mb-0"><input class="form-check-input" type="checkbox" name="is_active" <?= $edit['is_active'] ? 'checked' : '' ?>> <span class="form-check-label">In use</span></label>
          <a href="/pages/clinical_settings.php" class="btn btn-ghost btn-sm ms-auto">Cancel</a><?php endif; ?>
          <button class="btn btn-gold btn-sm <?= $edit ? '' : 'ms-auto' ?>"><i class="bi bi-check-lg"></i>Save template</button>
        </div>
      </form>
    </div>
  </div>
  <div class="col-xl-5">
    <div class="card-crm mb-3">
      <div class="card-header-crm"><span><i class="bi bi-list-check"></i> Required before signing</span></div>
      <form method="POST" class="card-body-crm"><?= csrfField() ?><input type="hidden" name="action" value="requirements"><input type="hidden" name="dep" value="<?= $reqDep ?>"><input type="hidden" name="department_id" value="<?= $reqDep ?>">
        <select class="form-select form-select-sm mb-3" aria-label="Department" onchange="location='?dep='+this.value"><option value="0">Clinic default</option><?php foreach ($departments as $k => $n): ?><option value="<?= $k ?>" <?= $reqDep === $k ? 'selected' : '' ?>><?= sanitize($n) ?></option><?php endforeach; ?></select>
        <?php if ($reqDep && !$cur): ?><p class="fs-12 text-tx-3">This department uses the clinic default. Tick sections to give it its own list.</p><?php endif; ?>
        <?php foreach (EMR_SECTIONS as $k => $l): ?><label class="form-check"><input class="form-check-input" type="checkbox" name="sections[]" value="<?= $k ?>" <?= in_array($k, $cur, true) ? 'checked' : '' ?>> <span class="form-check-label"><?= $l ?></span></label><?php endforeach; ?>
        <div class="d-flex"><button class="btn btn-gold btn-sm ms-auto"><i class="bi bi-check-lg"></i>Save</button></div>
      </form>
    </div>
    <div class="card-crm mb-3">
      <div class="card-header-crm"><span><i class="bi bi-lock"></i> Auto-lock</span></div>
      <form method="POST" class="card-body-crm d-flex flex-wrap gap-2 align-items-end"><?= csrfField() ?><input type="hidden" name="action" value="autolock">
        <div><label class="form-label fs-12" for="alH">Lock unsigned notes after (hours)</label><input class="form-control form-control-sm" type="number" id="alH" name="hours" min="1" max="720" value="<?= emrAutoLockHours() ?>" style="max-width:110px"></div>
        <button class="btn btn-ghost btn-sm"><i class="bi bi-check-lg"></i>Save</button>
        <div class="fs-12 text-tx-3 w-100">A locked note can still be signed; changes after that go in an addendum. Checked by the hourly job.</div>
      </form>
    </div>
    <div class="card-crm">
      <div class="card-header-crm"><span><i class="bi bi-upload"></i> ICD-10 codes</span><span class="card-header-sub"><?= number_format($icdCount) ?> loaded</span></div>
      <?php if (multiTenant()): ?><div class="card-body-crm fs-13 text-tx-2">The code list is shared by all clinics and kept up to date by the platform team.</div>
      <?php else: ?>
      <form method="POST" enctype="multipart/form-data" class="card-body-crm"><?= csrfField() ?><input type="hidden" name="action" value="icd_import">
        <p class="fs-13 text-tx-2">Upload the ICD-10-CM code file from CMS (cms.gov → ICD-10 → “Code Descriptions in Tabular Order”, the file <code>icd10cm-codes-YYYY.txt</code> inside the zip), or a CSV of code,description. Importing again updates descriptions.</p>
        <input class="form-control form-control-sm mb-2" type="file" name="file" accept=".txt,.csv,text/plain,text/csv" required aria-label="ICD-10 file">
        <div class="d-flex"><button class="btn btn-gold btn-sm ms-auto"><i class="bi bi-upload"></i>Import</button></div>
      </form>
      <?php endif; ?>
    </div>
  </div>
</div>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>

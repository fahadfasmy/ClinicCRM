<?php
// pages/patients.php — Front desk → Patients: search by MRN, name (English or Arabic), any phone number or Emirates ID,
// filter by branch, gender and status, page through, and export (patients.export). Merged records are hidden.
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/inventory.php';     // CSRF helpers, openCsv… helpers
require_once __DIR__ . '/../includes/patients.php';
requirePermission('patients.view');

$db = getDB();
$q = trim((string)($_GET['q'] ?? ''));
$fBranch = (int)($_GET['branch'] ?? 0);
$fGender = isset(PATIENT_GENDERS[$_GET['gender'] ?? '']) ? $_GET['gender'] : '';
$fStatus = in_array($_GET['status'] ?? '', ['active', 'inactive', 'deceased'], true) ? $_GET['status'] : '';
$page = max(1, (int)($_GET['page'] ?? 1));
$per = 50;

$where = ["p.status <> 'merged'"]; $args = [];
if ($q !== '') {
    $or = ['p.mrn = ?', "CONCAT_WS(' ', p.first_name, p.middle_name, p.last_name) LIKE ?", 'p.name_ar LIKE ?', 'p.email = ?'];
    array_push($args, $q, "%$q%", "%$q%", $q);
    if (($ph = normalizePhone($q)) && strlen(preg_replace('/\D/', '', $q)) >= 7) { $or[] = 'p.id IN (SELECT patient_id FROM patient_phones WHERE phone = ? OR phone LIKE ?)'; array_push($args, $ph, '%' . substr($ph, -9)); }
    if (($eid = eidNormalize($q)) && encryptionReady()) { $or[] = 'p.eid_hash = ?'; $args[] = eidHash($eid); }
    elseif (preg_match('/^\d{4}$/', $q)) { $or[] = 'p.eid_last4 = ?'; $args[] = $q; }
    $where[] = '(' . implode(' OR ', $or) . ')';
}
if ($fBranch) { $where[] = 'p.branch_id = ?'; $args[] = $fBranch; }
if ($fGender) { $where[] = 'p.gender = ?'; $args[] = $fGender; }
if ($fStatus) { $where[] = 'p.status = ?'; $args[] = $fStatus; }
$sqlWhere = 'WHERE ' . implode(' AND ', $where);
$select = "SELECT p.*, b.name AS branch_name, (SELECT phone FROM patient_phones x WHERE x.patient_id = p.id ORDER BY is_primary DESC, id LIMIT 1) AS phone
           FROM patients p LEFT JOIN branches b ON b.id = p.branch_id $sqlWhere ORDER BY p.id DESC";

if (($_GET['export'] ?? '') === 'csv') {
    requirePermission('patients.export');
    $s = $db->prepare($select); $s->execute($args);
    logActivity(currentStaffId(), 'patients_exported', null, null, $q !== '' ? "search: $q" : 'all');
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="patients-' . date('Y-m-d') . '.csv"');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF");   // Excel reads UTF-8 (Arabic names) correctly with the BOM
    fputcsv($out, ['MRN', 'First name', 'Last name', 'Arabic name', 'Gender', 'Date of birth', 'Nationality', 'Phone', 'Email', 'Branch', 'Emirates ID (last 4)', 'Status', 'Registered']);
    foreach ($s as $p) fputcsv($out, [$p['mrn'], $p['first_name'], $p['last_name'], $p['name_ar'], $p['gender'], $p['dob'], $p['nationality'], $p['phone'], $p['email'], $p['branch_name'], $p['eid_last4'], $p['status'], $p['created_at']]);
    exit;
}

$c = $db->prepare("SELECT COUNT(*) FROM patients p $sqlWhere"); $c->execute($args);
$total = (int)$c->fetchColumn();
$s = $db->prepare("$select LIMIT $per OFFSET " . (($page - 1) * $per)); $s->execute($args);
$rows = $s->fetchAll();
$branches = branchList($db, true);
$qs = fn(array $over) => '?' . http_build_query(array_filter(array_merge(['q' => $q, 'branch' => $fBranch ?: null, 'gender' => $fGender, 'status' => $fStatus], $over)));

$pageTitle    = 'Patients';
$pageSubtitle = $q !== '' ? $total . ' found for “' . sanitize($q) . '”' : number_format($total) . ' patient' . ($total == 1 ? '' : 's');
$pageActions  = (can('patients.export') ? '<a href="' . sanitize($qs(['export' => 'csv'])) . '" class="btn btn-ghost"><i class="bi bi-download"></i>' . t('Export') . '</a>' : '')
              . (can('patients.merge') ? '<a href="/pages/patient_merge.php" class="btn btn-ghost"><i class="bi bi-intersect"></i>' . t('Merge') . '</a>' : '')
              . (can('patients.edit') ? '<a href="/pages/patient_edit.php" class="btn btn-gold"><i class="bi bi-person-plus"></i>' . t('New patient') . '</a>' : '');
require_once __DIR__ . '/../includes/header.php';
?>
<form class="card-crm page-section mb-3" method="GET" role="search">
  <div class="card-body-crm d-flex flex-wrap gap-2 align-items-center">
    <input class="form-control" style="max-width:340px" name="q" value="<?= sanitize($q) ?>" placeholder="<?= t('MRN, name, phone or Emirates ID') ?>" aria-label="<?= t('Search patients') ?>" autofocus>
    <select class="form-select" style="max-width:180px" name="branch" aria-label="<?= t('Branch') ?>"><option value=""><?= t('All branches') ?></option>
      <?php foreach ($branches as $b): ?><option value="<?= (int)$b['id'] ?>" <?= $fBranch === (int)$b['id'] ? 'selected' : '' ?>><?= sanitize($b['name']) ?></option><?php endforeach; ?></select>
    <select class="form-select" style="max-width:150px" name="gender" aria-label="<?= t('Gender') ?>"><option value=""><?= t('Any gender') ?></option>
      <?php foreach (PATIENT_GENDERS as $k => $l): if ($k === 'unknown') continue; ?><option value="<?= $k ?>" <?= $fGender === $k ? 'selected' : '' ?>><?= $l ?></option><?php endforeach; ?></select>
    <select class="form-select" style="max-width:150px" name="status" aria-label="<?= t('Status') ?>"><option value=""><?= t('Any status') ?></option>
      <?php foreach (['active' => 'Active', 'inactive' => 'Inactive', 'deceased' => 'Deceased'] as $k => $l): ?><option value="<?= $k ?>" <?= $fStatus === $k ? 'selected' : '' ?>><?= $l ?></option><?php endforeach; ?></select>
    <button class="btn btn-gold"><i class="bi bi-search"></i><?= t('Search') ?></button>
    <?php if ($q !== '' || $fBranch || $fGender || $fStatus): ?><a href="/pages/patients.php" class="btn btn-ghost"><?= t('Clear') ?></a><?php endif; ?>
  </div>
</form>

<div class="card-crm">
  <?php if (!$rows): ?>
  <div class="empty-state"><i class="bi bi-person-vcard"></i><div class="empty-state-title"><?= $q !== '' ? 'No patient found' : 'No patients yet' ?></div>
    <p><?= $q !== '' ? 'Check the spelling or search by phone number.' : 'Register a patient, or convert a lead from its page.' ?></p>
    <?php if (can('patients.edit')): ?><a href="/pages/patient_edit.php" class="btn btn-gold btn-sm"><i class="bi bi-person-plus"></i><?= t('New patient') ?></a><?php endif; ?></div>
  <?php else: ?>
  <div class="table-responsive"><table class="table table-hover mb-0">
    <thead><tr><th>MRN</th><th><?= t('Name') ?></th><th><?= t('Phone') ?></th><th><?= t('Gender / age') ?></th><th><?= t('Branch') ?></th><th><?= t('Registered') ?></th></tr></thead>
    <tbody>
    <?php foreach ($rows as $p): ?>
      <tr>
        <td class="text-nowrap"><a href="/pages/patient.php?id=<?= (int)$p['id'] ?>" class="fw-semibold"><?= sanitize($p['mrn']) ?></a></td>
        <td><a href="/pages/patient.php?id=<?= (int)$p['id'] ?>"><?= sanitize(patientName($p)) ?></a>
          <?php if ($p['name_ar'] && $p['name_ar'] !== patientName($p)): ?><div class="fs-12 text-tx-3" dir="rtl" lang="ar"><?= sanitize($p['name_ar']) ?></div><?php endif; ?>
          <?php if ($p['status'] !== 'active'): ?><span class="chip tone-slate"><?= sanitize(ucfirst($p['status'])) ?></span><?php endif; ?></td>
        <td class="text-nowrap" dir="ltr"><?= sanitize($p['phone'] ?? '') ?></td>
        <td class="fs-13"><?= $p['gender'] !== 'unknown' ? PATIENT_GENDERS[$p['gender']] : '' ?><?= $p['dob'] ? ' · ' . (new DateTimeImmutable($p['dob']))->diff(new DateTimeImmutable('today'))->y : '' ?></td>
        <td class="fs-13"><?= sanitize($p['branch_name'] ?? '') ?></td>
        <td class="fs-13 text-nowrap"><?= formatDate($p['created_at']) ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody></table></div>
  <?php if ($total > $per): $pages = (int)ceil($total / $per); ?>
  <nav class="form-actions" aria-label="<?= t('Pages') ?>">
    <span class="fs-13 text-tx-3 me-auto">Page <?= $page ?> of <?= $pages ?></span>
    <?php if ($page > 1): ?><a class="btn btn-ghost btn-sm" href="<?= sanitize($qs(['page' => $page - 1])) ?>"><i class="bi bi-chevron-left"></i><?= t('Previous') ?></a><?php endif; ?>
    <?php if ($page < $pages): ?><a class="btn btn-ghost btn-sm" href="<?= sanitize($qs(['page' => $page + 1])) ?>"><?= t('Next') ?><i class="bi bi-chevron-right"></i></a><?php endif; ?>
  </nav>
  <?php endif; ?>
  <?php endif; ?>
</div>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>

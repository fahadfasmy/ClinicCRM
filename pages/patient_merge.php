<?php
// pages/patient_merge.php — combine two records of the same person (patients.merge). Pick both by MRN, compare side by
// side, choose which record to keep; everything on the other one moves over (mergePatients()) and it is marked merged.
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/inventory.php';     // CSRF helpers
require_once __DIR__ . '/../includes/patients.php';
require_once __DIR__ . '/../includes/countries.php';
requirePermission('patients.merge');

$db = getDB();
$byMrn = function (string $mrn) use ($db): ?array {
    $s = $db->prepare("SELECT * FROM patients WHERE mrn = ? AND status <> 'merged'");
    $s->execute([trim($mrn)]);
    return $s->fetch() ?: null;
};
$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCsrf();
    $keep = (int)($_POST['keep'] ?? 0); $drop = (int)($_POST['drop'] ?? 0);
    try {
        if (($_POST['confirm'] ?? '') !== 'MERGE') throw new RuntimeException('Type MERGE to confirm.');
        mergePatients($db, $keep, $drop);
        logActivity(currentStaffId(), 'patients_merged', null, (string)$drop, (string)$keep);
        header("Location: /pages/patient.php?id=$keep&saved=1"); exit;
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
}
$a = isset($_REQUEST['a']) ? $byMrn((string)$_REQUEST['a']) : null;
$b = isset($_REQUEST['b']) ? $byMrn((string)$_REQUEST['b']) : null;
$counts = function (array $p) use ($db): array {
    $out = [];
    foreach (['patient_phones' => 'Phones', 'appointments' => 'Appointments', 'patient_documents' => 'Documents', 'leads' => 'Leads'] as $t => $l) {
        $s = $db->prepare("SELECT COUNT(*) FROM $t WHERE patient_id = ?"); $s->execute([$p['id']]); $out[$l] = (int)$s->fetchColumn();
    }
    return $out;
};

$pageTitle = 'Merge patients';
$pageSubtitle = 'Two records of the same person';
$pageBack = ['href' => '/pages/patients.php', 'label' => 'Patients'];
require_once __DIR__ . '/../includes/header.php';
?>
<?php if ($error !== ''): ?><div class="alert alert-danger"><i class="bi bi-exclamation-triangle"></i><div><strong><?= sanitize($error) ?></strong></div></div><?php endif; ?>
<form method="GET" class="card-crm page-section mb-3"><div class="card-body-crm d-flex flex-wrap gap-2 align-items-end">
  <div><label class="form-label" for="mA">First file number (MRN)</label><input class="form-control" id="mA" name="a" value="<?= sanitize($_REQUEST['a'] ?? '') ?>" required></div>
  <div><label class="form-label" for="mB">Second file number (MRN)</label><input class="form-control" id="mB" name="b" value="<?= sanitize($_REQUEST['b'] ?? '') ?>" required></div>
  <button class="btn btn-ghost"><i class="bi bi-search"></i>Compare</button>
</div></form>

<?php if (isset($_REQUEST['a']) && (!$a || !$b)): ?><div class="alert alert-warning"><i class="bi bi-info-circle"></i>No active patient with <?= !$a ? 'the first' : 'the second' ?> file number.</div>
<?php elseif ($a && $b && $a['id'] === $b['id']): ?><div class="alert alert-warning"><i class="bi bi-info-circle"></i>That is the same record twice.</div>
<?php elseif ($a && $b): $ca = $counts($a); $cb = $counts($b);
  $rows = ['Name' => fn($p) => patientName($p), 'Arabic name' => fn($p) => $p['name_ar'], 'Date of birth' => fn($p) => $p['dob'] ? formatDate($p['dob']) : '',
           'Gender' => fn($p) => PATIENT_GENDERS[$p['gender']], 'Nationality' => fn($p) => COUNTRIES[$p['nationality']] ?? '', 'Emirates ID' => fn($p) => $p['eid_last4'] ? '…' . $p['eid_last4'] : '',
           'Email' => fn($p) => $p['email'], 'Phones' => fn($p) => implode(', ', array_column(patientPhones($db, (int)$p['id']), 'phone')), 'Registered' => fn($p) => formatDate($p['created_at'])]; ?>
<form method="POST" class="card-crm">
  <?= csrfField() ?><input type="hidden" name="a" value="<?= sanitize($a['mrn']) ?>"><input type="hidden" name="b" value="<?= sanitize($b['mrn']) ?>">
  <div class="table-responsive"><table class="table mb-0">
    <thead><tr><th></th>
      <?php foreach ([$a, $b] as $i => $p): $other = $i ? $a : $b; ?><th>
        <label class="form-check mb-0"><input class="form-check-input" type="radio" name="keep" value="<?= (int)$p['id'] ?>" <?= $i === 0 ? 'checked' : '' ?> data-drop="<?= (int)$other['id'] ?>" onchange="document.getElementById('mDrop').value=this.dataset.drop">
          <span class="form-check-label">Keep <a href="/pages/patient.php?id=<?= (int)$p['id'] ?>" target="_blank"><?= sanitize($p['mrn']) ?></a></span></label></th><?php endforeach; ?></tr></thead>
    <tbody>
      <?php foreach ($rows as $label => $fn): $va = (string)$fn($a); $vb = (string)$fn($b); ?>
      <tr><td class="text-tx-3 fs-13"><?= $label ?></td><td class="<?= $va !== $vb ? 'fw-semibold' : '' ?>"><?= sanitize($va) ?></td><td class="<?= $va !== $vb ? 'fw-semibold' : '' ?>"><?= sanitize($vb) ?></td></tr>
      <?php endforeach; ?>
      <?php foreach ($ca as $label => $n): ?><tr><td class="text-tx-3 fs-13"><?= $label ?></td><td><?= $n ?></td><td><?= $cb[$label] ?></td></tr><?php endforeach; ?>
    </tbody>
  </table></div>
  <input type="hidden" name="drop" id="mDrop" value="<?= (int)$b['id'] ?>">
  <div class="card-body-crm fs-13 text-tx-2">Everything on the other record (phones, appointments, documents, the lead link and anything added later) moves to the one you keep.
    Details the kept record is missing are copied over. The other record stays, marked as merged, and opening it leads here. This can’t be undone.</div>
  <div class="form-actions"><input class="form-control" style="max-width:200px" name="confirm" placeholder="Type MERGE" aria-label="Type MERGE to confirm" autocomplete="off" required>
    <button class="btn btn-gold"><i class="bi bi-intersect"></i>Merge</button></div>
</form>
<?php endif; ?>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>

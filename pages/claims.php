<?php
// pages/claims.php — Insurance → Claims: the ageing tiles (not sent within 7 / 30 / 45 days), claims by status and
// company, putting ready claims of one company into a batch, and the batches: export eClaimLink XML or CSV for the
// portal, then mark the batch submitted with the portal's reference.
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/insurance.php';
requirePermission('insurance.view');

$db = getDB();
if (isset($_GET['export'], $_GET['batch'])) {
    requirePermission('insurance.claims');
    $bid = (int)$_GET['batch'];
    if ($_GET['export'] === 'csv') { $out = exportBatchCsv($db, $bid); $type = 'text/csv; charset=utf-8'; $ext = 'csv'; }
    else { $out = exportBatchXml($db, $bid); $type = 'application/xml; charset=utf-8'; $ext = 'xml'; }
    header("Content-Type: $type");
    header('Content-Disposition: attachment; filename="claims-batch-' . $bid . '.' . $ext . '"');
    header('Cache-Control: private, no-store');
    echo $out; exit;
}
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCsrf();
    try {
        if (($_POST['action'] ?? '') === 'batch') { $bid = createClaimBatch($db, (int)$_POST['company_id'], (array)($_POST['claims'] ?? [])); $_SESSION['ins_flash'] = ['success', "Batch $bid made. Export it, upload it in the eClaimLink portal, then mark it submitted."]; }
        elseif (($_POST['action'] ?? '') === 'submitted') { markBatchSubmitted($db, (int)$_POST['batch_id'], (string)($_POST['ref'] ?? ''), (string)($_POST['date'] ?? '')); $_SESSION['ins_flash'] = ['success', 'Batch marked submitted.']; }
        elseif (($_POST['action'] ?? '') === 'recheck') { $n = 0; foreach ($db->query("SELECT id FROM claims WHERE status = 'draft'")->fetchAll(PDO::FETCH_COLUMN) as $cid) if (!refreshClaimReadiness($db, (int)$cid)) $n++; $_SESSION['ins_flash'] = ['success', "$n claim(s) are now ready to send."]; }
    } catch (Throwable $e) { $_SESSION['ins_flash'] = ['danger', $e->getMessage()]; }
    header('Location: /pages/claims.php' . (!empty($_POST['qs']) ? '?' . preg_replace('/[^a-z0-9_=&-]/i', '', $_POST['qs']) : '')); exit;
}
$flash = $_SESSION['ins_flash'] ?? null;
unset($_SESSION['ins_flash']);
$st = isset(CLAIM_STATUS[$_GET['status'] ?? '']) ? $_GET['status'] : '';
$co = (int)($_GET['company'] ?? 0);
$where = ['1=1']; $args = [];
if ($st) { $where[] = 'cl.status = ?'; $args[] = $st; } else $where[] = "cl.status NOT IN ('paid','written_off')";
if ($co) { $where[] = 'c.id = ?'; $args[] = $co; }
$s = $db->prepare("SELECT cl.*, i.number AS invoice_number, i.issued_at, c.id AS company_id, c.name AS company, CONCAT_WS(' ', pt.first_name, pt.last_name) AS patient, pt.mrn, DATEDIFF(NOW(), i.issued_at) AS age
                   FROM claims cl JOIN invoices i ON i.id = cl.invoice_id JOIN insurance_plans p ON p.id = cl.plan_id JOIN insurance_companies c ON c.id = p.company_id JOIN patients pt ON pt.id = i.patient_id
                   WHERE " . implode(' AND ', $where) . " ORDER BY i.issued_at LIMIT 500");
$s->execute($args);
$rows = $s->fetchAll();
$ageing = claimAgeing($db);
$batches = $db->query("SELECT b.*, c.name AS company, (SELECT COUNT(*) FROM claims WHERE batch_id = b.id) AS n, (SELECT SUM(claimed) FROM claims WHERE batch_id = b.id) AS total
                       FROM claim_batches b JOIN insurance_companies c ON c.id = b.company_id ORDER BY b.id DESC LIMIT 20")->fetchAll();
$ready = array_filter($rows, fn($r) => $r['status'] === 'ready');
$work = can('insurance.claims');

$pageTitle = 'Claims';
$pageSubtitle = $ageing['all'][0] . ' not sent yet · ' . money($ageing['all'][1] / 100);
$pageActions = $work ? '<form method="POST" class="d-inline">' . csrfField() . '<input type="hidden" name="action" value="recheck"><button class="btn btn-ghost"><i class="bi bi-arrow-repeat"></i>Recheck waiting claims</button></form>' : '';
require_once __DIR__ . '/../includes/header.php';
?>
<?php if ($flash): ?><div class="alert alert-<?= $flash[0] ?>"><i class="bi bi-info-circle"></i><div><strong><?= sanitize($flash[1]) ?></strong></div></div><?php endif; ?>
<div class="row g-3 mb-3">
  <?php foreach ([7 => 'tone-amber', 30 => 'tone-orange', 45 => 'tone-red'] as $d => $tone): ?>
  <div class="col-md-4"><div class="card-crm"><div class="card-body-crm"><div class="fs-12 text-tx-3">Not sent within <?= $d ?> days</div>
    <div class="d-flex align-items-baseline gap-2"><span class="fs-3 fw-700"><?= $ageing[$d][0] ?></span><span class="chip <?= $tone ?>"><?= money($ageing[$d][1] / 100) ?></span></div></div></div></div>
  <?php endforeach; ?>
</div>
<form class="card-crm page-section mb-3" method="GET"><div class="card-body-crm d-flex flex-wrap gap-2 align-items-end">
  <div><label class="form-label" for="fs">Status</label><select class="form-select" id="fs" name="status"><option value="">Open</option><?php foreach (CLAIM_STATUS as $k => [$l]): ?><option value="<?= $k ?>" <?= $st === $k ? 'selected' : '' ?>><?= $l ?></option><?php endforeach; ?></select></div>
  <div><label class="form-label" for="fc">Company</label><select class="form-select" id="fc" name="company"><option value="">All</option><?php foreach (insuranceCompanies($db) as $c): ?><option value="<?= (int)$c['id'] ?>" <?= $co === (int)$c['id'] ? 'selected' : '' ?>><?= sanitize($c['name']) ?></option><?php endforeach; ?></select></div>
  <button class="btn btn-gold">Show</button></div></form>
<form method="POST" class="card-crm mb-3"><?= csrfField() ?><input type="hidden" name="action" value="batch"><input type="hidden" name="qs" value="<?= sanitize(http_build_query(array_filter(['status' => $st, 'company' => $co ?: null]))) ?>">
  <?php if (!$rows): ?><div class="empty-state"><i class="bi bi-file-medical"></i><div class="empty-state-title">No claims here</div><p>Issuing an insurance invoice creates its claim.</p></div>
  <?php else: ?><div class="table-responsive"><table class="table table-hover mb-0 align-middle">
    <thead><tr><?php if ($work && $ready): ?><th></th><?php endif; ?><th>Claim</th><th>Patient</th><th>Company</th><th>Status</th><th class="text-end">Age</th><th class="text-end">Claimed</th><th class="text-end">Paid</th></tr></thead>
    <tbody><?php foreach ($rows as $r): [$l, $tone] = CLAIM_STATUS[$r['status']]; ?>
      <tr><?php if ($work && $ready): ?><td><?php if ($r['status'] === 'ready'): ?><input class="form-check-input" type="checkbox" name="claims[]" value="<?= (int)$r['id'] ?>" data-co="<?= (int)$r['company_id'] ?>" aria-label="Add <?= sanitize($r['number']) ?> to a batch"><?php endif; ?></td><?php endif; ?>
        <td><a class="fw-600" href="/pages/claim.php?id=<?= (int)$r['id'] ?>"><?= sanitize($r['number']) ?></a><span class="cell-sub"><?= sanitize($r['invoice_number']) ?></span></td>
        <td><?= sanitize($r['patient']) ?><span class="cell-sub"><?= sanitize($r['mrn']) ?></span></td><td><?= sanitize($r['company']) ?></td>
        <td><span class="chip <?= $tone ?>"><?= $l ?></span><?= $r['denial_code'] ? '<span class="cell-sub">' . sanitize($r['denial_code']) . '</span>' : '' ?></td>
        <td class="text-end tabular"><?= (int)$r['age'] ?> d</td><td class="text-end tabular"><?= number_format($r['claimed'], 2) ?></td><td class="text-end tabular"><?= number_format($r['paid'], 2) ?></td></tr>
    <?php endforeach; ?></tbody></table></div>
    <?php if ($work && $ready): ?><div class="form-actions"><input type="hidden" name="company_id" id="batchCo" value="">
      <span class="fs-13 text-tx-3">Tick ready claims of one company to send them together.</span><button class="btn btn-gold ms-auto" id="batchBtn" disabled><i class="bi bi-collection"></i>Make batch</button></div>
    <script>
    (function () { var boxes = document.querySelectorAll('[name="claims[]"]'), btn = document.getElementById('batchBtn');
      boxes.forEach(function (b) { b.addEventListener('change', function () { var on = [].filter.call(boxes, function (x) { return x.checked; }), cos = on.map(function (x) { return x.dataset.co; });
        var one = cos.length && cos.every(function (c) { return c === cos[0]; }); btn.disabled = !one; document.getElementById('batchCo').value = one ? cos[0] : ''; btn.title = cos.length && !one ? 'Choose claims of one company' : ''; }); }); })();
    </script><?php endif; ?>
  <?php endif; ?>
</form>
<div class="card-crm"><div class="card-header-crm"><span><i class="bi bi-collection"></i> Batches</span></div><div class="list-group list-group-flush">
  <?php foreach ($batches as $b): ?><div class="list-group-item d-flex flex-wrap gap-2 align-items-center">
    <span class="flex-grow-1"><span class="fw-600">Batch <?= (int)$b['id'] ?> · <?= sanitize($b['company']) ?></span>
      <span class="d-block fs-12 text-tx-3"><?= (int)$b['n'] ?> claims · <?= money($b['total']) ?> · made <?= formatDate($b['created_at']) ?><?= $b['exported_at'] ? ' · exported ' . formatDate($b['exported_at']) : '' ?><?= $b['submitted_at'] ? ' · submitted ' . formatDate($b['submitted_at']) . ' (' . sanitize($b['submission_ref']) . ')' : '' ?></span></span>
    <?php if ($work): ?><a class="btn btn-ghost btn-sm" href="?export=xml&amp;batch=<?= (int)$b['id'] ?>"><i class="bi bi-filetype-xml"></i>XML</a><a class="btn btn-ghost btn-sm" href="?export=csv&amp;batch=<?= (int)$b['id'] ?>"><i class="bi bi-filetype-csv"></i>CSV</a>
      <?php if (!$b['submitted_at'] && $b['exported_at']): ?><form method="POST" class="d-flex gap-1"><?= csrfField() ?><input type="hidden" name="action" value="submitted"><input type="hidden" name="batch_id" value="<?= (int)$b['id'] ?>">
        <label class="visually-hidden" for="br<?= (int)$b['id'] ?>">Portal reference</label><input class="form-control form-control-sm" id="br<?= (int)$b['id'] ?>" name="ref" placeholder="Portal reference" required style="width:150px">
        <label class="visually-hidden" for="bd<?= (int)$b['id'] ?>">Date</label><input class="form-control form-control-sm" type="date" id="bd<?= (int)$b['id'] ?>" name="date" value="<?= date('Y-m-d') ?>" max="<?= date('Y-m-d') ?>" required>
        <button class="btn btn-ghost btn-sm">Mark submitted</button></form><?php endif; ?><?php endif; ?>
  </div><?php endforeach; ?>
  <?php if (!$batches): ?><div class="list-group-item fs-13 text-tx-3">No batches yet.</div><?php endif; ?>
</div></div>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>

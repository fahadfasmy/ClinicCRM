<?php
// pages/reports_commission.php — Reports → Commission: the rules (per practitioner, for every treatment or one
// treatment: a percent of the net amount collected, optionally less the stock used, or a fixed fee per unit once
// paid) and the monthly report with the invoice lines behind each total. CSV export.
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/billing.php';
requirePermission('reports.commission');

$db = getDB();
$month = preg_match('/^\d{4}-\d{2}$/', $_GET['month'] ?? '') ? $_GET['month'] : date('Y-m');
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCsrf();
    try {
        if (($_POST['action'] ?? '') === 'delete') $db->prepare("DELETE FROM commission_rules WHERE id = ?")->execute([(int)$_POST['id']]);
        else {
            $staff = (int)($_POST['staff_id'] ?? 0); $svc = (int)($_POST['service_id'] ?? 0);
            $kind = ($_POST['kind'] ?? '') === 'fixed_per_unit' ? 'fixed_per_unit' : 'percent_net';
            $val = round((float)($_POST['value'] ?? 0), 2);
            if (!$staff || $val <= 0 || ($kind === 'percent_net' && $val > 100)) throw new RuntimeException('Choose the practitioner and a value (percent up to 100).');
            $db->prepare("INSERT INTO commission_rules (staff_id, service_id, kind, value, deduct_consumables) VALUES (?,?,?,?,?)
                          ON DUPLICATE KEY UPDATE kind = VALUES(kind), value = VALUES(value), deduct_consumables = VALUES(deduct_consumables)")
               ->execute([$staff, $svc, $kind, $val, empty($_POST['deduct']) ? 0 : 1]);
        }
        logActivity(currentStaffId(), 'commission_rules_changed', null, null, (string)($_POST['action'] ?? 'save'));
        $_SESSION['setup_flash'] = ['success', 'Commission rules saved.'];
    } catch (Throwable $e) { $_SESSION['setup_flash'] = ['danger', $e->getMessage()]; }
    header("Location: /pages/reports_commission.php?month=$month"); exit;
}
$flash = $_SESSION['setup_flash'] ?? null;
unset($_SESSION['setup_flash']);
$report = commissionReport($db, $month);
if (($_GET['export'] ?? '') === 'csv') {
    header('Content-Type: text/csv; charset=utf-8');
    header("Content-Disposition: attachment; filename=\"commission-$month.csv\"");
    $out = fopen('php://output', 'w'); fwrite($out, "\xEF\xBB\xBF");
    fputcsv($out, ['Practitioner', 'Invoice', 'Date', 'Item', 'Net', 'Collected %', 'Commission']);
    foreach ($report as $r) foreach ($r['lines'] as $l) fputcsv($out, [$r['name'], $l['number'], substr($l['date'], 0, 10), $l['description'], aed($l['net']), round($l['collected'] * 100), aed($l['commission'])]);
    exit;
}
$rules = $db->query("SELECT r.*, s.name AS staff_name, sv.name AS service_name FROM commission_rules r JOIN staff s ON s.id = r.staff_id LEFT JOIN services sv ON sv.id = r.service_id ORDER BY s.name, sv.name")->fetchAll();
$pracs = practitionerList($db);
$services = $db->query("SELECT id, name FROM services WHERE is_active = 1 ORDER BY name")->fetchAll(PDO::FETCH_KEY_PAIR);

$pageTitle = 'Commission';
$pageSubtitle = date('F Y', strtotime("$month-01")) . ' · ' . money(array_sum(array_column($report, 'commission')) / 100);
$pageActions = '<a class="btn btn-ghost" href="?month=' . $month . '&amp;export=csv"><i class="bi bi-download"></i>Export</a>';
require_once __DIR__ . '/../includes/header.php';
?>
<?php if ($flash): ?><div class="alert alert-<?= $flash[0] ?> <?= $flash[0] === 'success' ? 'alert-auto' : '' ?>"><i class="bi bi-info-circle"></i><div><strong><?= sanitize($flash[1]) ?></strong></div></div><?php endif; ?>
<form class="card-crm page-section mb-3" method="GET"><div class="card-body-crm d-flex gap-2 align-items-end">
  <div><label class="form-label" for="m">Month</label><input class="form-control" type="month" id="m" name="month" value="<?= $month ?>"></div><button class="btn btn-gold">Show</button></div></form>
<div class="card-crm mb-3">
  <?php if (!$report): ?><div class="empty-state"><i class="bi bi-graph-up"></i><div class="empty-state-title">No commission this month</div><p>Commission comes from invoice items with a practitioner who has a rule below.</p></div>
  <?php else: ?><div class="list-group list-group-flush"><?php foreach ($report as $r): ?>
    <details class="list-group-item"><summary class="d-flex gap-2"><span class="fw-600 flex-grow-1"><?= sanitize($r['name']) ?></span><span class="fs-13 text-tx-3">net sales <?= aed($r['net']) ?></span><span class="tabular fw-600"><?= money($r['commission'] / 100) ?></span></summary>
      <div class="table-responsive mt-2"><table class="table table-sm mb-0"><thead><tr><th>Invoice</th><th>Item</th><th class="text-end">Net</th><th class="text-end">Collected</th><th class="text-end">Commission</th></tr></thead>
        <tbody><?php foreach ($r['lines'] as $l): ?><tr><td><?= sanitize($l['number']) ?><span class="cell-sub"><?= formatDate($l['date']) ?></span></td><td><?= sanitize($l['description']) ?></td>
          <td class="text-end tabular"><?= aed($l['net']) ?></td><td class="text-end tabular"><?= round($l['collected'] * 100) ?>%</td><td class="text-end tabular"><?= aed($l['commission']) ?></td></tr><?php endforeach; ?></tbody></table></div></details>
  <?php endforeach; ?></div><?php endif; ?>
</div>
<div class="row g-3">
  <div class="col-lg-7"><div class="card-crm"><div class="card-header-crm"><span><i class="bi bi-sliders"></i> Rules</span></div><div class="list-group list-group-flush">
    <?php foreach ($rules as $r): ?><div class="list-group-item d-flex gap-2 align-items-center"><span class="flex-grow-1"><span class="fw-600"><?= sanitize($r['staff_name']) ?></span> · <?= sanitize($r['service_name'] ?? 'every treatment') ?>
      <span class="d-block fs-12 text-tx-3"><?= $r['kind'] === 'percent_net' ? rtrim(rtrim($r['value'], '0'), '.') . '% of net collected' . ($r['deduct_consumables'] ? ', less stock used' : '') : money($r['value']) . ' per unit when paid' ?></span></span>
      <form method="POST"><?= csrfField() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= (int)$r['id'] ?>"><button class="btn btn-ghost btn-sm" aria-label="Delete rule" onclick="return confirm('Delete this rule?')"><i class="bi bi-trash"></i></button></form></div><?php endforeach; ?>
    <?php if (!$rules): ?><div class="list-group-item fs-13 text-tx-3">No rules yet.</div><?php endif; ?>
  </div></div></div>
  <div class="col-lg-5"><form method="POST" class="card-crm"><?= csrfField() ?><input type="hidden" name="action" value="save">
    <div class="card-header-crm"><span>Add or change a rule</span></div><div class="card-body-crm">
      <label class="form-label" for="cs">Practitioner</label><select class="form-select mb-2" id="cs" name="staff_id" required><option value="">Choose…</option><?php foreach ($pracs as $p): ?><option value="<?= (int)$p['id'] ?>"><?= sanitize($p['name']) ?></option><?php endforeach; ?></select>
      <label class="form-label" for="csv">Treatment</label><select class="form-select mb-2" id="csv" name="service_id"><option value="">Every treatment</option><?php foreach ($services as $k => $n): ?><option value="<?= (int)$k ?>"><?= sanitize($n) ?></option><?php endforeach; ?></select>
      <div class="row g-2 mb-2"><div class="col-7"><label class="form-label" for="ck">Type</label><select class="form-select" id="ck" name="kind"><option value="percent_net">% of net collected</option><option value="fixed_per_unit">Fixed AED per unit</option></select></div>
        <div class="col-5"><label class="form-label" for="cv">Value</label><input class="form-control" id="cv" name="value" type="number" min="0.01" step="0.01" required></div></div>
      <div class="form-check"><input class="form-check-input" type="checkbox" id="cd" name="deduct" value="1"><label class="form-check-label" for="cd">Take off the cost of stock used in the visit</label></div>
    </div><div class="form-actions"><button class="btn btn-gold ms-auto">Save rule</button></div></form></div>
</div>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>

<?php
// pages/remittances.php — Insurance → Remittances: money still expected from each company (claims sent and not
// settled), and the payments recorded from insurers (insurer receipts) by date.
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/insurance.php';
requirePermission('insurance.view');

$db = getDB();
$from = preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['from'] ?? '') ? $_GET['from'] : date('Y-m-01');
$to = preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['to'] ?? '') ? $_GET['to'] : date('Y-m-d');
$out = insuranceOutstanding($db);
$s = $db->prepare("SELECT r.id, r.number, r.received_at, r.total, r.status, m.reference, cl.id AS claim_id, cl.number AS claim, c.name AS company
                   FROM receipts r JOIN receipt_methods m ON m.receipt_id = r.id AND m.method = 'insurance' JOIN receipt_allocations a ON a.receipt_id = r.id
                   JOIN claims cl ON cl.invoice_id = a.invoice_id JOIN insurance_plans p ON p.id = cl.plan_id JOIN insurance_companies c ON c.id = p.company_id
                   WHERE DATE(r.received_at) BETWEEN ? AND ? ORDER BY r.received_at DESC LIMIT 500");
$s->execute([$from, $to]);
$rows = $s->fetchAll();

$pageTitle = 'Remittances';
$pageSubtitle = money(array_sum(array_map('floatval', array_column($out, 'outstanding')))) . ' expected from insurers';
require_once __DIR__ . '/../includes/header.php';
?>
<div class="card-crm mb-3"><div class="card-header-crm"><span><i class="bi bi-hourglass-split"></i> Still expected</span></div>
  <div class="table-responsive"><table class="table mb-0"><thead><tr><th>Company</th><th class="text-end">Claims</th><th class="text-end">Claimed</th><th class="text-end">Paid</th><th class="text-end">Outstanding</th><th>Oldest sent</th></tr></thead>
  <tbody><?php foreach ($out as $o): ?><tr><td><a href="/pages/claims.php?company=<?= (int)$o['id'] ?>"><?= sanitize($o['name']) ?></a></td><td class="text-end"><?= (int)$o['claims'] ?></td><td class="text-end tabular"><?= number_format($o['claimed'], 2) ?></td>
    <td class="text-end tabular"><?= number_format($o['paid'], 2) ?></td><td class="text-end tabular fw-600"><?= number_format($o['outstanding'], 2) ?></td><td><?= $o['oldest'] ? formatDate($o['oldest']) : '—' ?></td></tr><?php endforeach; ?>
  <?php if (!$out): ?><tr><td colspan="6" class="text-tx-3">Nothing outstanding.</td></tr><?php endif; ?></tbody></table></div></div>
<form class="card-crm page-section mb-3" method="GET"><div class="card-body-crm d-flex gap-2 align-items-end">
  <div><label class="form-label" for="ff">From</label><input class="form-control" type="date" id="ff" name="from" value="<?= $from ?>"></div>
  <div><label class="form-label" for="ft">To</label><input class="form-control" type="date" id="ft" name="to" value="<?= $to ?>"></div><button class="btn btn-gold">Show</button></div></form>
<div class="card-crm"><div class="table-responsive"><table class="table mb-0"><thead><tr><th>Date</th><th>Company</th><th>Claim</th><th>Reference</th><th>Receipt</th><th class="text-end">Amount</th></tr></thead>
  <tbody><?php foreach ($rows as $r): ?><tr class="<?= $r['status'] === 'void' ? 'text-decoration-line-through text-tx-3' : '' ?>"><td><?= formatDate($r['received_at']) ?></td><td><?= sanitize($r['company']) ?></td>
    <td><a href="/pages/claim.php?id=<?= (int)$r['claim_id'] ?>"><?= sanitize($r['claim']) ?></a></td><td><?= sanitize((string)$r['reference']) ?></td><td><a href="/pages/receipt.php?id=<?= (int)$r['id'] ?>"><?= sanitize($r['number']) ?></a></td>
    <td class="text-end tabular"><?= number_format($r['total'], 2) ?></td></tr><?php endforeach; ?>
  <?php if (!$rows): ?><tr><td colspan="6" class="text-tx-3">No payments from insurers in this period.</td></tr><?php endif; ?></tbody></table></div></div>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>

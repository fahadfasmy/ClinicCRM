<?php
// pages/receipts.php — Billing → Receipts: payments taken by date, cashier and method, with totals per method.
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/billing.php';
requirePermission('billing.view');

$db = getDB();
$from = preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['from'] ?? '') ? $_GET['from'] : date('Y-m-d');
$to = preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['to'] ?? '') ? $_GET['to'] : date('Y-m-d');
$fStaff = (int)($_GET['staff'] ?? 0);
$where = 'DATE(r.received_at) BETWEEN ? AND ?' . ($fStaff ? ' AND r.received_by = ' . $fStaff : '');
$s = $db->prepare("SELECT r.*, s.name AS by_name, CONCAT_WS(' ', p.first_name, p.last_name) AS patient, p.mrn,
                          (SELECT GROUP_CONCAT(DISTINCT m.method) FROM receipt_methods m WHERE m.receipt_id = r.id) AS methods
                   FROM receipts r LEFT JOIN staff s ON s.id = r.received_by LEFT JOIN patients p ON p.id = r.patient_id WHERE $where ORDER BY r.received_at DESC LIMIT 500");
$s->execute([$from, $to]);
$rows = $s->fetchAll();
$byMethod = $db->prepare("SELECT m.method, SUM(m.amount) FROM receipt_methods m JOIN receipts r ON r.id = m.receipt_id WHERE r.status = 'valid' AND $where GROUP BY m.method");
$byMethod->execute([$from, $to]);
$byMethod = $byMethod->fetchAll(PDO::FETCH_KEY_PAIR);
$cashiers = $db->query("SELECT DISTINCT s.id, s.name FROM receipts r JOIN staff s ON s.id = r.received_by ORDER BY s.name")->fetchAll(PDO::FETCH_KEY_PAIR);

$pageTitle = 'Receipts';
$pageSubtitle = money(array_sum(array_map('floatval', $byMethod))) . ' received';
$pageActions = can('billing.day_close') ? '<a class="btn btn-gold" href="/pages/day_close.php"><i class="bi bi-safe"></i>' . t('Day close') . '</a>' : '';
require_once __DIR__ . '/../includes/header.php';
?>
<form class="card-crm page-section mb-3" method="GET"><div class="card-body-crm d-flex flex-wrap gap-2 align-items-end">
  <div><label class="form-label" for="fFrom"><?= t('From') ?></label><input class="form-control" type="date" id="fFrom" name="from" value="<?= $from ?>"></div>
  <div><label class="form-label" for="fTo"><?= t('To') ?></label><input class="form-control" type="date" id="fTo" name="to" value="<?= $to ?>"></div>
  <div><label class="form-label" for="fSt"><?= t('Received by') ?></label><select class="form-select" id="fSt" name="staff"><option value=""><?= t('Everyone') ?></option><?php foreach ($cashiers as $k => $n): ?><option value="<?= (int)$k ?>" <?= $fStaff === (int)$k ? 'selected' : '' ?>><?= sanitize($n) ?></option><?php endforeach; ?></select></div>
  <button class="btn btn-gold"><i class="bi bi-funnel"></i><?= t('Show') ?></button>
</div></form>
<?php if ($byMethod): ?><div class="d-flex flex-wrap gap-2 mb-3"><?php foreach ($byMethod as $m => $a): ?><span class="chip tone-slate"><?= PAY_METHODS[$m] ?? sanitize($m) ?>: <?= number_format($a, 2) ?></span><?php endforeach; ?></div><?php endif; ?>
<div class="card-crm">
  <?php if (!$rows): ?><div class="empty-state"><i class="bi bi-cash-coin"></i><div class="empty-state-title"><?= t('No payments in this period') ?></div></div>
  <?php else: ?><div class="table-responsive"><table class="table table-hover mb-0">
    <thead><tr><th><?= t('Number') ?></th><th><?= t('When') ?></th><th><?= t('Patient') ?></th><th><?= t('Paid by') ?></th><th><?= t('Received by') ?></th><th class="text-end"><?= t('Amount') ?></th></tr></thead>
    <tbody><?php foreach ($rows as $r): ?>
      <tr class="<?= $r['status'] === 'void' ? 'text-decoration-line-through text-tx-3' : '' ?>"><td><a href="/pages/receipt.php?id=<?= (int)$r['id'] ?>" class="fw-600"><?= sanitize($r['number']) ?></a><?= $r['day_close_id'] ? ' <i class="bi bi-lock text-tx-3" title="' . t('Day closed') . '"></i>' : '' ?></td>
        <td><?= date('d M H:i', strtotime($r['received_at'])) ?></td><td><?= sanitize((string)($r['patient'] ?? ($r['voucher_id'] ? 'Gift voucher' : ''))) ?><span class="cell-sub"><?= sanitize((string)$r['mrn']) ?></span></td>
        <td><?= sanitize(implode(', ', array_map(fn($m) => PAY_METHODS[$m] ?? $m, explode(',', (string)$r['methods'])))) ?></td><td><?= sanitize((string)$r['by_name']) ?></td>
        <td class="text-end tabular"><?= number_format($r['total'], 2) ?></td></tr>
    <?php endforeach; ?></tbody></table></div><?php endif; ?>
</div>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>

<?php
// pages/invoices.php — Billing → Invoices: every invoice by date, status and branch, with totals, and a CSV export.
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/billing.php';
requirePermission('billing.view');

$db = getDB();
$from = preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['from'] ?? '') ? $_GET['from'] : date('Y-m-01');
$to = preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['to'] ?? '') ? $_GET['to'] : date('Y-m-d');
$st = isset(INVOICE_STATUS[$_GET['status'] ?? '']) ? $_GET['status'] : (($_GET['status'] ?? '') === 'unpaid' ? 'unpaid' : '');
$fBranch = (int)($_GET['branch'] ?? 0);
$q = trim((string)($_GET['q'] ?? ''));
$where = ["DATE(COALESCE(i.issued_at, i.created_at)) BETWEEN ? AND ?"]; $args = [$from, $to];
if ($st === 'unpaid') $where[] = "i.status IN ('issued','partially_paid')";
elseif ($st) { $where[] = 'i.status = ?'; $args[] = $st; }
if ($fBranch) { $where[] = 'i.branch_id = ?'; $args[] = $fBranch; }
if ($q !== '') { $where[] = "(i.number LIKE ? OR p.mrn = ? OR CONCAT_WS(' ', p.first_name, p.last_name) LIKE ?)"; array_push($args, "%$q%", $q, "%$q%"); }
$s = $db->prepare("SELECT i.*, p.mrn, CONCAT_WS(' ', p.first_name, p.last_name) AS patient, b.name AS branch_name FROM invoices i JOIN patients p ON p.id = i.patient_id LEFT JOIN branches b ON b.id = i.branch_id
                   WHERE " . implode(' AND ', $where) . " ORDER BY COALESCE(i.issued_at, i.created_at) DESC, i.id DESC LIMIT 500");
$s->execute($args);
$rows = $s->fetchAll();

if (($_GET['export'] ?? '') === 'csv') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="invoices-' . $from . '-' . $to . '.csv"');
    $out = fopen('php://output', 'w'); fwrite($out, "\xEF\xBB\xBF");
    fputcsv($out, ['Number', 'Issued', 'Patient', 'MRN', 'Branch', 'Status', 'Taxable', 'VAT', 'Total', 'Credited', 'Paid', 'Balance']);
    foreach ($rows as $r) fputcsv($out, [$r['number'] ?? 'draft', $r['issued_at'], $r['patient'], $r['mrn'], $r['branch_name'], INVOICE_STATUS[$r['status']][0], $r['net'], $r['vat'], $r['total'], $r['credited'], aed(fils($r['paid']) - fils($r['refunded'])), $r['status'] === 'void' ? '0.00' : aed(invoiceBalance($r))]);
    exit;
}
$issued = array_filter($rows, fn($r) => !in_array($r['status'], ['draft', 'void'], true));
$sum = fn(string $k) => array_sum(array_map(fn($r) => fils($r[$k]), $issued));
$due = array_sum(array_map('invoiceBalance', $issued));
$qs = fn(array $o) => '?' . http_build_query(array_filter(array_merge(['from' => $from, 'to' => $to, 'status' => $st, 'branch' => $fBranch ?: null, 'q' => $q], $o)));

$pageTitle = 'Invoices';
$pageSubtitle = count($issued) . ' issued · ' . money(($sum('total') - $sum('credited')) / 100) . ' after credits · ' . money($due / 100) . ' still owed';
$pageActions = '<a class="btn btn-ghost" href="' . sanitize($qs(['export' => 'csv'])) . '"><i class="bi bi-download"></i>' . t('Export') . '</a>';
require_once __DIR__ . '/../includes/header.php';
?>
<form class="card-crm page-section mb-3" method="GET"><div class="card-body-crm d-flex flex-wrap gap-2 align-items-end">
  <div><label class="form-label" for="fFrom"><?= t('From') ?></label><input class="form-control" type="date" id="fFrom" name="from" value="<?= $from ?>"></div>
  <div><label class="form-label" for="fTo"><?= t('To') ?></label><input class="form-control" type="date" id="fTo" name="to" value="<?= $to ?>"></div>
  <div><label class="form-label" for="fSt"><?= t('Status') ?></label><select class="form-select" id="fSt" name="status"><option value=""><?= t('Any') ?></option><option value="unpaid" <?= $st === 'unpaid' ? 'selected' : '' ?>><?= t('Not fully paid') ?></option>
    <?php foreach (INVOICE_STATUS as $k => [$l]): ?><option value="<?= $k ?>" <?= $st === $k ? 'selected' : '' ?>><?= $l ?></option><?php endforeach; ?></select></div>
  <div><label class="form-label" for="fBr"><?= t('Branch') ?></label><select class="form-select" id="fBr" name="branch"><option value=""><?= t('All') ?></option><?php foreach (branchList($db, true) as $b): ?><option value="<?= (int)$b['id'] ?>" <?= $fBranch === (int)$b['id'] ? 'selected' : '' ?>><?= sanitize($b['name']) ?></option><?php endforeach; ?></select></div>
  <div><label class="form-label" for="fQ"><?= t('Search') ?></label><input class="form-control" id="fQ" name="q" value="<?= sanitize($q) ?>" placeholder="<?= t('Number, MRN or name') ?>"></div>
  <button class="btn btn-gold"><i class="bi bi-funnel"></i><?= t('Show') ?></button>
</div></form>
<div class="card-crm">
  <?php if (!$rows): ?><div class="empty-state"><i class="bi bi-receipt"></i><div class="empty-state-title"><?= t('No invoices in this period') ?></div><p><?= t('Invoices start from a completed visit (Invoice button) or a patient’s Invoices tab.') ?></p></div>
  <?php else: ?><div class="table-responsive"><table class="table table-hover mb-0">
    <thead><tr><th><?= t('Number') ?></th><th><?= t('Date') ?></th><th><?= t('Patient') ?></th><th><?= t('Status') ?></th><th class="text-end"><?= t('VAT') ?></th><th class="text-end"><?= t('Total') ?></th><th class="text-end"><?= t('Balance') ?></th></tr></thead>
    <tbody><?php foreach ($rows as $r): [$l, $tone] = INVOICE_STATUS[$r['status']]; ?>
      <tr><td><a href="/pages/invoice.php?id=<?= (int)$r['id'] ?>" class="fw-600"><?= sanitize($r['number'] ?? 'Draft') ?></a></td><td><?= formatDate($r['issued_at'] ?? $r['created_at']) ?></td>
        <td><?= sanitize($r['patient']) ?><span class="cell-sub"><?= sanitize($r['mrn']) ?></span></td><td><span class="chip <?= $tone ?>"><?= $l ?></span></td>
        <td class="text-end tabular"><?= number_format($r['vat'], 2) ?></td><td class="text-end tabular"><?= number_format($r['total'], 2) ?></td>
        <td class="text-end tabular"><?= in_array($r['status'], ['draft', 'void'], true) ? '–' : number_format(invoiceBalance($r) / 100, 2) ?></td></tr>
    <?php endforeach; ?></tbody></table></div><?php endif; ?>
</div>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>

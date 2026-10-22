<?php
// pages/vouchers.php — Billing → Vouchers: sell a gift voucher (a code with a value and an expiry; paid now, VAT is
// charged on what it is later spent on), and the list with what each has left. Spending one is a payment method.
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/billing.php';
requirePermission('billing.view');

$db = getDB();
$branches = branchList($db);
$error = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCsrf();
    try {
        requirePermission('billing.invoice');
        $pid = trim((string)($_POST['mrn'] ?? '')) !== '' ? (int)$db->query("SELECT id FROM patients WHERE mrn = " . $db->quote(trim($_POST['mrn'])))->fetchColumn() : null;
        if (trim((string)($_POST['mrn'] ?? '')) !== '' && !$pid) throw new RuntimeException('No patient with that MRN.');
        $method = isset(PAY_METHODS[$_POST['method'] ?? '']) ? $_POST['method'] : 'cash';
        [$vid, $code] = sellVoucher($db, (int)($_POST['branch_id'] ?? array_key_first($branches)), $_POST['value'] ?? 0, (string)($_POST['expires_on'] ?? ''),
            [['method' => $method, 'amount' => $_POST['value'] ?? 0, 'reference' => $_POST['reference'] ?? '']], $pid ?: null, (string)($_POST['recipient'] ?? ''));
        $_SESSION['bill_flash'] = ['success', "Voucher $code sold."];
        header('Location: /pages/vouchers.php'); exit;
    } catch (Throwable $e) { $error = $e->getMessage(); }
}
$flash = $_SESSION['bill_flash'] ?? null;
unset($_SESSION['bill_flash']);
$list = $db->query("SELECT v.*, CONCAT_WS(' ', p.first_name, p.last_name) AS buyer FROM vouchers v LEFT JOIN patients p ON p.id = v.patient_id ORDER BY v.id DESC LIMIT 300")->fetchAll();

$pageTitle = 'Vouchers';
$pageSubtitle = 'Open value ' . money(voucherLiability($db) / 100);
require_once __DIR__ . '/../includes/header.php';
?>
<?php if ($flash): ?><div class="alert alert-success alert-auto"><i class="bi bi-check-circle"></i><div><strong><?= sanitize($flash[1]) ?></strong></div></div><?php endif; ?>
<?php if ($error): ?><div class="alert alert-danger"><i class="bi bi-exclamation-triangle"></i><div><strong><?= sanitize($error) ?></strong></div></div><?php endif; ?>
<div class="row g-3">
  <div class="col-xl-8"><div class="card-crm"><div class="table-responsive"><table class="table mb-0">
    <thead><tr><th>Code</th><th>For</th><th class="text-end">Value</th><th class="text-end">Left</th><th>Expires</th></tr></thead>
    <tbody><?php foreach ($list as $v): $left = voucherBalance($db, (int)$v['id']); $dead = $v['status'] === 'void' || $v['expires_on'] < date('Y-m-d'); ?>
      <tr class="<?= $dead ? 'text-tx-3' : '' ?>"><td class="fw-600 tabular"><?= sanitize($v['code']) ?><?= $v['status'] === 'void' ? ' <span class="chip tone-red">Void</span>' : '' ?></td>
        <td><?= sanitize((string)($v['recipient'] ?: $v['buyer'])) ?><?= $v['recipient'] && $v['buyer'] ? '<span class="cell-sub">from ' . sanitize($v['buyer']) . '</span>' : '' ?></td>
        <td class="text-end tabular"><?= number_format($v['initial'], 2) ?></td><td class="text-end tabular"><?= aed($left) ?></td><td><?= formatDate($v['expires_on']) ?></td></tr>
    <?php endforeach; ?><?php if (!$list): ?><tr><td colspan="5" class="text-tx-3">No vouchers yet.</td></tr><?php endif; ?></tbody></table></div></div></div>
  <?php if (can('billing.invoice')): ?>
  <div class="col-xl-4"><form method="POST" class="card-crm"><?= csrfField() ?>
    <div class="card-header-crm"><span><i class="bi bi-gift"></i> Sell a voucher</span></div>
    <div class="card-body-crm">
      <label class="form-label" for="vVal">Value (AED)</label><input class="form-control mb-2" id="vVal" name="value" type="number" min="1" step="0.01" required value="<?= sanitize((string)($_POST['value'] ?? '')) ?>">
      <label class="form-label" for="vExp">Expires</label><input class="form-control mb-2" id="vExp" name="expires_on" type="date" min="<?= date('Y-m-d', strtotime('+1 day')) ?>" required value="<?= sanitize((string)($_POST['expires_on'] ?? date('Y-m-d', strtotime('+1 year')))) ?>">
      <label class="form-label" for="vFor">For (name on the voucher)</label><input class="form-control mb-2" id="vFor" name="recipient" maxlength="150" value="<?= sanitize((string)($_POST['recipient'] ?? '')) ?>">
      <label class="form-label" for="vMrn">Bought by (patient MRN, optional)</label><input class="form-control mb-2" id="vMrn" name="mrn" value="<?= sanitize((string)($_POST['mrn'] ?? '')) ?>">
      <div class="row g-2"><div class="col-6"><label class="form-label" for="vM">Paid by</label><select class="form-select" id="vM" name="method"><?php foreach (DRAWER_METHODS as $m): ?><option value="<?= $m ?>"><?= PAY_METHODS[$m] ?></option><?php endforeach; ?></select></div>
        <div class="col-6"><label class="form-label" for="vR">Reference</label><input class="form-control" id="vR" name="reference" maxlength="100"></div></div>
      <label class="form-label mt-2" for="vB">Branch</label><select class="form-select" id="vB" name="branch_id"><?php foreach ($branches as $b): ?><option value="<?= (int)$b['id'] ?>"><?= sanitize($b['name']) ?></option><?php endforeach; ?></select>
    </div>
    <div class="form-actions"><button class="btn btn-gold ms-auto"><i class="bi bi-gift"></i>Sell voucher</button></div>
  </form></div>
  <?php endif; ?>
</div>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>

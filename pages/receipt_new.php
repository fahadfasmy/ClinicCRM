<?php
// pages/receipt_new.php?invoice= | ?patient= — take a payment: one or more methods (cash, card, transfer, cheque,
// Tabby, Tamara, payment link, the patient's balance, a voucher), allocated across the patient's open invoices.
// What isn't allocated is kept as the patient's balance.
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/billing.php';
requirePermission('billing.invoice');

$db = getDB();
$invId = (int)($_GET['invoice'] ?? 0);
$pid = (int)($_GET['patient'] ?? 0);
if ($invId && ($i = findInvoice($db, $invId))) $pid = (int)$i['patient_id'];
$p = $pid ? findPatient($db, $pid) : null;
if (!$p) { http_response_code(404); exit('Patient not found'); }
$pid = (int)$p['id'];
$open = $db->prepare("SELECT * FROM invoices WHERE patient_id = ? AND status IN ('issued','partially_paid') ORDER BY issued_at");
$open->execute([$pid]);
$open = array_values(array_filter($open->fetchAll(), fn($x) => invoicePatientDue($x) > 0));
$branches = branchList($db);
$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCsrf();
    try {
        $methods = [];
        foreach ((array)($_POST['m'] ?? []) as $m) if (trim((string)($m['amount'] ?? '')) !== '') $methods[] = ['method' => (string)($m['method'] ?? ''), 'amount' => $m['amount'], 'reference' => $m['reference'] ?? '', 'voucher_code' => $m['reference'] ?? '', 'cheque_date' => $m['cheque_date'] ?? ''];
        $alloc = array_filter((array)($_POST['alloc'] ?? []), fn($v) => trim((string)$v) !== '');
        $branch = (int)($_POST['branch_id'] ?? 0);
        if (!isset($branches[$branch])) throw new RuntimeException('Choose the branch.');
        $rid = createReceipt($db, $pid, $branch, $methods, $alloc, ['notes' => $_POST['notes'] ?? '']);
        $_SESSION['bill_flash'] = ['success', 'Payment received.'];
        header("Location: /pages/receipt.php?id=$rid"); exit;
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
}
$wallet = walletBalance($db, $pid);
$defBranch = (int)($_POST['branch_id'] ?? ($invId && isset($i) ? $i['branch_id'] : ($p['branch_id'] ?: array_key_first($branches))));

$pageTitle = 'Take payment';
$pageSubtitle = sanitize(patientName($p)) . ' · ' . sanitize($p['mrn']) . ($wallet > 0 ? ' · balance ' . money($wallet / 100) : '');
$pageBack = $invId ? ['href' => "/pages/invoice.php?id=$invId", 'label' => 'Invoice'] : ['href' => "/pages/patient.php?id=$pid&tab=billing", 'label' => 'Patient'];
require_once __DIR__ . '/../includes/header.php';
$postM = (array)($_POST['m'] ?? []);
$firstDue = $invId ? array_values(array_filter($open, fn($x) => (int)$x['id'] === $invId)) : [];
$prefill = $firstDue ? aed(invoicePatientDue($firstDue[0])) : '';
?>
<?php if ($error): ?><div class="alert alert-danger"><i class="bi bi-exclamation-triangle"></i><div><strong><?= sanitize($error) ?></strong></div></div><?php endif; ?>
<form method="POST" class="row g-3" id="payForm"><?= csrfField() ?>
  <div class="col-lg-7"><div class="card-crm">
    <div class="card-header-crm"><span><i class="bi bi-wallet2"></i> <?= t('Paid by') ?></span></div>
    <div class="card-body-crm">
      <?php for ($n = 0; $n < 4; $n++): $pm = $postM[$n] ?? []; ?>
      <div class="row g-2 mb-2 pay-row">
        <div class="col-5 col-md-4"><label class="form-label <?= $n ? 'visually-hidden' : '' ?>" for="pm<?= $n ?>"><?= t('Method') ?></label>
          <select class="form-select" id="pm<?= $n ?>" name="m[<?= $n ?>][method]"><?php foreach (PAY_METHODS as $k => $l): if ($k === 'insurance' || ($k === 'wallet' && $wallet <= 0)) continue; ?><option value="<?= $k ?>" <?= ($pm['method'] ?? ($n === 0 ? 'cash' : ($n === 1 ? 'card' : ''))) === $k ? 'selected' : '' ?>><?= $l ?></option><?php endforeach; ?></select></div>
        <div class="col-7 col-md-3"><label class="form-label <?= $n ? 'visually-hidden' : '' ?>" for="pa<?= $n ?>"><?= t('Amount') ?></label>
          <input class="form-control text-end pay-amt" id="pa<?= $n ?>" name="m[<?= $n ?>][amount]" type="number" min="0.01" step="0.01" value="<?= sanitize((string)($pm['amount'] ?? ($n === 0 ? $prefill : ''))) ?>"></div>
        <div class="col-md-5"><label class="form-label <?= $n ? 'visually-hidden' : '' ?>" for="pr<?= $n ?>"><?= t('Reference') ?> <span class="fw-normal text-tx-3"><?= t('(card slip, cheque no., voucher code)') ?></span></label>
          <input class="form-control" id="pr<?= $n ?>" name="m[<?= $n ?>][reference]" maxlength="100" value="<?= sanitize((string)($pm['reference'] ?? '')) ?>"></div>
      </div>
      <?php endfor; ?>
      <div class="row g-2 mt-2">
        <div class="col-md-6"><label class="form-label" for="pBranch"><?= t('Branch') ?></label><select class="form-select" id="pBranch" name="branch_id"><?php foreach ($branches as $b): ?><option value="<?= (int)$b['id'] ?>" <?= $defBranch === (int)$b['id'] ? 'selected' : '' ?>><?= sanitize($b['name']) ?></option><?php endforeach; ?></select></div>
        <div class="col-md-6"><label class="form-label" for="pNotes"><?= t('Note') ?></label><input class="form-control" id="pNotes" name="notes" maxlength="255" value="<?= sanitize((string)($_POST['notes'] ?? '')) ?>"></div>
      </div>
    </div>
  </div></div>
  <div class="col-lg-5"><div class="card-crm">
    <div class="card-header-crm"><span><i class="bi bi-receipt"></i> <?= t('Pay against') ?></span></div>
    <div class="list-group list-group-flush">
      <?php foreach ($open as $o): $bal = invoicePatientDue($o); ?>
      <div class="list-group-item d-flex align-items-center gap-2"><label class="flex-grow-1" for="al<?= (int)$o['id'] ?>"><?= sanitize($o['number']) ?><span class="d-block fs-12 text-tx-3"><?= formatDate($o['issued_at']) ?> · owes <?= number_format($bal / 100, 2) ?></span></label>
        <input class="form-control text-end pay-alloc" style="width:120px" id="al<?= (int)$o['id'] ?>" name="alloc[<?= (int)$o['id'] ?>]" type="number" min="0" max="<?= aed($bal) ?>" step="0.01" value="<?= sanitize((string)($_POST['alloc'][$o['id']] ?? ((int)$o['id'] === $invId ? aed($bal) : ''))) ?>"></div>
      <?php endforeach; ?>
      <?php if (!$open): ?><div class="list-group-item fs-13 text-tx-3"><?= t('No unpaid invoices. The payment will be kept as the patient’s balance (an advance).') ?></div><?php endif; ?>
    </div>
    <div class="card-body-crm fs-13"><div class="d-flex"><span><?= t('Received') ?></span><span class="ms-auto tabular" id="sumIn">0.00</span></div>
      <div class="d-flex"><span><?= t('Against invoices') ?></span><span class="ms-auto tabular" id="sumAl">0.00</span></div>
      <div class="d-flex fw-600"><span><?= t('Kept as balance') ?></span><span class="ms-auto tabular" id="sumLeft">0.00</span></div></div>
    <div class="form-actions"><button class="btn btn-gold ms-auto"><i class="bi bi-check-lg"></i><?= t('Receive payment') ?></button></div>
  </div></div>
</form>
<script>
(function () {
  var f = document.getElementById('payForm');
  function sum(sel) { return [].reduce.call(f.querySelectorAll(sel), function (s, i) { return s + (parseFloat(i.value) || 0); }, 0); }
  function upd() { var a = sum('.pay-amt'), b = sum('.pay-alloc'); document.getElementById('sumIn').textContent = a.toFixed(2); document.getElementById('sumAl').textContent = b.toFixed(2);
    var l = document.getElementById('sumLeft'); l.textContent = (a - b).toFixed(2); l.classList.toggle('text-danger', a - b < -0.001); }
  f.addEventListener('input', upd); upd();
})();
</script>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>

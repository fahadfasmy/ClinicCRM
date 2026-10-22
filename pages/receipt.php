<?php
// pages/receipt.php?id= — one receipt: how it was paid, what it paid, print, and void (billing.void; after the day
// was closed, admins only).
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/billing.php';
requirePermission('billing.view');

$db = getDB();
$id = (int)($_GET['id'] ?? 0);
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCsrf();
    try { voidReceipt($db, $id, (string)($_POST['reason'] ?? '')); $_SESSION['bill_flash'] = ['success', 'Receipt void.']; }
    catch (Throwable $e) { $_SESSION['bill_flash'] = ['danger', $e->getMessage()]; }
    header("Location: /pages/receipt.php?id=$id"); exit;
}
$flash = $_SESSION['bill_flash'] ?? null;
unset($_SESSION['bill_flash']);
$r = findReceipt($db, $id);
if (!$r) { http_response_code(404); exit('Receipt not found'); }
$p = $r['patient_id'] ? findPatient($db, (int)$r['patient_id']) : null;
if ($p) logPatientAccess($db, (int)$p['id'], 'receipt:' . $id);
$methods = $db->prepare("SELECT * FROM receipt_methods WHERE receipt_id = ? ORDER BY id"); $methods->execute([$id]);
$alloc = $db->prepare("SELECT a.amount, i.id, i.number FROM receipt_allocations a JOIN invoices i ON i.id = a.invoice_id WHERE a.receipt_id = ?"); $alloc->execute([$id]); $alloc = $alloc->fetchAll();
$kept = fils($r['total']) - array_sum(array_map(fn($a) => fils($a['amount']), $alloc));

$pageTitle = $r['number'];
$pageSubtitle = ($r['status'] === 'void' ? '<span class="chip tone-red">' . t('Void') . '</span> · ' : '') . money($r['total']) . ' · ' . formatDate($r['received_at']) . ' · ' . sanitize((string)$r['received_by_name'])
    . ($p ? ' · <a href="/pages/patient.php?id=' . (int)$p['id'] . '&amp;tab=billing">' . sanitize(patientName($p)) . '</a>' : '') . ($r['day_close_id'] ? ' · day closed' : '');
$pageBack = ['href' => '/pages/receipts.php', 'label' => 'Receipts'];
$pageActions = '<a class="btn btn-ghost" href="/pages/doc_print.php?type=receipt&amp;id=' . $id . '" target="_blank"><i class="bi bi-printer"></i>' . t('Print') . '</a>';
require_once __DIR__ . '/../includes/header.php';
?>
<?php if ($flash): ?><div class="alert alert-<?= $flash[0] ?> <?= $flash[0] === 'success' ? 'alert-auto' : '' ?>"><i class="bi bi-<?= $flash[0] === 'success' ? 'check-circle' : 'exclamation-triangle' ?>"></i><div><strong><?= sanitize($flash[1]) ?></strong></div></div><?php endif; ?>
<?php if ($r['status'] === 'void'): ?><div class="alert alert-danger"><i class="bi bi-x-octagon"></i>Void on <?= formatDate($r['voided_at']) ?>: <?= sanitize((string)$r['void_reason']) ?></div><?php endif; ?>
<div class="row g-3">
  <div class="col-lg-6"><div class="card-crm"><div class="card-header-crm"><span><i class="bi bi-wallet2"></i> <?= t('Paid by') ?></span></div><div class="list-group list-group-flush">
    <?php foreach ($methods as $m): ?><div class="list-group-item d-flex"><span><?= PAY_METHODS[$m['method']] ?? sanitize($m['method']) ?><?= $m['reference'] ? ' <span class="text-tx-3 fs-12">' . sanitize($m['reference']) . '</span>' : '' ?></span><span class="ms-auto tabular"><?= number_format($m['amount'], 2) ?></span></div><?php endforeach; ?>
  </div></div></div>
  <div class="col-lg-6"><div class="card-crm"><div class="card-header-crm"><span><i class="bi bi-receipt"></i> <?= t('Paid against') ?></span></div><div class="list-group list-group-flush">
    <?php foreach ($alloc as $a): ?><a class="list-group-item list-group-item-action d-flex" href="/pages/invoice.php?id=<?= (int)$a['id'] ?>"><span><?= sanitize($a['number']) ?></span><span class="ms-auto tabular"><?= number_format($a['amount'], 2) ?></span></a><?php endforeach; ?>
    <?php if ($kept > 0): ?><div class="list-group-item d-flex"><span><?= $r['voucher_id'] ? 'Gift voucher sale' : 'Kept as the patient’s balance' ?></span><span class="ms-auto tabular"><?= aed($kept) ?></span></div><?php endif; ?>
  </div></div>
  <?php if ($r['status'] === 'valid' && can('billing.void') && (!$r['day_close_id'] || isAdmin())): ?>
  <details class="card-crm mt-3"><summary class="card-header-crm"><span><i class="bi bi-x-octagon"></i> <?= t('Void receipt') ?></span></summary>
    <form method="POST" class="card-body-crm d-flex gap-2"><?= csrfField() ?><label class="visually-hidden" for="vr"><?= t('Reason') ?></label><input class="form-control" id="vr" name="reason" placeholder="<?= t('Why it is void') ?>" required maxlength="255">
      <button class="btn btn-ghost" onclick="return confirm('Void this receipt? The invoices it paid become unpaid again.')"><?= t('Void') ?></button></form></details>
  <?php endif; ?>
  </div>
</div>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>

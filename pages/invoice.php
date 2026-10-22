<?php
// pages/invoice.php?id= — one invoice. A draft can be edited (treatments, packages, products, other items, discounts)
// and issued; an issued invoice shows its payments and credit notes and can be paid, credited, voided or printed.
// POST action=new with appointment_id (made from the visit) or patient_id (blank) creates a draft.
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/billing.php';
require_once __DIR__ . '/../includes/insurance.php';
requirePermission('billing.view');

$db = getDB();
$id = (int)($_GET['id'] ?? 0);
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCsrf();
    $action = (string)($_POST['action'] ?? '');
    try {
        if ($action === 'new') {
            requirePermission('billing.invoice');
            $id = !empty($_POST['appointment_id']) ? invoiceFromVisit($db, (int)$_POST['appointment_id']) : createInvoice($db, (int)($_POST['patient_id'] ?? 0), (int)($_POST['branch_id'] ?? 0) ?: null);
            header("Location: /pages/invoice.php?id=$id"); exit;
        }
        $inv = findInvoice($db, $id);
        if (!$inv) throw new RuntimeException('Invoice not found.');
        $msg = 'Saved.';
        switch ($action) {
            case 'add_line': requirePermission('billing.invoice'); addInvoiceLine($db, $id, $_POST); break;
            case 'discount': requirePermission('billing.invoice'); setLineDiscount($db, (int)$_POST['line_id'], $_POST); break;
            case 'remove_line': requirePermission('billing.invoice'); removeInvoiceLine($db, $id, (int)$_POST['line_id']); break;
            case 'insurance': requirePermission('billing.invoice'); setInvoiceInsurance($db, $id, (int)($_POST['card_id'] ?? 0) ?: null); break;
            case 'covered': requirePermission('billing.invoice'); setLineCovered($db, $id, (int)$_POST['line_id'], !empty($_POST['covered'])); break;
            case 'issue': requirePermission('billing.invoice'); $msg = 'Issued as ' . issueInvoice($db, $id) . '.'; break;
            case 'delete_draft': requirePermission('billing.invoice'); deleteDraftInvoice($db, $id);
                $_SESSION['bill_flash'] = ['success', 'Draft deleted.']; header('Location: /pages/patient.php?id=' . (int)$inv['patient_id'] . '&tab=billing'); exit;
            case 'void': voidInvoice($db, $id, (string)($_POST['reason'] ?? '')); $msg = 'Invoice void.'; break;
            case 'credit': $cn = issueCreditNote($db, $id, (array)($_POST['qty'] ?? []), (string)($_POST['reason'] ?? ''), (string)($_POST['settle'] ?? 'wallet'));
                $msg = 'Credit note issued.'; break;
            default: throw new RuntimeException('Unknown action.');
        }
        $_SESSION['bill_flash'] = ['success', $msg];
    } catch (Throwable $e) {
        $_SESSION['bill_flash'] = ['danger', $e->getMessage()];
    }
    header("Location: /pages/invoice.php?id=$id" . (!empty($_POST['anchor']) ? '#' . preg_replace('/[^a-z]/', '', $_POST['anchor']) : '')); exit;
}
$flash = $_SESSION['bill_flash'] ?? null;
unset($_SESSION['bill_flash']);

$inv = findInvoice($db, $id);
if (!$inv) { http_response_code(404); $pageTitle = 'Invoice not found'; require __DIR__ . '/../includes/header.php'; echo '<div class="empty-state"><i class="bi bi-receipt"></i><div class="empty-state-title">' . t('Invoice not found') . '</div></div>'; require __DIR__ . '/../includes/footer.php'; exit; }
$p = findPatient($db, (int)$inv['patient_id']);
logPatientAccess($db, (int)$inv['patient_id'], 'invoice:' . $id);
$lines = invoiceLines($db, $id);
$draft = $inv['status'] === 'draft';
$edit = $draft && can('billing.invoice');
$pays = $db->prepare("SELECT r.id, r.number, r.received_at, r.status, a.amount, (SELECT GROUP_CONCAT(DISTINCT m.method) FROM receipt_methods m WHERE m.receipt_id = r.id) AS methods
                      FROM receipt_allocations a JOIN receipts r ON r.id = a.receipt_id WHERE a.invoice_id = ? ORDER BY r.received_at");
$pays->execute([$id]); $pays = $pays->fetchAll();
$cns = $db->prepare("SELECT * FROM credit_notes WHERE invoice_id = ? ORDER BY id"); $cns->execute([$id]); $cns = $cns->fetchAll();
$balance = invoiceBalance($inv);
[$stLabel, $stTone] = INVOICE_STATUS[$inv['status']];

$pageTitle = $draft ? 'Draft invoice' : $inv['number'];
$pageSubtitle = '<span class="chip ' . $stTone . '">' . $stLabel . '</span> · <a href="/pages/patient.php?id=' . (int)$p['id'] . '&amp;tab=billing">' . sanitize(patientName($p)) . '</a> · ' . sanitize($p['mrn'])
    . ' · ' . sanitize((string)$inv['branch_name']) . ($inv['issued_at'] ? ' · ' . date('d M Y', strtotime($inv['issued_at'])) : '')
    . ($inv['appointment_id'] ? ' · <a href="/pages/appointment.php?id=' . (int)$inv['appointment_id'] . '">' . t('visit') . '</a>' : '');
$pageBack = ['href' => '/pages/invoices.php', 'label' => 'Invoices'];
$ins = $inv['type'] === 'insurance';
$claim = $ins ? $db->query("SELECT id, number, status FROM claims WHERE invoice_id = $id")->fetch() : null;
if ($claim && can('insurance.view')) $pageSubtitle .= ' · <a href="/pages/claim.php?id=' . (int)$claim['id'] . '">claim ' . sanitize($claim['number']) . '</a> <span class="chip ' . CLAIM_STATUS[$claim['status']][1] . '">' . CLAIM_STATUS[$claim['status']][0] . '</span>';
$imp = $inv['imported'] ?? null;
$pageActions = (!$draft && !$imp ? '<a class="btn btn-ghost" href="/pages/doc_print.php?type=invoice&amp;id=' . $id . '" target="_blank"><i class="bi bi-printer"></i>' . t('Print') . '</a>' : '')
    . (!$draft && $inv['status'] !== 'void' && invoicePatientDue($inv) > 0 && can('billing.invoice') ? ' <a class="btn btn-gold" href="/pages/receipt_new.php?invoice=' . $id . '"><i class="bi bi-cash-coin"></i>' . t('Take payment') . '</a>' : '');
require_once __DIR__ . '/../includes/header.php';
$hidden = fn(string $a, string $anchor = '') => csrfField() . '<input type="hidden" name="action" value="' . $a . '">' . ($anchor ? '<input type="hidden" name="anchor" value="' . $anchor . '">' : '');
?>
<?php if ($flash): ?><div class="alert alert-<?= $flash[0] ?> <?= $flash[0] === 'success' ? 'alert-auto' : '' ?>"><i class="bi bi-<?= $flash[0] === 'success' ? 'check-circle' : 'exclamation-triangle' ?>"></i><div><strong><?= sanitize($flash[1]) ?></strong></div></div><?php endif; ?>
<?php if ($imp === 'history'): ?><div class="alert alert-info"><i class="bi bi-archive"></i><?= sanitize(t('Imported from ClinicSoft (invoice {n}): a record only. It was issued and reported for VAT in ClinicSoft, so it isn’t counted again here.', ['n' => (string)$inv['legacy_ref']])) ?></div>
<?php elseif ($imp === 'opening_balance'): ?><div class="alert alert-info"><i class="bi bi-archive"></i><?= t('Balance carried over from ClinicSoft. Collect it with Take payment. It is not a tax invoice and isn’t counted as revenue or VAT here.') ?></div><?php endif; ?>
<?php if ($inv['status'] === 'void'): ?><div class="alert alert-danger"><i class="bi bi-x-octagon"></i>Void on <?= formatDate($inv['voided_at']) ?>: <?= sanitize((string)$inv['void_reason']) ?></div><?php endif; ?>

<?php if ($edit): $cards = patientCards($db, (int)$inv['patient_id'], true); ?>
<form method="POST" class="card-crm mb-3"><?= $hidden('insurance') ?><div class="card-body-crm d-flex flex-wrap gap-2 align-items-center">
  <label class="fw-600" for="insCard"><?= t('Paid by') ?></label>
  <select class="form-select" style="max-width:420px" id="insCard" name="card_id"><option value=""><?= t('The patient (cash invoice)') ?></option>
    <?php foreach ($cards as $c): ?><option value="<?= (int)$c['id'] ?>" <?= (int)$inv['patient_insurance_id'] === (int)$c['id'] ? 'selected' : '' ?>>Insurance: <?= sanitize($c['company'] . ' – ' . $c['plan_name'] . ' · ' . $c['member_no']) ?></option><?php endforeach; ?></select>
  <button class="btn btn-ghost btn-sm"><?= t('Apply') ?></button>
  <?php if (!$cards): ?><span class="fs-12 text-tx-3"><?= t('No valid insurance card on file (patient’s Insurance tab).') ?></span><?php endif; ?>
</div></form>
<?php endif; ?>
<?php if ($ins && $edit && ($need = servicesNeedingApproval($db, (int)$inv['patient_id'], (int)$inv['insurance_plan_id'], array_filter(array_column($lines, 'service_id'))))): ?>
<div class="alert alert-warning"><i class="bi bi-shield-exclamation"></i><div><strong>The plan needs an approval for <?= sanitize(implode(', ', $need)) ?>.</strong>
  <?php if (can('insurance.manage')): ?><a href="/pages/prior_auths.php?patient=<?= (int)$inv['patient_id'] ?><?= $inv['appointment_id'] ? '&amp;appointment=' . (int)$inv['appointment_id'] : '' ?>"><?= t('Request it') ?></a>, or mark the line as not covered.<?php else: ?>Ask the insurance team, or mark the line as not covered.<?php endif; ?></div></div>
<?php endif; ?>
<div class="card-crm mb-3" id="lines">
  <div class="card-header-crm"><span><i class="bi bi-list-ul"></i> <?= t('Items') ?></span><span class="fs-12 text-tx-3 ms-auto"><?= $inv['vat_inclusive'] ? 'Prices include VAT' : 'VAT added to prices' ?></span></div>
  <div class="table-responsive"><table class="table mb-0 align-middle">
    <thead><tr><th><?= t('Item') ?></th><th class="text-end"><?= t('Qty') ?></th><th class="text-end"><?= t('Price') ?></th><th class="text-end"><?= t('Discount') ?></th><th class="text-end"><?= t('VAT') ?></th><th class="text-end"><?= t('Total') ?></th><?php if ($ins): ?><th class="text-end"><?= t('Patient') ?></th><th class="text-end"><?= t('Insurer') ?></th><?php endif; ?><?php if ($edit): ?><th></th><?php endif; ?></tr></thead>
    <tbody>
    <?php foreach ($lines as $l): ?>
      <tr><td><?= sanitize($l['description']) ?><?= $l['practitioner_name'] ? '<div class="fs-12 text-tx-3">' . sanitize($l['practitioner_name']) . '</div>' : '' ?><?= (float)$l['credited_qty'] ? '<div class="fs-12 text-danger">' . fmtQty($l['credited_qty']) . ' credited</div>' : '' ?></td>
        <td class="text-end tabular"><?= fmtQty($l['qty']) ?></td><td class="text-end tabular"><?= number_format($l['unit_price'], 2) ?></td>
        <td class="text-end tabular"><?php if ($edit && !$l['patient_package_id'] && (float)$l['unit_price'] > 0): ?>
          <form method="POST" class="d-inline-flex gap-1 justify-content-end"><?= $hidden('discount', 'lines') ?><input type="hidden" name="line_id" value="<?= (int)$l['id'] ?>">
            <label class="visually-hidden" for="dp<?= (int)$l['id'] ?>"><?= t('Discount %') ?></label><input class="form-control form-control-sm text-end" style="width:70px" id="dp<?= (int)$l['id'] ?>" name="discount_pct" type="number" min="0" max="100" step="0.01" placeholder="%">
            <label class="visually-hidden" for="da<?= (int)$l['id'] ?>"><?= t('Discount amount') ?></label><input class="form-control form-control-sm text-end" style="width:90px" id="da<?= (int)$l['id'] ?>" name="discount" type="number" min="0" step="0.01" value="<?= (float)$l['discount'] ? $l['discount'] : '' ?>" placeholder="AED">
            <button class="btn btn-ghost btn-sm" aria-label="<?= t('Apply discount') ?>"><i class="bi bi-check-lg"></i></button></form>
          <?php else: ?><?= (float)$l['discount'] ? number_format($l['discount'], 2) : '–' ?><?php endif; ?></td>
        <td class="text-end tabular"><?= number_format($l['vat_amount'], 2) ?><div class="fs-12 text-tx-3"><?= rtrim(rtrim($l['vat_rate'], '0'), '.') ?>%</div></td>
        <td class="text-end tabular fw-600"><?= number_format($l['line_total'], 2) ?></td>
        <?php if ($ins): ?><td class="text-end tabular"><?= number_format($l['patient_share'], 2) ?><?php if ($edit): ?>
          <form method="POST" class="mt-1"><?= $hidden('covered', 'lines') ?><input type="hidden" name="line_id" value="<?= (int)$l['id'] ?>"><input type="hidden" name="covered" value="<?= $l['covered'] ? '' : '1' ?>">
            <button class="btn btn-ghost btn-sm py-0 fs-12"><?= $l['covered'] ? 'Not covered' : 'Covered' ?></button></form><?php elseif (!$l['covered']): ?><div class="fs-12 text-tx-3"><?= t('not covered') ?></div><?php endif; ?></td>
          <td class="text-end tabular"><?= number_format($l['insurance_share'], 2) ?></td><?php endif; ?>
        <?php if ($edit): ?><td><form method="POST"><?= $hidden('remove_line', 'lines') ?><input type="hidden" name="line_id" value="<?= (int)$l['id'] ?>"><button class="btn btn-ghost btn-sm" aria-label="Remove <?= sanitize($l['description']) ?>"><i class="bi bi-trash"></i></button></form></td><?php endif; ?></tr>
    <?php endforeach; ?>
    <?php if (!$lines): ?><tr><td colspan="7" class="text-tx-3"><?= t('No items yet.') ?></td></tr><?php endif; ?>
    </tbody>
  </table></div>
  <div class="card-body-crm"><div class="ms-auto" style="max-width:320px">
    <div class="d-flex"><span><?= t('Subtotal') ?></span><span class="ms-auto tabular"><?= number_format($inv['gross'], 2) ?></span></div>
    <?php if ((float)$inv['discount']): ?><div class="d-flex"><span><?= t('Discount') ?></span><span class="ms-auto tabular">−<?= number_format($inv['discount'], 2) ?></span></div><?php endif; ?>
    <div class="d-flex"><span><?= t('Taxable amount') ?></span><span class="ms-auto tabular"><?= number_format($inv['net'], 2) ?></span></div>
    <div class="d-flex"><span><?= t('VAT') ?></span><span class="ms-auto tabular"><?= number_format($inv['vat'], 2) ?></span></div>
    <div class="d-flex fw-700 fs-5 border-top mt-1 pt-1"><span><?= t('Total') ?></span><span class="ms-auto tabular"><?= money($inv['total']) ?></span></div>
    <?php if ($ins): ?><div class="d-flex"><span><?= t('Patient pays') ?></span><span class="ms-auto tabular fw-600"><?= number_format($inv['patient_share'], 2) ?></span></div>
      <div class="d-flex"><span><?= t('Insurer pays') ?></span><span class="ms-auto tabular"><?= number_format($inv['insurance_share'], 2) ?></span></div><?php endif; ?>
    <?php if (!$draft): ?>
      <?php if ((float)$inv['credited']): ?><div class="d-flex text-tx-2"><span><?= t('Credited') ?></span><span class="ms-auto tabular">−<?= number_format($inv['credited'], 2) ?></span></div><?php endif; ?>
      <div class="d-flex text-tx-2"><span><?= t('Paid') ?></span><span class="ms-auto tabular"><?= number_format((float)$inv['paid'] - (float)$inv['refunded'], 2) ?></span></div>
      <div class="d-flex fw-600"><span><?= $balance < 0 ? 'Overpaid' : 'Balance due' ?></span><span class="ms-auto tabular"><?= money(abs($balance) / 100) ?></span></div>
      <?php if ($ins && $balance > 0): ?><div class="d-flex fs-13 text-tx-2"><span><?= t('of which the patient owes') ?></span><span class="ms-auto tabular"><?= money(invoicePatientDue($inv) / 100) ?></span></div><?php endif; ?>
    <?php endif; ?>
  </div></div>
</div>

<?php if ($edit):
  $services = $db->query("SELECT id, name, price FROM services WHERE is_active = 1 ORDER BY name")->fetchAll();
  $packages = packageList($db);
  $products = $db->query("SELECT id, name, brand, sell_price FROM products WHERE is_active = 1 AND sell_price IS NOT NULL ORDER BY name")->fetchAll();
  $pracs = practitionerList($db);
  $rules = activeDiscountRules($db);
  $myPkgs = patientPackages($db, (int)$inv['patient_id'], true); ?>
<div class="row g-3 mb-3">
  <div class="col-lg-8"><div class="card-crm"><div class="card-header-crm"><span><i class="bi bi-plus-lg"></i> <?= t('Add an item') ?></span></div><div class="card-body-crm">
    <form method="POST" class="row g-2 align-items-end mb-3"><?= $hidden('add_line', 'lines') ?><input type="hidden" name="kind" value="service">
      <div class="col-md-5"><label class="form-label" for="aSvc"><?= t('Treatment') ?></label><select class="form-select" id="aSvc" name="service_id" required><option value=""><?= t('Choose…') ?></option><?php foreach ($services as $s): ?><option value="<?= (int)$s['id'] ?>"><?= sanitize($s['name']) ?> · <?= number_format($s['price'], 2) ?></option><?php endforeach; ?></select></div>
      <div class="col-md-3"><label class="form-label" for="aPr"><?= t('Practitioner') ?></label><select class="form-select" id="aPr" name="practitioner_id"><option value="">—</option><?php foreach ($pracs as $pr): ?><option value="<?= (int)$pr['id'] ?>"><?= sanitize($pr['name']) ?></option><?php endforeach; ?></select></div>
      <div class="col-4 col-md-1"><label class="form-label" for="aQty"><?= t('Qty') ?></label><input class="form-control" id="aQty" name="qty" type="number" min="0.01" step="0.01" value="1"></div>
      <?php if ($myPkgs): ?><div class="col-8 col-md-3"><label class="form-label" for="aPk"><?= t('From package') ?></label><select class="form-select" id="aPk" name="patient_package_id"><option value=""><?= t('No') ?></option><?php foreach ($myPkgs as $mp): ?><option value="<?= (int)$mp['id'] ?>"><?= sanitize($mp['name']) ?></option><?php endforeach; ?></select></div><?php endif; ?>
      <div class="col-auto"><button class="btn btn-gold"><i class="bi bi-plus-lg"></i><?= t('Add') ?></button></div>
    </form>
    <?php if ($packages): ?><form method="POST" class="row g-2 align-items-end mb-3"><?= $hidden('add_line', 'lines') ?><input type="hidden" name="kind" value="package">
      <div class="col-md-8"><label class="form-label" for="aPkg"><?= t('Sell a package') ?></label><select class="form-select" id="aPkg" name="package_id" required><?php foreach ($packages as $pk): ?><option value="<?= (int)$pk['id'] ?>"><?= sanitize($pk['name']) ?> · <?= number_format($pk['price'], 2) ?> (<?= sanitize((string)$pk['contents']) ?>)</option><?php endforeach; ?></select></div>
      <div class="col-auto"><button class="btn btn-ghost"><i class="bi bi-plus-lg"></i><?= t('Add package') ?></button></div></form><?php endif; ?>
    <?php if ($products): ?><form method="POST" class="row g-2 align-items-end mb-3"><?= $hidden('add_line', 'lines') ?><input type="hidden" name="kind" value="product">
      <div class="col-md-7"><label class="form-label" for="aProd"><?= t('Sell a product') ?></label><select class="form-select" id="aProd" name="product_id" required><?php foreach ($products as $pd): ?><option value="<?= (int)$pd['id'] ?>"><?= sanitize($pd['name'] . ($pd['brand'] ? ' (' . $pd['brand'] . ')' : '')) ?> · <?= number_format($pd['sell_price'], 2) ?></option><?php endforeach; ?></select></div>
      <div class="col-4 col-md-2"><label class="form-label" for="aPq"><?= t('Qty') ?></label><input class="form-control" id="aPq" name="qty" type="number" min="1" step="1" value="1"></div>
      <div class="col-auto"><button class="btn btn-ghost"><i class="bi bi-plus-lg"></i><?= t('Add product') ?></button></div></form><?php endif; ?>
    <form method="POST" class="row g-2 align-items-end"><?= $hidden('add_line', 'lines') ?><input type="hidden" name="kind" value="manual">
      <div class="col-md-5"><label class="form-label" for="aDesc"><?= t('Other item') ?></label><input class="form-control" id="aDesc" name="description" maxlength="200" required></div>
      <div class="col-4 col-md-2"><label class="form-label" for="aUp"><?= t('Price') ?></label><input class="form-control" id="aUp" name="unit_price" type="number" min="0" step="0.01" required></div>
      <div class="col-4 col-md-2"><label class="form-label" for="aVat"><?= t('VAT') ?></label><select class="form-select" id="aVat" name="vat_rate"><option value="5">5%</option><option value="0">0%</option></select></div>
      <div class="col-auto"><button class="btn btn-ghost"><i class="bi bi-plus-lg"></i><?= t('Add') ?></button></div></form>
  </div></div></div>
  <div class="col-lg-4">
    <?php if ($rules && $lines): ?><form method="POST" class="card-crm mb-3"><?= $hidden('discount', 'lines') ?>
      <div class="card-header-crm"><span><i class="bi bi-tag"></i> <?= t('Apply a discount offer') ?></span></div><div class="card-body-crm">
        <label class="form-label" for="rLine"><?= t('Item') ?></label><select class="form-select mb-2" id="rLine" name="line_id"><?php foreach ($lines as $l): ?><option value="<?= (int)$l['id'] ?>"><?= sanitize($l['description']) ?></option><?php endforeach; ?></select>
        <label class="form-label" for="rRule"><?= t('Offer') ?></label><select class="form-select" id="rRule" name="discount_rule_id"><?php foreach ($rules as $r): ?><option value="<?= (int)$r['id'] ?>"><?= sanitize($r['name']) ?> (<?= $r['kind'] === 'percent' ? rtrim(rtrim($r['value'], '0'), '.') . '%' : money($r['value']) ?>)</option><?php endforeach; ?></select></div>
      <div class="form-actions"><button class="btn btn-ghost ms-auto"><?= t('Apply') ?></button></div></form><?php endif; ?>
    <div class="card-crm"><div class="card-body-crm">
      <p class="fs-13 text-tx-3">Your discount limit: <?= rtrim(rtrim(number_format(discountLimitPct($db), 2), '0'), '.') ?>% of an item.</p>
      <form method="POST" onsubmit="return confirm('Issue this invoice? It can’t be changed afterwards.')"><?= $hidden('issue') ?><button class="btn btn-gold w-100" <?= $lines ? '' : 'disabled' ?>><i class="bi bi-send-check"></i><?= t('Issue invoice') ?></button></form>
      <form method="POST" class="mt-2" onsubmit="return confirm('Delete this draft?')"><?= $hidden('delete_draft') ?><button class="btn btn-ghost btn-sm w-100"><?= t('Delete draft') ?></button></form>
    </div></div>
  </div>
</div>
<?php endif; ?>

<?php if (!$draft): ?>
<div class="row g-3">
  <div class="col-lg-6"><div class="card-crm"><div class="card-header-crm"><span><i class="bi bi-cash-coin"></i> <?= t('Payments') ?></span></div><div class="list-group list-group-flush">
    <?php foreach ($pays as $r): ?><a class="list-group-item list-group-item-action d-flex gap-2 <?= $r['status'] === 'void' ? 'text-decoration-line-through text-tx-3' : '' ?>" href="/pages/receipt.php?id=<?= (int)$r['id'] ?>">
      <span><?= sanitize($r['number']) ?><span class="d-block fs-12 text-tx-3"><?= formatDate($r['received_at']) ?> · <?= sanitize(implode(', ', array_map(fn($m) => PAY_METHODS[$m] ?? $m, explode(',', (string)$r['methods'])))) ?></span></span>
      <span class="ms-auto tabular"><?= number_format($r['amount'], 2) ?></span></a><?php endforeach; ?>
    <?php foreach ($cns as $c): ?><a class="list-group-item list-group-item-action d-flex gap-2" href="/pages/doc_print.php?type=credit_note&amp;id=<?= (int)$c['id'] ?>" target="_blank">
      <span>Credit note <?= sanitize($c['number']) ?><span class="d-block fs-12 text-tx-3"><?= formatDate($c['issued_at']) ?> · <?= sanitize($c['reason']) ?><?= (float)$c['refunded'] ? ' · ' . ($c['refund_method'] === 'wallet' ? 'kept as balance' : 'refunded ' . (REFUND_METHODS[$c['refund_method']] ?? '')) . ' ' . number_format($c['refunded'], 2) : '' ?></span></span>
      <span class="ms-auto tabular text-danger">−<?= number_format($c['total'], 2) ?></span></a><?php endforeach; ?>
    <?php if (!$pays && !$cns): ?><div class="list-group-item text-tx-3 fs-13"><?= t('No payments yet.') ?></div><?php endif; ?>
  </div></div></div>
  <div class="col-lg-6">
    <?php if ($inv['status'] !== 'void' && !$imp && can('billing.refund')): $creditable = array_filter($lines, fn($l) => (float)$l['qty'] - (float)$l['credited_qty'] > 0.001); if ($creditable): ?>
    <details class="card-crm mb-3"><summary class="card-header-crm"><span><i class="bi bi-arrow-counterclockwise"></i> <?= t('Credit note (refund or correction)') ?></span></summary>
      <form method="POST" class="card-body-crm"><?= $hidden('credit') ?>
        <?php foreach ($creditable as $l): $left = (float)$l['qty'] - (float)$l['credited_qty']; ?>
        <div class="d-flex align-items-center gap-2 mb-2"><label class="flex-grow-1" for="cq<?= (int)$l['id'] ?>"><?= sanitize($l['description']) ?> <span class="fs-12 text-tx-3">(up to <?= fmtQty($left) ?>)</span></label>
          <input class="form-control form-control-sm text-end" style="width:90px" type="number" id="cq<?= (int)$l['id'] ?>" name="qty[<?= (int)$l['id'] ?>]" min="0" max="<?= $left ?>" step="0.01" value="0"></div>
        <?php endforeach; ?>
        <label class="form-label mt-2" for="cReason"><?= t('Reason') ?></label><input class="form-control mb-2" id="cReason" name="reason" maxlength="255" required>
        <label class="form-label" for="cSettle"><?= t('Money already paid') ?></label><select class="form-select" id="cSettle" name="settle">
          <option value="wallet"><?= t('Keep as the patient’s balance') ?></option><?php foreach (REFUND_METHODS as $k => $lbl): ?><option value="<?= $k ?>">Refund: <?= $lbl ?></option><?php endforeach; ?></select>
        <div class="fs-12 text-tx-3 mt-1"><?= t('Only money paid beyond what is still owed is refunded.') ?></div>
        <button class="btn btn-gold mt-3" onclick="return confirm('Issue the credit note?')"><?= t('Issue credit note') ?></button>
      </form></details>
    <?php endif; endif; ?>
    <?php if ($inv['status'] !== 'void' && $imp !== 'history' && can('billing.void') && !(float)$inv['paid'] && !(float)$inv['credited']): ?>
    <details class="card-crm"><summary class="card-header-crm"><span><i class="bi bi-x-octagon"></i> <?= t('Void invoice') ?></span></summary>
      <form method="POST" class="card-body-crm d-flex gap-2"><?= $hidden('void') ?><label class="visually-hidden" for="vReason"><?= t('Reason') ?></label>
        <input class="form-control" id="vReason" name="reason" placeholder="<?= t('Why it is void') ?>" maxlength="255" required><button class="btn btn-ghost" onclick="return confirm('Void this invoice? Its number stays used.')"><?= t('Void') ?></button></form></details>
    <?php endif; ?>
  </div>
</div>
<?php endif; ?>
<?php require_once __DIR__ . '/../includes/footer.php'; ?>

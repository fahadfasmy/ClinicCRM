<?php
// pages/reports_finance.php — Insights → Finance reports (reports.finance): daily collection, day closes, the VAT return
// (FTA VAT 201 boxes) with the purchase VAT register for input VAT, the invoice register, what patients and insurers
// still owe, credit notes, and package and voucher liability. Commission and treatment margin keep their own pages.
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/reports.php';
require_once __DIR__ . '/../includes/report_ui.php';
require_once __DIR__ . '/../includes/insurance.php';
requirePermission('reports.finance');

$db = getDB();
$tabs = ['collection' => 'Daily collection', 'dayclose' => 'Day closes', 'vat' => 'VAT return', 'register' => 'Invoice register', 'receivables' => 'Receivables',
         'credit' => 'Credit notes', 'liability' => 'Packages & vouchers'];
$r = isset($tabs[$_GET['r'] ?? '']) ? $_GET['r'] : 'collection';
$_GET['r'] = $r;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCsrf();
    try {
        if (($_POST['action'] ?? '') === 'purchase_vat') { savePurchaseVat($db, $_POST); $_SESSION['rpt_flash'] = ['success', 'Supplier invoice added.']; }
        elseif (($_POST['action'] ?? '') === 'purchase_vat_delete') {
            $id = (int)$_POST['id'];
            $row = $db->query("SELECT supplier, invoice_ref, net, vat FROM purchase_vat WHERE id = $id")->fetch();
            if ($row) { $db->exec("DELETE FROM purchase_vat WHERE id = $id"); auditLog($db, 'purchase_vat', $id, 'deleted', $row); }
            $_SESSION['rpt_flash'] = ['success', 'Removed.'];
        }
    } catch (RuntimeException $e) { $_SESSION['rpt_flash'] = ['danger', $e->getMessage()]; }
    header('Location: /pages/reports_finance.php?' . http_build_query(['r' => 'vat', 'from' => $_POST['from'] ?? '', 'to' => $_POST['to'] ?? ''])); exit;
}

$f = rptFilters();
[$from, $to, $br] = [$f['from'], $f['to'], $f['branch']];
$opt = ['tabs' => $tabs, 'after' => '<p class="fs-13 text-tx-3">Also: <a href="/pages/reports_commission.php">Commission</a> · <a href="/pages/day_close.php">Close the day</a>'
        . (can('reports.margin') ? ' · <a href="/pages/stock_reports.php">Treatment margin</a>' : '') . '</p>'];
$flash = $_SESSION['rpt_flash'] ?? null; unset($_SESSION['rpt_flash']);
if ($flash) $opt['before'] = '<div class="alert alert-' . $flash[0] . '" role="' . ($flash[0] === 'danger' ? 'alert' : 'status') . '">' . sanitize($flash[1]) . '</div>';
$money = fn(string $l) => [$l, 'money'];
$sections = [];

switch ($r) {
    case 'collection':
        $cols = ['day' => ['Date', 'date']];
        $daily = rptDailyCollection($db, $from, $to, $br);
        foreach (RPT_METHOD_LABELS as $k => $l) if (array_filter(array_column($daily, $k))) $cols[$k] = $money($l);
        $cols['total'] = $money('Total');
        $sections[] = rptSection('Money received per day', $cols, $daily, ['total' => true, 'chart' => ['type' => 'bar', 'label' => 'day', 'values' => 'total'],
            'note' => 'Valid receipts by receipt date. Wallet, voucher and points are payments from money taken earlier; insurance is company remittances.']);
        $sections[] = rptSection('By payment method', ['label' => ['Method', 'text'], 'receipts' => ['Receipts', 'int'], 'amount' => $money('AED')], rptCollectionsByMethod($db, $from, $to, $br), ['total' => true, 'no_total' => ['receipts']]);
        break;
    case 'dayclose':
        $sections[] = rptSection('Day closes', ['business_date' => ['Day', 'date'], 'branch' => ['Branch', 'text'], 'cashier' => ['Closed by', 'text'], 'difference' => $money('Difference (counted − expected)'),
            'note' => ['Reason', 'text'], 'approved_by' => ['Approved by', 'text'], 'closed_at' => ['Closed at', 'datetime']], rptDayCloses($db, $from, $to, $br),
            ['total' => true]);
        break;
    case 'vat':
        $emirate = in_array($_GET['emirate'] ?? '', UAE_EMIRATES, true) ? $_GET['emirate'] : 'Dubai';
        $v = rptVatReturn($db, $from, $to, $br);
        $sections[] = rptSection('VAT return (VAT 201)', ['box' => ['Box', 'text'], 'item' => ['', 'text'], 'amount' => $money('Amount (AED)'), 'vat' => $money('VAT (AED)'),
            'adjustment' => $money('Adjustment (AED)'), 'adj_vat' => $money('VAT adjustment (AED)')], rptVatRows($v, $emirate),
            ['note' => 'Sales: issued invoices by issue date; adjustments: credit notes issued in the period. Input VAT: the supplier invoices below. Check with your accountant before filing.']);
        $sections[] = rptSection('Purchase VAT register', ['invoice_date' => ['Invoice date', 'date'], 'supplier' => ['Supplier', 'text'], 'supplier_trn' => ['TRN', 'text'], 'invoice_ref' => ['Invoice no.', 'text'],
            'net' => $money('Before VAT'), 'vat' => $money('VAT'), 'note' => ['Note', 'text'], 'entered_by' => ['Entered by', 'text']], rptPurchaseVat($db, $from, $to), ['total' => true, 'empty' => 'No supplier invoices entered for this period.']);
        $opt['extra_filters'] = '<div><label class="form-label" for="re">Emirate</label><select class="form-select" id="re" name="emirate">'
            . implode('', array_map(fn($e) => '<option' . ($e === $emirate ? ' selected' : '') . '>' . $e . '</option>', UAE_EMIRATES)) . '</select></div>';
        $rows = rptPurchaseVat($db, $from, $to);
        ob_start(); ?>
<details class="card-crm mb-3"><summary class="card-header-crm" style="cursor:pointer"><span><i class="bi bi-plus-lg"></i> Add a supplier tax invoice</span></summary>
  <form method="POST" class="card-body-crm"><?= csrfField() ?><input type="hidden" name="action" value="purchase_vat"><input type="hidden" name="from" value="<?= $from ?>"><input type="hidden" name="to" value="<?= $to ?>">
    <div class="row g-2">
      <div class="col-6 col-md-2"><label class="form-label" for="pvD">Invoice date</label><input class="form-control" type="date" id="pvD" name="invoice_date" required max="<?= date('Y-m-d') ?>"></div>
      <div class="col-6 col-md-3"><label class="form-label" for="pvS">Supplier</label><input class="form-control" id="pvS" name="supplier" required maxlength="150"></div>
      <div class="col-6 col-md-2"><label class="form-label" for="pvT">Supplier TRN</label><input class="form-control" id="pvT" name="supplier_trn" inputmode="numeric" maxlength="15"></div>
      <div class="col-6 col-md-2"><label class="form-label" for="pvR">Their invoice no.</label><input class="form-control" id="pvR" name="invoice_ref" required maxlength="60"></div>
      <div class="col-6 col-md-1"><label class="form-label" for="pvN">Before VAT</label><input class="form-control" type="number" step="0.01" min="0.01" id="pvN" name="net" required></div>
      <div class="col-6 col-md-1"><label class="form-label" for="pvV">VAT</label><input class="form-control" type="number" step="0.01" min="0" id="pvV" name="vat" required></div>
      <div class="col-12 col-md-1 d-flex align-items-end"><button class="btn btn-gold w-100">Add</button></div>
      <div class="col-12"><label class="form-label" for="pvO">Note</label><input class="form-control" id="pvO" name="note" maxlength="255" placeholder="e.g. Rent, consumables, equipment"></div>
    </div></form></details>
<?php if ($rows): ?><details class="mb-3"><summary class="fs-13 text-tx-3">Remove an entry made by mistake</summary><div class="d-flex flex-wrap gap-2 mt-2"><?php foreach ($rows as $pv): ?>
  <form method="POST" class="d-inline"><?= csrfField() ?><input type="hidden" name="action" value="purchase_vat_delete"><input type="hidden" name="id" value="<?= (int)$pv['id'] ?>"><input type="hidden" name="from" value="<?= $from ?>"><input type="hidden" name="to" value="<?= $to ?>">
    <button class="btn btn-ghost btn-sm" onclick="return confirm('Remove this supplier invoice?')"><i class="bi bi-x"></i><?= sanitize($pv['supplier'] . ' ' . $pv['invoice_ref']) ?></button></form><?php endforeach; ?></div></details><?php endif;
        $opt['after'] = ob_get_clean() . $opt['after'];
        $opt['tiles'] = [['Output VAT', money($v['box12'])], ['Input VAT', money($v['box13'])], [$v['box14'] >= 0 ? 'VAT payable' : 'VAT to reclaim', money(abs($v['box14']))]];
        break;
    case 'register':
        $sections[] = rptSection('Invoice register', ['number' => ['Invoice', 'text'], 'issued_at' => ['Issued', 'datetime'], 'patient' => ['Patient', 'text'], 'mrn' => ['MRN', 'text'], 'type' => ['Type', 'text'],
            'net' => $money('Before VAT'), 'vat' => $money('VAT'), 'total' => $money('Total'), 'paid' => $money('Paid'), 'credited' => $money('Credited'), 'balance' => $money('Balance'), 'status' => ['Status', 'text']],
            rptInvoiceRegister($db, $from, $to, $br), ['total' => true, 'links' => ['number' => ['/pages/invoice.php?id=', 'id']], 'note' => 'Every issued invoice in the period, voided ones included (their status shows it).']);
        break;
    case 'receivables':
        $rows = rptPatientReceivables($db, $br);
        $buckets = [];
        foreach (['0-30', '31-60', '61-90', '90+'] as $b) $buckets[] = ['bucket' => "$b days", 'n' => count(array_filter($rows, fn($x) => $x['bucket'] === $b)), 'due' => array_sum(array_map(fn($x) => $x['bucket'] === $b ? $x['due'] : 0, $rows))];
        $sections[] = rptSection('Patients: owed by age', ['bucket' => ['Since invoice', 'text'], 'n' => ['Invoices', 'int'], 'due' => $money('Owed (AED)')], $buckets, ['total' => true, 'half' => true]);
        $sections[] = rptSection('Insurance companies: outstanding claims', ['name' => ['Company', 'text'], 'claims' => ['Claims', 'int'], 'claimed' => $money('Claimed'), 'paid' => $money('Paid'), 'outstanding' => $money('Outstanding'),
            'oldest' => ['Oldest sent', 'date']], insuranceOutstanding($db), ['total' => true, 'half' => true]);
        $sections[] = rptSection('Patients: invoices with money owed', ['number' => ['Invoice', 'text'], 'issued_at' => ['Issued', 'date'], 'patient' => ['Patient', 'text'], 'mrn' => ['MRN', 'text'],
            'age' => ['Days', 'int'], 'due' => $money('Owed (AED)')], $rows, ['total' => true, 'no_total' => ['age'], 'links' => ['number' => ['/pages/invoice.php?id=', 'id']]]);
        $opt['no_period'] = true;
        break;
    case 'credit':
        $sections[] = rptSection('Credit notes', ['number' => ['Credit note', 'text'], 'issued_at' => ['Issued', 'datetime'], 'invoice' => ['Invoice', 'text'], 'patient' => ['Patient', 'text'], 'reason' => ['Reason', 'text'],
            'gross' => $money('Before VAT'), 'vat' => $money('VAT'), 'total' => $money('Total'), 'refund_method' => ['Refund', 'text'], 'refunded' => $money('Refunded')], rptCreditNotes($db, $from, $to, $br), ['total' => true]);
        break;
    case 'liability':
        $pk = packageLiability($db);
        $sections[] = rptSection('Package liability (sessions paid for, not used)', ['name' => ['Package', 'text'], 'patient_id' => ['Patient', 'int'], 'expires_on' => ['Expires', 'date'], 'total' => ['Sessions', 'int'],
            'used' => ['Used', 'int'], 'value' => $money('Paid'), 'unused_value' => $money('Unused value')], $pk, ['total' => true, 'no_total' => ['patient_id', 'total', 'used'],
            'links' => ['patient_id' => ['/pages/patient.php?id=', 'patient_id']]]);
        $sections[] = rptSection('Voucher liability (gift vouchers not spent)', ['code' => ['Voucher', 'text'], 'recipient' => ['For', 'text'], 'expires_on' => ['Expires', 'date'], 'initial' => $money('Value'),
            'used' => $money('Used'), 'balance' => $money('Left')], rptVoucherLiability($db), ['total' => true]);
        $opt['no_period'] = true;
        $opt['tiles'] = [['Unused package value', money(array_sum(array_column($pk, 'unused_value')))], ['Unspent vouchers', money(aed(voucherLiability($db)))]];
        break;
}
rptOutput('Finance reports · ' . $tabs[$r], $f, $sections, $opt);

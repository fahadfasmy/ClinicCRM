<?php
// Phase 7 money tests: VAT maths (exclusive and inclusive, half-up per line), discount limits, issuing and numbering
// (two processes), receipts split across methods and invoices, the wallet as a ledger, credit notes, voids, packages,
// vouchers, commission, and a scripted clinic day of 20 visits that must close balanced.
// Scratch database clinic_crm_test_billing.
require_once __DIR__ . '/../includes/billing.php';

function billDb(bool $fresh = false): PDO {
    static $done = false;
    if ($fresh) $done = false;
    $db = testDb('clinic_crm_test_billing', !$done);
    if (!$done) {
        migrateApply($db, __DIR__ . '/../migrations/tenant', fn($l) => null);
        $db->exec("UPDATE branches SET invoice_prefix = 'TST', trn = '100000000000003' WHERE id = 1");
        $db->exec("INSERT INTO staff (id, name, email, password, role) VALUES (501, 'Rita Front', 'rita.b@example.test', 'x', 'receptionist'), (502, 'Dr Bill', 'dr.b@example.test', 'x', 'practitioner'),
                   (503, 'Fay Finance', 'fay.b@example.test', 'x', 'finance'), (504, 'Ann Admin', 'ann.b@example.test', 'x', 'admin')");
        $db->exec("INSERT INTO services (id, name, price, vat_rate, duration_min) VALUES (21, 'Botox', 1200, 5, 30), (22, 'Facial', 350, 5, 60), (23, 'Consultation', 33.33, 5, 15), (24, 'Medical report', 100, 0, 15)");
        $db->exec("INSERT INTO patients (id, mrn, first_name, last_name, status, branch_id) VALUES (1, 'B-1', 'Hana', 'Bill', 'active', 1), (2, 'B-2', 'Sami', 'Pay', 'active', 1)");
        $done = true;
    }
    tenantSetting('__reload');
    as_(501, 'receptionist');
    return $db;
}
function as_(int $id, string $role): void { $_SESSION['staff_id'] = $id; $_SESSION['role'] = $role; staffPermissionOverrides($id, true); }
function setting(PDO $db, string $k, string $v): void { $db->prepare("REPLACE INTO integration_settings (k, v) VALUES (?, ?)")->execute([$k, $v]); tenantSetting('__reload'); }
function inv(PDO $db, int $id): array { return findInvoice($db, $id); }
function newInvoice(PDO $db, int $pid, array $lines): int {
    $id = createInvoice($db, $pid, 1);
    foreach ($lines as $l) addInvoiceLine($db, $id, $l);
    return $id;
}

function test_vat_maths() {
    check(lineAmounts(1, 120000, 0, 5, false) === [120000, 0, 120000, 6000, 126000], 'exclusive: 1,200 + 5% = 1,260');
    check(lineAmounts(1, 120000, 0, 5, true) === [120000, 0, 114286, 5714, 120000], 'inclusive: 1,200 holds 57.14 VAT on 1,142.86');
    check(lineAmounts(3, 3333, 0, 5, false) === [9999, 0, 9999, 500, 10499], '3 × 33.33: VAT 4.9995 rounds half-up to 5.00');
    check(lineAmounts(1, 10, 0, 5, false)[3] === 1 && lineAmounts(1, 9, 0, 5, false)[3] === 0, '0.10 → VAT 0.005 rounds up to 0.01; 0.09 → 0.0045 rounds to 0');
    check(lineAmounts(2, 35000, 7000, 5, false) === [70000, 7000, 63000, 3150, 66150], 'discount comes off before VAT');
    check(lineAmounts(1, 10000, 0, 0, false) === [10000, 0, 10000, 0, 10000], 'zero-rated line has no VAT');
    check(lineAmounts(1, 5000, 9000, 5, false)[1] === 5000, 'a discount can’t exceed the line');
    check(fils('1.005') === 101 && fils(0.1 + 0.2) === 30 && fils('1200') === 120000, 'amounts become whole fils without float drift');
}

function test_invoice_totals_are_the_sum_of_the_lines() {
    $db = billDb(true);
    setting($db, 'billing.prices_include_vat', '0');
    $id = newInvoice($db, 1, [['kind' => 'service', 'service_id' => 21], ['kind' => 'service', 'service_id' => 23, 'qty' => 3], ['kind' => 'service', 'service_id' => 24]]);
    $i = inv($db, $id);
    check($i['gross'] === '1399.99' && $i['vat'] === '65.00' && $i['total'] === '1464.99' && $i['net'] === '1399.99', "totals: gross {$i['gross']}, VAT {$i['vat']}, total {$i['total']}");
    $sum = $db->query("SELECT SUM(line_total), SUM(vat_amount) FROM invoice_lines WHERE invoice_id = $id")->fetch(PDO::FETCH_NUM);
    check($sum[0] === $i['total'] && $sum[1] === $i['vat'], 'the invoice equals the sum of its lines');
    setting($db, 'billing.prices_include_vat', '1');
    $j = newInvoice($db, 1, [['kind' => 'service', 'service_id' => 21]]);
    check(inv($db, $j)['total'] === '1200.00' && inv($db, $j)['vat'] === '57.14' && (int)inv($db, $j)['vat_inclusive'] === 1, 'inclusive clinic: the patient pays the list price');
    setting($db, 'billing.prices_include_vat', '0');
    check((int)inv($db, $j)['vat_inclusive'] === 1, 'changing the setting later doesn’t change an existing invoice');
    checkThrows(fn() => addInvoiceLine($db, $id, ['kind' => 'manual', 'description' => 'X', 'unit_price' => 10, 'vat_rate' => 7]), '5% or 0%', 'only 5% or 0% VAT');
}

function test_discount_limits() {
    $db = billDb();
    $id = newInvoice($db, 1, [['kind' => 'service', 'service_id' => 22]]);
    $line = (int)$db->query("SELECT id FROM invoice_lines WHERE invoice_id = $id")->fetchColumn();
    setLineDiscount($db, $line, ['discount_pct' => 10]);
    check(inv($db, $id)['discount'] === '35.00', 'reception may give 10%');
    checkThrows(fn() => setLineDiscount($db, $line, ['discount_pct' => 15]), 'discount limit of 10%', 'reception can’t give 15%');
    setting($db, 'billing.discount_limit.receptionist', '20');
    setLineDiscount($db, $line, ['discount' => 70]);
    check(inv($db, $id)['discount'] === '70.00', 'the limit is a clinic setting (now 20%)');
    $db->exec("INSERT INTO discount_rules (id, name, kind, value, max_amount, service_ids) VALUES (1, 'Ramadan', 'percent', 50, 100, '[22]'), (2, 'Old', 'amount', 10, NULL, NULL)");
    $db->exec("UPDATE discount_rules SET valid_to = CURDATE() - INTERVAL 1 DAY WHERE id = 2");
    setLineDiscount($db, $line, ['discount_rule_id' => 1]);
    check(inv($db, $id)['discount'] === '100.00', 'a rule is pre-approved: 50% capped at 100');
    checkThrows(fn() => setLineDiscount($db, $line, ['discount_rule_id' => 2]), 'isn’t available', 'an expired rule is refused');
    $b = newInvoice($db, 1, [['kind' => 'service', 'service_id' => 21]]);
    checkThrows(fn() => setLineDiscount($db, (int)$db->query("SELECT id FROM invoice_lines WHERE invoice_id = $b")->fetchColumn(), ['discount_rule_id' => 1]), 'doesn’t apply', 'a rule only for some services');
    as_(504, 'admin');
    setLineDiscount($db, $line, ['discount_pct' => 100]);
    check(inv($db, $id)['total'] === '0.00', 'admin has no limit');
    setting($db, 'billing.discount_limit.receptionist', '10');
}

function test_issue_numbers_and_immutability() {
    $db = billDb();
    $y = (new DateTimeImmutable('now', new DateTimeZone(CLINIC_TZ)))->format('Y');
    $a = newInvoice($db, 1, [['kind' => 'service', 'service_id' => 22]]);
    $b = newInvoice($db, 2, [['kind' => 'service', 'service_id' => 22]]);
    checkThrows(fn() => issueInvoice($db, createInvoice($db, 1, 1)), 'at least one', 'an empty invoice can’t be issued');
    $na = issueInvoice($db, $a); $nb = issueInvoice($db, $b);
    check(preg_match("/^TST-INV-$y-(\d{6})$/", $na, $m1) && preg_match("/^TST-INV-$y-(\d{6})$/", $nb, $m2) && (int)$m2[1] === (int)$m1[1] + 1, "consecutive numbers $na, $nb");
    checkThrows(fn() => addInvoiceLine($db, $a, ['kind' => 'service', 'service_id' => 21]), 'credit note', 'an issued invoice can’t be changed');
    checkThrows(fn() => issueInvoice($db, $a), 'credit note', 'or issued twice');
    $r = documentRendition($db, 'invoice', $a);
    check($r && str_contains($r['html'], 'Tax Invoice') && str_contains($r['html'], 'فاتورة ضريبية') && str_contains($r['html'], '100000000000003') && str_contains($r['html'], $na), 'the printed copy is stored at issue (title, TRN, number)');
    $db->exec("UPDATE services SET name = 'Renamed facial' WHERE id = 22");
    check(!str_contains(documentRendition($db, 'invoice', $a)['html'], 'Renamed'), 'later changes don’t alter the stored copy');
    $db->exec("UPDATE services SET name = 'Facial' WHERE id = 22");
}

function test_invoice_numbers_under_two_processes() {
    billDb();
    $dir = tempDir('inv');
    file_put_contents("$dir/w.php", '<?php require ' . var_export(realpath(__DIR__ . '/lib.php'), true) . '; require ' . var_export(realpath(__DIR__ . '/../includes/billing.php'), true) . ';
        $_SESSION["staff_id"] = 501; $_SESSION["role"] = "receptionist";
        $db = testDb("clinic_crm_test_billing"); $out = [];
        for ($i = 0; $i < 12; $i++) { $id = createInvoice($db, 1, 1); addInvoiceLine($db, $id, ["kind" => "service", "service_id" => 22]); $out[] = issueInvoice($db, $id); }
        echo json_encode($out);');
    $procs = []; $pipes = [];
    foreach ([0, 1] as $k) $procs[$k] = proc_open([phpBin(), "$dir/w.php"], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes[$k]);
    $all = [];
    foreach ([0, 1] as $k) { $all = array_merge($all, json_decode(stream_get_contents($pipes[$k][1]), true) ?: []); proc_close($procs[$k]); }
    $n = array_map(fn($x) => (int)substr($x, -6), $all); sort($n);
    check(count($n) === 24 && count(array_unique($n)) === 24 && $n === range($n[0], $n[0] + 23), 'two cashiers issuing at once: 24 unique numbers, no gaps');
    rmTree($dir);
}

function test_receipts_split_and_allocate_and_the_wallet() {
    $db = billDb();
    $i1 = newInvoice($db, 2, [['kind' => 'service', 'service_id' => 21]]); issueInvoice($db, $i1);   // 1,260
    $i2 = newInvoice($db, 2, [['kind' => 'service', 'service_id' => 22]]); issueInvoice($db, $i2);   // 367.50
    $w0 = walletBalance($db, 2);
    checkThrows(fn() => createReceipt($db, 2, 1, [['method' => 'cash', 'amount' => 100]], [$i1 => 200]), 'More is allocated', 'can’t allocate more than received');
    $r = createReceipt($db, 2, 1, [['method' => 'cash', 'amount' => 500], ['method' => 'card', 'amount' => 1200, 'reference' => '4242']], [$i1 => 1260, $i2 => 300]);
    check(inv($db, $i1)['status'] === 'paid' && inv($db, $i2)['status'] === 'partially_paid' && invoiceBalance(inv($db, $i2)) === 6750, 'one receipt pays one invoice and part of another');
    check(walletBalance($db, 2) - $w0 === 14000, 'the 140 not allocated is kept as the patient’s balance');
    checkThrows(fn() => createReceipt($db, 2, 1, [['method' => 'cash', 'amount' => 100]], [$i2 => 100]), 'only AED 67.50', 'can’t pay more than is owed');
    createReceipt($db, 2, 1, [['method' => 'wallet', 'amount' => 67.50]], [$i2 => 67.50]);
    check(inv($db, $i2)['status'] === 'paid' && walletBalance($db, 2) - $w0 === 7250, 'paid from the balance');
    checkThrows(fn() => createReceipt($db, 2, 1, [['method' => 'wallet', 'amount' => 500]], []), 'only pay invoices', 'balance money can’t become new balance');
    $sum = fils($db->query("SELECT SUM(amount) FROM patient_wallet WHERE patient_id = 2")->fetchColumn());
    check($sum === walletBalance($db, 2), 'the balance is always the sum of the ledger');
    checkThrows(fn() => refundWallet($db, 2, 1, 20, 'cash', ''), 'permission', 'reception can’t refund');
    as_(503, 'finance');
    refundWallet($db, 2, 1, 20, 'cash', 'Patient asked');
    check(walletBalance($db, 2) - $w0 === 5250, 'refund paid out of the balance');
    checkThrows(fn() => refundWallet($db, 2, 1, 999, 'cash', ''), 'more than', 'can’t refund more than the balance');
    // Voiding the first receipt: its allocations and its advance come back out
    checkThrows(fn() => voidReceipt($db, $r, 'Wrong patient'), 'already been spent', 'can’t void a receipt whose advance was spent');
    $r3 = createReceipt($db, 2, 1, [['method' => 'cash', 'amount' => 50]], []);
    voidReceipt($db, $r3, 'Typed twice');
    check(walletBalance($db, 2) - $w0 === 5250 && findReceipt($db, $r3)['status'] === 'void', 'a void receipt takes its advance back out');
    $html = documentRendition($db, 'receipt', $r)['html'];
    check(str_contains($html, 'Receipt') && str_contains($html, '4242') && str_contains($html, 'Kept as balance'), 'the receipt prints methods, references and the kept balance');
}

function test_credit_notes() {
    $db = billDb(); as_(503, 'finance');
    $i = newInvoice($db, 1, [['kind' => 'service', 'service_id' => 23, 'qty' => 3], ['kind' => 'service', 'service_id' => 22]]);   // 104.99 + 367.50
    issueInvoice($db, $i);
    $lines = invoiceLines($db, $i);
    createReceipt($db, 1, 1, [['method' => 'cash', 'amount' => 472.49]], [$i => 472.49]);
    check(inv($db, $i)['status'] === 'paid', 'paid in full');
    $w0 = walletBalance($db, 1);
    $c1 = issueCreditNote($db, $i, [$lines[0]['id'] => 1], 'One consultation not given', 'cash');
    $c2 = issueCreditNote($db, $i, [$lines[0]['id'] => 2], 'The rest', 'wallet');
    $cn = $db->query("SELECT SUM(gross), SUM(vat), SUM(total) FROM credit_notes WHERE invoice_id = $i")->fetch(PDO::FETCH_NUM);
    check($cn[0] === '99.99' && $cn[1] === '5.00' && $cn[2] === '104.99', 'two partial credits add up exactly to the line (99.99 + 5.00)');
    check(walletBalance($db, 1) - $w0 === fils($db->query("SELECT refunded FROM credit_notes WHERE id = $c2")->fetchColumn()), 'the second credit was kept as balance');
    check($db->query("SELECT refund_method FROM credit_notes WHERE id = $c1")->fetchColumn() === 'cash', 'the first was refunded in cash');
    check(inv($db, $i)['status'] === 'paid' && invoiceBalance(inv($db, $i)) === 0, 'the invoice still balances to zero');
    checkThrows(fn() => issueCreditNote($db, $i, [$lines[0]['id'] => 1], 'again'), 'left to credit', 'can’t credit more than was sold');
    check(str_contains(documentRendition($db, 'credit_note', $c1)['html'], 'Tax Credit Note') && str_contains(documentRendition($db, 'credit_note', $c1)['html'], inv($db, $i)['number']), 'the credit note prints its title and the original invoice');
    // An unpaid invoice: the credit just lowers what is owed
    $u = newInvoice($db, 1, [['kind' => 'service', 'service_id' => 22]]); issueInvoice($db, $u);
    issueCreditNote($db, $u, [invoiceLines($db, $u)[0]['id'] => 1], 'Cancelled', 'cash');
    check(inv($db, $u)['refunded'] === '0.00' && invoiceBalance(inv($db, $u)) === 0 && inv($db, $u)['status'] === 'paid', 'crediting an unpaid invoice refunds nothing and settles it');
}

function test_void_rules() {
    $db = billDb(); as_(503, 'finance');
    $i = newInvoice($db, 1, [['kind' => 'service', 'service_id' => 22]]); issueInvoice($db, $i);
    $r = createReceipt($db, 1, 1, [['method' => 'card', 'amount' => 100]], [$i => 100]);
    checkThrows(fn() => voidInvoice($db, $i, 'x'), 'payments', 'an invoice with payments can’t be voided');
    voidReceipt($db, $r, 'Card declined');
    voidInvoice($db, $i, 'Wrong patient');
    check(inv($db, $i)['status'] === 'void' && inv($db, $i)['number'] !== null, 'void keeps its number (no gap)');
    as_(501, 'receptionist');
    $j = newInvoice($db, 1, [['kind' => 'service', 'service_id' => 22]]); issueInvoice($db, $j);
    checkThrows(fn() => voidInvoice($db, $j, 'x'), 'permission', 'reception can’t void');
}

function test_packages_sell_book_complete() {
    $db = billDb(); as_(504, 'admin');
    $pk = savePackage($db, 0, ['name' => '6 Facials', 'price' => 1800, 'vat_rate' => 5, 'validity_days' => 180, 'is_active' => 1], [22 => 6]);
    $i = newInvoice($db, 1, [['kind' => 'package', 'package_id' => $pk]]);
    issueInvoice($db, $i);
    $pp = patientPackages($db, 1, true);
    $pp = array_values(array_filter($pp, fn($x) => (int)$x['package_id'] === $pk))[0];
    check($pp && $pp['value'] === '1890.00' && $pp['expires_on'] === date('Y-m-d', strtotime('+180 days')), 'issuing the invoice gives the patient the package (value with VAT, expiry)');
    $db->exec("INSERT INTO appointments (patient_id, service_id, scheduled_at, ends_at, practitioner_id, branch_id, status) VALUES (1, 22, NOW(), NOW() + INTERVAL 1 HOUR, 502, 1, 'arrived')");
    $ap = (int)$db->lastInsertId();
    $db->exec("INSERT INTO appointment_services (appointment_id, service_id) VALUES ($ap, 22)");
    setAppointmentPackage($db, $ap, (int)$pp['id']);
    completeAppointment($db, $ap, []);
    check((int)$db->query("SELECT used FROM patient_package_sessions WHERE patient_package_id = {$pp['id']}")->fetchColumn() === 1, 'completing the visit uses one session');
    $vi = invoiceFromVisit($db, $ap);
    $l = invoiceLines($db, $vi)[0];
    check($l['unit_price'] === '0.00' && str_contains($l['description'], 'package') && (int)$l['practitioner_id'] === 502, 'the visit’s invoice shows the session at no charge, with the practitioner');
    check(invoiceFromVisit($db, $ap) === $vi, 'invoicing the visit again opens the same invoice');
    checkThrows(fn() => voidInvoice($db, $i, 'x'), 'has been used', 'a used package’s invoice can’t be voided');
    $liab = array_values(array_filter(packageLiability($db), fn($x) => (int)$x['id'] === (int)$pp['id']))[0];
    check($liab['unused_value'] === '1575.00', 'liability: 5 of 6 sessions unused = 1,575.00');
    $db->exec("UPDATE patient_packages SET expires_on = CURDATE() - INTERVAL 1 DAY WHERE id = {$pp['id']}");
    expirePackages($db);
    check($db->query("SELECT status FROM patient_packages WHERE id = {$pp['id']}")->fetchColumn() === 'expired', 'past its date it expires');
}

function test_vouchers() {
    $db = billDb();
    [$vid, $code] = sellVoucher($db, 1, 500, date('Y-m-d', strtotime('+1 year')), [['method' => 'card', 'amount' => 500]], 1, 'Mum');
    check(voucherBalance($db, $vid) === 50000 && walletBalance($db, 1) === walletBalance($db, 1), 'voucher sold for 500');
    $vr = $db->query("SELECT * FROM receipts WHERE voucher_id = $vid")->fetch();
    check($vr && (float)$vr['total'] === 500.0 && !$db->query("SELECT COUNT(*) FROM patient_wallet WHERE receipt_id = {$vr['id']}")->fetchColumn(), 'its sale is a receipt, not patient balance, and has no VAT');
    $i = newInvoice($db, 2, [['kind' => 'service', 'service_id' => 22]]); issueInvoice($db, $i);
    createReceipt($db, 2, 1, [['method' => 'voucher', 'amount' => 367.50, 'voucher_code' => strtolower($code)]], [$i => 367.50]);
    check(voucherBalance($db, $vid) === 13250 && inv($db, $i)['status'] === 'paid', 'spent 367.50: 132.50 left (code typed in lower case still works)');
    $j = newInvoice($db, 2, [['kind' => 'service', 'service_id' => 22]]); issueInvoice($db, $j);
    checkThrows(fn() => createReceipt($db, 2, 1, [['method' => 'voucher', 'amount' => 200, 'voucher_code' => $code]], [$j => 200]), 'only AED 132.50', 'can’t spend more than is left');
    $db->exec("UPDATE vouchers SET expires_on = CURDATE() - INTERVAL 1 DAY WHERE id = $vid");
    checkThrows(fn() => createReceipt($db, 2, 1, [['method' => 'voucher', 'amount' => 10, 'voucher_code' => $code]], [$j => 10]), 'expired', 'an expired voucher is refused');
    checkThrows(fn() => sellVoucher($db, 1, 100, date('Y-m-d', strtotime('+1 year')), [['method' => 'cash', 'amount' => 90]]), 'must equal', 'the payment must match the value');
}

function test_commission() {
    $db = billDb();
    $db->exec("DELETE FROM commission_rules");
    $db->exec("INSERT INTO commission_rules (staff_id, service_id, kind, value) VALUES (502, 0, 'percent_net', 10), (502, 24, 'fixed_per_unit', 25)");
    $i = newInvoice($db, 1, [['kind' => 'service', 'service_id' => 21, 'practitioner_id' => 502], ['kind' => 'service', 'service_id' => 24, 'practitioner_id' => 502]]);   // 1,260 + 100
    issueInvoice($db, $i);
    createReceipt($db, 1, 1, [['method' => 'cash', 'amount' => 680]], [$i => 680]);   // half of 1,360
    $rep = commissionReport($db, date('Y-m'))[502] ?? null;
    $botox = array_values(array_filter($rep['lines'] ?? [], fn($l) => $l['number'] === inv($db, $i)['number'] && str_starts_with($l['description'], 'Botox')))[0] ?? null;
    $report = array_values(array_filter($rep['lines'] ?? [], fn($l) => $l['number'] === inv($db, $i)['number'] && str_starts_with($l['description'], 'Medical')))[0] ?? null;
    check($botox && $botox['commission'] === 6000, '10% of the net actually collected (half of 1,200) = 60.00');
    check($report && $report['commission'] === 0, 'a fixed fee waits until the invoice is fully paid');
    createReceipt($db, 1, 1, [['method' => 'cash', 'amount' => 680]], [$i => 680]);
    $rep = commissionReport($db, date('Y-m'))[502];
    $report = array_values(array_filter($rep['lines'], fn($l) => $l['number'] === inv($db, $i)['number'] && str_starts_with($l['description'], 'Medical')))[0];
    check($report['commission'] === 2500, 'then pays 25 per unit');
}

function test_a_clinic_day_of_20_visits_closes_balanced() {
    $db = billDb(true);
    as_(501, 'receptionist');
    $day = date('Y-m-d');
    $methods = [['cash'], ['card'], ['cash', 'card'], ['bank_transfer'], ['tabby'], ['cash']];
    $expect = array_fill_keys(DRAWER_METHODS, 0);
    for ($v = 1; $v <= 20; $v++) {
        $pid = $v % 2 ? 1 : 2;
        $db->exec("INSERT INTO appointments (patient_id, service_id, scheduled_at, ends_at, practitioner_id, branch_id, status) VALUES ($pid, " . (21 + $v % 3) . ", NOW(), NOW() + INTERVAL 30 MINUTE, 502, 1, 'arrived')");
        $ap = (int)$db->lastInsertId();
        completeAppointment($db, $ap, []);
        $inv = invoiceFromVisit($db, $ap);
        if ($v % 5 === 0) setLineDiscount($db, (int)invoiceLines($db, $inv)[0]['id'], ['discount_pct' => 10]);
        issueInvoice($db, $inv);
        $total = fils(inv($db, $inv)['total']);
        $ms = $methods[$v % count($methods)];
        $parts = count($ms) === 2 ? [$ms[0] => intdiv($total, 3), $ms[1] => $total - intdiv($total, 3)] : [$ms[0] => $total];
        if ($v === 7) $parts = [$ms[0] => intdiv($total, 2)];   // pays half today
        if ($v === 11) $parts = ['cash' => $total + 5000];     // leaves 50 as balance
        createReceipt($db, $pid, 1, array_map(fn($m, $a) => ['method' => $m, 'amount' => aed($a), 'reference' => $m === 'cheque' ? '1' : ''], array_keys($parts), $parts), [$inv => aed(min($total, array_sum($parts)))]);
        foreach ($parts as $m => $a) $expect[$m] += $a;
    }
    as_(503, 'finance'); refundWallet($db, 1, 1, 30, 'cash', 'Change given back'); as_(501, 'receptionist');
    // The refund was paid by finance, so it belongs to finance's drawer, not reception's
    $e = dayCloseExpected($db, 1, 501, $day);
    check($e['receipts'] === 20 && $e['methods'] === $expect, '20 receipts; expected per method = what was taken (' . implode(', ', array_map(fn($m, $a) => "$m " . aed($a), array_keys(array_filter($e['methods'])), array_filter($e['methods']))) . ')');
    $paid = fils($db->query("SELECT SUM(total) FROM receipts WHERE DATE(received_at) = CURDATE() AND received_by = 501 AND status = 'valid'")->fetchColumn());
    check(array_sum($e['methods']) === $paid, 'the drawer equals the receipts total');
    checkThrows(fn() => closeDay($db, 1, $day, array_map('aed', array_merge($expect, ['cash' => $expect['cash'] - 1000]))), 'short by AED 10.00', 'a short count needs a note');
    $id = closeDay($db, 1, $day, array_map('aed', $expect));
    $dc = $db->query("SELECT * FROM day_closes WHERE id = $id")->fetch();
    check($dc['difference'] === '0.00', 'the day closes balanced');
    check(!$db->query("SELECT COUNT(*) FROM receipts WHERE received_by = 501 AND day_close_id IS NULL AND DATE(received_at) = CURDATE()")->fetchColumn(), 'every receipt is locked to the close');
    check(dayCloseExpected($db, 1, 501, $day)['receipts'] === 0, 'nothing left to close');
    $rid = (int)$db->query("SELECT id FROM receipts WHERE day_close_id = $id LIMIT 1")->fetchColumn();
    as_(503, 'finance');
    checkThrows(fn() => voidReceipt($db, $rid, 'late'), 'Only an admin', 'after the close only an admin can void a receipt');
    $fin = dayCloseExpected($db, 1, 503, $day);
    check($fin['methods']['cash'] === -3000, 'finance’s drawer shows the 30 cash refund going out');
    $owing = $db->query("SELECT COUNT(*) FROM invoices WHERE status = 'partially_paid'")->fetchColumn();
    check((int)$owing === 1, 'one visit still owes money (paid half)');
}

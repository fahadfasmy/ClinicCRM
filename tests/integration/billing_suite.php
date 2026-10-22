<?php
const ROOT = __DIR__ . '/../..';   // the project folder
// Phase 7 through the real pages (local server 8080): reception invoices a completed visit, is held to the discount
// limit, issues it (numbered, printed tax invoice with TRN), takes a split payment, closes the day balanced; finance
// credits part of it; a closed receipt can't be voided by finance; vouchers and packages; who sees billing.
// Everything it creates is removed at the end.
require ROOT . '/includes/auth.php';
require ROOT . '/includes/billing.php';
$db = getDB();
$pass = 0; $fail = 0;
$ok = function (bool $c, string $m) use (&$pass, &$fail) { $c ? $pass++ : $fail++; echo ($c ? "  ok   " : "  FAIL "), $m, PHP_EOL; };
$BASE = 'http://127.0.0.1:8080';
$http = function (string $jar, string $method, string $path, array $form = []) use ($BASE) {
    $ch = curl_init($BASE . $path);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => true, CURLOPT_COOKIEJAR => $jar, CURLOPT_COOKIEFILE => $jar, CURLOPT_USERAGENT => 'ClinicCRM-billing-suite/1.0']);
    if ($method === 'POST') { curl_setopt($ch, CURLOPT_POST, true); curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($form)); }
    $raw = (string)curl_exec($ch); $code = curl_getinfo($ch, CURLINFO_HTTP_CODE); $hs = curl_getinfo($ch, CURLINFO_HEADER_SIZE); curl_close($ch);
    preg_match('/^Location:\s*(\S+)/mi', substr($raw, 0, $hs), $m);
    return [$code, $m[1] ?? '', substr($raw, $hs)];
};
$acc = file(ROOT . '/local/test_accounts.txt', FILE_IGNORE_NEW_LINES);
$pw = trim(preg_replace('/^Password:\s*/i', '', $acc[2]));
$tok = [];
$login = function (string $email) use ($http, $pw, &$tok) {
    $jar = tempnam(sys_get_temp_dir(), 'bl');
    [, , $b] = $http($jar, 'GET', '/login.php'); preg_match('/name="_csrf" value="([a-f0-9]+)"/', $b, $m);
    $http($jar, 'POST', '/login.php', ['_csrf' => $m[1] ?? '', 'step' => 'password', 'email' => $email, 'password' => $pw]);
    [, , $d] = $http($jar, 'GET', '/pages/dashboard.php'); preg_match('/name="csrf-token" content="([a-f0-9]+)"/', $d, $t);
    $tok[$jar] = $t[1] ?? '';
    return $jar;
};
$post = function (string $jar, string $path, array $f) use ($http, &$tok) { return $http($jar, 'POST', $path, ['_csrf' => $tok[$jar]] + $f); };
$phpError = fn(string $h) => (bool)preg_match('/(Fatal error|Parse error|Uncaught |Warning: |Notice: |Deprecated: )/', $h);
$flashOf = fn(string $h) => preg_match('/alert-(success|danger)[^>]*>.*?<strong>(.*?)<\/strong>/s', $h, $m) ? $m[1] . ': ' . html_entity_decode(strip_tags($m[2])) : '';

$tag = (string)random_int(1000, 9999);
$recId = (int)$db->query("SELECT id FROM staff WHERE email = 'reception@clinic.test'")->fetchColumn();
$doctorId = (int)$db->query("SELECT id FROM staff WHERE email = 'doctor@clinic.test'")->fetchColumn();
$branch = (int)$db->query("SELECT id FROM branches WHERE is_active = 1 ORDER BY id LIMIT 1")->fetchColumn();
$oldTrn = $db->query("SELECT trn FROM branches WHERE id = $branch")->fetchColumn();
$db->exec("UPDATE branches SET trn = '100123456700003' WHERE id = $branch");
$oldSettings = $db->query("SELECT k, v FROM integration_settings WHERE k LIKE 'billing.%'")->fetchAll(PDO::FETCH_KEY_PAIR);
$db->exec("DELETE FROM integration_settings WHERE k LIKE 'billing.%'");   // defaults: VAT added, reception 10%
$db->prepare("INSERT INTO services (name, price, vat_rate, duration_min, is_active) VALUES (?, 1000, 5, 30, 1)")->execute(["Peel$tag"]);
$svc = (int)$db->lastInsertId();
$db->prepare("INSERT INTO patients (mrn, first_name, last_name, branch_id, status) VALUES (?, ?, 'Billing', ?, 'active')")->execute(["BIL-$tag", "Noor$tag", $branch]);
$pid = (int)$db->lastInsertId();
$db->prepare("INSERT INTO appointments (patient_id, service_id, scheduled_at, ends_at, duration_min, practitioner_id, branch_id, status, completed_at) VALUES (?, ?, NOW(), NOW() + INTERVAL 30 MINUTE, 30, ?, ?, 'completed', NOW())")->execute([$pid, $svc, $doctorId, $branch]);
$appt = (int)$db->lastInsertId();
$db->prepare("INSERT INTO appointment_services (appointment_id, service_id) VALUES (?, ?)")->execute([$appt, $svc]);
// Today's earlier receipts by the reception test account must not be swept into this test's close
$preOpen = $db->query("SELECT id FROM receipts WHERE received_by = $recId AND day_close_id IS NULL")->fetchAll(PDO::FETCH_COLUMN);
if ($preOpen) $db->exec("UPDATE receipts SET day_close_id = 0 WHERE id IN (" . implode(',', $preOpen) . ")");
$invId = 0; $pkgId = 0; $vid = 0; $closeId = 0;

try {
    echo "Invoicing the visit\n";
    $rec = $login('reception@clinic.test');
    [, , $b] = $http($rec, 'GET', "/pages/appointment.php?id=$appt");
    $ok(str_contains($b, 'Invoice this visit') && !$phpError($b), 'the completed visit offers “Invoice this visit”');
    [, $loc] = $post($rec, '/pages/invoice.php', ['action' => 'new', 'appointment_id' => $appt]);
    $invId = preg_match('/id=(\d+)/', $loc, $m) ? (int)$m[1] : 0;
    [, , $b] = $http($rec, 'GET', "/pages/invoice.php?id=$invId");
    $line = (int)$db->query("SELECT id FROM invoice_lines WHERE invoice_id = $invId")->fetchColumn();
    $ok($invId && $line && str_contains($b, "Peel$tag") && str_contains($b, 'AED 1,050.00') && !$phpError($b), 'draft made from the visit: 1,000 + 5% VAT = 1,050');
    $post($rec, "/pages/invoice.php?id=$invId", ['action' => 'discount', 'line_id' => $line, 'discount_pct' => 15]);
    [, , $b] = $http($rec, 'GET', "/pages/invoice.php?id=$invId");
    $ok(str_contains($flashOf($b), 'discount limit of 10%'), 'reception is held to 10%');
    $post($rec, "/pages/invoice.php?id=$invId", ['action' => 'discount', 'line_id' => $line, 'discount_pct' => 10]);
    $post($rec, "/pages/invoice.php?id=$invId", ['action' => 'add_line', 'kind' => 'manual', 'description' => 'Aftercare cream', 'unit_price' => 80, 'vat_rate' => 5]);
    $post($rec, "/pages/invoice.php?id=$invId", ['action' => 'issue']);
    $inv = findInvoice($db, $invId);
    $ok($inv['status'] === 'issued' && preg_match('/-INV-\d{4}-\d{6}$/', (string)$inv['number']) && $inv['total'] === '1029.00', "issued {$inv['number']}: (900 + 80) + 49 VAT = 1,029.00");
    [$c, , $pr] = $http($rec, 'GET', "/pages/doc_print.php?type=invoice&id=$invId");
    $ok($c === 200 && str_contains($pr, 'Tax Invoice') && str_contains($pr, 'فاتورة ضريبية') && str_contains($pr, '100123456700003') && str_contains($pr, $inv['number']) && str_contains($pr, '1,029.00'), 'the printed tax invoice: title in both languages, TRN, number, total');
    $db->exec("UPDATE branches SET trn = 'CHANGED' WHERE id = $branch");
    [, , $pr2] = $http($rec, 'GET', "/pages/doc_print.php?type=invoice&id=$invId");
    $ok(str_contains($pr2, '100123456700003'), 'a re-print shows the invoice exactly as issued');
    $db->exec("UPDATE branches SET trn = '100123456700003' WHERE id = $branch");

    echo "Payment and day close\n";
    [$c, , $b] = $http($rec, 'GET', "/pages/receipt_new.php?invoice=$invId");
    $ok($c === 200 && str_contains($b, $inv['number']) && !$phpError($b), 'take-payment page lists the invoice');
    [, $loc] = $post($rec, "/pages/receipt_new.php?invoice=$invId", ['branch_id' => $branch, 'm' => [['method' => 'cash', 'amount' => '500'], ['method' => 'card', 'amount' => '600', 'reference' => '7781']], 'alloc' => [$invId => '1029.00']]);
    $rid = preg_match('/id=(\d+)/', $loc, $m) ? (int)$m[1] : 0;
    $ok($rid && findInvoice($db, $invId)['status'] === 'paid' && walletBalance($db, $pid) === 7100, 'cash 500 + card 600: invoice paid, 71 kept as the patient’s balance');
    [, , $b] = $http($rec, 'GET', "/pages/patient.php?id=$pid&tab=billing");
    $ok(str_contains($b, $inv['number']) && str_contains($b, 'Balance AED 71.00') && !$phpError($b), 'the patient’s Invoices & payments tab');
    [, , $b] = $http($rec, 'GET', '/pages/day_close.php');
    $ok(str_contains($b, '500.00') && str_contains($b, '600.00') && !$phpError($b), 'day close expects cash 500 and card 600');
    $post($rec, '/pages/day_close.php', ['branch' => $branch, 'date' => date('Y-m-d'), 'counted' => ['cash' => '490', 'card' => '600']]);
    $ok(!$db->query("SELECT COUNT(*) FROM day_closes WHERE staff_id = $recId AND closed_at > NOW() - INTERVAL 1 MINUTE")->fetchColumn(), 'a 10 short count without a note is refused');
    $post($rec, '/pages/day_close.php', ['branch' => $branch, 'date' => date('Y-m-d'), 'counted' => ['cash' => '500', 'card' => '600']]);
    $closeId = (int)$db->query("SELECT id FROM day_closes WHERE staff_id = $recId ORDER BY id DESC LIMIT 1")->fetchColumn();
    $ok($closeId && $db->query("SELECT difference FROM day_closes WHERE id = $closeId")->fetchColumn() === '0.00' && (int)findReceipt($db, $rid)['day_close_id'] === $closeId, 'closed balanced; the receipt is locked to the close');

    echo "Corrections\n";
    $fin = $login('finance@clinic.test');
    [, , $b] = $post($fin, "/pages/receipt.php?id=$rid", ['reason' => 'test']);
    [, , $b] = $http($fin, 'GET', "/pages/receipt.php?id=$rid");
    $ok(findReceipt($db, $rid)['status'] === 'valid' && !str_contains($b, 'Void receipt'), 'after the close finance can’t void the receipt');
    $cream = (int)$db->query("SELECT id FROM invoice_lines WHERE invoice_id = $invId AND description = 'Aftercare cream'")->fetchColumn();
    $post($fin, "/pages/invoice.php?id=$invId", ['action' => 'credit', 'qty' => [$cream => 1], 'reason' => 'Cream returned', 'settle' => 'cash']);
    $cn = $db->query("SELECT * FROM credit_notes WHERE invoice_id = $invId")->fetch();
    $ok($cn && $cn['total'] === '84.00' && $cn['refunded'] === '84.00' && $cn['refund_method'] === 'cash', 'credit note for the cream: 84.00 refunded in cash');
    [, , $p] = $http($fin, 'GET', "/pages/doc_print.php?type=credit_note&id={$cn['id']}");
    $ok(str_contains($p, 'Tax Credit Note') && str_contains($p, $inv['number']), 'the credit note prints with the original invoice number');
    $post($rec, "/pages/invoice.php?id=$invId", ['action' => 'credit', 'qty' => [$line => 1], 'reason' => 'x']);
    $ok((int)$db->query("SELECT COUNT(*) FROM credit_notes WHERE invoice_id = $invId")->fetchColumn() === 1, 'reception can’t issue credit notes');

    echo "Packages and vouchers\n";
    $admin = $login('admin@clinic.test');
    $post($admin, '/pages/packages.php', ['id' => 0, 'name' => "Peel x3 $tag", 'price' => 2700, 'vat_rate' => 5, 'validity_days' => 90, 'is_active' => 1, 'svc' => [$svc], 'sessions' => [3]]);
    $pkgId = (int)$db->query("SELECT id FROM packages WHERE name = " . $db->quote("Peel x3 $tag"))->fetchColumn();
    $ok($pkgId > 0, 'admin creates a package');
    $post($rec, '/pages/packages.php', ['id' => 0, 'name' => "Nope $tag", 'price' => 1, 'validity_days' => 1, 'svc' => [$svc], 'sessions' => [1]]);
    $ok(!$db->query("SELECT COUNT(*) FROM packages WHERE name = " . $db->quote("Nope $tag"))->fetchColumn(), 'reception can’t create packages');
    [, $loc] = $post($rec, '/pages/invoice.php', ['action' => 'new', 'patient_id' => $pid]);
    $pInv = preg_match('/id=(\d+)/', $loc, $m) ? (int)$m[1] : 0;
    $post($rec, "/pages/invoice.php?id=$pInv", ['action' => 'add_line', 'kind' => 'package', 'package_id' => $pkgId]);
    $post($rec, "/pages/invoice.php?id=$pInv", ['action' => 'issue']);
    $ok((int)$db->query("SELECT COUNT(*) FROM patient_packages WHERE patient_id = $pid AND package_id = $pkgId AND status = 'active'")->fetchColumn() === 1, 'selling it on an invoice gives the patient 3 sessions');
    $post($rec, '/pages/vouchers.php', ['value' => 250, 'expires_on' => date('Y-m-d', strtotime('+6 months')), 'recipient' => "Gift $tag", 'method' => 'cash', 'branch_id' => $branch]);
    $vid = (int)$db->query("SELECT id FROM vouchers WHERE recipient = " . $db->quote("Gift $tag"))->fetchColumn();
    $ok($vid && voucherBalance($db, $vid) === 25000, 'a 250 voucher sold');

    echo "Who sees billing\n";
    foreach (['layla@clinic.test' => 'consultant', 'inventory@clinic.test' => 'inventory', 'nurse@clinic.test' => 'nurse'] as $email => $role) {
        $j = $login($email);
        [$c1] = $http($j, 'GET', "/pages/invoice.php?id=$invId");
        [$c2] = $http($j, 'GET', "/pages/doc_print.php?type=invoice&id=$invId");
        [, , $pb] = $http($j, 'GET', "/pages/patient.php?id=$pid&tab=billing");
        $ok($c1 === 403 && $c2 === 403 && !str_contains($pb, $inv['number']), "$role: no invoices");
    }
    foreach (['/pages/invoices.php', '/pages/invoices.php?status=unpaid', '/pages/receipts.php', '/pages/packages.php', '/pages/vouchers.php', '/pages/billing_settings.php', '/pages/reports_commission.php', '/pages/day_close.php', "/pages/patient.php?id=$pid", '/pages/services.php?new=1', '/pages/settings_clinic.php'] as $p) {
        [$c, , $b] = $http($admin, 'GET', $p); $ok($c === 200 && !$phpError($b), "$p loads");
    }
    [, , $csv] = $http($admin, 'GET', '/pages/invoices.php?export=csv');
    $ok(str_contains($csv, $inv['number']) && str_contains($csv, 'Taxable'), 'invoice register exports to CSV');
} finally {
    $invIds = $db->query("SELECT id FROM invoices WHERE patient_id = $pid")->fetchAll(PDO::FETCH_COLUMN);
    if ($invIds) {
        $in = implode(',', $invIds);
        $db->exec("DELETE FROM credit_note_lines WHERE credit_note_id IN (SELECT id FROM credit_notes WHERE invoice_id IN ($in))");
        $db->exec("DELETE FROM document_renditions WHERE (doc_type = 'invoice' AND doc_id IN ($in)) OR (doc_type = 'credit_note' AND doc_id IN (SELECT id FROM credit_notes WHERE invoice_id IN ($in)))");
        $db->exec("DELETE FROM credit_notes WHERE invoice_id IN ($in)");
        $db->exec("DELETE FROM patient_package_sessions WHERE patient_package_id IN (SELECT id FROM patient_packages WHERE patient_id = $pid)");
        $db->exec("DELETE FROM patient_packages WHERE patient_id = $pid");
        $db->exec("DELETE FROM invoice_lines WHERE invoice_id IN ($in)");
        $db->exec("DELETE FROM audit_log WHERE entity = 'invoice' AND entity_id IN ($in)");
        $db->exec("DELETE FROM invoices WHERE id IN ($in)");
    }
    $rids = $db->query("SELECT id FROM receipts WHERE patient_id = $pid" . ($vid ? " OR voucher_id = $vid" : ''))->fetchAll(PDO::FETCH_COLUMN);
    if ($rids) { $in = implode(',', $rids);
        foreach (['receipt_methods', 'receipt_allocations'] as $t) $db->exec("DELETE FROM $t WHERE receipt_id IN ($in)");
        $db->exec("DELETE FROM document_renditions WHERE doc_type = 'receipt' AND doc_id IN ($in)");
        $db->exec("DELETE FROM audit_log WHERE entity = 'receipt' AND entity_id IN ($in)");
        $db->exec("DELETE FROM receipts WHERE id IN ($in)"); }
    if ($vid) $db->exec("DELETE FROM vouchers WHERE id = $vid");
    if ($closeId) { $db->exec("DELETE FROM day_closes WHERE id = $closeId"); $db->exec("DELETE FROM audit_log WHERE entity = 'day_close' AND entity_id = $closeId"); }
    if ($preOpen) $db->exec("UPDATE receipts SET day_close_id = NULL WHERE id IN (" . implode(',', $preOpen) . ")");
    if ($pkgId) { $db->exec("DELETE FROM package_items WHERE package_id = $pkgId"); $db->exec("DELETE FROM packages WHERE id = $pkgId"); }
    $db->exec("DELETE FROM patient_wallet WHERE patient_id = $pid");
    foreach (['patient_access_log', 'patient_phones'] as $t) $db->exec("DELETE FROM $t WHERE patient_id = $pid");
    foreach (['appointment_status_history', 'appointment_services'] as $t) $db->exec("DELETE FROM $t WHERE appointment_id = $appt");
    $db->exec("DELETE FROM appointments WHERE id = $appt");
    $db->exec("DELETE FROM audit_log WHERE entity = 'patient' AND entity_id = $pid");
    $db->exec("DELETE FROM patients WHERE id = $pid");
    $db->exec("DELETE FROM services WHERE id = $svc");
    $db->prepare("UPDATE branches SET trn = ? WHERE id = ?")->execute([$oldTrn ?: null, $branch]);
    $db->exec("DELETE FROM integration_settings WHERE k LIKE 'billing.%'");
    foreach ($oldSettings as $k => $v) $db->prepare("INSERT INTO integration_settings (k, v) VALUES (?, ?)")->execute([$k, $v]);
    $db->exec("DELETE FROM login_log WHERE user_agent = 'ClinicCRM-billing-suite/1.0'");
}
echo "\n$pass passed, $fail failed\n";
exit($fail ? 1 : 0);

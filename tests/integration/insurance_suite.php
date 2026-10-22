<?php
const ROOT = __DIR__ . '/../..';   // the project folder
// Phase 9 through the real pages (local server 8080): admin sets up a company, plan and plan prices; reception adds the
// patient's card, invoices the visit to insurance (approval warning, a line not covered), takes only the patient's
// share; the insurance officer batches the claim, exports eClaimLink XML (valid against the sample schema), submits,
// records the rejection, corrects, resubmits and records the remittance: the claim is paid. Ageing tiles, prior
// approvals, remittances, and who sees what. Everything it creates is removed at the end.
require ROOT . '/includes/auth.php';
require ROOT . '/includes/insurance.php';
$db = getDB();
$pass = 0; $fail = 0;
$ok = function (bool $c, string $m) use (&$pass, &$fail) { $c ? $pass++ : $fail++; echo ($c ? "  ok   " : "  FAIL "), $m, PHP_EOL; };
$BASE = 'http://127.0.0.1:8080';
$http = function (string $jar, string $method, string $path, array $form = []) use ($BASE) {
    $ch = curl_init($BASE . $path);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => true, CURLOPT_COOKIEJAR => $jar, CURLOPT_COOKIEFILE => $jar, CURLOPT_USERAGENT => 'ClinicCRM-insurance-suite/1.0']);
    if ($method === 'POST') { curl_setopt($ch, CURLOPT_POST, true); curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($form)); }
    $raw = (string)curl_exec($ch); $code = curl_getinfo($ch, CURLINFO_HTTP_CODE); $hs = curl_getinfo($ch, CURLINFO_HEADER_SIZE); curl_close($ch);
    preg_match('/^Location:\s*(\S+)/mi', substr($raw, 0, $hs), $m);
    return [$code, $m[1] ?? '', substr($raw, $hs)];
};
$acc = file(ROOT . '/local/test_accounts.txt', FILE_IGNORE_NEW_LINES);
$pw = trim(preg_replace('/^Password:\s*/i', '', $acc[2]));
$tok = [];
$login = function (string $email) use ($http, $pw, &$tok) {
    $jar = tempnam(sys_get_temp_dir(), 'in');
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
$branch = (int)$db->query("SELECT id FROM branches WHERE is_active = 1 ORDER BY id LIMIT 1")->fetchColumn();
$oldDha = $db->query("SELECT dha_license FROM branches WHERE id = $branch")->fetchColumn();
$db->exec("UPDATE branches SET dha_license = 'DHA-F-SUITE$tag' WHERE id = $branch");
$doctorId = (int)$db->query("SELECT id FROM staff WHERE email = 'doctor@clinic.test'")->fetchColumn();
$hadProfile = $db->query("SELECT dha_license FROM practitioner_profiles WHERE staff_id = $doctorId")->fetch();
if ($hadProfile) $db->exec("UPDATE practitioner_profiles SET dha_license = 'DHA-P-SUITE$tag' WHERE staff_id = $doctorId");
else $db->exec("INSERT INTO practitioner_profiles (staff_id, dha_license) VALUES ($doctorId, 'DHA-P-SUITE$tag')");
$db->prepare("INSERT INTO services (name, price, vat_rate, cpt_code, duration_min, is_active) VALUES (?, 400, 5, '99214', 20, 1), (?, 300, 5, NULL, 30, 1)")->execute(["Derm consult $tag", "Glow facial $tag"]);
$svcA = (int)$db->lastInsertId(); $svcB = $svcA + 1;
$db->prepare("INSERT INTO patients (mrn, first_name, last_name, branch_id, status) VALUES (?, ?, 'Insured', ?, 'active')")->execute(["INS-$tag", "Nadia$tag", $branch]);
$pid = (int)$db->lastInsertId();
$db->prepare("INSERT INTO appointments (patient_id, service_id, scheduled_at, ends_at, duration_min, practitioner_id, branch_id, status, completed_at) VALUES (?, ?, NOW() - INTERVAL 1 HOUR, NOW() - INTERVAL 40 MINUTE, 20, ?, ?, 'arrived', NULL)")->execute([$pid, $svcA, $doctorId, $branch]);
$appt = (int)$db->lastInsertId();
$db->exec("INSERT INTO appointment_services (appointment_id, service_id) VALUES ($appt, $svcA), ($appt, $svcB)");
$db->exec("INSERT INTO encounters (patient_id, appointment_id, practitioner_id, created_by) VALUES ($pid, $appt, $doctorId, $doctorId)");
$enc = (int)$db->lastInsertId();
$db->exec("INSERT INTO encounter_diagnoses (encounter_id, icd10_code, description, is_primary) VALUES ($enc, 'L70.0', 'Acne vulgaris', 1)");
$coId = 0; $planId = 0; $invId = 0;

try {
    echo "Insurance set-up\n";
    $admin = $login('admin@clinic.test');
    $post($admin, '/pages/insurance.php', ['action' => 'company', 'name' => "Suite Health $tag", 'type' => 'payer', 'payer_id' => 'INS9' . substr($tag, 0, 2), 'is_active' => 1]);
    $coId = (int)$db->query("SELECT id FROM insurance_companies WHERE name = " . $db->quote("Suite Health $tag"))->fetchColumn();
    $post($admin, '/pages/insurance.php', ['action' => 'plan', 'company_id' => $coId, 'name' => 'Silver', 'copay_percent' => 10, 'deductible' => 20, 'max_copay' => '', 'preauth' => [$svcA], 'is_active' => 1]);
    $planId = (int)$db->query("SELECT id FROM insurance_plans WHERE company_id = $coId")->fetchColumn();
    $post($admin, '/pages/insurance.php', ['action' => 'prices', 'plan_id' => $planId, 'p' => [$svcA => ['price' => 350, 'cpt_code' => '99214']]]);
    [$c, , $b] = $http($admin, 'GET', '/pages/insurance.php');
    $ok($coId && $planId && $c === 200 && str_contains($b, "Suite Health $tag") && str_contains($b, 'Co-pay 10%') && !$phpError($b), 'company, plan (co-pay 10% after 20) and plan price set up');

    echo "Front desk\n";
    $rec = $login('reception@clinic.test');
    $post($rec, "/pages/patient.php?id=$pid", ['action' => 'card_save', 'plan_id' => $planId, 'member_no' => "M-$tag", 'policy_no' => 'P-1', 'valid_from' => date('Y-m-d', strtotime('-1 month')), 'valid_to' => date('Y-m-d', strtotime('+10 days')), 'is_primary' => 1, 'is_active' => 1]);
    [, , $b] = $http($rec, 'GET', "/pages/patient.php?id=$pid&tab=insurance");
    $card = (int)$db->query("SELECT id FROM patient_insurance WHERE patient_id = $pid")->fetchColumn();
    $ok($card && str_contains($b, "M-$tag") && !$phpError($b), 'reception adds the insurance card');
    [, , $b] = $http($rec, 'GET', "/pages/patient.php?id=$pid");
    $ok(str_contains($b, 'Insurance card ends'), 'the overview warns the card ends soon');
    [, , $b] = $http($rec, 'GET', "/pages/appointment.php?id=$appt");
    $ok(str_contains($b, 'Approval needed for') && str_contains($b, "Derm consult $tag"), 'the appointment says the consult needs approval');
    [, $loc] = $post($rec, '/pages/invoice.php', ['action' => 'new', 'appointment_id' => $appt]);
    $invId = preg_match('/id=(\d+)/', $loc, $m) ? (int)$m[1] : 0;
    $post($rec, "/pages/invoice.php?id=$invId", ['action' => 'insurance', 'card_id' => $card]);
    $lineB = (int)$db->query("SELECT id FROM invoice_lines WHERE invoice_id = $invId AND service_id = $svcB")->fetchColumn();
    $post($rec, "/pages/invoice.php?id=$invId", ['action' => 'covered', 'line_id' => $lineB, 'covered' => '']);
    [, , $b] = $http($rec, 'GET', "/pages/invoice.php?id=$invId");
    $i = findInvoice($db, $invId);
    // consult 350 + VAT = 367.50: patient 20 + 10% of 347.50 = 54.75; facial 315 not covered
    $ok($i['type'] === 'insurance' && $i['patient_share'] === '369.75' && $i['insurance_share'] === '312.75', "insurance invoice: patient {$i['patient_share']} (54.75 + facial 315), insurer {$i['insurance_share']}");
    $ok(str_contains($b, 'The plan needs an approval for') && str_contains($b, 'Insurer pays') && !$phpError($b), 'the invoice shows both shares and the approval warning');
    $post($rec, "/pages/invoice.php?id=$invId", ['action' => 'issue']);
    $cl = findClaim($db, (int)$db->query("SELECT id FROM claims WHERE invoice_id = $invId")->fetchColumn());
    $ok($cl && $cl['status'] === 'ready' && $cl['claimed'] === '312.75', "issued; claim {$cl['number']} ready for 312.75");
    [, , $b] = $post($rec, "/pages/receipt_new.php?invoice=$invId", ['branch_id' => $branch, 'm' => [['method' => 'cash', 'amount' => '500']], 'alloc' => [$invId => '500']]);
    $ok(str_contains($b, 'by the patient'), 'the front desk can’t take the insurer’s part');
    [, $loc] = $post($rec, "/pages/receipt_new.php?invoice=$invId", ['branch_id' => $branch, 'm' => [['method' => 'card', 'amount' => '369.75']], 'alloc' => [$invId => '369.75']]);
    $ok(str_contains($loc, 'receipt.php') && findInvoice($db, $invId)['status'] === 'partially_paid', 'the patient pays their 369.75; the invoice waits for the insurer');
    [$c] = $http($rec, 'GET', '/pages/claims.php');
    $ok($c === 403, 'reception doesn’t see the claims');

    echo "Claims\n";
    $ins = $login('insurance@clinic.test');
    [$c, , $b] = $http($ins, 'GET', '/pages/claims.php');
    $ok($c === 200 && str_contains($b, $cl['number']) && str_contains($b, 'Not sent within 7 days') && !$phpError($b), 'claims page with the ageing tiles');
    $post($ins, '/pages/claims.php', ['action' => 'batch', 'company_id' => $coId, 'claims' => [$cl['id']]]);
    $bid = (int)$db->query("SELECT batch_id FROM claims WHERE id = {$cl['id']}")->fetchColumn();
    [$c, , $xml] = $http($ins, 'GET', "/pages/claims.php?export=xml&batch=$bid");
    libxml_use_internal_errors(true); $d = new DOMDocument(); $d->loadXML($xml);
    $ok($c === 200 && $d->schemaValidate(ROOT . '/tests/fixtures/eclaimlink_claim_submission.xsd') && str_contains($xml, "<MemberID>M-$tag</MemberID>") && str_contains($xml, "DHA-P-SUITE$tag"), 'the batch exports eClaimLink XML that validates');
    libxml_clear_errors();
    [, , $csv] = $http($ins, 'GET', "/pages/claims.php?export=csv&batch=$bid");
    $ok(str_contains($csv, $cl['number']) && str_contains($csv, '99214'), 'and CSV');
    $post($ins, '/pages/claims.php', ['action' => 'submitted', 'batch_id' => $bid, 'ref' => "PORTAL-$tag", 'date' => date('Y-m-d')]);
    $ok(findClaim($db, (int)$cl['id'])['status'] === 'submitted', 'marked submitted');
    $post($ins, "/pages/claim.php?id={$cl['id']}", ['action' => 'rejected', 'denial_code' => 'PRCE-001', 'note' => 'No approval']);
    $post($ins, "/pages/claim.php?id={$cl['id']}", ['action' => 'ready', 'note' => 'Approval obtained']);
    $post($ins, '/pages/claims.php', ['action' => 'batch', 'company_id' => $coId, 'claims' => [$cl['id']]]);
    $bid2 = (int)$db->query("SELECT batch_id FROM claims WHERE id = {$cl['id']}")->fetchColumn();
    [, , $xml2] = $http($ins, 'GET', "/pages/claims.php?export=xml&batch=$bid2");
    $post($ins, '/pages/claims.php', ['action' => 'submitted', 'batch_id' => $bid2, 'ref' => "PORTAL2-$tag", 'date' => date('Y-m-d')]);
    $ok($bid2 !== $bid && str_contains($xml2, '<Resubmission>') && findClaim($db, (int)$cl['id'])['status'] === 'resubmitted', 'rejected → corrected → resubmitted in a new batch');
    $post($ins, "/pages/claim.php?id={$cl['id']}", ['action' => 'remit', 'amount' => '312.75', 'date' => date('Y-m-d'), 'ref' => "RA-$tag"]);
    [, , $b] = $http($ins, 'GET', "/pages/claim.php?id={$cl['id']}");
    $ok(findClaim($db, (int)$cl['id'])['status'] === 'paid' && findInvoice($db, $invId)['status'] === 'paid' && str_contains($b, 'Paid') && !$phpError($b), 'the remittance pays the claim; the invoice is paid');
    [, , $b] = $http($ins, 'GET', '/pages/remittances.php');
    $ok(str_contains($b, "RA-$tag") && !$phpError($b), 'the remittance is listed');

    echo "Prior approvals\n";
    $post($ins, '/pages/prior_auths.php', ['action' => 'request', 'patient_id' => $pid, 'card_id' => $card, 'services' => [$svcA], 'codes' => 'L70.0', 'appointment_id' => $appt]);
    $pa = (int)$db->query("SELECT id FROM prior_auths WHERE patient_id = $pid")->fetchColumn();
    $post($ins, '/pages/prior_auths.php', ['action' => 'decide', 'id' => $pa, 'status' => 'approved', 'approval_no' => "PA-$tag", 'valid_until' => date('Y-m-d', strtotime('+30 days'))]);
    [, , $b] = $http($ins, 'GET', "/pages/prior_auths.php?id=$pa");
    $ok($pa && str_contains($b, "PA-$tag") && str_contains($b, 'Approved') && !$phpError($b), 'approval requested and approved');
    [, , $b] = $http($rec, 'GET', "/pages/appointment.php?id=$appt");
    $ok(!str_contains($b, 'Approval needed for'), 'the appointment no longer warns');

    echo "Who sees what\n";
    foreach (['layla@clinic.test' => 'consultant', 'nurse@clinic.test' => 'nurse'] as $email => $role) {
        $j = $login($email);
        [$c1] = $http($j, 'GET', '/pages/claims.php'); [$c2] = $http($j, 'GET', "/pages/claim.php?id={$cl['id']}"); [$c3] = $http($j, 'GET', '/pages/insurance.php');
        $ok($c1 === 403 && $c2 === 403 && $c3 === 403, "$role: no claims or insurance set-up");
    }
    $fin = $login('finance@clinic.test');
    [$c, , $b] = $http($fin, 'GET', "/pages/claim.php?id={$cl['id']}");
    $ok($c === 200 && !str_contains($b, 'Record a payment'), 'finance can view a claim but not work on it');
    foreach (['/pages/claims.php?status=paid', '/pages/prior_auths.php', "/pages/prior_auths.php?patient=$pid", "/pages/insurance.php?prices=$planId", '/pages/settings_clinic.php', "/pages/invoice.php?id=$invId", "/pages/doc_print.php?type=invoice&id=$invId"] as $p) {
        [$c, , $b] = $http($admin, 'GET', $p); $ok($c === 200 && !$phpError($b), "$p loads");
    }
} finally {
    $rids = $db->query("SELECT DISTINCT receipt_id FROM receipt_allocations WHERE invoice_id = " . (int)$invId)->fetchAll(PDO::FETCH_COLUMN);
    if ($rids) { $in = implode(',', $rids); foreach (['receipt_methods', 'receipt_allocations'] as $t) $db->exec("DELETE FROM $t WHERE receipt_id IN ($in)");
        $db->exec("DELETE FROM document_renditions WHERE doc_type = 'receipt' AND doc_id IN ($in)"); $db->exec("DELETE FROM audit_log WHERE entity = 'receipt' AND entity_id IN ($in)"); $db->exec("DELETE FROM receipts WHERE id IN ($in)"); }
    $cids = $db->query("SELECT id FROM claims WHERE invoice_id = " . (int)$invId)->fetchAll(PDO::FETCH_COLUMN);
    if ($cids) { $in = implode(',', $cids);
        $db->exec("DELETE FROM claim_events WHERE claim_id IN ($in)"); $db->exec("DELETE FROM audit_log WHERE entity = 'claim' AND entity_id IN ($in)"); $db->exec("DELETE FROM claims WHERE id IN ($in)"); }
    if ($coId) { foreach ($db->query("SELECT id, file_path FROM claim_batches WHERE company_id = $coId")->fetchAll() as $b) { if ($b['file_path'] && is_file(tenantStoragePath() . '/' . $b['file_path'])) unlink(tenantStoragePath() . '/' . $b['file_path']); $db->exec("DELETE FROM audit_log WHERE entity = 'claim_batch' AND entity_id = " . (int)$b['id']); }
        $db->exec("DELETE FROM claim_batches WHERE company_id = $coId"); }
    if ($invId) { $db->exec("DELETE FROM document_renditions WHERE doc_type = 'invoice' AND doc_id = $invId"); $db->exec("DELETE FROM invoice_lines WHERE invoice_id = $invId");
        $db->exec("DELETE FROM audit_log WHERE entity = 'invoice' AND entity_id = $invId"); $db->exec("DELETE FROM invoices WHERE id = $invId"); }
    $db->exec("DELETE FROM audit_log WHERE entity = 'prior_auth' AND entity_id IN (SELECT id FROM prior_auths WHERE patient_id = $pid)");
    foreach (['prior_auths', 'patient_insurance', 'patient_wallet', 'patient_access_log'] as $t) $db->exec("DELETE FROM $t WHERE patient_id = $pid");
    if ($planId) { $db->exec("DELETE i FROM price_list_items i JOIN price_lists p ON p.id = i.price_list_id WHERE p.insurance_plan_id = $planId"); $db->exec("DELETE FROM price_lists WHERE insurance_plan_id = $planId");
        $db->exec("DELETE FROM audit_log WHERE entity = 'insurance_plan' AND entity_id = $planId"); $db->exec("DELETE FROM insurance_plans WHERE id = $planId"); }
    if ($coId) { $db->exec("DELETE FROM audit_log WHERE entity = 'insurance_company' AND entity_id = $coId"); $db->exec("DELETE FROM insurance_companies WHERE id = $coId"); }
    $db->exec("DELETE FROM encounter_diagnoses WHERE encounter_id = $enc"); $db->exec("DELETE FROM encounters WHERE id = $enc");
    foreach (['appointment_status_history', 'appointment_services'] as $t) $db->exec("DELETE FROM $t WHERE appointment_id = $appt");
    $db->exec("DELETE FROM appointments WHERE id = $appt");
    $db->exec("DELETE FROM audit_log WHERE entity = 'patient' AND entity_id = $pid"); $db->exec("DELETE FROM patients WHERE id = $pid");
    $db->exec("DELETE FROM services WHERE id IN ($svcA, $svcB)");
    $db->prepare("UPDATE branches SET dha_license = ? WHERE id = ?")->execute([$oldDha ?: null, $branch]);
    if ($hadProfile) $db->prepare("UPDATE practitioner_profiles SET dha_license = ? WHERE staff_id = ?")->execute([$hadProfile['dha_license'], $doctorId]); else $db->exec("DELETE FROM practitioner_profiles WHERE staff_id = $doctorId");
    $db->exec("DELETE FROM login_log WHERE user_agent = 'ClinicCRM-insurance-suite/1.0'");
}
echo "\n$pass passed, $fail failed\n";
exit($fail ? 1 : 0);

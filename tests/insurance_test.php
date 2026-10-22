<?php
// Phase 9: insurance. Co-pay maths, plan prices, patient vs insurer share (the front desk takes only the patient's),
// cards and prior approvals, and the brief's test: an insurance visit runs to a "paid" claim (batch → eClaimLink XML
// that validates against the sample schema → submitted → rejected → corrected → resubmitted → paid by remittances);
// the ageing tiles count correctly. Scratch database clinic_crm_test_insurance.
require_once __DIR__ . '/../includes/insurance.php';

function insDb(): PDO {
    static $done = false;
    $db = testDb('clinic_crm_test_insurance', !$done);
    if (!$done) {
        migrateApply($db, __DIR__ . '/../migrations/tenant', fn($l) => null);
        $db->exec("UPDATE branches SET invoice_prefix = 'INS', trn = '100300400500003', dha_license = 'DHA-F-0045678' WHERE id = 1");
        $db->exec("INSERT INTO staff (id, name, email, password, role) VALUES (701, 'Ina Insurance', 'ina.i@example.test', 'x', 'insurance'), (702, 'Dr Claim', 'dr.i@example.test', 'x', 'practitioner'), (703, 'Rex Reception', 'rex.i@example.test', 'x', 'receptionist')");
        $db->exec("INSERT INTO practitioner_profiles (staff_id, dha_license) VALUES (702, 'DHA-P-0099887')");
        $db->exec("INSERT INTO services (id, name, price, vat_rate, cpt_code, duration_min) VALUES (41, 'Consultation', 300, 5, '99213', 20), (42, 'Acne laser', 800, 5, '17110', 30), (43, 'HydraFacial', 350, 5, NULL, 45)");
        $db->exec("INSERT INTO patients (id, mrn, first_name, last_name, status, branch_id) VALUES (1, 'I-1', 'Ira', 'Insured', 'active', 1)");
        $db->exec("INSERT INTO insurance_companies (id, name, type, payer_id, receiver_id) VALUES (1, 'Gulf Health', 'payer', 'INS026', 'TPA001'), (2, 'Other Co', 'payer', 'INS099', NULL)");
        $db->exec("INSERT INTO insurance_plans (id, company_id, name, copay_percent, deductible, max_copay, preauth_service_ids) VALUES (1, 1, 'Gold', 20, 50, 100, '[42]'), (2, 2, 'Basic', 10, 0, NULL, NULL)");
        $done = true;
    }
    $_SESSION['staff_id'] = 701; $_SESSION['role'] = 'insurance'; staffPermissionOverrides(701, true);
    return $db;
}
function visitWithDx(PDO $db, array $services, string $code = 'L70.0'): array {
    $db->exec("INSERT INTO appointments (patient_id, service_id, scheduled_at, ends_at, practitioner_id, branch_id, status) VALUES (1, {$services[0]}, NOW() - INTERVAL 1 HOUR, NOW() - INTERVAL 30 MINUTE, 702, 1, 'completed')");
    $ap = (int)$db->lastInsertId();
    foreach ($services as $s) $db->exec("INSERT INTO appointment_services (appointment_id, service_id) VALUES ($ap, $s)");
    $db->exec("INSERT INTO encounters (patient_id, appointment_id, practitioner_id, created_by) VALUES (1, $ap, 702, 702)");
    $enc = (int)$db->lastInsertId();
    if ($code) $db->exec("INSERT INTO encounter_diagnoses (encounter_id, icd10_code, description, is_primary) VALUES ($enc, '$code', 'Acne', 1)");
    return [$ap, $enc];
}

function test_copay_maths() {
    $rows = [['id' => 1, 'total' => 31500, 'covered' => true], ['id' => 2, 'total' => 84000, 'covered' => true], ['id' => 3, 'total' => 36750, 'covered' => false]];
    $s = patientShares($rows, ['deductible' => 50, 'copay_percent' => 20, 'max_copay' => 100]);
    check($s[3] === 36750, 'a line not covered is all the patient’s');
    check($s[1] + $s[2] === 10000, 'deductible 50 + 20% of the rest, capped at 100 = 100.00');
    check($s[1] === 2727 && $s[2] === 7273, 'the patient’s part is spread by line size; the last takes the rounding');
    $s = patientShares($rows, ['deductible' => 0, 'copay_percent' => 10, 'max_copay' => null]);
    check($s[1] + $s[2] === 11550, 'no cap: 10% of 1,155.00 = 115.50');
    $s = patientShares([['id' => 1, 'total' => 3000, 'covered' => true]], ['deductible' => 50, 'copay_percent' => 20, 'max_copay' => null]);
    check($s[1] === 3000, 'a deductible larger than the bill: the patient pays it all, never more');
}

function test_cards_prices_and_shares() {
    $db = insDb();
    $exp = savePatientCard($db, 1, 0, ['plan_id' => 2, 'member_no' => 'B-1', 'valid_from' => date('Y-m-d', strtotime('-2 years')), 'valid_to' => date('Y-m-d', strtotime('-1 day'))]);
    $card = savePatientCard($db, 1, 0, ['plan_id' => 1, 'member_no' => 'GH-55501', 'policy_no' => 'POL-9', 'valid_from' => date('Y-m-d', strtotime('-1 year')), 'valid_to' => date('Y-m-d', strtotime('+20 days')), 'is_primary' => 1]);
    check(count(patientCards($db, 1, true)) === 1, 'only cards valid today can be used');
    $alerts = implode(' ', cardAlerts($db, 1));
    check(str_contains($alerts, 'expired') && str_contains($alerts, 'ends'), 'alerts: one expired, one ending within 30 days');
    savePlanPrices($db, 1, [41 => ['price' => 250, 'cpt_code' => '99213'], 42 => ['price' => '', 'cpt_code' => '']]);
    [$ap] = visitWithDx($db, [41]);
    $_SESSION['staff_id'] = 703; $_SESSION['role'] = 'receptionist'; staffPermissionOverrides(703, true);
    $inv = invoiceFromVisit($db, $ap);
    check(findInvoice($db, $inv)['total'] === '315.00', 'cash price first: 300 + VAT');
    checkThrows(fn() => setInvoiceInsurance($db, $inv, $exp), 'isn’t valid today', 'an expired card is refused');
    setInvoiceInsurance($db, $inv, $card);
    $i = findInvoice($db, $inv);
    check($i['type'] === 'insurance' && $i['total'] === '262.50' && $i['patient_share'] === '92.50' && $i['insurance_share'] === '170.00', "plan price 250 + VAT = 262.50; patient 50 + 20% × 212.50 = 92.50 (got {$i['patient_share']})");
    addInvoiceLine($db, $inv, ['kind' => 'service', 'service_id' => 43]);
    $hf = array_values(array_filter(invoiceLines($db, $inv), fn($l) => (int)$l['service_id'] === 43))[0];
    setLineCovered($db, $inv, (int)$hf['id'], false);
    $i = findInvoice($db, $inv);
    check($i['patient_share'] === '460.00' && $i['insurance_share'] === '170.00', 'HydraFacial not covered: the patient pays its 367.50 on top');
    setInvoiceInsurance($db, $inv, null);
    check(findInvoice($db, $inv)['total'] === '682.50' && findInvoice($db, $inv)['patient_share'] === '682.50', 'back to cash: cash prices, the patient pays everything');
    deleteDraftInvoice($db, $inv);
}

function test_prior_approvals() {
    $db = insDb();
    $card = (int)$db->query("SELECT id FROM patient_insurance WHERE member_no = 'GH-55501'")->fetchColumn();
    check(array_keys(servicesNeedingApproval($db, 1, 1, [41, 42])) === [42], 'Gold needs approval for the acne laser');
    checkThrows(fn() => requestPriorAuth($db, 1, $card, [42], ['acne']), 'ICD-10', 'diagnosis codes are checked');
    $pa = requestPriorAuth($db, 1, $card, [42], ['l70.0']);
    checkThrows(fn() => decidePriorAuth($db, $pa, 'approved', []), 'approval number', 'approving needs the insurer’s number');
    decidePriorAuth($db, $pa, 'approved', ['approval_no' => 'PA-777', 'approved_amount' => 800, 'valid_until' => date('Y-m-d', strtotime('+30 days'))]);
    check(servicesNeedingApproval($db, 1, 1, [42]) === [], 'once approved, nothing is missing');
    $db->exec("UPDATE prior_auths SET valid_until = CURDATE() - INTERVAL 1 DAY WHERE id = $pa");
    check(expirePriorAuths($db) === 1 && isset(servicesNeedingApproval($db, 1, 1, [42])[42]), 'after its date it expires and is needed again');
}

function test_an_insurance_visit_runs_to_a_paid_claim() {
    $db = insDb();
    $card = (int)$db->query("SELECT id FROM patient_insurance WHERE member_no = 'GH-55501'")->fetchColumn();
    $pa = requestPriorAuth($db, 1, $card, [42], []);
    decidePriorAuth($db, $pa, 'approved', ['approval_no' => 'PA-888', 'valid_until' => date('Y-m-d', strtotime('+30 days'))]);
    [$ap, $enc] = visitWithDx($db, [41, 42], '');
    $db->exec("UPDATE appointments SET prior_auth_id = $pa WHERE id = $ap");
    $_SESSION['staff_id'] = 703; $_SESSION['role'] = 'receptionist'; staffPermissionOverrides(703, true);
    $inv = invoiceFromVisit($db, $ap);
    setInvoiceInsurance($db, $inv, $card);
    issueInvoice($db, $inv);
    $i = findInvoice($db, $inv);
    $cl = findClaim($db, (int)$db->query("SELECT id FROM claims WHERE invoice_id = $inv")->fetchColumn());
    check($cl && $cl['claimed'] === $i['insurance_share'] && (float)$i['patient_share'] === 100.0 && preg_match('/^INS-CLM-\d{4}-\d{6}$/', $cl['number']), "issuing makes claim {$cl['number']} for the insurer’s " . $cl['claimed'] . '; patient pays 100.00 (capped)');
    check($cl['status'] === 'draft' && str_contains(implode(' ', claimProblems($db, $cl)), 'diagnosis'), 'no diagnosis yet: the claim waits, saying why');
    // The front desk takes the patient's share only
    checkThrows(fn() => createReceipt($db, 1, 1, [['method' => 'cash', 'amount' => 200]], [$inv => 200]), 'by the patient', 'the front desk can’t take more than the patient’s share');
    checkThrows(fn() => createReceipt($db, 1, 1, [['method' => 'insurance', 'amount' => 10]], [$inv => 10]), 'remittance', 'insurance payments aren’t taken at the front desk');
    createReceipt($db, 1, 1, [['method' => 'card', 'amount' => 100]], [$inv => 100]);
    check(findInvoice($db, $inv)['status'] === 'partially_paid' && invoicePatientDue(findInvoice($db, $inv)) === 0, 'patient paid their share; the invoice waits for the insurer');
    $db->exec("INSERT INTO encounter_diagnoses (encounter_id, icd10_code, description, is_primary) VALUES ($enc, 'L70.0', 'Acne vulgaris', 1), ($enc, 'L90.5', 'Scar', 0)");
    $_SESSION['staff_id'] = 701; $_SESSION['role'] = 'insurance'; staffPermissionOverrides(701, true);
    check(refreshClaimReadiness($db, (int)$cl['id']) === [] && findClaim($db, (int)$cl['id'])['status'] === 'ready', 'with the diagnosis it is ready to send');
    checkThrows(fn() => createClaimBatch($db, 2, [$cl['id']]), 'one insurance company', 'a batch is for one company');
    $b = createClaimBatch($db, 1, [$cl['id']]);
    $xml = exportBatchXml($db, $b);
    libxml_use_internal_errors(true);
    $doc = new DOMDocument(); $doc->loadXML($xml);
    $valid = $doc->schemaValidate(__DIR__ . '/fixtures/eclaimlink_claim_submission.xsd');
    check($valid, 'the eClaimLink XML validates against the sample schema' . ($valid ? '' : ': ' . implode('; ', array_map(fn($e) => trim($e->message), libxml_get_errors()))));
    libxml_clear_errors();
    check(str_contains($xml, '<ReceiverID>TPA001</ReceiverID>') && str_contains($xml, '<MemberID>GH-55501</MemberID>') && str_contains($xml, '<Code>L70.0</Code>') && str_contains($xml, '<Type>Principal</Type>')
        && str_contains($xml, '<Clinician>DHA-P-0099887</Clinician>') && str_contains($xml, '<PriorAuthorizationID>PA-888</PriorAuthorizationID>') && str_contains($xml, '<EmiratesIDNumber>' . EID_UNKNOWN . '</EmiratesIDNumber>'), 'XML carries member, TPA, diagnoses, clinician licence, approval number, EID placeholder');
    $sx = simplexml_load_string($xml);
    check((string)$sx->Claim->Net === $cl['claimed'] && (string)$sx->Claim->PatientShare === '100.00', 'Net = the insurer’s share, PatientShare = the patient’s');
    check(str_contains(exportBatchCsv($db, $b), $cl['number']), 'CSV export too');
    markBatchSubmitted($db, $b, 'ECL-2026-001', date('Y-m-d'));
    check(findClaim($db, (int)$cl['id'])['status'] === 'submitted', 'submitted with the portal reference');
    $db->exec("INSERT INTO denial_codes (code, description) VALUES ('MNEC-004', 'Not clinically indicated')");
    checkThrows(fn() => claimTransition($db, (int)$cl['id'], 'rejected', []), 'denial code', 'a rejection needs its denial code');
    claimTransition($db, (int)$cl['id'], 'rejected', ['denial_code' => 'MNEC-004']);
    claimTransition($db, (int)$cl['id'], 'ready', ['note' => 'Added scar diagnosis and photos']);
    $b2 = createClaimBatch($db, 1, [$cl['id']]);
    $xml2 = exportBatchXml($db, $b2);
    check(str_contains($xml2, '<Resubmission>') && str_contains($xml2, 'Added scar diagnosis'), 'the corrected claim is sent again as a resubmission');
    markBatchSubmitted($db, $b2, 'ECL-2026-002', date('Y-m-d'));
    check(findClaim($db, (int)$cl['id'])['status'] === 'resubmitted', 'status resubmitted');
    claimTransition($db, (int)$cl['id'], 'accepted', []);
    $half = round((float)$cl['claimed'] / 2, 2);
    checkThrows(fn() => recordRemittance($db, (int)$cl['id'], 99999, date('Y-m-d'), 'RA-1'), 'at most', 'can’t record more than is claimed');
    recordRemittance($db, (int)$cl['id'], $half, date('Y-m-d'), 'RA-1');
    check(findClaim($db, (int)$cl['id'])['status'] === 'partially_paid' && findInvoice($db, $inv)['status'] === 'partially_paid', 'first remittance: part paid');
    recordRemittance($db, (int)$cl['id'], round((float)$cl['claimed'] - $half, 2), date('Y-m-d'), 'RA-2');
    $i = findInvoice($db, $inv);
    check(findClaim($db, (int)$cl['id'])['status'] === 'paid' && $i['status'] === 'paid' && $i['insurer_paid'] === $cl['claimed'], 'second remittance: claim PAID and the invoice paid');
    check(dayCloseExpected($db, 1, 701, date('Y-m-d'))['receipts'] === 0, 'insurer payments don’t go into anyone’s cash drawer');
    check((int)$db->query("SELECT COUNT(*) FROM claim_events WHERE claim_id = {$cl['id']}")->fetchColumn() >= 8, 'every step is in the claim’s history');
    $_SESSION['role'] = 'admin'; staffPermissionOverrides(701, true);
    checkThrows(fn() => voidInvoice($db, $inv, 'x'), 'payments', 'a paid insurance invoice can’t be voided');
}

function test_ageing_tiles_count_correctly() {
    $db = insDb();
    $db->exec("UPDATE claims SET status = 'paid' WHERE status IN ('draft','ready')");   // start clean
    $card = (int)$db->query("SELECT id FROM patient_insurance WHERE member_no = 'GH-55501'")->fetchColumn();
    $_SESSION['staff_id'] = 703; $_SESSION['role'] = 'receptionist'; staffPermissionOverrides(703, true);
    $made = [];
    foreach ([2, 10, 35, 50] as $days) {
        $i = createInvoice($db, 1, 1); setInvoiceInsurance($db, $i, $card); addInvoiceLine($db, $i, ['kind' => 'service', 'service_id' => 41, 'practitioner_id' => 702]); issueInvoice($db, $i);
        $db->exec("UPDATE invoices SET issued_at = NOW() - INTERVAL $days DAY WHERE id = $i");
        $made[$days] = (float)findInvoice($db, $i)['insurance_share'];
    }
    $a = claimAgeing($db);
    check($a[7][0] === 3 && $a[30][0] === 2 && $a[45][0] === 1 && $a['all'][0] === 4, "tiles: >7 days 3, >30 days 2, >45 days 1, all 4 (got {$a[7][0]}/{$a[30][0]}/{$a[45][0]}/{$a['all'][0]})");
    check($a[45][1] === fils($made[50]) && $a[30][1] === fils($made[35] + $made[50]), 'and their amounts');
    $c = (int)$db->query("SELECT id FROM claims ORDER BY id DESC LIMIT 1")->fetchColumn();
    $inv = (int)$db->query("SELECT invoice_id FROM claims WHERE id = $c")->fetchColumn();
    $_SESSION['staff_id'] = 701; $_SESSION['role'] = 'admin'; staffPermissionOverrides(701, true);
    voidInvoice($db, $inv, 'Wrong patient');
    check(findClaim($db, $c)['status'] === 'written_off' && claimAgeing($db)['all'][0] === 3, 'voiding an unsent invoice writes its claim off');
}

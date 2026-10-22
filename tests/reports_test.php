<?php
// Phase 12: the reports against a hand-calculated fixture (September 2026, branch 1). Every expected number below is
// worked out by hand in the comments, from the rows inserted in rptDb(). Scratch database clinic_crm_test_reports.
//
// Patients: 801 Aisha (AE, female, born 1990-05-01, national), 802 Ravi (IN, male, born 2020-01-01, resident),
//           803 Grace (GB, female, born 1960-01-01, visitor).
// Practitioners: 911 Dr Dent (Dental), 912 Dr Skin (Dermatology). Treatments: 71 Filling 5%, 72 Peel 5% (needs consent), 73 Check-up 0%.
// Appointments: A1 Aisha 2026-08-15 Filling done (before the period: her first visit)
//               A2 Aisha 09-05 Filling done, arrived 10:00, doctor 10:20 (20 min)    → follow-up
//               A3 Ravi  09-10 Peel done, arrived 11:00, doctor 11:10 (10 min)       → new
//               A4 Grace 09-12 Check-up done (no times)                              → new
//               A5 Ravi  09-20 Peel no-show · A6 Grace 09-25 cancelled · A7 Ravi 09-28 Peel done → follow-up
// Invoices: I1 Aisha cash 09-05: Filling 1,000 + VAT 50 = 1,050, paid; credit note 09-15: 100 + 5 = 105 refunded in cash
//           I2 Ravi insurance (Acme Health) 09-10: Peel 400 + 20, Check-up 200 + 0 = 620; patient share 124 paid by card
//           I3 Grace cash 09-12: Check-up 300 + 0 = 300, unpaid · I4 void 09-13 (999) · I5 2026-08-30 (500, before the period)
// Receipts: cash 1,050 (09-05), card 124 (09-10), cash 200 voucher sale (09-11), void cash 77 (09-12), insurance 300 (09-20)
// Wallet deposit 250 (09-14). Purchase VAT register: 2,000 + 100 (09-03), 50 + 2.50 (2026-10-01, outside).
require_once __DIR__ . '/../includes/reports.php';

const RF = '2026-09-01', RT = '2026-09-30';

function rptDb(): PDO {
    static $done = false;
    $db = testDb('clinic_crm_test_reports', !$done);
    if (!$done) {
        migrateApply($db, __DIR__ . '/../migrations/tenant', fn($l) => null);
        $db->exec("UPDATE branches SET invoice_prefix = 'RPT' WHERE id = 1");
        $db->exec("INSERT INTO departments (id, name) VALUES (1, 'Dental'), (2, 'Dermatology')");
        $db->exec("INSERT INTO staff (id, name, email, password, role) VALUES (910, 'Owner', 'o.r@example.test', 'x', 'admin'), (911, 'Dr Dent', 'd.r@example.test', 'x', 'practitioner'), (912, 'Dr Skin', 's.r@example.test', 'x', 'practitioner')");
        $db->exec("INSERT INTO practitioner_profiles (staff_id, department_id) VALUES (911, 1), (912, 2)");
        $db->exec("INSERT INTO consent_templates (id, name, body_en, version, is_active) VALUES (5, 'Peel consent', 'x', 1, 1)");
        $db->exec("INSERT INTO services (id, name, price, vat_rate, duration_min, category, requires_consent_template_id) VALUES (71, 'Filling', 1000, 5, 30, 'Dental', NULL), (72, 'Peel', 400, 5, 30, 'Skin', 5), (73, 'Check-up', 200, 0, 15, 'General', NULL)");
        $db->exec("INSERT INTO patients (id, mrn, first_name, last_name, gender, dob, nationality, residency, branch_id, status, marketing_consent) VALUES
                   (801, 'R-801', 'Aisha', 'A', 'female', '1990-05-01', 'AE', 'citizen', 1, 'active', 1), (802, 'R-802', 'Ravi', 'R', 'male', '2020-01-01', 'IN', 'resident', 1, 'active', 0),
                   (803, 'R-803', 'Grace', 'G', 'female', '1960-01-01', 'GB', 'visitor', 1, 'active', 1)");
        foreach ([[1, 801, 71, 911, '2026-08-15 09:00', 'completed', null, null], [2, 801, 71, 911, '2026-09-05 10:00', 'completed', '2026-09-05 10:00', '2026-09-05 10:20'],
                  [3, 802, 72, 912, '2026-09-10 11:00', 'completed', '2026-09-10 11:00', '2026-09-10 11:10'], [4, 803, 73, 912, '2026-09-12 12:00', 'completed', null, null],
                  [5, 802, 72, 912, '2026-09-20 12:00', 'no_show', null, null], [6, 803, 73, 912, '2026-09-25 12:00', 'cancelled', null, null], [7, 802, 72, 912, '2026-09-28 12:00', 'completed', null, null]] as [$id, $p, $s, $pr, $at, $st, $arr, $doc]) {
            $db->prepare("INSERT INTO appointments (id, patient_id, service_id, practitioner_id, branch_id, scheduled_at, ends_at, status, arrived_at, doctor_in_at, visit_type) VALUES (?,?,?,?,1,?, ? + INTERVAL 30 MINUTE,?,?,?, 'treatment')")
               ->execute([$id, $p, $s, $pr, $at, $at, $st, $arr, $doc]);
            $db->exec("INSERT INTO appointment_services (appointment_id, service_id, qty) VALUES ($id, $s, 1)");
        }
        $db->exec("INSERT INTO insurance_companies (id, name) VALUES (1, 'Acme Health')");
        $db->exec("INSERT INTO insurance_plans (id, company_id, name) VALUES (1, 1, 'Gold')");
        $db->exec("INSERT INTO patient_insurance (patient_id, plan_id, member_no, valid_from, valid_to, is_active) VALUES (802, 1, 'M1', '2026-01-01', CURDATE() + INTERVAL 10 DAY, 1)");
        $inv = $db->prepare("INSERT INTO invoices (id, number, branch_id, patient_id, type, insurance_plan_id, status, issued_at, gross, net, vat, total, patient_share, insurance_share, paid, credited, refunded) VALUES (?,?,1,?,?,?,?,?,?,?,?,?,?,?,?,?,?)");
        $inv->execute([1, 'RPT-1', 801, 'cash', null, 'paid', '2026-09-05 10:40', 1000, 1000, 50, 1050, 1050, 0, 1050, 105, 105]);
        $inv->execute([2, 'RPT-2', 802, 'insurance', 1, 'partially_paid', '2026-09-10 11:40', 600, 600, 20, 620, 124, 496, 124, 0, 0]);
        $inv->execute([3, 'RPT-3', 803, 'cash', null, 'issued', '2026-09-12 12:30', 300, 300, 0, 300, 300, 0, 0, 0, 0]);
        $inv->execute([4, 'RPT-4', 803, 'cash', null, 'void', '2026-09-13 12:30', 999, 999, 0, 999, 999, 0, 0, 0, 0]);
        $inv->execute([5, 'RPT-5', 801, 'cash', null, 'paid', '2026-08-30 12:30', 500, 500, 0, 500, 500, 0, 500, 0, 0]);
        $ln = $db->prepare("INSERT INTO invoice_lines (id, invoice_id, kind, service_id, practitioner_id, description, qty, unit_price, taxable, vat_rate, vat_amount, line_total) VALUES (?,?, 'service', ?,?,?, 1, ?,?,?,?,?)");
        foreach ([[1, 1, 71, 911, 'Filling', 1000, 5, 50], [2, 2, 72, 912, 'Peel', 400, 5, 20], [3, 2, 73, 912, 'Check-up', 200, 0, 0], [4, 3, 73, 912, 'Check-up', 300, 0, 0], [5, 4, 73, 912, 'Check-up', 999, 0, 0], [6, 5, 73, 911, 'Check-up', 500, 0, 0]] as [$id, $i, $s, $pr, $d, $amt, $r, $v])
            $ln->execute([$id, $i, $s, $pr, $d, $amt, $amt, $r, $v, $amt + $v]);
        $db->exec("INSERT INTO credit_notes (id, number, invoice_id, branch_id, reason, gross, vat, total, refund_method, refunded, issued_at) VALUES (1, 'RPT-CN-1', 1, 1, 'Goodwill', 100, 5, 105, 'cash', 105, '2026-09-15 09:00')");
        $db->exec("INSERT INTO credit_note_lines (credit_note_id, invoice_line_id, qty, amount, vat_amount) VALUES (1, 1, 0.1, 100, 5)");
        $db->exec("INSERT INTO vouchers (id, code, initial, expires_on, status) VALUES (1, 'GVTEST01', 200, CURDATE() + INTERVAL 1 YEAR, 'active')");
        foreach ([[1, 801, null, 1050, '2026-09-05 10:45', 'valid', 'cash'], [2, 802, null, 124, '2026-09-10 11:45', 'valid', 'card'], [3, null, 1, 200, '2026-09-11 09:00', 'valid', 'cash'],
                  [4, 803, null, 77, '2026-09-12 13:00', 'void', 'cash'], [5, null, null, 300, '2026-09-20 09:00', 'valid', 'insurance']] as [$id, $p, $v, $amt, $at, $st, $m]) {
            $db->prepare("INSERT INTO receipts (id, number, branch_id, patient_id, voucher_id, total, received_at, status) VALUES (?,?,1,?,?,?,?,?)")->execute([$id, "RPT-R-$id", $p, $v, $amt, $at, $st]);
            $db->prepare("INSERT INTO receipt_methods (receipt_id, method, amount) VALUES (?,?,?)")->execute([$id, $m, $amt]);
        }
        $db->exec("INSERT INTO patient_wallet (patient_id, type, amount, method, branch_id, at) VALUES (803, 'deposit', 250, 'cash', 1, '2026-09-14 10:00')");
        $db->exec("INSERT INTO purchase_vat (invoice_date, supplier, invoice_ref, net, vat) VALUES ('2026-09-03', 'MedSupply', 'MS-1', 2000, 100), ('2026-10-01', 'Stationer', 'ST-9', 50, 2.5)");
        $enc = $db->prepare("INSERT INTO encounters (id, patient_id, appointment_id, branch_id, practitioner_id, department_id, status, created_at) VALUES (?,?,?,1,?,?,?,?)");
        $enc->execute([1, 801, 2, 911, 1, 'signed', '2026-09-05 10:20']); $enc->execute([2, 802, 3, 912, 2, 'signed', '2026-09-10 11:10']); $enc->execute([3, 803, 4, 912, 2, 'open', '2026-09-12 12:05']);
        $db->exec("INSERT INTO encounter_diagnoses (encounter_id, icd10_code, description, is_primary, kind) VALUES (1, 'K02.1', 'Caries of dentine', 1, 'final'), (2, 'L70.0', 'Acne vulgaris', 1, 'final'), (3, 'L70.0', 'Acne vulgaris', 1, 'provisional')");
        $db->exec("INSERT INTO prescriptions (id, encounter_id, patient_id, prescribed_by, created_at) VALUES (1, 2, 802, 912, '2026-09-10 11:20')");
        $db->exec("INSERT INTO prescription_items (prescription_id, drug, strength) VALUES (1, 'Doxycycline', '100 mg')");
        $done = true;
    }
    $_SESSION['staff_id'] = 910; $_SESSION['role'] = 'admin';
    return $db;
}

/** Rows keyed by one column, for easy checks. */
function by(array $rows, string $k): array { return array_column($rows, null, $k); }

function test_money_summary_and_collections() {
    $db = rptDb();
    $m = rptMoneySummary($db, RF, RT);
    // Invoiced 1,050 + 620 + 300 = 1,970 (I4 void, I5 August left out); before VAT 1,000 + 600 + 300 = 1,900; VAT 50 + 20 = 70
    check($m['invoices'] === 3 && $m['invoiced'] == 1970 && $m['revenue'] == 1900 && $m['vat'] == 70, 'invoiced 1,970, revenue 1,900, VAT 70 (void and August left out)');
    // Credit note 105; revenue after credit notes 1,900 − 100 = 1,800
    check($m['credited'] == 105 && $m['net_revenue'] == 1800, 'credit notes 105, revenue after them 1,800');
    // Money from patients: 1,050 + 124 + 200 = 1,374 (the void 77 left out); insurance 300 separately
    check($m['collected'] == 1374 && $m['insurance'] == 300, 'collected 1,374 from patients, 300 from insurance');
    $c = by(rptCollectionsByMethod($db, RF, RT), 'method');
    check($c['cash']['amount'] == 1250 && $c['card']['amount'] == 124 && $c['insurance']['amount'] == 300 && count($c) === 3, 'by method: cash 1,250, card 124, insurance 300');
    $d = by(rptDailyCollection($db, RF, RT), 'day');
    check($d['2026-09-05']['cash'] == 1050 && $d['2026-09-11']['total'] == 200 && !isset($d['2026-09-12']), 'daily collection: 5 Sep cash 1,050, 11 Sep 200, nothing on 12 Sep (void)');
    $a = rptAdvancesRefunds($db, RF, RT);
    check($a['deposits'] == 250 && $a['vouchers_sold'] == 200 && $a['refunds_credit_notes'] == 105 && $a['refunds_wallet'] == 0, 'advances: deposit 250, voucher 200; refunded 105');
}

function test_revenue_breakdowns() {
    $db = rptDb();
    $dep = by(rptRevenueBy($db, 'department', RF, RT), 'name');
    // Dental: I1 Filling 1,000. Dermatology: I2 400 + 200, I3 300 = 900
    check($dep['Dental']['revenue'] == 1000 && $dep['Dermatology']['revenue'] == 900 && count($dep) === 2, 'by department: Dental 1,000, Dermatology 900');
    $pr = by(rptRevenueBy($db, 'practitioner', RF, RT), 'name');
    check($pr['Dr Dent']['revenue'] == 1000 && $pr['Dr Skin']['revenue'] == 900, 'by practitioner');
    $sv = by(rptRevenueBy($db, 'service', RF, RT), 'name');
    check($sv['Check-up']['revenue'] == 500 && $sv['Check-up']['qty'] == 2 && $sv['Peel']['revenue'] == 400, 'by treatment: Check-up 500 (2), Peel 400');
    $ins = by(rptRevenueBy($db, 'insurance', RF, RT), 'name');
    check($ins['Self-pay']['revenue'] == 1300 && $ins['Acme Health']['revenue'] == 600, 'by insurance: self-pay 1,300, Acme Health 600');
}

function test_vat_return() {
    $db = rptDb();
    $v = rptVatReturn($db, RF, RT);
    // Box 1: 1,000 + 400 = 1,400, VAT 50 + 20 = 70; adjustment (credit note) −100, VAT −5
    check($v['box1'] === ['amount' => 1400.0, 'vat' => 70.0, 'adj_amount' => -100.0, 'adj_vat' => -5.0], 'box 1: standard-rated 1,400, VAT 70, credit note −100 / −5');
    // Box 4 zero-rated: 200 + 300 = 500. Box 8: 1,400 − 100 + 500 = 1,800, VAT 65
    check($v['box4']['amount'] == 500 && $v['box8'] === ['amount' => 1800.0, 'vat' => 65.0], 'box 4 zero-rated 500; box 8 totals 1,800 and 65');
    // Box 9: the September supplier invoice only, 2,000 + 100. Box 14: 65 − 100 = −35 (to reclaim)
    check($v['box9']['amount'] == 2000 && $v['box9']['vat'] == 100 && $v['box9']['invoices'] === 1 && $v['box14'] == -35.0, 'box 9 input 2,000 / 100; box 14 −35 (reclaim)');
    $rows = by(rptVatRows($v, 'Dubai'), 'box');
    check(str_contains($rows['1']['item'], 'Dubai') && str_contains($rows['14']['item'], 'reclaimed'), 'the return rows name the emirate and say it is a reclaim');
    checkThrows(fn() => savePurchaseVat($db, ['invoice_date' => '2026-09-04', 'supplier' => 'MedSupply', 'invoice_ref' => 'MS-1', 'net' => 10, 'vat' => 0.5]), 'already', 'the same supplier invoice can’t be entered twice');
    checkThrows(fn() => savePurchaseVat($db, ['invoice_date' => '2026-09-04', 'supplier' => 'X', 'invoice_ref' => 'X-1', 'net' => 100, 'vat' => 20]), '5%', 'VAT above 5% is refused');
}

function test_receivables_register_and_liability() {
    $db = rptDb();
    $r = rptPatientReceivables($db);
    // I1 fully settled (1,050 − 105 credited − (1,050 − 105 refunded) = 0); I2 the patient paid their 124 share (the rest is the insurer's); I3 owes 300
    $age = (int)floor((time() - strtotime('2026-09-12 12:30')) / 86400);
    check(count($r) === 1 && $r[0]['number'] === 'RPT-3' && $r[0]['due'] == 300 && $r[0]['age'] === $age, 'patients owe only I3: 300');
    $reg = by(rptInvoiceRegister($db, RF, RT), 'number');
    check(count($reg) === 4 && $reg['RPT-4']['status'] === 'void' && $reg['RPT-2']['balance'] == 496, 'the register has the 4 September invoices (void shown); I2 balance 496 is the insurer’s');
    $cn = rptCreditNotes($db, RF, RT);
    check(count($cn) === 1 && $cn[0]['invoice'] === 'RPT-1' && $cn[0]['total'] == 105, 'credit notes: one, against I1');
    $vl = rptVoucherLiability($db);
    check(count($vl) === 1 && $vl[0]['balance'] == 200, 'voucher liability 200 (not spent)');
}

function test_appointments_and_patients() {
    $db = rptDb();
    $a = rptAppointments($db, RF, RT);
    // September: A2–A7 = 6; not cancelled 5; one no-show → 20%. Waits 20 and 10 → 15 min
    check(array_sum(array_column($a['status'], 'n')) === 6 && $a['no_show_rate'] == 20.0 && $a['avg_wait_min'] == 15.0, 'six appointments, no-show rate 20%, average wait 15 min');
    $dept = by($a['department'], 'name');
    check($dept['Dental']['n'] == 1 && $dept['Dermatology']['n'] == 5, 'by department: Dental 1, Dermatology 5');
    // Seen: Aisha (returning: first visit in August), Ravi and Grace (new)
    check(rptNewReturning($db, RF, RT) === ['seen' => 3, 'new' => 2, 'returning' => 1], 'seen 3: 2 new, 1 returning');
    check(count(rptCardsExpiring($db, 30)) === 1, 'Ravi’s insurance card runs out within 30 days');
}

function test_clinical() {
    $db = rptDb();
    $d = by(rptDiagnoses($db, RF, RT), 'code');
    check($d['L70.0']['encounters'] == 2 && $d['L70.0']['patients'] == 2 && $d['K02.1']['patients'] == 1, 'diagnoses: acne 2 patients, caries 1');
    check(array_column(rptDiagnoses($db, RF, RT, 0, 911), 'code') === ['K02.1'], 'a practitioner sees only their own diagnoses');
    $p = by(rptProcedures($db, RF, RT), 'name');
    // Completed September visits: Filling (A2), Peel (A3, A7), Check-up (A4)
    check($p['Peel']['qty'] == 2 && $p['Peel']['patients'] == 1 && $p['Filling']['qty'] == 1 && $p['Check-up']['qty'] == 1, 'treatments done: Peel 2, Filling 1, Check-up 1');
    check(rptPrescriptions($db, RF, RT)[0]['drug'] === 'Doxycycline', 'prescriptions');
    $u = rptUnsignedNotes($db);
    check(count($u) === 1 && $u[0]['patient'] === 'Grace G', 'one unsigned note (Grace)');
    // Peel needs a consent form; Ravi has none signed → A3 and A7
    check(array_column(rptConsentsMissing($db, RF, RT), 'appointment_id') === [3, 7], 'consents missing for both of Ravi’s peels');
    $db->exec("INSERT INTO patient_consents (patient_id, template_id, template_version, snapshot_html, signed_at) VALUES (802, 5, 1, 'x', '2026-09-10 10:55')");
    check(rptConsentsMissing($db, RF, RT) === [], 'once signed, none missing');
    $db->exec("UPDATE patient_consents SET revoked_at = NOW() WHERE patient_id = 802");
    check(count(rptConsentsMissing($db, RF, RT)) === 2, 'a withdrawn consent counts as missing');
    // Not seen: last visits Aisha 09-05, Grace 09-12, Ravi 09-28 → bucket by days since today
    $days = fn($d) => (int)((strtotime('today') - strtotime($d)) / 86400);
    $bucket = fn($n) => $n > 360 ? 360 : ($n > 180 ? 180 : ($n > 90 ? 90 : ($n > 30 ? 30 : 0)));
    $want = [30 => 0, 90 => 0, 180 => 0, 360 => 0];
    foreach (['2026-09-05', '2026-09-12', '2026-09-28'] as $d) if ($b = $bucket($days($d))) $want[$b]++;
    check(array_column(rptNotVisited($db), 'patients', 'days') === $want, 'patients by time since their last visit');
}

function test_dha() {
    $db = rptDb();
    $v = rptDhaVisits($db, RF, RT);
    check($v['total'] === ['new' => 2, 'follow_up' => 2], 'four visits: 2 new (Ravi, Grace), 2 follow-up (Aisha, Ravi again)');
    $n = by($v['nationality'], 'name');
    check($n['United Arab Emirates']['follow_up'] === 1 && $n['India']['new'] === 1 && $n['India']['follow_up'] === 1 && $n['United Kingdom']['new'] === 1, 'by nationality');
    $s = by($v['sex'], 'name');
    check($s['Female'] === ['name' => 'Female', 'new' => 1, 'follow_up' => 1, 'total' => 2] && $s['Male']['total'] === 2, 'by sex');
    $g = by($v['age'], 'name');
    // Ages at the visit: Aisha 36 (25-44), Ravi 6 (5-14) twice, Grace 66 (65+)
    check($g['25-44']['total'] === 1 && $g['5-14']['total'] === 2 && $g['65+']['new'] === 1 && !isset($g['Not recorded']), 'by age group');
    $nd = rptDhaNationalityDiagnosis($db, RF, RT);
    check(count($nd) === 3 && in_array(['nationality' => 'India', 'code' => 'L70.0', 'description' => 'Acne vulgaris', 'patients' => 1], $nd), 'patients by nationality and diagnosis');
    $w = by(rptDhaWaiting($db, RF, RT), 'practitioner');
    check($w['Dr Dent']['avg_min'] == 20 && $w['Dr Skin']['avg_min'] == 10 && $w['Dr Skin']['within_15'] == 1, 'waiting time per practitioner');
    $dent = rptDhaDepartment($db, 1, RF, RT);
    check($dent['patients'] === 1 && $dent['treatments'][0]['name'] === 'Filling' && $dent['nationality'][0]['nationality'] === 'United Arab Emirates', 'dental: 1 patient, a filling, UAE');
    $skin = rptDhaDepartment($db, 2, RF, RT);
    check($skin['patients'] === 2 && array_sum(array_column($skin['treatments'], 'treatments')) == 3, 'dermatology: 2 patients, 3 treatments');
    $res = by(rptDhaResidency($db, RF, RT), 'name');
    check($res['UAE national']['patients'] == 1 && $res['Resident']['visits'] == 2 && $res['Tourist / visitor']['patients'] == 1, 'nationals, residents, tourists');
}

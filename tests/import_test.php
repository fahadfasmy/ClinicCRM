<?php
// Phase 16: the ClinicSoft import. Reading (semicolons, BOM, UTF-16, Windows Arabic), recognising columns, a check that
// saves nothing, then the import; running it again adds nothing; patients already here (converted leads) are linked,
// not duplicated; balances become collectable opening balances and wallet credit; packages, future appointments,
// past visits, past invoices (records only) and signed clinical notes; nothing imported counts as revenue or VAT.
// Scratch database clinic_crm_test_import.
require_once __DIR__ . '/../includes/importer.php';
require_once __DIR__ . '/../includes/reports.php';

function impDb(): PDO {
    static $done = false;
    $db = testDb('clinic_crm_test_import', !$done);
    if (!$done) {
        migrateApply($db, __DIR__ . '/../migrations/tenant', fn($l) => null);
        $db->exec("UPDATE branches SET invoice_prefix = 'IMP' WHERE id = 1");
        $db->exec("INSERT INTO staff (id, name, email, password, role) VALUES (961, 'Ada Admin', 'ada.i@example.test', 'x', 'admin'), (962, 'Sara Ali', 'sara.i@example.test', 'x', 'practitioner')");
        $db->exec("INSERT INTO services (id, name, price, vat_rate, duration_min) VALUES (91, 'HydraFacial', 500, 5, 60), (92, 'Botox', 1200, 5, 30)");
        $db->exec("INSERT INTO packages (id, name, price, validity_days) VALUES (11, 'Glow 6', 2400, 365), (12, 'Combo', 3000, 365)");
        $db->exec("INSERT INTO package_items (package_id, service_id, sessions) VALUES (11, 91, 6), (12, 91, 3), (12, 92, 2)");
        // A patient already here from the CRM (a converted lead), who is also in ClinicSoft
        $db->exec("INSERT INTO patients (id, mrn, first_name, last_name, status, branch_id) VALUES (970, 'P-970', 'Mariam', 'Lead', 'active', 1)");
        $db->exec("INSERT INTO patient_phones (patient_id, phone, is_primary) VALUES (970, '+971501110000', 1)");
        $done = true;
    }
    $_SESSION['staff_id'] = 961; $_SESSION['role'] = 'admin'; staffPermissionOverrides(961, true);
    return $db;
}
function csvFile(string $content): string { $f = tempnam(sys_get_temp_dir(), 'imp'); file_put_contents($f, $content); return $f; }
function runCsv(PDO $db, string $kind, string $content, bool $commit): array {
    [$h, $rows] = importRead(csvFile($content));
    return importRun($db, $kind, $h, $rows, importGuessMapping($kind, $h), $commit);
}
const PATIENTS_CSV = "File No;Patient Name;Arabic Name;Gender;DOB;Nationality;Mobile;Email;Remarks\n"
    . "10001;Layla Hassan;ليلى حسن;F;12/03/1990;Emirati;050 222 3333;layla@example.test;VIP\n"
    . "10002;Mariam Lead;;Female;31/02/1985;Martian;0501110000;;\n"
    . "10003;;;M;;;;;\n"
    . "10004;Omar Khan;;Male;1979-07-01;Pakistani;+971 55 444 5555;not-an-email;\n";

function test_reading_files_from_any_export() {
    [$h, $r] = importRead(csvFile("\xEF\xBB\xBFFile No;Patient Name\n1;Ali\n\n2;Sara\n"));
    check($h === ['File No', 'Patient Name'] && count($r) === 2, 'semicolons, a BOM and blank lines');
    [$h, $r] = importRead(csvFile("\xFF\xFE" . mb_convert_encoding("File No\tName\n1\tليلى\n", 'UTF-16LE', 'UTF-8')));
    check($h === ['File No', 'Name'] && $r[0][1] === 'ليلى', 'UTF-16 tab-separated (Excel “Unicode text”), Arabic intact');
    [, $r] = importRead(csvFile(iconv('UTF-8', 'WINDOWS-1256', "File No,Name\n1,مريم\n")));
    check($r[0][1] === 'مريم', 'Windows Arabic (1256) is converted');
    $m = importGuessMapping('patients', ['File No', 'Patient Name', 'Mobile', 'D.O.B', 'E-mail', 'Nationality']);
    check($m === ['legacy_id' => 0, 'full_name' => 1, 'dob' => 3, 'nationality' => 5, 'mobile' => 2, 'email' => 4], 'ClinicSoft-style column names are recognised: ' . json_encode($m));
}

function test_patients_check_then_import_then_rerun() {
    $db = impDb();
    $before = (int)$db->query("SELECT COUNT(*) FROM patients")->fetchColumn();
    $chk = runCsv($db, 'patients', PATIENTS_CSV, false);
    check($chk['ok'] === 3 && $chk['error'] === 1 && (int)$db->query("SELECT COUNT(*) FROM patients")->fetchColumn() === $before, 'the check: 3 fine, 1 to fix (no name), nothing saved');
    $rep = runCsv($db, 'patients', PATIENTS_CSV, true);
    $l = $db->query("SELECT * FROM patients WHERE legacy_id = '10001'")->fetch();
    check($rep['ok'] === 3 && $l['first_name'] === 'Layla' && $l['last_name'] === 'Hassan' && $l['name_ar'] === 'ليلى حسن' && $l['dob'] === '1990-03-12' && $l['nationality'] === 'AE' && $l['gender'] === 'female',
        'imported with Arabic name, date of birth (day/month), nationality from “Emirati”');
    check($db->query("SELECT phone FROM patient_phones WHERE patient_id = {$l['id']}")->fetchColumn() === '+971502223333', 'a local mobile becomes +971');
    check($db->query("SELECT legacy_id FROM patients WHERE id = 970")->fetchColumn() === '10002' && (int)$db->query("SELECT COUNT(*) FROM patients WHERE first_name = 'Mariam'")->fetchColumn() === 1,
        'Mariam was already here (a converted lead): linked by mobile and first name, not duplicated');
    $notes = implode(' ', array_column($rep['rows'], 2));
    check(str_contains($notes, '31/02/1985') && str_contains($notes, 'Martian') && str_contains($notes, 'not-an-email'), 'impossible dates, unknown nationalities and bad emails are reported, the rest still imported');
    $again = runCsv($db, 'patients', PATIENTS_CSV, true);
    check($again['ok'] === 0 && $again['skipped'] === 3 && (int)$db->query("SELECT COUNT(*) FROM patients")->fetchColumn() === $before + 2, 'the same file again adds nothing');
}

function test_balances_are_collectable_but_not_revenue_or_vat() {
    $db = impDb();
    $rep = runCsv($db, 'balances', "File No,Balance Due,Advance,Invoice No\n10001,350.50,,CS-881\n10004,,200,\n99999,100,,\n", true);
    check($rep['ok'] === 2 && $rep['error'] === 1 && str_contains(implode(' ', array_column($rep['rows'], 2)), 'import the patients first'), 'two balances in; an unknown file number is reported');
    $pl = (int)$db->query("SELECT id FROM patients WHERE legacy_id = '10001'")->fetchColumn();
    $ob = $db->query("SELECT * FROM invoices WHERE patient_id = $pl AND imported = 'opening_balance'")->fetch();
    check($ob && $ob['number'] === 'OB-10001' && $ob['total'] == 350.5 && $ob['vat'] == 0 && $ob['status'] === 'issued' && $ob['legacy_ref'] === 'CS-881', 'the amount owed is an opening balance to collect');
    $po = (int)$db->query("SELECT id FROM patients WHERE legacy_id = '10004'")->fetchColumn();
    check(walletBalance($db, $po) === 20000, 'the advance is wallet credit (AED 200)');
    $today = date('Y-m-d');
    $m = rptMoneySummary($db, $today, $today);
    check($m['invoiced'] == 0 && rptVatReturn($db, $today, $today)['box8']['amount'] == 0 && rptInvoiceRegister($db, $today, $today) === [], 'not revenue, not VAT, not in the tax invoice register');
    check(in_array('OB-10001', array_column(rptPatientReceivables($db), 'number'), true), 'but it is in what patients owe');
    createReceipt($db, $pl, 1, [['method' => 'cash', 'amount' => 350.5]], [(int)$ob['id'] => 350.5]);
    check($db->query("SELECT status FROM invoices WHERE id = {$ob['id']}")->fetchColumn() === 'paid', 'collected with a normal receipt');
    checkThrows(fn() => issueCreditNote($db, (int)$ob['id'], [], 'test'), 'ClinicSoft', 'no credit notes against imported balances');
    check(runCsv($db, 'balances', "File No,Balance Due\n10001,350.50\n", true)['skipped'] === 1, 'importing balances again adds nothing');
}

function test_packages() {
    $db = impDb();
    $rep = runCsv($db, 'packages', "File No,Package Name,Remaining Sessions,Expiry Date\n10001,Glow 6,4,31/12/2099\n10004,Combo,3,\n10004,Combo,5,\n10001,Unknown pack,2,\n10004,Glow 6,0,\n", true);
    $pp = $db->query("SELECT pp.*, s.total, s.used FROM patient_packages pp JOIN patient_package_sessions s ON s.patient_package_id = pp.id JOIN patients p ON p.id = pp.patient_id WHERE p.legacy_id = '10001'")->fetch();
    check($pp && $pp['total'] == 6 && $pp['used'] == 2 && $pp['expires_on'] === '2099-12-31' && $pp['value'] == 2400, 'Glow 6 with 4 of 6 sessions left');
    $msgs = implode(' | ', array_column($rep['rows'], 2));
    check(str_contains($msgs, 'several treatments') && str_contains($msgs, 'No package called') && str_contains($msgs, 'No sessions left'), 'a part-used multi-treatment package, an unknown package and a used-up one are reported');
    check((int)$db->query("SELECT COUNT(*) FROM patient_packages pp JOIN patients p ON p.id = pp.patient_id WHERE p.legacy_id = '10004'")->fetchColumn() === 1, 'an unused multi-treatment package comes in whole');
}

function test_future_appointments_and_past_visits() {
    $db = impDb();
    $d = date('d/m/Y', strtotime('+10 days'));
    $rep = runCsv($db, 'appointments', "File No,Appointment Date,Time,Service,Doctor\n10001,$d,10:30 AM,HydraFacial,Dr. Sara Ali\n10001,01/01/2020,10:00,HydraFacial,\n10004,$d,11:00,Laser X,\n", true);
    $a = $db->query("SELECT a.* FROM appointments a JOIN patients p ON p.id = a.patient_id WHERE p.legacy_id = '10001' AND a.status = 'booked'")->fetch();
    check($a && substr($a['scheduled_at'], 11, 5) === '10:30' && (int)$a['practitioner_id'] === 962 && str_contains($a['notes'], 'ClinicSoft'), 'booked at 10:30 with Sara Ali (“Dr. Sara Ali”)');
    $msgs = implode(' | ', array_column($rep['rows'], 2));
    check(str_contains($msgs, 'in the past') && str_contains($msgs, 'No treatment called'), 'a past date and an unknown treatment are reported');
    $rep = runCsv($db, 'visits', "File No,Visit Date,Service,Status\n10001,05/05/2025,HydraFacial,Checked Out\n10001,06/06/2025,Botox,No Show\n10004,07/07/2025,Old peel,Cancelled\n", true);
    $st = $db->query("SELECT a.status FROM appointments a JOIN patients p ON p.id = a.patient_id WHERE p.legacy_id IN ('10001','10004') AND a.scheduled_at < '2026-01-01' ORDER BY a.scheduled_at")->fetchAll(PDO::FETCH_COLUMN);
    check($rep['ok'] === 3 && $st === ['completed', 'no_show', 'cancelled'], 'past visits keep their outcome: ' . implode(',', $st));
    $old = $db->query("SELECT s.name, s.is_active, a.notes FROM appointments a JOIN services s ON s.id = a.service_id JOIN patients p ON p.id = a.patient_id WHERE p.legacy_id = '10004' AND a.status = 'cancelled'")->fetch();
    check($old['name'] === 'Visit (from ClinicSoft)' && !$old['is_active'] && str_contains($old['notes'], 'Old peel'), 'a treatment no longer offered: a hidden placeholder, the old name kept in the notes');
    check(runCsv($db, 'visits', "File No,Visit Date,Service,Status\n10001,05/05/2025,HydraFacial,Checked Out\n", true)['skipped'] === 1, 'the same visit again is skipped');
}

function test_past_invoices_are_records_only() {
    $db = impDb();
    $rep = runCsv($db, 'invoices', "File No,Invoice No,Invoice Date,Subtotal,VAT,Grand Total,Paid,Status\n10001,7001,10/01/2025,1000,50,1050,1050,Paid\n10004,7002,11/01/2025,,,500,300,\n10004,7003,12/01/2025,100,5,999,,\n10001,7004,13/01/2025,200,10,210,,Cancelled\n", true);
    $i = $db->query("SELECT * FROM invoices WHERE legacy_ref = '7001'")->fetch();
    check($i['number'] === 'CS-7001' && $i['imported'] === 'history' && $i['status'] === 'paid' && $i['net'] == 1000 && $i['vat'] == 50, 'a ClinicSoft invoice is kept as a record (CS-7001)');
    $msgs = implode(' | ', array_column($rep['rows'], 2));
    check(str_contains($msgs, 'still owed') && str_contains($msgs, '≠ total'), 'part-paid ones point to the Balances import; totals that don’t add up are refused');
    check($db->query("SELECT status FROM invoices WHERE legacy_ref = '7004'")->fetchColumn() === 'void', 'cancelled ones come in as void');
    check(rptVatReturn($db, '2025-01-01', '2025-01-31')['box1']['amount'] == 0 && rptMoneySummary($db, '2025-01-01', '2025-01-31')['invoiced'] == 0, 'not counted again in revenue or the VAT return');
    checkThrows(fn() => voidInvoice($db, (int)$i['id'], 'test'), 'void it there', 'can’t be voided here');
}

function test_clinical_notes_come_in_signed() {
    $db = impDb();
    $rep = runCsv($db, 'notes', "File No,Visit Date,Doctor,Chief Complaint,Diagnosis,Notes\n10001,05/05/2025,Dr Sara Ali,Dull skin,Dehydration,Advised HydraFacial monthly\n10001,05/05/2025,,,,\n", true);
    $e = $db->query("SELECT e.* FROM encounters e JOIN patients p ON p.id = e.patient_id WHERE p.legacy_id = '10001'")->fetch();
    check($rep['ok'] === 1 && $rep['skipped'] === 1 && $e['status'] === 'signed' && $e['chief_complaint'] === 'Dull skin' && str_contains($e['assessment'], 'Dehydration') && str_contains($e['assessment'], 'monthly'), 'the note is in, read-only; an empty one skipped');
    check(json_decode($e['data'], true)['source'] === 'clinicsoft' && hash_equals($e['content_hash'], encounterHash($db, (int)$e['id'])) && (int)$e['practitioner_id'] === 962, 'marked as from ClinicSoft, with an intact content hash and the doctor matched');
}

function test_a_check_run_saves_nothing_even_when_booking() {
    // Booking code checks some tables with CREATE TABLE IF NOT EXISTS, which makes MySQL commit; the importer runs those
    // checks before its transaction, so a check run can never leave appointments, keys or stock reservations behind
    $db = impDb();
    $count = fn() => $db->query("SELECT (SELECT COUNT(*) FROM appointments) + (SELECT COUNT(*) FROM import_keys) + (SELECT COUNT(*) FROM audit_log) + (SELECT COUNT(*) FROM invoices) + (SELECT COUNT(*) FROM patients)")->fetchColumn();
    $before = $count();
    $d = date('d/m/Y', strtotime('+12 days'));
    $chk = runCsv($db, 'appointments', "File No,Appointment Date,Time,Service\n10001,$d,15:00,HydraFacial\n10004,$d,16:00,Botox\n", false);
    $chk2 = runCsv($db, 'patients', "File No,Patient Name,Mobile\n20001,New Person,0507770000\n", false);
    check($chk['ok'] === 2 && $chk2['ok'] === 1 && $count() === $before, 'two bookings and a patient checked; the database is exactly as before');
}

<?php
// Phase 5: one note per visit, vitals and BMI, required sections before signing, a signed note can't be changed (and a
// change behind its back is detected), addenda, auto-lock, who may do what, templates, ICD-10 import formats, the
// injection map and the dental chart. Scratch database clinic_crm_test_emr; ICD-10 search uses the local reference list.
require_once __DIR__ . '/../includes/emr.php';

function emrDb(): PDO {
    static $done = false;
    $db = testDb('clinic_crm_test_emr', !$done);
    if (!$done) {
        migrateApply($db, __DIR__ . '/../migrations/tenant', fn($l) => null);
        $db->exec("INSERT INTO staff (id, name, email, password, role) VALUES (301, 'Dr Emr', 'emr@example.test', 'x', 'practitioner'), (302, 'Nurse Emr', 'nurse.emr@example.test', 'x', 'nurse'), (303, 'Dr Other', 'other.emr@example.test', 'x', 'practitioner')");
        $db->exec("INSERT INTO services (id, name, duration_min) VALUES (1, 'Botox', 30)");
        $db->exec("INSERT INTO patients (id, mrn, first_name, last_name) VALUES (1, 'E-1', 'Emr', 'Patient')");
        $db->exec("INSERT INTO appointments (patient_id, service_id, scheduled_at, ends_at, practitioner_id, branch_id) VALUES (1, 1, NOW(), NOW() + INTERVAL 30 MINUTE, 301, 1)");
        $done = true;
    }
    return $db;
}
function asStaff(int $id, string $role): void { $_SESSION['staff_id'] = $id; $_SESSION['role'] = $role; staffPermissionOverrides($id, true); }
function enc(PDO $db, int $id): array { return findEncounter($db, $id); }

function test_one_note_per_visit_and_walk_ins() {
    $db = emrDb(); asStaff(301, 'practitioner');
    $appt = (int)$db->query("SELECT id FROM appointments LIMIT 1")->fetchColumn();
    $a = openEncounter($db, 0, $appt);
    check(openEncounter($db, 0, $appt) === $a, 'opening the same visit twice gives the same note');
    $e = enc($db, $a);
    check((int)$e['patient_id'] === 1 && (int)$e['practitioner_id'] === 301, 'the note takes the visit’s patient and doctor');
    $w = openEncounter($db, 1, null);
    check($w !== $a && enc($db, $w)['appointment_id'] === null, 'a walk-in note has no appointment');
    check($db->query("SELECT COUNT(*) FROM audit_log WHERE entity = 'encounter' AND action = 'created'")->fetchColumn() == 2, 'creation is audited');
}

function test_vitals_and_bmi() {
    $db = emrDb(); asStaff(302, 'nurse');
    $id = openEncounter($db, 1, null);
    saveVitals($db, enc($db, $id), ['bp_sys' => '120', 'bp_dia' => '80', 'pulse' => '72', 'weight_kg' => '70', 'height_cm' => '175', 'spo2' => '98']);
    $v = $db->query("SELECT * FROM vitals WHERE encounter_id = $id")->fetch();
    check((float)$v['bmi'] === 22.9 && (int)$v['recorded_by'] === 302, 'BMI worked out (70 kg, 175 cm = 22.9), nurse recorded');
    checkThrows(fn() => saveVitals($db, enc($db, $id), ['pulse' => '900']), 'Check the pulse', 'impossible values are refused');
    checkThrows(fn() => saveVitals($db, enc($db, $id), []), 'at least one', 'an empty set is refused');
}

function test_sign_needs_required_sections_then_the_note_is_read_only() {
    $db = emrDb(); asStaff(301, 'practitioner');
    $id = openEncounter($db, 1, null);
    $missing = missingSections($db, enc($db, $id));
    check(array_keys($missing) === ['vitals', 'allergies', 'complaint', 'diagnosis', 'procedure', 'notes'], 'the default required sections are all missing at first');
    checkThrows(fn() => signEncounter($db, $id), 'Fill these sections', 'signing an incomplete note is refused, naming what is missing');
    saveVitals($db, enc($db, $id), ['bp_sys' => '118', 'bp_dia' => '76']);
    saveNote($db, enc($db, $id), ['chief_complaint' => 'Frown lines', 'examination' => 'Dynamic glabellar lines', 'plan' => 'Botulinum toxin', 'allergies_reviewed' => true]);
    addDiagnosis($db, enc($db, $id), 'R23.4', false, 'final');
    addProcedure($db, enc($db, $id), ['service_id' => 1, 'area' => 'Glabella', 'qty' => '20']);
    savePrescription($db, enc($db, $id), [['drug' => 'Paracetamol', 'strength' => '500 mg', 'dose' => '1 tablet', 'route' => 'Oral', 'frequency' => 'Twice daily', 'duration' => '3 days']]);
    check(missingSections($db, enc($db, $id)) === [], 'nothing missing once filled');
    check((int)$db->query("SELECT is_primary FROM encounter_diagnoses WHERE encounter_id = $id")->fetchColumn() === 1, 'the first diagnosis is primary');
    asStaff(302, 'nurse');
    checkThrows(fn() => signEncounter($db, $id), 'treating doctor', 'a nurse can’t sign');
    asStaff(303, 'practitioner');
    $db->exec("UPDATE encounters SET practitioner_id = 301 WHERE id = $id");
    checkThrows(fn() => signEncounter($db, $id), 'treating doctor', 'another doctor can’t sign it');
    asStaff(301, 'practitioner');
    signEncounter($db, $id);
    $e = enc($db, $id);
    check($e['status'] === 'signed' && (int)$e['signed_by'] === 301 && strlen($e['content_hash']) === 64, 'signed: who, when and a SHA-256 of the content');
    check(encounterIntact($db, $e) === true, 'the content matches what was signed');
    foreach ([fn() => saveNote($db, enc($db, $id), ['plan' => 'changed']), fn() => addDiagnosis($db, enc($db, $id), 'I10', false, 'final'),
              fn() => saveVitals($db, enc($db, $id), ['pulse' => '80']), fn() => savePrescription($db, enc($db, $id), [['drug' => 'X', 'dose' => '1', 'frequency' => 'Once']]),
              fn() => removeEncounterLine($db, enc($db, $id), 'encounter_diagnoses', 1)] as $i => $fn)
        checkThrows($fn, 'is signed', 'a signed note can’t be changed (' . ['note', 'diagnosis', 'vitals', 'prescription', 'remove line'][$i] . ')');
    $db->exec("UPDATE encounters SET plan = 'tampered' WHERE id = $id");
    check(encounterIntact($db, enc($db, $id)) === false, 'a change made behind the system’s back is detected');
    $db->exec("UPDATE encounters SET plan = 'Botulinum toxin' WHERE id = $id");
    check(encounterIntact($db, enc($db, $id)) === true, 'and restoring it matches again');
    checkThrows(fn() => signEncounter($db, $id), 'already signed', 'signing twice is refused');
}

function test_addenda_on_signed_notes_only() {
    $db = emrDb(); asStaff(301, 'practitioner');
    $open = openEncounter($db, 1, null);
    checkThrows(fn() => addAmendment($db, $open, 'x', 'y'), 'signed notes', 'an open note is edited, not amended');
    $signed = (int)$db->query("SELECT id FROM encounters WHERE status = 'signed' LIMIT 1")->fetchColumn();
    checkThrows(fn() => addAmendment($db, $signed, '', 'text'), 'reason', 'an addendum needs a reason');
    addAmendment($db, $signed, 'Wrong area', 'Treatment area was the forehead, not the glabella.');
    $e = enc($db, $signed);
    check($e['status'] === 'amended' && encounterIntact($db, $e) === true, 'status becomes “amended”; the signed content itself is untouched');
    check($db->query("SELECT text FROM encounter_amendments WHERE encounter_id = $signed")->fetchColumn() === 'Treatment area was the forehead, not the glabella.', 'the addendum is stored with its text');
    asStaff(302, 'nurse');
    checkThrows(fn() => addAmendment($db, $signed, 'r', 't'), 'permission', 'a nurse can’t add addenda');
}

function test_auto_lock_and_permissions() {
    $db = emrDb(); asStaff(301, 'practitioner');
    $id = openEncounter($db, 1, null);
    $db->exec("UPDATE encounters SET created_at = NOW() - INTERVAL 30 HOUR WHERE id = $id");
    check(emrAutoLock($db) >= 1 && enc($db, $id)['locked_at'] !== null, 'unsigned notes older than 24 hours lock');
    checkThrows(fn() => saveNote($db, enc($db, $id), ['plan' => 'late']), 'locked', 'a locked note can’t be edited');
    asStaff(302, 'nurse');
    $n = openEncounter($db, 1, null);
    checkThrows(fn() => savePrescription($db, enc($db, $n), [['drug' => 'X', 'dose' => '1', 'frequency' => 'Once']]), 'prescribe', 'nurses don’t prescribe');
    asStaff(310, 'consultant');
    checkThrows(fn() => saveNote($db, enc($db, $n), ['plan' => 'x']), 'permission', 'a consultant can’t write clinical notes');
    check(!canSeeEncounter(enc($db, $n)), 'nor see them');
    asStaff(303, 'practitioner');
    $db->exec("UPDATE encounters SET created_at = NOW() - INTERVAL 3 DAY WHERE id = $n");
    check(!canSeeEncounter(enc($db, $n)), 'another doctor can’t open a past note that isn’t theirs');
    asStaff(1, 'admin');
    check(canSeeEncounter(enc($db, $n)), 'with “see every chart” (admin) they can');
}

function test_templates_fill_only_empty_sections() {
    $db = emrDb(); asStaff(301, 'practitioner');
    $db->exec("INSERT INTO note_templates (name, fields) VALUES ('Botox follow-up', '{\"chief_complaint\":\"Review after toxin\",\"plan\":\"Top-up if needed\"}')");
    $tpl = (int)$db->lastInsertId();
    $id = openEncounter($db, 1, null);
    saveNote($db, enc($db, $id), ['plan' => 'My own plan']);
    check(applyTemplate($db, enc($db, $id), $tpl) === 1, 'one section filled');
    $e = enc($db, $id);
    check($e['chief_complaint'] === 'Review after toxin' && $e['plan'] === 'My own plan', 'what the doctor wrote is never overwritten');
}

function test_icd10_import_formats() {
    $db = testDb('clinic_crm_test_emr');
    $db->exec("DELETE FROM icd10_codes");
    $dir = tempDir('icd');
    file_put_contents("$dir/codes.txt", "A000    Cholera due to Vibrio cholerae 01, biovar cholerae\nL700    Acne vulgaris\n");
    file_put_contents("$dir/order.txt", sprintf("%05d %-7s %d %-60s %s\n", 1, 'B079', 1, 'Viral wart, unspecified', 'Viral wart, unspecified'));
    file_put_contents("$dir/c.csv", "\xEF\xBB\xBFcode,description\nE66.9,\"Obesity, unspecified\"\n");
    check(icdImportFile($db, "$dir/codes.txt") === [2, 2], 'CMS codes file');
    check(icdImportFile($db, "$dir/order.txt") === [1, 1] && $db->query("SELECT description FROM icd10_codes WHERE code = 'B079'")->fetchColumn() === 'Viral wart, unspecified', 'CMS order file (long description)');
    check(icdImportFile($db, "$dir/c.csv") === [1, 1] && $db->query("SELECT description FROM icd10_codes WHERE code = 'E669'")->fetchColumn() === 'Obesity, unspecified', 'CSV with dotted codes; the header row is skipped');
    file_put_contents("$dir/bad.txt", "hello\nworld\n");
    checkThrows(fn() => icdImportFile($db, "$dir/bad.txt"), 'No ICD-10 codes', 'a file without codes is refused');
    check(icdFormat('L700') === 'L70.0' && icdFormat('I10') === 'I10', 'codes are shown with their dot');
    rmTree($dir);
}

function test_injection_map_and_dental_chart() {
    $db = emrDb(); asStaff(301, 'practitioner');
    $clean = cleanInjections(['glabella' => ['units' => '20', 'product' => 'Botox'], 'made_up' => ['units' => '5'], 'chin' => ['units' => '']]);
    check(array_keys($clean) === ['glabella'] && $clean['glabella']['units'] === 20, 'only real, filled zones are kept');
    checkThrows(fn() => cleanInjections(['forehead' => ['units' => 'lots']]), 'Check the amount', 'a non-number amount is refused');
    $id = openEncounter($db, 1, null);
    checkThrows(fn() => addDentalEntry($db, enc($db, $id), ['tooth' => '19', 'code' => 'Caries']), 'Choose a tooth', 'tooth 19 doesn’t exist (FDI)');
    addDentalEntry($db, enc($db, $id), ['tooth' => '16', 'code' => 'Caries', 'surfaces' => ['O', 'D', 'X'], 'entry_type' => 'planned']);
    addDentalEntry($db, enc($db, $id), ['tooth' => '55', 'code' => 'Sealant']);
    $rows = $db->query("SELECT tooth, surfaces, entry_type FROM dental_chart WHERE encounter_id = $id ORDER BY id")->fetchAll();
    check($rows[0] === ['tooth' => '16', 'surfaces' => 'O,D', 'entry_type' => 'planned'] && $rows[1]['tooth'] === '55', 'permanent and primary teeth; unknown surfaces dropped');
    check(count(fdiTeeth()[0]) === 16 && fdiTeeth()[0][0] === 18 && fdiTeeth(true)[1][0] === 85, 'FDI chart order (18…11 21…28; primary 85…81 71…75)');
    unset($_SESSION['staff_id'], $_SESSION['role']);
}

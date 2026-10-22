<?php
// Phase 6: consent templates (versions, safe text, required per service), the tablet hand-over token (once, 15 minutes,
// right patient), signing (PNG only, frozen copy, witness), withdrawing, what a visit is missing, and clinical photos
// (images only, right patient's visit, hidden not deleted). Scratch database clinic_crm_test_consents.
require_once __DIR__ . '/../includes/consents.php';
require_once __DIR__ . '/../includes/emr.php';

function consentDb(): PDO {
    static $done = false;
    $db = testDb('clinic_crm_test_consents', !$done);
    if (!$done) {
        migrateApply($db, __DIR__ . '/../migrations/tenant', fn($l) => null);
        $db->exec("INSERT INTO staff (id, name, email, password, role) VALUES (401, 'Rana Reception', 'rec.c@example.test', 'x', 'receptionist'), (402, 'Dr Cons', 'dr.c@example.test', 'x', 'practitioner')");
        $db->exec("INSERT INTO services (id, name, duration_min) VALUES (11, 'Botox', 30), (12, 'Facial', 60)");
        $db->exec("INSERT INTO patients (id, mrn, first_name, last_name, status) VALUES (1, 'C-1', 'Maya', 'Consent', 'active'), (2, 'C-2', 'Omar', 'Other', 'active')");
        $db->exec("INSERT INTO appointments (id, patient_id, service_id, scheduled_at, ends_at, practitioner_id, branch_id) VALUES (51, 1, 11, NOW(), NOW() + INTERVAL 30 MINUTE, 402, 1), (52, 2, 12, NOW(), NOW() + INTERVAL 30 MINUTE, 402, 1)");
        $db->exec("INSERT INTO appointment_services (appointment_id, service_id) VALUES (51, 11)");
        $done = true;
    }
    $_SESSION['staff_id'] = 401; $_SESSION['role'] = 'receptionist';
    return $db;
}
/** A real PNG (w×h, grey) made without GD. */
function testPng(int $w = 40, int $h = 20): string {
    $chunk = fn($t, $d) => pack('N', strlen($d)) . $t . $d . pack('N', crc32($t . $d));
    $raw = str_repeat("\0" . str_repeat("\x80", $w), $h);
    return "\x89PNG\r\n\x1a\n" . $chunk('IHDR', pack('NNCCCCC', $w, $h, 8, 0, 0, 0, 0)) . $chunk('IDAT', gzcompress($raw)) . $chunk('IEND', '');
}
function pngUrl(): string { return 'data:image/png;base64,' . base64_encode(testPng()); }

function test_template_versions_and_required_services() {
    $db = consentDb();
    $id = saveConsentTemplate($db, 0, ['name' => 'Botox consent', 'body_en' => 'I agree to {procedure}.', 'is_active' => 1, 'services' => [11]]);
    check((int)findConsentTemplate($db, $id)['version'] === 1, 'a new form is version 1');
    saveConsentTemplate($db, $id, ['name' => 'Botox consent', 'body_en' => 'I agree to {procedure}.', 'is_active' => 1, 'services' => [11, 12]]);
    check((int)findConsentTemplate($db, $id)['version'] === 1, 'changing only the services keeps the version');
    check((int)$db->query("SELECT COUNT(*) FROM services WHERE requires_consent_template_id = $id")->fetchColumn() === 2, 'both services now require it');
    saveConsentTemplate($db, $id, ['name' => 'Botox consent', 'body_en' => "I agree to {procedure}.\r\n\r\nRisks explained.", 'is_active' => 1, 'services' => [11]]);
    $t = findConsentTemplate($db, $id);
    check((int)$t['version'] === 2 && !str_contains($t['body_en'], "\r"), 'changing the text makes version 2 (line endings tidied)');
    check($db->query("SELECT requires_consent_template_id FROM services WHERE id = 12")->fetchColumn() === null, 'unticking a service frees it');
    checkThrows(fn() => saveConsentTemplate($db, 0, ['name' => '', 'body_en' => 'x']), 'name', 'a form needs a name');
}

function test_consent_text_is_escaped() {
    $h = consentText("Hello <script>alert(1)</script> {patient_name}\n\nSecond", ['patient_name' => '<img src=x onerror=alert(1)>']);
    check(!str_contains($h, '<script>') && !str_contains($h, '<img'), 'HTML in the template or in a value is shown as text');
    check(substr_count($h, '<p>') === 2 && str_contains($h, '<strong>&lt;img'), 'paragraphs kept; filled-in values in bold');
}

function test_handoff_once_in_time_for_the_right_patient() {
    $db = consentDb();
    $tpl = (int)$db->query("SELECT id FROM consent_templates WHERE name = 'Botox consent'")->fetchColumn();
    checkThrows(fn() => startConsentHandoff($db, 1, $tpl, 52), 'another patient', 'a visit of another patient is refused');
    $tok = startConsentHandoff($db, 1, $tpl, 51);
    check(strlen($tok) === 64 && $db->query("SELECT COUNT(*) FROM consent_handoffs WHERE token_hash = '$tok'")->fetchColumn() == 0, 'only a hash of the token is stored');
    check(handoffFromToken($db, $tok)['patient_id'] == 1, 'the token opens the hand-over');
    check(handoffFromToken($db, 'nothex') === null && handoffFromToken($db, str_repeat('a', 64)) === null, 'made-up tokens open nothing');
    checkThrows(fn() => signConsent($db, $tok, 'data:image/jpeg;base64,' . base64_encode('x')), 'sign', 'only a PNG signature is accepted');
    checkThrows(fn() => signConsent($db, $tok, 'data:image/png;base64,' . base64_encode(str_repeat('<?php ', 50))), 'PNG', 'a "PNG" that isn\'t one is refused');
    checkThrows(fn() => signConsent($db, $tok, pngUrl(), '', pngUrl()), 'witness', 'a witness signature needs the witness\'s name');
    $files = count(glob(tenantStoragePath() . '/patients/1/*') ?: []);
    $cid = signConsent($db, $tok, pngUrl(), 'Nurse Joy', pngUrl());
    $c = findConsent($db, $cid);
    check($c && (int)$c['template_version'] === 2 && (int)$c['collected_by'] === 401 && $c['witness_name'] === 'Nurse Joy', 'signed with the version, who handed it over and the witness');
    check(str_contains($c['snapshot_html'], '<strong>Botox</strong>') && str_contains($c['snapshot_html'], 'Risks explained'), 'the frozen copy has the procedure filled in');
    check(count(glob(tenantStoragePath() . '/patients/1/*')) === $files + 2 && patientFilePath($c['signature_path']), 'both signatures stored in the patient\'s folder');
    checkThrows(fn() => signConsent($db, $tok, pngUrl()), 'expired', 'the token can\'t be used twice');
    $old = startConsentHandoff($db, 1, $tpl);
    $db->exec("UPDATE consent_handoffs SET expires_at = NOW() - INTERVAL 1 SECOND WHERE consent_id IS NULL");
    check(handoffFromToken($db, $old) === null, 'after 15 minutes the token is dead');
    // Editing the text later doesn't change what was signed
    saveConsentTemplate($db, $tpl, ['name' => 'Botox consent', 'body_en' => 'New wording {procedure}.', 'is_active' => 1, 'services' => [11]]);
    check(str_contains(findConsent($db, $cid)['snapshot_html'], 'Risks explained'), 'the signed copy keeps the old text');
}

function test_missing_consents_and_withdrawal() {
    $db = consentDb();
    $tpl = (int)$db->query("SELECT id FROM consent_templates WHERE name = 'Botox consent'")->fetchColumn();
    check(visitServiceIds($db, 51, null) === [11], 'the visit\'s services');
    check(missingConsents($db, 1, [11]) === [], 'patient 1 signed: nothing missing');
    check(isset(missingConsents($db, 2, [11])[$tpl]), 'patient 2 has not signed: missing');
    check(missingConsents($db, 2, [12]) === [], 'a service without a required form needs nothing');
    $cid = (int)$db->query("SELECT id FROM patient_consents WHERE patient_id = 1 LIMIT 1")->fetchColumn();
    checkThrows(fn() => revokeConsent($db, $cid, ' '), 'why', 'withdrawing needs a reason');
    revokeConsent($db, $cid, 'Patient changed her mind');
    check(isset(missingConsents($db, 1, [11])[$tpl]), 'once withdrawn it is missing again');
    checkThrows(fn() => revokeConsent($db, $cid, 'again'), 'already', 'can\'t withdraw twice');
    check($db->query("SELECT COUNT(*) FROM audit_log WHERE entity = 'patient' AND entity_id = 1 AND action IN ('consent_signed','consent_withdrawn')")->fetchColumn() == 2, 'signing and withdrawing are audited');
}

function test_photos() {
    $db = consentDb(); $_SESSION['staff_id'] = 402; $_SESSION['role'] = 'practitioner';
    $id = savePatientPhoto($db, 1, testPng(), ['area' => 'Forehead', 'stage' => 'before']);
    $ph = $db->query("SELECT * FROM patient_photos WHERE id = $id")->fetch();
    check($ph['mime'] === 'image/png' && $ph['taken_at'] === date('Y-m-d') && patientFilePath($ph['path']) && str_starts_with($ph['path'], 'patients/1/'), 'stored outside the web root under the patient');
    checkThrows(fn() => savePatientPhoto($db, 1, "%PDF-1.4\n" . str_repeat('x', 200), ['area' => 'Forehead']), 'JPG or PNG', 'a PDF is not a photo');
    checkThrows(fn() => savePatientPhoto($db, 1, "<?php system('id');", ['area' => 'Forehead']), 'JPG or PNG', 'a script is refused (checked by content)');
    checkThrows(fn() => savePatientPhoto($db, 1, testPng(), ['area' => ' ']), 'area', 'the area is required');
    checkThrows(fn() => savePatientPhoto($db, 1, testPng(), ['area' => 'Chin', 'taken_at' => date('Y-m-d', strtotime('+2 days'))]), 'date', 'no future dates');
    $db->exec("INSERT INTO encounters (patient_id, practitioner_id, created_by) VALUES (2, 402, 402)");
    $other = (int)$db->lastInsertId();
    checkThrows(fn() => savePatientPhoto($db, 1, testPng(), ['area' => 'Chin', 'encounter_id' => $other]), 'another patient', 'can\'t tag another patient\'s visit');
    removePatientPhoto($db, 1, $id);
    check(patientPhotos($db, 1) === [] && patientFilePath($ph['path']), 'removed photos leave the gallery but the file stays in the record');
    checkThrows(fn() => removePatientPhoto($db, 2, $id), 'not found', 'a photo can only be removed from its own patient');
}

function test_file_paths_stay_in_the_clinic_folder() {
    consentDb();
    check(patientFilePath('../../includes/config.php') === null && patientFilePath('') === null && patientFilePath(null) === null, 'paths outside the clinic\'s folder are refused');
}

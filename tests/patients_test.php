<?php
// Phase 3: phone and Emirates ID rules, encrypted storage, duplicate checks, the lead backfill (dry run = real run,
// idempotent, leads untouched), merging (everything moves, including tables added later) and document storage.
// Scratch database clinic_crm_test_patients; encryption uses the local clinic's data key.
require_once __DIR__ . '/../includes/patients.php';

function patientsDb(): PDO {
    static $done = false;
    $db = testDb('clinic_crm_test_patients', !$done);
    if (!$done) { migrateApply($db, __DIR__ . '/../migrations/tenant', fn($l) => null); $db->exec("INSERT INTO services (id, name) VALUES (1, 'Test treatment')"); $done = true; }
    return $db;
}

/** A valid Emirates ID for a given 14-digit stem (adds the Luhn check digit). */
function testEid(string $stem14): string {
    for ($c = 0; $c <= 9; $c++) if (eidNormalize($stem14 . $c)) return $stem14 . $c;
    throw new RuntimeException('no check digit');
}

function test_phone_numbers_become_e164() {
    check(normalizePhone('050 123 4567') === '+971501234567', 'local mobile');
    check(normalizePhone('+971-50-123-4567') === '+971501234567', 'international with dashes');
    check(normalizePhone('00971501234567') === '+971501234567', '00 prefix');
    check(normalizePhone('501234567') === '+971501234567', 'without the leading 0');
    check(normalizePhone('04 000 0000') === '+97140000000', 'Dubai landline');
    check(normalizePhone('+44 7700 900123') === '+447700900123', 'foreign number kept');
    check(normalizePhone('123') === null && normalizePhone('abc') === null, 'too short or not a number');
}

function test_emirates_id_needs_784_and_a_check_digit() {
    $eid = testEid('78419901234567');
    check(eidNormalize(eidFormat($eid)) === $eid, 'valid with dashes');
    check(eidNormalize(substr($eid, 0, 14) . (((int)substr($eid, -1) + 1) % 10)) === null, 'wrong check digit refused');
    check(eidNormalize('123456789012345') === null, 'not starting 784 refused');
    check(eidNormalize('78419901234') === null, 'too short refused');
}

function test_ids_are_stored_encrypted_and_duplicates_refused() {
    $db = patientsDb();
    $eid = testEid('78419851111111');
    $id = savePatient($db, ['first_name' => 'Layla', 'last_name' => 'Test', 'eid' => eidFormat($eid), 'passport' => 'N1234567', 'gender' => 'female', 'dob' => '1990-05-01'], ['050 111 2222', '+971501112222', '04 111 2222']);
    $p = $db->query("SELECT * FROM patients WHERE id = $id")->fetch();
    check(preg_match('/^MRN-\d{6}$/', $p['mrn']) === 1, 'gets a file number: ' . $p['mrn']);
    check(!str_contains($p['eid_enc'], $eid) && !str_contains($p['eid_enc'], substr($eid, 3, 8)) && $p['eid_last4'] === substr($eid, -4), 'Emirates ID is encrypted; only the last 4 are plain');
    check(tenantDecrypt($p['eid_enc']) === $eid && tenantDecrypt($p['passport_enc']) === 'N1234567', 'and decrypts back');
    check(array_column(patientPhones($db, $id), 'phone') === ['+971501112222', '+97141112222'], 'phones normalised, the same number typed twice kept once');
    checkThrows(fn() => savePatient($db, ['first_name' => 'Other', 'eid' => $eid], []), 'already on patient', 'the same Emirates ID on a second patient is refused');
    checkThrows(fn() => savePatient($db, ['first_name' => 'X', 'eid' => '784-1990-1234567-0'], []), 'isn’t valid', 'an invalid Emirates ID is refused');
    checkThrows(fn() => savePatient($db, ['first_name' => ''], []), 'first name', 'a name is required');
    checkThrows(fn() => savePatient($db, ['first_name' => 'X', 'dob' => '2999-01-01'], []), 'past', 'a future date of birth is refused');
    $dups = findPatientDuplicates($db, ['0501112222'], null);
    check(count($dups['phone']) === 1 && (int)$dups['phone'][0]['id'] === $id, 'a shared phone is found (to warn, not to block)');
    check(findPatientDuplicates($db, [], $eid)['eid']['id'] == $id, 'search by Emirates ID finds them (by keyed hash)');
    savePatient($db, ['first_name' => 'Layla', 'last_name' => 'Test-Changed', 'eid_clear' => true, 'gender' => 'female', 'dob' => '1990-05-01'], ['0501112222'], $id);
    $au = json_decode($db->query("SELECT changes FROM audit_log WHERE entity = 'patient' AND entity_id = $id ORDER BY id DESC LIMIT 1")->fetchColumn(), true);
    check(isset($au['last_name'], $au['eid_last4'], $au['phones']) && !str_contains(json_encode($au), $eid), 'the audit log records what changed, never the ID number itself');
    check($db->query("SELECT eid_enc FROM patients WHERE id = $id")->fetchColumn() === null, 'the Emirates ID can be removed');
}

function test_backfill_preview_matches_the_real_run_and_leads_stay_untouched() {
    $db = patientsDb();
    $db->exec("DELETE FROM patient_phones; DELETE FROM appointments; DELETE FROM lead_phones; UPDATE patients SET lead_id = NULL; DELETE FROM leads");
    $lead = function (string $name, string $stage, array $phones, int $appts) use ($db): int {
        $db->prepare("INSERT INTO leads (name, phone, stage, source, email) VALUES (?,?,?,?,?)")->execute([$name, $phones[0], $stage, 'whatsapp', strtolower(strtok($name, ' ')) . '@example.test']);
        $id = (int)$db->lastInsertId();
        foreach ($phones as $ph) $db->prepare("INSERT INTO lead_phones (lead_id, phone) VALUES (?, ?)")->execute([$id, $ph]);
        for ($i = 0; $i < $appts; $i++) $db->prepare("INSERT INTO appointments (lead_id, service_id, scheduled_at, status) VALUES (?, 1, NOW() + INTERVAL ? DAY, 'booked')")->execute([$id, $i + 1]);
        return $id;
    };
    $a = $lead('Aisha Converted', 'converted', ['0502220001', '0502220002'], 0);
    $b = $lead('Bilal Booked', 'booked', ['0502220003'], 2);
    $lead('Carla Just Asking', 'new', ['0502220004'], 0);
    $lead('Dana Sister', 'contacted', ['0502220001'], 1);   // shares Aisha's number
    $lead('فاطمة علي', 'new', ['0502220005'], 1);
    $leadsBefore = $db->query("SELECT MD5(GROUP_CONCAT(CONCAT_WS('|', id, name, phone, stage, source, email, updated_at) ORDER BY id)) FROM leads")->fetchColumn();
    $patientsBefore = (int)$db->query("SELECT COUNT(*) FROM patients")->fetchColumn();

    $dry = backfillPatients($db, true);
    check((int)$db->query("SELECT COUNT(*) FROM patients")->fetchColumn() === $patientsBefore && !$db->query("SELECT COUNT(*) FROM leads WHERE patient_id IS NOT NULL")->fetchColumn(), 'preview saves nothing');
    $real = backfillPatients($db, false);
    $keys = ['eligible', 'created', 'linked_existing', 'phones', 'appointments'];
    check(array_intersect_key($dry, array_flip($keys)) === array_intersect_key($real, array_flip($keys)), 'preview and real run report the same numbers: ' . json_encode(array_intersect_key($real, array_flip($keys))));
    check($real['eligible'] === 4 && $real['created'] === 4 && $real['phones'] === 5 && $real['appointments'] === 4, 'the 4 leads with an appointment or Converted, 5 phones, 4 appointments');
    check(count($real['collisions']) === 1 && $real['collisions'][0]['phone'] === '+971502220001', 'the shared phone is reported for review');
    check($db->query("SELECT COUNT(*) FROM patients")->fetchColumn() - $patientsBefore === 4, '4 patients were created');
    check((int)$db->query("SELECT COUNT(*) FROM appointments WHERE patient_id IS NULL")->fetchColumn() === 0, 'every appointment of those leads is linked');
    check($db->query("SELECT MD5(GROUP_CONCAT(CONCAT_WS('|', id, name, phone, stage, source, email, updated_at) ORDER BY id)) FROM leads")->fetchColumn() === $leadsBefore, 'lead data itself is unchanged (only the new patient link)');
    $p = $db->query("SELECT * FROM patients WHERE lead_id = $b")->fetch();
    check($p['first_name'] === 'Bilal' && $p['last_name'] === 'Booked' && $p['email'] === 'bilal@example.test', 'name split and email copied');
    check($db->query("SELECT name_ar FROM patients WHERE first_name = 'فاطمة'")->fetchColumn() === 'فاطمة علي', 'Arabic names also go into the Arabic name');
    $again = backfillPatients($db, false);
    check($again['eligible'] === 0 && $again['created'] === 0, 'running it again does nothing');
    check($db->query("SELECT COUNT(*) FROM audit_log WHERE action = 'backfill'")->fetchColumn() == 1, 'the real run is in the audit log');
}

function test_merge_moves_everything_including_tables_added_later() {
    $db = patientsDb();
    $db->exec("CREATE TABLE IF NOT EXISTS future_things (id INT AUTO_INCREMENT PRIMARY KEY, patient_id INT UNSIGNED)");
    $w = savePatient($db, ['first_name' => 'Sara', 'last_name' => 'Merge'], ['0503330001']);
    $eid = testEid('78419872222222');
    $l = savePatient($db, ['first_name' => 'Sarah', 'last_name' => 'Merge', 'dob' => '1987-03-03', 'email' => 's@example.test', 'eid' => $eid, 'notes' => 'Prefers mornings'], ['0503330001', '0503330002']);
    $db->prepare("INSERT INTO leads (name, phone, patient_id) VALUES ('Sarah lead', '0503330002', ?)")->execute([$l]);
    $sLead = (int)$db->lastInsertId();
    $db->prepare("UPDATE patients SET lead_id = ? WHERE id = ?")->execute([$sLead, $l]);
    $db->prepare("INSERT INTO appointments (lead_id, service_id, scheduled_at, patient_id) VALUES (?, 1, NOW(), ?)")->execute([$sLead, $l]);
    $db->prepare("INSERT INTO future_things (patient_id) VALUES (?), (?)")->execute([$l, $l]);
    $doc = savePatientDocumentBytes($db, $l, 'report', "%PDF-1.4\n%test\n1 0 obj<<>>endobj\ntrailer<<>>\n%%EOF", 'report.pdf');
    $db->prepare("INSERT INTO patients (mrn, first_name, referred_by_patient_id) VALUES ('REF-1', 'Friend', ?)")->execute([$l]);

    $moved = mergePatients($db, $w, $l);
    $count = fn(string $sql) => (int)$db->query($sql)->fetchColumn();
    check($count("SELECT COUNT(*) FROM future_things WHERE patient_id = $w") === 2 && ($moved['future_things'] ?? 0) === 2, 'rows in a table added later move too');
    check($count("SELECT COUNT(*) FROM appointments WHERE patient_id = $w") === 1 && $count("SELECT COUNT(*) FROM patient_documents WHERE patient_id = $w AND id = $doc") === 1, 'appointments and documents move');
    check(array_column(patientPhones($db, $w), 'phone') === ['+971503330001', '+971503330002'] && !$count("SELECT COUNT(*) FROM patient_phones WHERE patient_id = $l"), 'phones combined without duplicates');
    check($count("SELECT COUNT(*) FROM leads WHERE patient_id = $w") === 1, 'the lead now points at the kept patient');
    $wp = $db->query("SELECT * FROM patients WHERE id = $w")->fetch();
    check($wp['dob'] === '1987-03-03' && $wp['email'] === 's@example.test' && $wp['eid_last4'] === substr($eid, -4) && $wp['lead_id'] !== null, 'missing details (date of birth, email, Emirates ID, lead) are filled in');
    check(str_contains((string)$wp['notes'], 'Prefers mornings'), 'notes are kept');
    $lp = $db->query("SELECT * FROM patients WHERE id = $l")->fetch();
    check($lp['status'] === 'merged' && (int)$lp['merged_into_id'] === $w && $lp['eid_enc'] === null && $lp['lead_id'] === null, 'the other record is marked merged and keeps no ID number');
    check((int)findPatient($db, $l)['id'] === $w, 'opening the merged record leads to the kept one');
    check($count("SELECT COUNT(*) FROM patients WHERE referred_by_patient_id = $w") === 1, 'referrals follow');
    check($count("SELECT COUNT(*) FROM audit_log WHERE action IN ('merged_in','merged_into') AND entity_id IN ($w, $l)") === 2, 'both records have an audit entry');
    $x = savePatient($db, ['first_name' => 'X', 'eid' => testEid('78419753333333')], []);
    checkThrows(fn() => mergePatients($db, $w, $x), 'different Emirates IDs', 'two different Emirates IDs can’t be merged');
    checkThrows(fn() => mergePatients($db, $w, $l), 'already merged', 'a merged record can’t be merged again');
    $db->exec("DROP TABLE future_things");
    @unlink(tenantStoragePath() . '/' . $db->query("SELECT path FROM patient_documents WHERE id = $doc")->fetchColumn());
}

function test_documents_are_checked_by_content_and_lose_location_data() {
    $db = patientsDb();
    $pid = savePatient($db, ['first_name' => 'Doc', 'last_name' => 'Test'], []);
    $jpeg = "\xFF\xD8" . "\xFF\xE1" . pack('n', 2 + 26) . "Exif\0\0GPSLatitude=25.2048N!!" . "\xFF\xDB" . pack('n', 4) . "\x00\x00" . "\xFF\xDA" . pack('n', 4) . "\x00\x00" . str_repeat("\x11", 64) . "\xFF\xD9";
    $id = savePatientDocumentBytes($db, $pid, 'eid_front', $jpeg, '../../evil name.jpg');
    $d = $db->query("SELECT * FROM patient_documents WHERE id = $id")->fetch();
    $stored = file_get_contents(tenantStoragePath() . '/' . $d['path']);
    check($d['mime'] === 'image/jpeg' && !str_contains($stored, 'GPSLatitude') && str_ends_with($stored, "\xFF\xD9"), 'JPEG stored without its EXIF/GPS block, image data intact');
    check(preg_match('#^patients/' . $pid . '/[a-f0-9]{32}\.jpg$#', $d['path']) === 1 && $d['original_name'] === 'evil name.jpg', 'stored under a random name in the patient’s folder');
    checkThrows(fn() => savePatientDocumentBytes($db, $pid, 'other', "<?php echo 'hi';", 'photo.jpg'), 'Only JPG, PNG or PDF', 'a script named .jpg is refused (checked by content)');
    checkThrows(fn() => savePatientDocumentBytes($db, $pid, 'other', '', 'x.pdf'), 'empty', 'an empty file is refused');
    @unlink(tenantStoragePath() . '/' . $d['path']);
}

function test_linking_and_merging_never_touch_lead_timestamps() {
    $db = patientsDb();
    $db->exec("INSERT INTO leads (name, phone, stage, source, updated_at) VALUES ('Old Stamp', '0507770001', 'converted', 'other', '2025-01-02 03:04:05')");
    $lid = (int)$db->lastInsertId();
    $db->beginTransaction(); [$pid] = patientFromLead($db, $lid); $db->commit();
    check($db->query("SELECT updated_at FROM leads WHERE id = $lid")->fetchColumn() === '2025-01-02 03:04:05', 'linking a patient keeps the lead’s “last updated” date (reports use it)');
    $other = savePatient($db, ['first_name' => 'Stamp', 'last_name' => 'Two'], []);
    mergePatients($db, $other, $pid);
    check($db->query("SELECT updated_at FROM leads WHERE id = $lid")->fetchColumn() === '2025-01-02 03:04:05' && (int)$db->query("SELECT patient_id FROM leads WHERE id = $lid")->fetchColumn() === $other, 'and so does merging');
}

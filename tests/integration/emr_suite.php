<?php
const ROOT = __DIR__ . '/../..';   // the project folder
// Phase 5 through the real pages (local server 8080): nurse triage, the doctor's note with an ICD-10 diagnosis, procedure
// and prescription, signing; a signed note refuses edits; an addendum shows on the note and the visit summary; the
// prescription prints in English and in Arabic (right-to-left); who may see clinical data; every opening is logged.
// Everything it creates is removed at the end.
require ROOT . '/includes/auth.php';
require ROOT . '/includes/emr.php';
$db = getDB();
$pass = 0; $fail = 0;
$ok = function (bool $c, string $m) use (&$pass, &$fail) { $c ? $pass++ : $fail++; echo ($c ? "  ok   " : "  FAIL "), $m, PHP_EOL; };
$BASE = 'http://127.0.0.1:8080';
$http = function (string $jar, string $method, string $path, array $form = []) use ($BASE) {
    $ch = curl_init($BASE . $path);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => true, CURLOPT_COOKIEJAR => $jar, CURLOPT_COOKIEFILE => $jar, CURLOPT_USERAGENT => 'ClinicCRM-emr-suite/1.0']);
    if ($method === 'POST') { curl_setopt($ch, CURLOPT_POST, true); curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($form)); }
    $raw = (string)curl_exec($ch); $code = curl_getinfo($ch, CURLINFO_HTTP_CODE); $hs = curl_getinfo($ch, CURLINFO_HEADER_SIZE); curl_close($ch);
    preg_match('/^Location:\s*(\S+)/mi', substr($raw, 0, $hs), $m);
    return [$code, $m[1] ?? '', substr($raw, $hs)];
};
$acc = file(ROOT . '/local/test_accounts.txt', FILE_IGNORE_NEW_LINES);
$pw = trim(preg_replace('/^Password:\s*/i', '', $acc[2]));
$tok = [];
$login = function (string $email) use ($http, $pw, &$tok) {
    $jar = tempnam(sys_get_temp_dir(), 'em');
    [, , $b] = $http($jar, 'GET', '/login.php'); preg_match('/name="_csrf" value="([a-f0-9]+)"/', $b, $m);
    $http($jar, 'POST', '/login.php', ['_csrf' => $m[1] ?? '', 'step' => 'password', 'email' => $email, 'password' => $pw]);
    [, , $d] = $http($jar, 'GET', '/pages/dashboard.php'); preg_match('/name="csrf-token" content="([a-f0-9]+)"/', $d, $t);
    $tok[$jar] = $t[1] ?? '';
    return $jar;
};
$post = function (string $jar, string $path, array $f) use ($http, &$tok) { return $http($jar, 'POST', $path, ['_csrf' => $tok[$jar]] + $f); };
$phpError = fn(string $h) => (bool)preg_match('/(Fatal error|Parse error|Uncaught |Warning: |Notice: |Deprecated: )/', $h);
$flashOf = fn(string $h) => preg_match('/alert-(success|danger)[^>]*>.*?<strong>(.*?)<\/strong>/s', $h, $m) ? $m[1] . ': ' . html_entity_decode(strip_tags($m[2])) : '';

$doctorId = (int)$db->query("SELECT id FROM staff WHERE email = 'doctor@clinic.test'")->fetchColumn();
$svc = (int)$db->query("SELECT id FROM services WHERE is_active = 1 LIMIT 1")->fetchColumn();
$tag = (string)random_int(1000, 9999);
$db->prepare("INSERT INTO patients (mrn, first_name, last_name, name_ar, gender, dob, branch_id) VALUES (?, ?, 'Emr', 'سلمى', 'female', '1990-04-04', 1)")->execute(["EMR-$tag", "Salma$tag"]);
$pid = (int)$db->lastInsertId();
$db->prepare("INSERT INTO appointments (patient_id, service_id, scheduled_at, ends_at, duration_min, practitioner_id, branch_id, status) VALUES (?, ?, NOW(), NOW() + INTERVAL 30 MINUTE, 30, ?, 1, 'arrived')")->execute([$pid, $svc, $doctorId]);
$appt = (int)$db->lastInsertId();
$encId = 0;

try {
    echo "Triage by the nurse\n";
    $nurse = $login('nurse@clinic.test');
    [$c, $loc] = $post($nurse, '/pages/encounter.php', ['action' => 'open', 'appointment_id' => $appt, 'patient_id' => $pid]);
    $encId = preg_match('/id=(\d+)/', $loc, $m) ? (int)$m[1] : 0;
    $ok($encId > 0, 'the nurse starts the visit’s note');
    [, $loc, ] = $post($nurse, "/pages/encounter.php?id=$encId", ['action' => 'vitals', 'tab' => 'note', 'bp_sys' => 125, 'bp_dia' => 82, 'pulse' => 70, 'weight_kg' => 60, 'height_cm' => 165]);
    $ok((float)$db->query("SELECT bmi FROM vitals WHERE encounter_id = $encId")->fetchColumn() === 22.0, 'vitals saved with BMI');
    $post($nurse, "/pages/encounter.php?id=$encId", ['action' => 'allergy_add', 'tab' => 'note', 'allergen' => "Lidocaine$tag", 'type' => 'drug', 'severity' => 'severe', 'reaction' => 'Swelling']);
    [, , $b] = $http($nurse, 'GET', "/pages/encounter.php?id=$encId");
    $ok(str_contains($b, "Lidocaine$tag") && str_contains($b, 'SEVERE') && !$phpError($b), 'the allergy shows as a red alert on the note');
    [, , $b] = $http($nurse, 'GET', "/pages/encounter.php?id=$encId&tab=rx");
    $ok(!str_contains($b, 'New prescription'), 'nurses don’t get the prescription form');

    echo "The doctor's note\n";
    $doc = $login('doctor@clinic.test');
    foreach (['note', 'aesthetic', 'dental', 'rx', 'sign'] as $t) { [$c, , $b] = $http($doc, 'GET', "/pages/encounter.php?id=$encId&tab=$t"); $ok($c === 200 && !$phpError($b), "tab $t loads"); }
    [, , $b] = $http($doc, 'GET', "/pages/emr_api.php?action=icd&q=acne");
    $ok(str_contains($b, 'L70.0'), 'ICD-10 search answers');
    $post($doc, "/pages/encounter.php?id=$encId", ['action' => 'note', 'tab' => 'note', 'allergies_reviewed_sent' => 1, 'allergies_reviewed' => 1, 'chief_complaint' => 'Acne on cheeks',
        'hpi' => '6 months', 'examination' => 'Papules and comedones', 'assessment' => 'Mild acne', 'plan' => 'Topical treatment', 'followup_instruction' => 'Review in 6 weeks', 'followup_date' => date('Y-m-d', strtotime('+42 days'))]);
    $post($doc, "/pages/encounter.php?id=$encId", ['action' => 'dx_add', 'tab' => 'note', 'code' => 'L700', 'kind' => 'final']);
    $post($doc, "/pages/encounter.php?id=$encId", ['action' => 'proc_add', 'tab' => 'note', 'service_id' => $svc, 'area' => 'Cheeks', 'qty' => 1]);
    $post($doc, "/pages/encounter.php?id=$encId", ['action' => 'aesthetic', 'tab' => 'aesthetic', 'inj' => ['glabella' => ['units' => 20, 'product' => 'Botox']], 'laser' => ['device' => 'Nd:YAG', 'wavelength' => 1064], 'fitzpatrick' => 'III', 'post_care' => 'No sun for 48 h']);
    $post($doc, "/pages/encounter.php?id=$encId", ['action' => 'dental_add', 'tab' => 'dental', 'tooth' => 26, 'code' => 'Caries', 'entry_type' => 'planned', 'surfaces' => ['O']]);
    [, , $b] = $post($doc, "/pages/encounter.php?id=$encId", ['action' => 'rx_add', 'tab' => 'rx', 'items' => [['drug' => 'Adapalene gel', 'strength' => '0.1%', 'dose' => 'Thin layer', 'route' => 'Topical', 'frequency' => 'At bedtime', 'duration' => '6 weeks', 'qty' => '1 tube', 'instructions' => 'Avoid eyes'],
        ['drug' => 'Doxycycline', 'strength' => '100 mg', 'dose' => '1 capsule', 'route' => 'Oral', 'frequency' => 'Twice daily', 'duration' => '14 days', 'qty' => '28']]]);
    $rxId = (int)$db->query("SELECT id FROM prescriptions WHERE encounter_id = $encId")->fetchColumn();
    $e = findEncounter($db, $encId);
    $ok($rxId && $db->query("SELECT COUNT(*) FROM prescription_items WHERE prescription_id = $rxId")->fetchColumn() == 2, 'prescription with two medicines saved');
    $ok(($e['data']['injections']['glabella']['units'] ?? null) === 20 && ($e['data']['laser']['device'] ?? '') === 'Nd:YAG' && $e['data']['fitzpatrick'] === 'III', 'injection map, laser settings and skin type saved');
    $ok($db->query("SELECT CONCAT(tooth, entry_type, surfaces) FROM dental_chart WHERE encounter_id = $encId")->fetchColumn() === '26plannedO', 'dental chart entry saved');

    echo "Prescription prints\n";
    [$c, , $en] = $http($doc, 'GET', "/pages/encounter_print.php?id=$encId&doc=rx&rx=$rxId&lang=en");
    $ok($c === 200 && str_contains($en, 'dir="ltr"') && str_contains($en, '>Prescription<') && str_contains($en, 'Adapalene gel') && str_contains($en, "Lidocaine$tag") && str_contains($en, 'Twice daily'), 'English: title, medicines, allergy warning');
    [$c, , $ar] = $http($doc, 'GET', "/pages/encounter_print.php?id=$encId&doc=rx&rx=$rxId&lang=ar");
    $ok($c === 200 && str_contains($ar, 'dir="rtl"') && str_contains($ar, 'lang="ar"') && str_contains($ar, 'وصفة طبية') && str_contains($ar, 'مرتين يومياً') && str_contains($ar, 'عن طريق الفم') && str_contains($ar, 'سلمى'), 'Arabic: right-to-left, Arabic labels, frequency and route translated, Arabic name');
    $ok(str_contains($ar, '<bdi>Doxycycline 100 mg</bdi>') && str_contains($ar, 'IBM Plex Sans Arabic'), 'medicine names stay left-to-right inside the Arabic page; Arabic font loaded');
    [, , $both] = $http($doc, 'GET', "/pages/encounter_print.php?id=$encId&doc=rx&rx=$rxId&lang=both");
    $ok(str_contains($both, 'Prescription / وصفة طبية') && str_contains($both, 'Medicine / الدواء'), 'bilingual labels');
    $ok($db->query("SELECT printed_at FROM prescriptions WHERE id = $rxId")->fetchColumn() !== null, 'printing is recorded');

    echo "Signing\n";
    [, , $b] = $post($doc, "/pages/encounter.php?id=$encId", ['action' => 'sign', 'tab' => 'sign']);
    $e = findEncounter($db, $encId);
    $ok($e['status'] === 'signed' && strlen((string)$e['content_hash']) === 64, 'signed with the content hash');
    $ok((bool)$db->query("SELECT COUNT(*) FROM waiting_list WHERE patient_id = $pid AND notes LIKE 'Follow-up:%'")->fetchColumn(), 'the follow-up date went on the waiting list');
    [, , $b] = $post($doc, "/pages/encounter.php?id=$encId", ['action' => 'note', 'tab' => 'note', 'plan' => 'Changed after signing']);
    $ok(str_contains($flashOf($b) ?: $flashOf($http($doc, 'GET', "/pages/encounter.php?id=$encId")[2]), 'signed') && findEncounter($db, $encId)['plan'] === 'Topical treatment', 'a signed note refuses edits');
    [, , $b] = $http($doc, 'GET', "/pages/encounter.php?id=$encId&tab=note");
    $ok(!str_contains($b, 'name="chief_complaint"') && str_contains($b, 'Topical treatment'), 'the signed note shows as read-only text');
    $post($doc, "/pages/encounter.php?id=$encId", ['action' => 'amend', 'tab' => 'sign', 'reason' => 'Dose clarified', 'text' => "Doxycycline with food $tag"]);
    [, , $b] = $http($doc, 'GET', "/pages/encounter.php?id=$encId&tab=sign");
    $ok(str_contains($b, "Doxycycline with food $tag") && str_contains($b, 'Dose clarified') && str_contains($b, 'Unchanged since signing'), 'the addendum shows; the signed content is still intact');
    [, , $sum] = $http($doc, 'GET', "/pages/encounter_print.php?id=$encId&doc=summary");
    $ok(str_contains($sum, 'Addendum') && str_contains($sum, "Doxycycline with food $tag") && str_contains($sum, 'L70.0'), 'the visit summary prints the diagnosis and the addendum');

    echo "Who sees clinical data\n";
    foreach (['layla@clinic.test' => 'consultant', 'finance@clinic.test' => 'finance', 'inventory@clinic.test' => 'inventory'] as $email => $role) {
        $j = $login($email);
        [$c] = $http($j, 'GET', "/pages/encounter.php?id=$encId");
        [$c2] = $http($j, 'GET', "/pages/encounter_print.php?id=$encId&doc=rx&rx=$rxId&lang=en");
        [, , $pb] = $http($j, 'GET', "/pages/patient.php?id=$pid&tab=clinical");
        $ok($c === 403 && $c2 === 403 && !str_contains($pb, "Lidocaine$tag") && !str_contains($pb, 'Clinical notes'), "$role: no clinical note, prescription, allergies or Clinical tab");
    }
    $ok((int)$db->query("SELECT COUNT(*) FROM patient_access_log WHERE patient_id = $pid AND encounter_id = $encId AND what LIKE 'encounter:%'")->fetchColumn() >= 5
        && (int)$db->query("SELECT COUNT(*) FROM patient_access_log WHERE patient_id = $pid AND what = 'print:rx'")->fetchColumn() === 3, 'every opening and print is in the access log');
    foreach (['/pages/notes.php', '/pages/notes.php?tab=unsigned', '/pages/clinical_settings.php', "/pages/patient.php?id=$pid&tab=clinical"] as $p) {
        $admin ??= $login('admin@clinic.test');
        [$c, , $b] = $http($admin, 'GET', $p); $ok($c === 200 && !$phpError($b), "$p loads");
    }
} finally {
    if ($encId) {
        foreach (['vitals', 'encounter_diagnoses', 'encounter_procedures', 'encounter_amendments', 'dental_chart'] as $t) $db->exec("DELETE FROM $t WHERE encounter_id = $encId");
        $db->exec("DELETE i FROM prescription_items i JOIN prescriptions r ON r.id = i.prescription_id WHERE r.encounter_id = $encId");
        $db->exec("DELETE FROM prescriptions WHERE encounter_id = $encId");
        $db->exec("DELETE FROM audit_log WHERE entity = 'encounter' AND entity_id = $encId");
        $db->exec("DELETE FROM encounters WHERE id = $encId");
    }
    foreach (['allergies', 'patient_history', 'waiting_list', 'patient_access_log', 'patient_phones'] as $t) $db->exec("DELETE FROM $t WHERE patient_id = $pid");
    foreach (['appointment_status_history', 'appointment_services'] as $t) $db->exec("DELETE FROM $t WHERE appointment_id = $appt");
    $db->exec("DELETE FROM appointments WHERE id = $appt");
    $db->exec("DELETE FROM audit_log WHERE entity = 'patient' AND entity_id = $pid");
    $db->exec("DELETE FROM patients WHERE id = $pid");
    $db->exec("DELETE FROM login_log WHERE user_agent = 'ClinicCRM-emr-suite/1.0'");
}
echo "\n$pass passed, $fail failed\n";
exit($fail ? 1 : 0);

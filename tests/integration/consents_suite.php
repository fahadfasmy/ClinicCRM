<?php
const ROOT = __DIR__ . '/../..';   // the project folder
// Phase 6 through the real pages (local server 8080): a service requires a consent, reception and the doctor are warned;
// reception hands the device to the patient (the device is locked to the consent screen until the password is typed),
// the patient signs, the warning goes; the signed copy and signature open for staff. Photos: the nurse uploads two,
// compares them; a photo address can't be opened signed out, by a consultant, or from another clinic. All logged.
// Everything it creates is removed at the end.
require ROOT . '/includes/auth.php';
require ROOT . '/includes/consents.php';
$db = getDB();
$pass = 0; $fail = 0;
$ok = function (bool $c, string $m) use (&$pass, &$fail) { $c ? $pass++ : $fail++; echo ($c ? "  ok   " : "  FAIL "), $m, PHP_EOL; };
$BASE = 'http://127.0.0.1:8080';
$http = function (string $jar, string $method, string $path, $form = [], string $host = '127.0.0.1') use ($BASE) {
    $ch = curl_init($BASE . $path);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => true, CURLOPT_COOKIEJAR => $jar, CURLOPT_COOKIEFILE => $jar, CURLOPT_HTTPHEADER => ["Host: $host"], CURLOPT_USERAGENT => 'ClinicCRM-consents-suite/1.0']);
    if ($method === 'POST') { curl_setopt($ch, CURLOPT_POST, true); curl_setopt($ch, CURLOPT_POSTFIELDS, is_array($form) && !array_filter($form, fn($v) => $v instanceof CURLFile) ? http_build_query($form) : $form); }
    $raw = (string)curl_exec($ch); $code = curl_getinfo($ch, CURLINFO_HTTP_CODE); $hs = curl_getinfo($ch, CURLINFO_HEADER_SIZE); $ct = (string)curl_getinfo($ch, CURLINFO_CONTENT_TYPE); curl_close($ch);
    preg_match('/^Location:\s*(\S+)/mi', substr($raw, 0, $hs), $m);
    return [$code, $m[1] ?? '', substr($raw, $hs), $ct];
};
$acc = file(ROOT . '/local/test_accounts.txt', FILE_IGNORE_NEW_LINES);
$pw = trim(preg_replace('/^Password:\s*/i', '', $acc[2]));
$tok = [];
$login = function (string $email, string $password = '', string $host = '127.0.0.1') use ($http, $pw, &$tok) {
    $jar = tempnam(sys_get_temp_dir(), 'cs');
    [, , $b] = $http($jar, 'GET', '/login.php', [], $host); preg_match('/name="_csrf" value="([a-f0-9]+)"/', $b, $m);
    $http($jar, 'POST', '/login.php', ['_csrf' => $m[1] ?? '', 'step' => 'password', 'email' => $email, 'password' => $password ?: $pw], $host);
    [, , $d] = $http($jar, 'GET', '/pages/dashboard.php', [], $host); preg_match('/name="csrf-token" content="([a-f0-9]+)"/', $d, $t);
    $tok[$jar] = $t[1] ?? '';
    return $jar;
};
$csrfOf = fn(string $html) => preg_match('/name="_csrf" value="([a-f0-9]+)"/', $html, $m) ? $m[1] : '';
$post = function (string $jar, string $path, array $f) use ($http, &$tok) { return $http($jar, 'POST', $path, ['_csrf' => $tok[$jar]] + $f); };
$phpError = fn(string $h) => (bool)preg_match('/(Fatal error|Parse error|Uncaught |Warning: |Notice: |Deprecated: )/', $h);
$png = function (int $w = 60, int $h = 40, int $grey = 128): string {
    $chunk = fn($t, $d) => pack('N', strlen($d)) . $t . $d . pack('N', crc32($t . $d));
    return "\x89PNG\r\n\x1a\n" . $chunk('IHDR', pack('NNCCCCC', $w, $h, 8, 0, 0, 0, 0)) . $chunk('IDAT', gzcompress(str_repeat("\0" . str_repeat(chr($grey), $w), $h))) . $chunk('IEND', '');
};

$tag = (string)random_int(1000, 9999);
$doctorId = (int)$db->query("SELECT id FROM staff WHERE email = 'doctor@clinic.test'")->fetchColumn();
$db->prepare("INSERT INTO services (name, duration_min, is_active) VALUES (?, 30, 1)")->execute(["Fillers$tag"]);
$svc = (int)$db->lastInsertId();
$db->prepare("INSERT INTO patients (mrn, first_name, last_name, name_ar, gender, branch_id) VALUES (?, ?, 'Consent', 'ليلى', 'female', 1)")->execute(["CNS-$tag", "Laila$tag"]);
$pid = (int)$db->lastInsertId();
$db->prepare("INSERT INTO appointments (patient_id, service_id, scheduled_at, ends_at, duration_min, practitioner_id, branch_id, status) VALUES (?, ?, NOW(), NOW() + INTERVAL 30 MINUTE, 30, ?, 1, 'arrived')")->execute([$pid, $svc, $doctorId]);
$appt = (int)$db->lastInsertId();
$db->prepare("INSERT INTO appointment_services (appointment_id, service_id) VALUES (?, ?)")->execute([$appt, $svc]);
$tplId = 0; $encId = 0; $isoRow = 0; $iso = null;

try {
    echo "Consent form set-up\n";
    $admin = $login('admin@clinic.test');
    [$c, , $b] = $http($admin, 'GET', '/pages/consent_templates.php');
    $ok($c === 200 && str_contains($b, 'General treatment consent') && !$phpError($b), 'consent forms list');
    [, $loc] = $post($admin, '/pages/consent_templates.php', ['id' => 0, 'name' => "Filler consent $tag", 'name_ar' => 'موافقة الفيلر', 'body_en' => "I, {patient_name}, agree to {procedure} by {practitioner}.\n\n<b>bold?</b> Risks: bruising.", 'body_ar' => 'أنا {patient_name} أوافق على {procedure}.', 'is_active' => 1, 'services' => [$svc]]);
    $tplId = (int)$db->query("SELECT id FROM consent_templates WHERE name = " . $db->quote("Filler consent $tag"))->fetchColumn();
    $ok($tplId && (int)$db->query("SELECT requires_consent_template_id FROM services WHERE id = $svc")->fetchColumn() === $tplId, 'saved and required for the service');

    echo "Warnings before signing\n";
    $rec = $login('reception@clinic.test');
    [, , $b] = $http($rec, 'GET', "/pages/appointment.php?id=$appt");
    $ok(str_contains($b, 'Consent not signed') && str_contains($b, "Filler consent $tag") && str_contains($b, 'Hand to patient'), 'reception sees the missing consent on the appointment');
    $doc = $login('doctor@clinic.test');
    [, $loc] = $post($doc, '/pages/encounter.php', ['action' => 'open', 'appointment_id' => $appt, 'patient_id' => $pid]);
    $encId = preg_match('/id=(\d+)/', $loc, $m) ? (int)$m[1] : 0;
    [, , $b] = $http($doc, 'GET', "/pages/encounter.php?id=$encId");
    $ok($encId && str_contains($b, 'Consent not signed') && !$phpError($b), 'the doctor sees it on the clinical note');

    echo "Signing on the tablet\n";
    [, $loc] = $post($rec, '/pages/consent_sign.php', ['action' => 'start', 'patient_id' => $pid, 'appointment_id' => $appt, 'template_id' => $tplId]);
    $ok($loc === '/pages/consent_sign.php', 'hand-over starts (no token in the address)');
    [$c, $loc] = $http($rec, 'GET', '/pages/dashboard.php');
    [$c2, $loc2] = $http($rec, 'GET', "/pages/patient.php?id=$pid");
    $ok($c === 302 && $loc === '/pages/consent_sign.php' && $loc2 === '/pages/consent_sign.php', 'the device is locked: staff pages lead back to the consent screen');
    [$c, , $screen] = $http($rec, 'GET', '/pages/consent_sign.php');
    $ok($c === 200 && str_contains($screen, "Laila$tag") && str_contains($screen, '<strong>Fillers' . $tag . '</strong>') && str_contains($screen, 'dir="rtl"') && str_contains($screen, 'ليلى') && !$phpError($screen), 'the patient sees the form with her name and the procedure, English and Arabic');
    $ok(str_contains($screen, '&lt;b&gt;bold?&lt;/b&gt;') && !str_contains($screen, '<b>bold?</b>'), 'HTML typed into the template shows as text');
    $ok(!str_contains($screen, 'nav-link') && !str_contains($screen, 'sidebar'), 'no staff menu on the consent screen');
    [, , $b] = $http($rec, 'POST', '/pages/consent_sign.php', ['_csrf' => $csrfOf($screen), 'action' => 'sign', 'signature' => 'data:image/png;base64,' . base64_encode('not a png at all, just text' . str_repeat('.', 80))]);
    [, , $b] = $http($rec, 'GET', '/pages/consent_sign.php');
    $ok(str_contains($b, 'alert-danger') && !$db->query("SELECT COUNT(*) FROM patient_consents WHERE patient_id = $pid")->fetchColumn(), 'a fake signature is refused and the patient can try again');
    $http($rec, 'POST', '/pages/consent_sign.php', ['_csrf' => $csrfOf($b), 'action' => 'sign', 'signature' => 'data:image/png;base64,' . base64_encode($png(300, 80, 30)), 'witness_name' => 'Nurse Joy', 'witness_signature' => 'data:image/png;base64,' . base64_encode($png(300, 80, 60))]);
    $cons = $db->query("SELECT * FROM patient_consents WHERE patient_id = $pid")->fetch();
    $ok($cons && (int)$cons['appointment_id'] === $appt && $cons['witness_name'] === 'Nurse Joy' && $cons['user_agent'] === 'ClinicCRM-consents-suite/1.0', 'signed: visit, witness and device recorded');
    [, , $b] = $http($rec, 'GET', '/pages/consent_sign.php');
    $ok(str_contains($b, 'Thank you') && str_contains($b, 'name="password"'), 'thank-you screen asks staff to unlock');
    [, , $b] = $http($rec, 'POST', '/pages/consent_sign.php', ['_csrf' => $csrfOf($b), 'action' => 'sign', 'signature' => 'data:image/png;base64,' . base64_encode($png())]);
    $ok((int)$db->query("SELECT COUNT(*) FROM patient_consents WHERE patient_id = $pid")->fetchColumn() === 1, 'a second signature with the same hand-over is ignored');
    [, , $b] = $http($rec, 'GET', '/pages/consent_sign.php');
    $http($rec, 'POST', '/pages/consent_sign.php', ['_csrf' => $csrfOf($b), 'action' => 'unlock', 'password' => 'wrong-' . $tag]);
    [, , $b] = $http($rec, 'GET', '/pages/consent_sign.php');
    [$c, $loc] = $http($rec, 'GET', '/pages/dashboard.php');
    $ok(str_contains($b, 'That password is not right') && $loc === '/pages/consent_sign.php', 'a wrong password keeps it locked');
    [, $loc] = $http($rec, 'POST', '/pages/consent_sign.php', ['_csrf' => $csrfOf($b), 'action' => 'unlock', 'password' => $pw]);
    [$c] = $http($rec, 'GET', '/pages/dashboard.php');
    $ok($loc === "/pages/appointment.php?id=$appt" && $c === 200, 'the staff password unlocks it and goes back to the appointment');
    // The session id was replaced after unlocking, so read a fresh CSRF token for later posts
    [, , $d] = $http($rec, 'GET', '/pages/dashboard.php'); preg_match('/name="csrf-token" content="([a-f0-9]+)"/', $d, $t); $tok[$rec] = $t[1] ?? '';
    [, , $b] = $http($rec, 'GET', "/pages/appointment.php?id=$appt");
    [, , $b2] = $http($doc, 'GET', "/pages/encounter.php?id=$encId");
    $ok(!str_contains($b, 'Consent not signed') && !str_contains($b2, 'Consent not signed'), 'the warnings are gone');

    echo "The signed copy\n";
    [$c, , $b] = $http($rec, 'GET', "/pages/consent_view.php?id={$cons['id']}");
    $ok($c === 200 && str_contains($b, "Filler consent $tag") && str_contains($b, 'Nurse Joy') && str_contains($b, "download.php?consent={$cons['id']}"), 'the signed form opens with both signatures');
    [$c, , $sig, $ct] = $http($rec, 'GET', "/pages/download.php?consent={$cons['id']}");
    $ok($c === 200 && $ct === 'image/png' && str_starts_with($sig, "\x89PNG"), 'the signature image opens for staff');
    [, , $b] = $http($rec, 'GET', "/pages/patient.php?id=$pid&tab=consents");
    $ok(str_contains($b, 'chip tone-green">Valid') && !$phpError($b), 'the Consents tab lists it as valid');
    $post($rec, "/pages/patient.php?id=$pid", ['action' => 'consent_revoke', 'consent_id' => $cons['id'], 'reason' => 'Changed her mind']);
    [, , $b] = $http($rec, 'GET', "/pages/appointment.php?id=$appt");
    $ok(str_contains($b, 'Consent not signed'), 'withdrawn: the warning is back');

    echo "Photos\n";
    $nurse = $login('nurse@clinic.test');
    [, , $pb] = $http($nurse, 'GET', "/pages/patient.php?id=$pid&tab=photos");
    $ok(str_contains($pb, 'Add photos') && !$phpError($pb), 'the nurse has the Photos tab');
    $f1 = tempnam(sys_get_temp_dir(), 'ph'); file_put_contents($f1, $png(80, 80, 90));
    $f2 = tempnam(sys_get_temp_dir(), 'ph'); file_put_contents($f2, $png(80, 80, 200));
    $http($nurse, 'POST', "/pages/patient.php?id=$pid", ['_csrf' => $tok[$nurse], 'action' => 'photo_upload', 'area' => 'Lips', 'stage' => 'before', 'encounter_id' => $encId, 'photos[0]' => new CURLFile($f1, 'image/png', 'a.png'), 'photos[1]' => new CURLFile($f2, 'image/png', 'b.png')]);
    $photos = $db->query("SELECT id, path FROM patient_photos WHERE patient_id = $pid ORDER BY id")->fetchAll();
    $ok(count($photos) === 2, 'two photos uploaded at once');
    $fake = tempnam(sys_get_temp_dir(), 'ph'); file_put_contents($fake, "<?php echo 'x';");
    $http($nurse, 'POST', "/pages/patient.php?id=$pid", ['_csrf' => $tok[$nurse], 'action' => 'photo_upload', 'area' => 'Lips', 'photos[0]' => new CURLFile($fake, 'image/jpeg', 'evil.jpg')]);
    $ok(count($db->query("SELECT id FROM patient_photos WHERE patient_id = $pid")->fetchAll()) === 2, 'a script named .jpg is refused');
    [$p1, $p2] = [(int)$photos[0]['id'], (int)$photos[1]['id']];
    [, , $b] = $http($nurse, 'GET', "/pages/patient.php?id=$pid&tab=photos&compare=$p1,$p2");
    $ok(str_contains($b, 'Compare') && substr_count($b, 'style="object-fit:contain') === 2, 'side-by-side compare');
    [$c, , $img, $ct] = $http($nurse, 'GET', "/pages/download.php?photo=$p1");
    $ok($c === 200 && $ct === 'image/png' && str_starts_with($img, "\x89PNG"), 'the nurse opens a photo');
    $ok(!is_file(ROOT . '/' . $photos[0]['path']) && !str_contains(realpath(patientFilePath($photos[0]['path'])), realpath(ROOT . '/pages')), 'photos are stored outside the web pages');

    echo "Who can open a photo\n";
    $out = tempnam(sys_get_temp_dir(), 'cs');
    [$c, $loc] = $http($out, 'GET', "/pages/download.php?photo=$p1");
    $ok($c === 302 && str_contains($loc, 'login'), 'signed out: sent to sign in, no photo');
    foreach (['layla@clinic.test' => 'consultant', 'reception@clinic.test' => 'receptionist', 'finance@clinic.test' => 'finance'] as $email => $role) {
        $j = $role === 'receptionist' ? $rec : $login($email);
        [$c, , $body] = $http($j, 'GET', "/pages/download.php?photo=$p1");
        [, , $tabB] = $http($j, 'GET', "/pages/patient.php?id=$pid&tab=photos");
        $ok($c === 403 && !str_contains($body, "\x89PNG") && !str_contains($tabB, 'download.php?photo='), "$role: no photo, no Photos tab");
    }
    // Another clinic: its owner, signed in at its own address, asks for this photo's id; even a row in its own database
    // pointing at this clinic's file path finds nothing (each clinic has its own folder)
    require_once ROOT . '/includes/tenant.php';
    $isoT = tenantBySlug('isotest');
    if ($isoT) {
        $iso = dbConnect($isoT['db_name'], $isoT['db_user'], $isoT['db_pass'], $isoT['db_host']);
        $isoPw = 'Iso-' . bin2hex(random_bytes(8));
        $iso->prepare("UPDATE staff SET password = ?, is_active = 1 WHERE email = 'owner@isotest.test'")->execute([password_hash($isoPw, PASSWORD_DEFAULT)]);
        $iso->prepare("INSERT INTO patient_photos (patient_id, area, stage, path, mime, size, taken_at) VALUES (1, 'x', 'before', ?, 'image/png', 1, CURDATE())")->execute([$photos[0]['path']]);
        $isoRow = (int)$iso->lastInsertId();
        $ij = $login('owner@isotest.test', $isoPw, 'isotest.localhost');
        [$c1, , $b1] = $http($ij, 'GET', "/pages/download.php?photo=$p1", [], 'isotest.localhost');
        [$c2, , $b2] = $http($ij, 'GET', "/pages/download.php?photo=$isoRow", [], 'isotest.localhost');
        $ok(in_array($c1, [403, 404], true) && $c2 === 404 && !str_contains($b1 . $b2, "\x89PNG"), 'another clinic: not found, even with a copied file path');
    } else $ok(false, 'the isotest clinic exists (run tenancy_suite first)');
    $ok((int)$db->query("SELECT COUNT(*) FROM patient_access_log WHERE patient_id = $pid AND what = 'photo:$p1'")->fetchColumn() >= 1
        && (int)$db->query("SELECT COUNT(*) FROM patient_access_log WHERE patient_id = $pid AND what LIKE 'consent%'")->fetchColumn() >= 2, 'photo and consent views are in the access log');
    foreach (['/pages/settings_clinic.php', '/pages/consent_templates.php?id=' . $tplId, '/pages/consent_templates.php?new=1'] as $p) { [$c, , $b] = $http($admin, 'GET', $p); $ok($c === 200 && !$phpError($b), "$p loads"); }
} finally {
    if ($iso && $isoRow) $iso->exec("DELETE FROM patient_photos WHERE id = $isoRow");
    foreach ($db->query("SELECT path FROM patient_photos WHERE patient_id = $pid UNION ALL SELECT signature_path FROM patient_consents WHERE patient_id = $pid UNION ALL SELECT witness_signature_path FROM patient_consents WHERE patient_id = $pid")->fetchAll(PDO::FETCH_COLUMN) as $f)
        if ($full = patientFilePath($f)) unlink($full);
    @rmdir(tenantStoragePath() . "/patients/$pid");
    if ($encId) { $db->exec("DELETE FROM audit_log WHERE entity = 'encounter' AND entity_id = $encId"); $db->exec("DELETE FROM encounters WHERE id = $encId"); }
    foreach (['patient_photos', 'patient_consents', 'consent_handoffs', 'patient_access_log', 'waiting_list'] as $t) $db->exec("DELETE FROM $t WHERE patient_id = $pid");
    foreach (['appointment_status_history', 'appointment_services'] as $t) $db->exec("DELETE FROM $t WHERE appointment_id = $appt");
    $db->exec("DELETE FROM appointments WHERE id = $appt");
    $db->exec("DELETE FROM audit_log WHERE entity = 'patient' AND entity_id = $pid");
    $db->exec("DELETE FROM patients WHERE id = $pid");
    $db->exec("DELETE FROM services WHERE id = $svc");
    if ($tplId) $db->exec("DELETE FROM consent_templates WHERE id = $tplId");
    $db->exec("DELETE FROM activity_log WHERE new_value LIKE " . $db->quote("%Filler consent $tag%"));
    $db->exec("DELETE FROM login_log WHERE user_agent = 'ClinicCRM-consents-suite/1.0'");
}
echo "\n$pass passed, $fail failed\n";
exit($fail ? 1 : 0);

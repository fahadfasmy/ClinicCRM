<?php
const ROOT = __DIR__ . '/../..';   // the project folder
// Phase 3 through the real pages (local server 8080): register a patient with an Emirates ID (masked, shown only with
// permission and logged), shared-phone warning, duplicate Emirates ID refused, convert a lead from its page, upload and
// open a document (not from another clinic), merge, the backfill preview, and who may see what.
// Everything it creates is removed at the end.
require ROOT . '/includes/auth.php';
require ROOT . '/includes/inventory.php';
require ROOT . '/includes/patients.php';
$db = getDB();
$pass = 0; $fail = 0;
$ok = function (bool $c, string $m) use (&$pass, &$fail) { $c ? $pass++ : $fail++; echo ($c ? "  ok   " : "  FAIL "), $m, PHP_EOL; };
$BASE = 'http://127.0.0.1:8080';
$http = function (string $jar, string $method, string $path, $form = [], string $host = '127.0.0.1') use ($BASE) {
    $ch = curl_init($BASE . $path);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => true, CURLOPT_COOKIEJAR => $jar, CURLOPT_COOKIEFILE => $jar,
        CURLOPT_HTTPHEADER => ["Host: $host"], CURLOPT_USERAGENT => 'ClinicCRM-patients-suite/1.0']);
    if ($method === 'POST') { curl_setopt($ch, CURLOPT_POST, true); curl_setopt($ch, CURLOPT_POSTFIELDS, is_array($form) && !array_filter($form, fn($v) => $v instanceof CURLFile) ? http_build_query($form) : $form); }
    $raw = (string)curl_exec($ch); $code = curl_getinfo($ch, CURLINFO_HTTP_CODE); $hs = curl_getinfo($ch, CURLINFO_HEADER_SIZE); $type = (string)curl_getinfo($ch, CURLINFO_CONTENT_TYPE); curl_close($ch);
    preg_match('/^Location:\s*(\S+)/mi', substr($raw, 0, $hs), $m);
    return [$code, $m[1] ?? '', substr($raw, $hs), $type];
};
$acc = file(ROOT . '/local/test_accounts.txt', FILE_IGNORE_NEW_LINES);
$pw = trim(preg_replace('/^Password:\s*/i', '', $acc[2]));
$login = function (string $email) use ($http, $pw) {
    $jar = tempnam(sys_get_temp_dir(), 'pt');
    [, , $b] = $http($jar, 'GET', '/login.php'); preg_match('/name="_csrf" value="([a-f0-9]+)"/', $b, $m);
    [, $loc] = $http($jar, 'POST', '/login.php', ['_csrf' => $m[1] ?? '', 'step' => 'password', 'email' => $email, 'password' => $pw]);
    return $loc === '/pages/dashboard.php' ? $jar : '';
};
$csrf = function (string $jar, string $path) use ($http) { [, , $b] = $http($jar, 'GET', $path); preg_match('/name="_csrf" value="([a-f0-9]+)"|name="csrf-token" content="([a-f0-9]+)"/', $b, $m); return ($m[1] ?? '') ?: ($m[2] ?? ''); };
$phpError = fn(string $h) => (bool)preg_match('/(Fatal error|Parse error|Uncaught |Warning: |Notice: |Deprecated: )/', $h);
$eid = (function () { for ($c = 0; $c <= 9; $c++) if (eidNormalize('78419929876543' . $c)) return '78419929876543' . $c; })();
$tag = (string)random_int(1000, 9999);   // digits: it goes into phone numbers
$made = ['patients' => [], 'leads' => []];

$admin = $login('admin@clinic.test');
$ok($admin !== '', 'admin signs in');
try {
    echo "Register\n";
    [$c, $loc] = $http($admin, 'POST', '/pages/patient_edit.php', ['_csrf' => $csrf($admin, '/pages/patient_edit.php'), 'first_name' => "Mariam$tag", 'last_name' => 'Suite',
        'name_ar' => 'مريم', 'gender' => 'female', 'dob' => '1992-02-02', 'nationality' => 'AE', 'eid' => eidFormat($eid), 'phones' => ["050{$tag}111", ''], 'data_consent' => 1, 'branch_id' => 1]);
    $pid = preg_match('/patient\.php\?id=(\d+)/', $loc, $m) ? (int)$m[1] : 0;
    if ($pid) $made['patients'][] = $pid;
    $ok($c === 302 && $pid > 0, 'new patient saved, straight to the profile');
    [$c, , $b] = $http($admin, 'GET', "/pages/patient.php?id=$pid");
    $ok($c === 200 && !$phpError($b) && str_contains($b, "Mariam$tag") && str_contains($b, 'مريم'), 'profile shows the patient (English and Arabic names)');
    $ok(!str_contains($b, $eid) && !str_contains($b, eidFormat($eid)) && str_contains($b, substr($eid, -4, 3)), 'the Emirates ID is masked (last 4 only)');
    [$c, , $b] = $http($admin, 'GET', "/pages/patient.php?id=$pid&reveal=1");
    $ok(str_contains($b, eidFormat($eid)), 'Show reveals it for someone with permission');
    $ok($db->query("SELECT COUNT(*) FROM patient_access_log WHERE patient_id = $pid AND what = 'eid_view'")->fetchColumn() == 1 && $db->query("SELECT COUNT(*) FROM audit_log WHERE entity_id = $pid AND action = 'eid_viewed'")->fetchColumn() == 1, 'and that is logged');
    [$c, , $b] = $http($admin, 'GET', '/pages/patients.php?q=' . substr($eid, -4));
    $ok(str_contains($b, "Mariam$tag"), 'found by the last 4 of the Emirates ID');
    [$c, , $b] = $http($admin, 'GET', '/pages/patients.php?q=' . urlencode(eidFormat($eid)));
    $ok(str_contains($b, "Mariam$tag"), 'found by the full Emirates ID');
    [$c, , $b] = $http($admin, 'GET', '/pages/patients.php?q=' . urlencode("050{$tag}111"));
    $ok(str_contains($b, "Mariam$tag"), 'found by phone typed the local way');

    echo "Duplicates\n";
    $form = ['_csrf' => $csrf($admin, '/pages/patient_edit.php'), 'first_name' => "Noor$tag", 'last_name' => 'Suite', 'phones' => ["+971 50 {$tag}111"], 'branch_id' => 1];
    [$c, $loc, $b] = $http($admin, 'POST', '/pages/patient_edit.php', $form);
    $ok($c === 200 && str_contains($b, 'already on another patient') && str_contains($b, 'Save anyway'), 'a shared phone number asks first');
    [$c, $loc] = $http($admin, 'POST', '/pages/patient_edit.php', $form + ['confirm_shared_phone' => 1]);
    $pid2 = preg_match('/patient\.php\?id=(\d+)/', $loc, $m) ? (int)$m[1] : 0;
    if ($pid2) $made['patients'][] = $pid2;
    $ok($pid2 > 0, 'and saves when confirmed (family members share numbers)');
    [$c, , $b] = $http($admin, 'POST', '/pages/patient_edit.php', ['_csrf' => $csrf($admin, '/pages/patient_edit.php'), 'first_name' => 'Dup', 'eid' => $eid, 'branch_id' => 1]);
    $ok($c === 200 && str_contains($b, 'already on patient'), 'the same Emirates ID twice is refused');

    echo "Convert a lead\n";
    $db->prepare("INSERT INTO leads (name, phone, stage, source, assigned_to) VALUES (?, ?, 'qualified', 'instagram', 1)")->execute(["Huda $tag Lead", "058{$tag}222"]);
    $lid = (int)$db->lastInsertId(); $made['leads'][] = $lid;
    $db->prepare("INSERT INTO lead_phones (lead_id, phone) VALUES (?, ?), (?, ?)")->execute([$lid, "058{$tag}222", $lid, "048{$tag}22"]);
    [, , $b] = $http($admin, 'GET', "/pages/lead_view.php?id=$lid");
    $ok(str_contains($b, 'Convert to patient'), 'the lead page offers “Convert to patient”');
    [$c, $loc] = $http($admin, 'POST', '/pages/patient_edit.php', ['_csrf' => $csrf($admin, "/pages/lead_view.php?id=$lid"), 'action' => 'convert', 'lead_id' => $lid]);
    $cp = preg_match('/patient\.php\?id=(\d+)/', $loc, $m) ? (int)$m[1] : 0;
    if ($cp) $made['patients'][] = $cp;
    $lead = $db->query("SELECT stage, patient_id FROM leads WHERE id = $lid")->fetch();
    $ok($cp > 0 && $lead['stage'] === 'converted' && (int)$lead['patient_id'] === $cp, 'converted: patient made, lead linked and moved to Converted');
    $ok(array_column(patientPhones($db, $cp), 'phone') === ["+97158{$tag}222", "+97148{$tag}22"], 'both lead phones copied, in E.164');
    [, , $b] = $http($admin, 'GET', "/pages/patient.php?id=$cp&tab=lead");
    $ok(str_contains($b, "Huda $tag Lead"), 'the patient shows the lead history');
    [, , $b] = $http($admin, 'GET', "/pages/lead_view.php?id=$lid");
    $ok(str_contains($b, 'Patient record') && !str_contains($b, 'Convert to patient'), 'the lead now links to the patient instead');
    [$c, $loc] = $http($admin, 'POST', '/pages/patient_edit.php', ['_csrf' => $csrf($admin, "/pages/lead_view.php?id=$lid"), 'action' => 'convert', 'lead_id' => $lid]);
    $ok(str_contains($loc, "id=$cp") && $db->query("SELECT COUNT(*) FROM patients WHERE lead_id = $lid")->fetchColumn() == 1, "converting twice gives the same patient ($c $loc)");

    echo "Documents\n";
    $tmp = tempnam(sys_get_temp_dir(), 'pdf') . '.pdf';
    file_put_contents($tmp, "%PDF-1.4\n%suite $tag\n1 0 obj<<>>endobj\ntrailer<<>>\n%%EOF");
    [$c, $loc] = $http($admin, 'POST', "/pages/patient.php?id=$pid", ['_csrf' => $csrf($admin, "/pages/patient.php?id=$pid&tab=documents"), 'action' => 'upload', 'type' => 'report', 'file' => new CURLFile($tmp, 'application/pdf', 'lab report.pdf')]);
    $docId = (int)$db->query("SELECT MAX(id) FROM patient_documents WHERE patient_id = $pid")->fetchColumn();
    $ok($docId > 0, 'a PDF uploads');
    [$c, , $b, $type] = $http($admin, 'GET', "/pages/download.php?doc=$docId");
    $ok($c === 200 && str_starts_with($type, 'application/pdf') && str_contains($b, "suite $tag"), 'and opens through download.php');
    $ok($db->query("SELECT COUNT(*) FROM patient_access_log WHERE patient_id = $pid AND what = 'document:$docId'")->fetchColumn() == 1, 'opening it is logged');
    $isoT = tenantBySlug('isotest');
    if ($isoT) {
        $iso = dbConnect($isoT['db_name'], $isoT['db_user'], $isoT['db_pass'], $isoT['db_host']);
        $isoPw = 'Iso-' . bin2hex(random_bytes(6));
        $iso->prepare("UPDATE staff SET password = ? WHERE email = 'owner@isotest.test'")->execute([password_hash($isoPw, PASSWORD_DEFAULT)]);
        $ij = tempnam(sys_get_temp_dir(), 'pi');
        [, , $lb] = $http($ij, 'GET', '/login.php', [], 'isotest.localhost'); preg_match('/name="_csrf" value="([a-f0-9]+)"/', $lb, $mm);
        $http($ij, 'POST', '/login.php', ['_csrf' => $mm[1] ?? '', 'step' => 'password', 'email' => 'owner@isotest.test', 'password' => $isoPw], 'isotest.localhost');
        [$c, , $b] = $http($ij, 'GET', "/pages/download.php?doc=$docId", [], 'isotest.localhost');
        $ok($c === 404 && !str_contains($b, "suite $tag"), 'the same document number at another clinic: not found');
        [$c, , $b] = $http($ij, 'GET', "/pages/patient.php?id=$pid", [], 'isotest.localhost');
        $ok($c === 404 && !str_contains($b, "Mariam$tag"), 'nor the patient');
        @unlink($ij);
    }
    [$c] = $http(tempnam(sys_get_temp_dir(), 'x'), 'GET', "/pages/download.php?doc=$docId");
    $ok($c === 302, 'signed out: no document');

    echo "Who sees what\n";
    $inv = $login('inventory@clinic.test');
    [$c] = $http($inv, 'GET', '/pages/patients.php');
    $ok($c === 403, 'inventory staff can’t open patients');
    $doc = $login('doctor@clinic.test');
    [$c, , $b] = $http($doc, 'GET', "/pages/patient.php?id=$pid&reveal=1");
    $ok($c === 200 && !str_contains($b, eidFormat($eid)) && !str_contains($b, '>Show<'), 'a practitioner sees the patient but not the full Emirates ID');
    [$c] = $http($doc, 'GET', '/pages/patient_merge.php');
    $ok($c === 403, 'only admins merge');
    [$c] = $http($doc, 'GET', '/pages/patients.php?export=csv');
    $ok($c === 403, 'only admins export');
    [$c, , $b, $type] = $http($admin, 'GET', '/pages/patients.php?export=csv&q=' . urlencode("Mariam$tag"));
    $ok($c === 200 && str_contains($type, 'text/csv') && str_contains($b, "Mariam$tag") && !str_contains($b, $eid), 'admin export works and holds no full Emirates ID');

    echo "Merge\n";
    $mrn = fn($id) => $db->query("SELECT mrn FROM patients WHERE id = $id")->fetchColumn();
    [$c, , $b] = $http($admin, 'GET', '/pages/patient_merge.php?a=' . urlencode($mrn($pid)) . '&b=' . urlencode($mrn($pid2)));
    $ok($c === 200 && !$phpError($b) && str_contains($b, "Mariam$tag") && str_contains($b, "Noor$tag"), 'the two records side by side');
    [$c, $loc, $b] = $http($admin, 'POST', '/pages/patient_merge.php', ['_csrf' => $csrf($admin, '/pages/patient_merge.php'), 'keep' => $pid, 'drop' => $pid2, 'confirm' => 'MERGE']);
    $ok(str_contains($loc, "patient.php?id=$pid") && $db->query("SELECT status FROM patients WHERE id = $pid2")->fetchColumn() === 'merged', "merged ($c $loc " . (preg_match('/alert-danger.*?<strong>(.*?)<\/strong>/s', $b, $em) ? $em[1] : substr(strip_tags($b), 0, 120)) . ')');
    [$c, $loc] = $http($admin, 'GET', "/pages/patient.php?id=$pid2");
    $ok($c === 302 && str_contains($loc, "id=$pid"), 'opening the merged record goes to the kept one');

    echo "Backfill preview\n";
    [$c, , $b] = $http($admin, 'POST', '/pages/patient_backfill.php', ['_csrf' => $csrf($admin, '/pages/patient_backfill.php'), 'action' => 'preview']);
    $ok($c === 200 && !$phpError($b) && str_contains($b, 'Preview (nothing saved)'), 'the backfill preview runs');
    foreach (['/pages/patients.php', '/pages/patient_backfill.php', "/pages/patient.php?id=$pid&tab=audit", "/pages/patient.php?id=$pid&tab=appointments", "/pages/patient_edit.php?id=$pid"] as $p) {
        [$c, , $b] = $http($admin, 'GET', $p); $ok($c === 200 && !$phpError($b), "$p loads");
    }
} finally {
    foreach ($made['leads'] as $l) { $db->exec("UPDATE leads SET patient_id = NULL WHERE id = $l"); $db->exec("DELETE FROM lead_phones WHERE lead_id = $l"); $db->exec("DELETE FROM activity_log WHERE lead_id = $l"); $db->exec("DELETE FROM lead_score_log WHERE lead_id = $l"); $db->exec("DELETE FROM leads WHERE id = $l"); }
    foreach ($made['patients'] as $p) {
        foreach ($db->query("SELECT path FROM patient_documents WHERE patient_id = $p")->fetchAll(PDO::FETCH_COLUMN) as $f) @unlink(tenantStoragePath() . '/' . $f);
        foreach (['patient_documents', 'patient_phones', 'patient_access_log'] as $t) $db->exec("DELETE FROM $t WHERE patient_id = $p");
        $db->exec("DELETE FROM audit_log WHERE entity = 'patient' AND entity_id = $p");
    }
    if ($made['patients']) { $db->exec("UPDATE patients SET merged_into_id = NULL WHERE id IN (" . implode(',', $made['patients']) . ")"); $db->exec("DELETE FROM patients WHERE id IN (" . implode(',', $made['patients']) . ")"); }
    $db->exec("DELETE FROM login_log WHERE user_agent LIKE 'ClinicCRM-patients-suite/%'");
}
echo "\n$pass passed, $fail failed\n";
exit($fail ? 1 : 0);

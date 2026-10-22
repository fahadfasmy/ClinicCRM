<?php
const ROOT = __DIR__ . '/../..';   // the project folder
// Phase 13 through the real pages: the bell (count, done), the audit log tabs and export, the Data flows screen and a
// recorded restore drill, a patient's data request (JSON, printable copy, anonymise), the sign-in security settings
// (2-step by role, idle sign-out and its browser timer), and who sees what. Everything it adds is removed at the end.
require ROOT . '/includes/auth.php';
$db = getDB();
$pass = 0; $fail = 0;
$ok = function (bool $c, string $m) use (&$pass, &$fail) { $c ? $pass++ : $fail++; echo ($c ? "  ok   " : "  FAIL "), $m, PHP_EOL; };
$BASE = 'http://127.0.0.1:8080';
$UA = 'ClinicCRM-compliance-suite/1.0';
$http = function (string $jar, string $method, string $path, array $form = []) use ($BASE, $UA) {
    $ch = curl_init($BASE . $path);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => true, CURLOPT_COOKIEJAR => $jar, CURLOPT_COOKIEFILE => $jar, CURLOPT_USERAGENT => $UA]);
    if ($method === 'POST') { curl_setopt($ch, CURLOPT_POST, true); curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($form)); }
    $raw = (string)curl_exec($ch); $code = curl_getinfo($ch, CURLINFO_HTTP_CODE); $hs = curl_getinfo($ch, CURLINFO_HEADER_SIZE); curl_close($ch);
    return [$code, substr($raw, 0, $hs), substr($raw, $hs)];
};
$acc = file(ROOT . '/local/test_accounts.txt', FILE_IGNORE_NEW_LINES);
$pw = trim(preg_replace('/^Password:\s*/i', '', $acc[2]));
$tok = [];
$login = function (string $email) use ($http, $pw, &$tok) {
    $jar = tempnam(sys_get_temp_dir(), 'cp');
    [, , $b] = $http($jar, 'GET', '/login.php'); preg_match('/name="_csrf" value="([a-f0-9]+)"/', $b, $m);
    $http($jar, 'POST', '/login.php', ['_csrf' => $m[1] ?? '', 'step' => 'password', 'email' => $email, 'password' => $pw]);
    [, , $d] = $http($jar, 'GET', '/pages/dashboard.php'); preg_match('/name="csrf-token" content="([a-f0-9]+)"/', $d, $t);
    $tok[$jar] = $t[1] ?? '';
    return $jar;
};
$post = function (string $jar, string $path, array $f) use ($http, &$tok) { return $http($jar, 'POST', $path, ['_csrf' => $tok[$jar]] + $f); };
$phpError = fn(string $h) => (bool)preg_match('/(Fatal error|Parse error|Uncaught |Warning: |Notice: |Deprecated: )/', $h);
$tag = (string)random_int(1000, 9999);
$oldMail = $db->query("SELECT k, v FROM integration_settings WHERE k IN ('mail.require_2fa','mail.require_2fa_roles','mail.idle_minutes')")->fetchAll(PDO::FETCH_KEY_PAIR);
$db->prepare("INSERT INTO patients (mrn, first_name, last_name, email, branch_id, status, notes) VALUES (?, ?, 'Private', ?, 1, 'active', 'secret note')")->execute(["PRV-$tag", "Nadia$tag", "nadia$tag@example.test"]);
$pid = (int)$db->lastInsertId();
$db->prepare("INSERT INTO patient_phones (patient_id, phone, is_primary) VALUES (?, ?, 1)")->execute([$pid, '+97155' . random_int(1000000, 9999999)]);
$db->prepare("INSERT INTO staff_notifications (permission, kind, title) VALUES ('messages.view', 'request', ?)")->execute(["Suite request $tag"]);
$nid = (int)$db->lastInsertId();
$drillFile = "suite_$tag.sql.gz";

try {
    echo "Notification bell\n";
    $rec = $login('reception@clinic.test');
    [, , $b] = $http($rec, 'GET', '/pages/dashboard.php');
    $ok(str_contains($b, '/pages/notifications.php') && (bool)preg_match('/aria-label="Notifications, \d+ new"/', $b), 'the bell shows a count');
    [$c, , $b] = $http($rec, 'GET', '/pages/notifications.php');
    $ok($c === 200 && !$phpError($b) && str_contains($b, "Suite request $tag") && !$phpError($b), 'reception sees the patient request');
    $post($rec, '/pages/notifications.php', ['id' => $nid]);
    $ok($db->query("SELECT read_by FROM staff_notifications WHERE id = $nid")->fetchColumn() !== null, '“Done” closes it and records who');
    $inv = $login('inventory@clinic.test');
    [, , $b] = $http($inv, 'GET', '/pages/notifications.php?all=1');
    $ok(!str_contains($b, "Suite request $tag"), 'inventory staff never see patient requests');

    echo "Audit log\n";
    $admin = $login('admin@clinic.test');
    foreach (['changes', 'access', 'leads'] as $r) { [$c, , $b] = $http($admin, 'GET', "/pages/audit.php?r=$r"); $ok($c === 200 && !$phpError($b) && str_contains($b, 'card-crm'), "the $r tab loads"); }
    [, , $b] = $http($admin, 'GET', "/pages/patient.php?id=$pid");
    [, , $b] = $http($admin, 'GET', "/pages/audit.php?r=access&patient=PRV-$tag");
    $ok(str_contains($b, "Nadia$tag") && str_contains($b, 'profile:'), 'opening the chart shows up under Chart access');
    [$c, $h, $b] = $http($admin, 'GET', '/pages/audit.php?r=changes&export=csv');
    $ok($c === 200 && str_contains($h, 'text/csv'), 'CSV export');
    [$c] = $http($rec, 'GET', '/pages/audit.php'); [$c2] = $http($rec, 'GET', '/pages/audit.php?r=access&export=csv');
    $ok($c === 403 && $c2 === 403, 'reception can’t open it or export it');

    echo "Data flows and backups\n";
    [$c, , $b] = $http($admin, 'GET', '/pages/data_flows.php');
    $ok($c === 200 && !$phpError($b) && str_contains($b, 'UAE residency check') && str_contains($b, 'Passed') && str_contains($b, 'WhatsApp Cloud API'), 'the screen: residency check passed, the register');
    $post($admin, '/pages/data_flows.php', ['drilled_on' => date('Y-m-d'), 'backup_file' => $drillFile, 'result' => 'pass', 'tables_count' => 118, 'rows_count' => 4271, 'notes' => 'Suite']);
    [, , $b] = $http($admin, 'GET', '/pages/data_flows.php');
    $ok(str_contains($b, $drillFile) && str_contains($b, 'passed'), 'a restore drill is recorded and shown');
    [$c] = $http($rec, 'GET', '/pages/data_flows.php');
    $ok($c === 403, 'reception can’t open it');

    echo "A patient's data request\n";
    [, , $b] = $http($admin, 'GET', "/pages/patient.php?id=$pid");
    $ok(str_contains($b, "/pages/patient_privacy.php?id=$pid"), 'the patient page has “Data request”');
    [$c, $h, $b] = $http($admin, 'GET', "/pages/patient_privacy.php?id=$pid&export=json");
    $j = json_decode($b, true);
    $ok($c === 200 && str_contains($h, 'attachment') && ($j['patient']['first_name'] ?? '') === "Nadia$tag" && isset($j['patient_phones']), 'JSON download with their details and phones');
    [$c, , $b] = $http($admin, 'GET', "/pages/patient_privacy.php?id=$pid&export=print");
    $ok($c === 200 && str_contains($b, "Nadia$tag") && str_contains($b, 'save as PDF') && !$phpError($b), 'printable copy');
    $post($admin, '/pages/patient_privacy.php', ['id' => $pid, 'reason' => 'Suite test request', 'confirm' => 'WRONG']);
    $ok($db->query("SELECT status FROM patients WHERE id = $pid")->fetchColumn() === 'active', 'a wrong MRN anonymises nothing');
    $post($admin, '/pages/patient_privacy.php', ['id' => $pid, 'reason' => 'Suite test request', 'confirm' => "PRV-$tag"]);
    $p = $db->query("SELECT first_name, email, notes, status FROM patients WHERE id = $pid")->fetch();
    $ok($p === ['first_name' => 'Anonymised', 'email' => null, 'notes' => null, 'status' => 'anonymised'] && !$db->query("SELECT COUNT(*) FROM patient_phones WHERE patient_id = $pid")->fetchColumn(), 'with the MRN typed, the record is anonymised');
    [$c] = $http($rec, 'GET', "/pages/patient_privacy.php?id=$pid&export=json");
    $ok($c === 403, 'reception can’t export or anonymise');

    echo "Sign-in security settings\n";
    $post($admin, '/pages/staff.php', ['action' => 'security_policy', 'require_2fa' => 'on', 'roles_2fa' => ['admin', 'finance'], 'idle_minutes' => 15]);
    $ok($db->query("SELECT v FROM integration_settings WHERE k = 'mail.require_2fa_roles'")->fetchColumn() === '["admin","finance"]' && $db->query("SELECT v FROM integration_settings WHERE k = 'mail.idle_minutes'")->fetchColumn() === '15', '2-step for admin and finance only; idle sign-out 15 minutes');
    [, , $b] = $http($rec, 'GET', '/pages/dashboard.php');
    $ok(str_contains($b, "/logout.php?idle=1") && str_contains($b, '15 * 60000'), 'pages carry the 15-minute timer');
    [, , $b] = $http($admin, 'GET', '/pages/staff.php');
    $ok(str_contains($b, 'value="15" selected') && !$phpError($b), 'the staff page shows the setting');
    [, $h] = $http(tempnam(sys_get_temp_dir(), 'cp'), 'GET', '/logout.php?idle=1');
    [, , $b] = $http(tempnam(sys_get_temp_dir(), 'cp'), 'GET', '/login.php?idle=1');
    $ok(str_contains($h, 'login.php?idle=1') && str_contains($b, 'without activity'), 'signed out by the timer: the sign-in page says why');
} finally {
    $db->exec("DELETE FROM integration_settings WHERE k IN ('mail.require_2fa','mail.require_2fa_roles','mail.idle_minutes')");
    foreach ($oldMail as $k => $v) $db->prepare("INSERT INTO integration_settings (k, v) VALUES (?, ?)")->execute([$k, $v]);
    $db->exec("DELETE FROM staff_notifications WHERE id = $nid");
    $did = $db->query("SELECT id FROM restore_drills WHERE backup_file = " . $db->quote($drillFile))->fetchAll(PDO::FETCH_COLUMN);
    if ($did) { $in = implode(',', $did); $db->exec("DELETE FROM audit_log WHERE entity = 'restore_drill' AND entity_id IN ($in)"); $db->exec("DELETE FROM restore_drills WHERE id IN ($in)"); }
    foreach (['patient_phones', 'patient_access_log'] as $t) $db->exec("DELETE FROM $t WHERE patient_id = $pid");
    $db->exec("DELETE FROM audit_log WHERE entity = 'patient' AND entity_id = $pid");
    $db->exec("DELETE FROM patients WHERE id = $pid");
    $db->exec("DELETE FROM activity_log WHERE action = 'security_policy_changed' AND created_at > NOW() - INTERVAL 1 HOUR");
    $db->exec("DELETE FROM audit_log WHERE entity = 'setting' AND action = 'security_policy' AND at > NOW() - INTERVAL 1 HOUR");
    $db->exec("DELETE FROM login_log WHERE user_agent = " . $db->quote($UA));
}
echo "\n$pass passed, $fail failed\n";
exit($fail ? 1 : 0);

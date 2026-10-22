<?php
const ROOT = __DIR__ . '/../..';   // the project folder
// Phase 2 acceptance through the real pages (local server 8080): as admin, create a branch with 3 rooms and 2 doctors with
// weekly schedules and time off; the scheduler's free slots follow them; Staff → Access shows the new permissions.
// Everything it creates is removed at the end.
require ROOT . '/includes/auth.php';
require ROOT . '/includes/inventory.php';
require ROOT . '/includes/clinic_setup.php';
$db = getDB();
$pass = 0; $fail = 0;
$ok = function (bool $c, string $m) use (&$pass, &$fail) { $c ? $pass++ : $fail++; echo ($c ? "  ok   " : "  FAIL "), $m, PHP_EOL; };
$BASE = 'http://127.0.0.1:8080';
$jar = tempnam(sys_get_temp_dir(), 'cs');
$http = function (string $method, string $path, array $form = []) use ($BASE, $jar) {
    $ch = curl_init($BASE . $path);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => true, CURLOPT_COOKIEJAR => $jar, CURLOPT_COOKIEFILE => $jar, CURLOPT_USERAGENT => 'ClinicCRM-setup-suite/1.0']);
    if ($method === 'POST') { curl_setopt($ch, CURLOPT_POST, true); curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($form)); }
    $raw = (string)curl_exec($ch); $code = curl_getinfo($ch, CURLINFO_HTTP_CODE); $hs = curl_getinfo($ch, CURLINFO_HEADER_SIZE); curl_close($ch);
    preg_match('/^Location:\s*(\S+)/mi', substr($raw, 0, $hs), $m);
    return [$code, $m[1] ?? '', substr($raw, $hs)];
};
$csrf = function (string $path) use ($http) { [, , $b] = $http('GET', $path); preg_match('/name="_csrf" value="([a-f0-9]+)"/', $b, $m); return $m[1] ?? ''; };
$phpError = fn(string $h) => (bool)preg_match('/(Fatal error|Parse error|Uncaught |Warning: |Notice: |Deprecated: )/', $h);

$acc = file(ROOT . '/local/test_accounts.txt', FILE_IGNORE_NEW_LINES);
$t = $csrf('/login.php');
[, $loc] = $http('POST', '/login.php', ['_csrf' => $t, 'step' => 'password', 'email' => trim(explode('/', $acc[1])[0]), 'password' => trim(preg_replace('/^Password:\s*/i', '', $acc[2]))]);
$ok($loc === '/pages/dashboard.php', 'admin signs in');
$tag = strtoupper(substr(bin2hex(random_bytes(2)), 0, 3));
$made = ['branch' => 0, 'staff' => []];

try {
    echo "Branch and rooms\n";
    [, $loc] = $http('POST', '/pages/settings_clinic.php', ['_csrf' => $csrf('/pages/settings_clinic.php'), 'action' => 'branch_add', 'name' => "Test Branch $tag", 'invoice_prefix' => "T$tag"]);
    $made['branch'] = $bid = (int)($db->query("SELECT id FROM branches WHERE invoice_prefix = 'T$tag'")->fetchColumn());
    $ok($bid > 0 && str_contains($loc, "/pages/branch.php?id=$bid"), 'branch added, straight on to its page');
    [, , $b] = $http('POST', '/pages/settings_clinic.php', ['_csrf' => $csrf('/pages/settings_clinic.php'), 'action' => 'branch_add', 'name' => 'Dup', 'invoice_prefix' => "T$tag"]);
    $ok($db->query("SELECT COUNT(*) FROM branches WHERE invoice_prefix = 'T$tag'")->fetchColumn() == 1, 'a second branch with the same prefix is refused');
    $hours = ['open' => [1 => 1, 2 => 1, 3 => 1], 'from' => [1 => '10:00', 2 => '10:00', 3 => '10:00'], 'to' => [1 => '22:00', 2 => '22:00', 3 => '22:00']];
    $http('POST', "/pages/branch.php?id=$bid", ['_csrf' => $csrf("/pages/branch.php?id=$bid"), 'action' => 'save', 'name' => "Test Branch $tag", 'name_ar' => 'فرع تجريبي',
        'invoice_prefix' => "T$tag", 'trn' => '100000000000003', 'dha_license' => 'DHA-F-0000001', 'is_active' => 1] + $hours);
    $br = $db->query("SELECT * FROM branches WHERE id = $bid")->fetch();
    $ok($br['name_ar'] === 'فرع تجريبي' && $br['trn'] === '100000000000003' && json_decode($br['working_hours'], true)[2] === ['10:00', '22:00'], 'details, Arabic name and opening hours saved');
    foreach ([['Room 1', 'consultation'], ['Laser room', 'laser'], ['Procedure room', 'procedure']] as [$rn, $rt])
        $http('POST', "/pages/branch.php?id=$bid", ['_csrf' => $csrf("/pages/branch.php?id=$bid"), 'action' => 'room_save', 'room_name' => $rn, 'type' => $rt]);
    $rooms = $db->query("SELECT name, type FROM rooms WHERE branch_id = $bid ORDER BY id")->fetchAll(PDO::FETCH_KEY_PAIR);
    $ok($rooms === ['Room 1' => 'consultation', 'Laser room' => 'laser', 'Procedure room' => 'procedure'], '3 rooms added with their types');
    [$c, , $b] = $http('GET', "/pages/branch.php?id=$bid");
    $ok($c === 200 && !$phpError($b) && substr_count($b, 'list-group-item d-flex align-items-center') === 3, 'branch page lists the 3 rooms');

    echo "Two doctors with schedules\n";
    $doctors = [];
    foreach (['Dr Amal Test', 'Dr Omar Test'] as $i => $dn) {
        $email = "doctor$i.$tag@example.test";
        $http('POST', '/pages/staff.php', ['_csrf' => $csrf('/pages/staff.php'), 'action' => 'add', 'name' => "$dn $tag", 'email' => $email,
            'role' => 'practitioner', 'branch_id' => $bid, 'language' => $i ? 'ar' : 'en', 'password' => 'Pw-' . bin2hex(random_bytes(8))]);
        $row = $db->query("SELECT id, role, branch_id, language FROM staff WHERE email = " . $db->quote($email))->fetch();
        if ($row) { $doctors[] = (int)$row['id']; $made['staff'][] = (int)$row['id']; }
        $ok($row && $row['role'] === 'practitioner' && (int)$row['branch_id'] === $bid && $row['language'] === ($i ? 'ar' : 'en'), "$dn added with home branch and language");
    }
    [$c, , $b] = $http('GET', '/pages/practitioners.php');
    $ok($c === 200 && !$phpError($b) && str_contains($b, "Dr Amal Test $tag") && str_contains($b, "Dr Omar Test $tag"), 'both appear on Practitioners');
    $db->exec("INSERT IGNORE INTO departments (name) VALUES ('Dermatology Test $tag')");
    $dept = (int)$db->query("SELECT id FROM departments WHERE name = 'Dermatology Test $tag'")->fetchColumn();
    [$d0, $d1] = $doctors + [0, 0];
    $http('POST', "/pages/practitioners.php?id=$d0", ['_csrf' => $csrf("/pages/practitioners.php?id=$d0"), 'action' => 'profile', 'staff_id' => $d0, 'branch_id' => $bid,
        'dha_license' => 'DHA-P-123', 'specialty' => 'Dermatologist', 'department_id' => $dept, 'color' => '#2a7f62', 'is_bookable' => 1, 'sort_order' => 1]);
    $pp = $db->query("SELECT * FROM practitioner_profiles WHERE staff_id = $d0")->fetch();
    $ok($pp && $pp['dha_license'] === 'DHA-P-123' && (int)$pp['department_id'] === $dept && $pp['color'] === '#2a7f62', 'profile saved (licence, department, colour)');
    $sched = function (int $sid, array $rows) use ($http, $csrf, $bid) {
        $f = ['_csrf' => $csrf("/pages/practitioners.php?id=$sid&branch=$bid"), 'action' => 'schedule', 'staff_id' => $sid, 'branch_id' => $bid, 'from' => [], 'to' => []];
        foreach ($rows as [$d, $k, $a, $z]) { $f['from'][$d][$k] = $a; $f['to'][$d][$k] = $z; }
        return $http('POST', "/pages/practitioners.php?id=$sid&branch=$bid", $f);
    };
    $sched($d0, [[1, 0, '10:00', '14:00'], [1, 1, '17:00', '21:00'], [3, 0, '10:00', '18:00']]);
    $sched($d1, [[2, 0, '12:00', '20:00']]);
    $ok(practitionerSchedule($db, $d0)[$bid] === [1 => [['10:00', '14:00'], ['17:00', '21:00']], 3 => [['10:00', '18:00']]], 'doctor 1: split shift Monday, Wednesday');
    $ok(practitionerSchedule($db, $d1)[$bid] === [2 => [['12:00', '20:00']]], 'doctor 2: Tuesday');
    $sched($d1, [[2, 0, '20:00', '12:00']]);
    [, , $b] = $http('GET', "/pages/practitioners.php?id=$d1&branch=$bid");
    $ok(str_contains($b, 'must be after the start') && practitionerSchedule($db, $d1)[$bid] === [2 => [['12:00', '20:00']]], 'a wrong schedule is refused with a reason and the old one kept');

    echo "Free slots and time off\n";
    $tz = new DateTimeZone(CLINIC_TZ);
    $mon = (new DateTimeImmutable('next monday', $tz))->format('Y-m-d');
    $tue = (new DateTimeImmutable('next tuesday', $tz))->format('Y-m-d');
    $ok(count(freeSlots($db, $d0, $bid, $mon, 60, 60)) === 8 && freeSlots($db, $d0, $bid, $tue, 30) === [], 'doctor 1: 8 one-hour slots on Monday, none on Tuesday');
    $http('POST', "/pages/practitioners.php?id=$d0", ['_csrf' => $csrf("/pages/practitioners.php?id=$d0&branch=$bid"), 'action' => 'block_add', 'staff_id' => $d0, 'branch_id' => $bid,
        'from_date' => $mon, 'from_time' => '17:00', 'to_date' => '', 'to_time' => '21:00', 'reason' => 'Training']);
    $ok(count(freeSlots($db, $d0, $bid, $mon, 60, 60)) === 4, 'time off on Monday evening removes those 4 slots');
    [, , $b] = $http('GET', "/pages/practitioners.php?id=$d0&branch=$bid");
    $ok(str_contains($b, 'Training') && !$phpError($b), 'time off is listed');
    $ok(count(freeSlots($db, $d1, $bid, $tue, 30, 30)) === 16, 'doctor 2: 16 half-hour slots on Tuesday');

    echo "Staff → Access\n";
    [$c, , $b] = $http('GET', "/pages/staff_access.php?id=$d0");
    $want = ['See patients', 'See the scheduler', 'Sign notes', 'Prescribe', 'Invoice and take payments', 'Claims', 'Clinical reports', 'Document layouts', 'Clinical records', 'Billing', 'Insurance'];
    $missing = array_filter($want, fn($w) => !str_contains($b, $w));
    $ok($c === 200 && !$missing, 'new permissions and groups are listed' . ($missing ? ': missing ' . implode(', ', $missing) : ''));
    $ok((bool)preg_match('/name="perm\[emr\.sign\]"[^>]*checked/', $b) && !preg_match('/name="perm\[billing\.void\]"[^>]*checked/', $b), 'practitioner defaults: signs notes, cannot void invoices');
    [$c, , $b] = $http('GET', '/pages/staff.php');
    $ok($c === 200 && str_contains($b, '>Nurse<') && str_contains($b, '>Finance<') && str_contains($b, '>Insurance<'), 'Staff page offers the new roles');
    foreach (['/pages/settings_clinic.php', '/pages/practitioners.php', "/pages/branch.php?id=$bid"] as $p) { [$c, , $b] = $http('GET', $p); $ok($c === 200 && !$phpError($b), "$p loads"); }
    [$c] = $http('GET', '/pages/branch.php?id=999999');
    $ok($c === 404, 'unknown branch → 404');
} finally {
    foreach ($made['staff'] as $s) {
        foreach (['schedule_blocks', 'practitioner_schedules', 'practitioner_profiles', 'staff_permissions'] as $tb) $db->exec("DELETE FROM $tb WHERE staff_id = $s");
        $db->exec("DELETE FROM staff WHERE id = $s");
    }
    if ($made['branch']) { $db->exec("DELETE FROM schedule_blocks WHERE branch_id = {$made['branch']}"); $db->exec("DELETE FROM rooms WHERE branch_id = {$made['branch']}"); $db->exec("DELETE FROM branches WHERE id = {$made['branch']}"); }
    $db->exec("DELETE FROM departments WHERE name = 'Dermatology Test $tag'");
    $db->exec("DELETE FROM login_log WHERE user_agent = 'ClinicCRM-setup-suite/1.0'");
    $http('GET', '/logout.php'); @unlink($jar);
}
echo "\n$pass passed, $fail failed\n";
exit($fail ? 1 : 0);

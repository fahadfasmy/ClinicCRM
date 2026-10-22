<?php
const ROOT = __DIR__ . '/../..';   // the project folder
// Phase 4 through the real pages and the scheduler API (local server 8080): book for a patient and for a lead (which
// becomes a patient), double booking refused, move, the visit steps by role (reception checks in, nurse triages),
// practitioners only see their own column, the waiting list, blocking time, the old lead-page booking still filling
// the new fields, and every new page loading. Everything it creates is removed at the end (stock released).
require ROOT . '/includes/auth.php';
require ROOT . '/includes/scheduler.php';
$db = getDB();
$pass = 0; $fail = 0;
$ok = function (bool $c, string $m) use (&$pass, &$fail) { $c ? $pass++ : $fail++; echo ($c ? "  ok   " : "  FAIL "), $m, PHP_EOL; };
$BASE = 'http://127.0.0.1:8080';
$http = function (string $jar, string $method, string $path, array $form = [], array $headers = []) use ($BASE) {
    $ch = curl_init($BASE . $path);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => true, CURLOPT_COOKIEJAR => $jar, CURLOPT_COOKIEFILE => $jar, CURLOPT_HTTPHEADER => $headers, CURLOPT_USERAGENT => 'ClinicCRM-scheduler-suite/1.0']);
    if ($method === 'POST') { curl_setopt($ch, CURLOPT_POST, true); curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($form)); }
    $raw = (string)curl_exec($ch); $code = curl_getinfo($ch, CURLINFO_HTTP_CODE); $hs = curl_getinfo($ch, CURLINFO_HEADER_SIZE); curl_close($ch);
    preg_match('/^Location:\s*(\S+)/mi', substr($raw, 0, $hs), $m);
    return [$code, $m[1] ?? '', substr($raw, $hs)];
};
$acc = file(ROOT . '/local/test_accounts.txt', FILE_IGNORE_NEW_LINES);
$pw = trim(preg_replace('/^Password:\s*/i', '', $acc[2]));
$tokens = [];
$login = function (string $email) use ($http, $pw, &$tokens) {
    $jar = tempnam(sys_get_temp_dir(), 'sc');
    [, , $b] = $http($jar, 'GET', '/login.php'); preg_match('/name="_csrf" value="([a-f0-9]+)"/', $b, $m);
    $http($jar, 'POST', '/login.php', ['_csrf' => $m[1] ?? '', 'step' => 'password', 'email' => $email, 'password' => $pw]);
    [, , $d] = $http($jar, 'GET', '/pages/dashboard.php'); preg_match('/name="csrf-token" content="([a-f0-9]+)"/', $d, $t);
    $tokens[$jar] = $t[1] ?? '';
    return $jar;
};
$api = function (string $jar, array $q) use ($http) { [$c, , $b] = $http($jar, 'GET', '/pages/scheduler_api.php?' . http_build_query($q)); return [$c, json_decode($b, true)]; };
$apiPost = function (string $jar, array $f) use ($http, &$tokens) { [$c, , $b] = $http($jar, 'POST', '/pages/scheduler_api.php', $f, ['X-CSRF-Token: ' . $tokens[$jar]]); return [$c, json_decode($b, true) ?? ['raw' => substr($b, 0, 200)]]; };
$phpError = fn(string $h) => (bool)preg_match('/(Fatal error|Parse error|Uncaught |Warning: |Notice: |Deprecated: )/', $h);

$branch = (int)$db->query("SELECT MIN(id) FROM branches WHERE is_active = 1")->fetchColumn();
$doctorId = (int)$db->query("SELECT id FROM staff WHERE email = 'doctor@clinic.test'")->fetchColumn();
$nurseId = (int)$db->query("SELECT id FROM staff WHERE email = 'nurse@clinic.test'")->fetchColumn();
$svc = (int)$db->query("SELECT id FROM services WHERE is_active = 1 ORDER BY duration_min LIMIT 1")->fetchColumn();
$day = (new DateTimeImmutable('+20 days', new DateTimeZone(CLINIC_TZ)))->format('Y-m-d');
$tag = (string)random_int(1000, 9999);
$made = ['appts' => [], 'patients' => [], 'leads' => [], 'blocks' => [], 'wait' => []];
$maxAppt = (int)$db->query("SELECT COALESCE(MAX(id),0) FROM appointments")->fetchColumn();
$db->prepare("INSERT INTO patients (mrn, first_name, last_name, branch_id) VALUES (?, ?, 'Sched', ?)")->execute(["SCH-$tag", "Pat$tag", $branch]);
$pid = (int)$db->lastInsertId(); $made['patients'][] = $pid;
$db->prepare("INSERT INTO leads (name, phone, stage, source, assigned_to) VALUES (?, ?, 'qualified', 'whatsapp', 1)")->execute(["Lead$tag Sched", "05099{$tag}1"]);
$lid = (int)$db->lastInsertId(); $made['leads'][] = $lid;

$admin = $login('admin@clinic.test');
try {
    echo "Pages and data\n";
    foreach (['/pages/scheduler.php', '/pages/scheduler.php?view=week', '/pages/visit_board.php', '/pages/waiting_list.php', "/pages/scheduler.php?patient=$pid", "/pages/scheduler.php?lead=$lid"] as $p) {
        [$c, , $b] = $http($admin, 'GET', $p); $ok($c === 200 && !$phpError($b), "$p loads");
    }
    [$c, $r] = $api($admin, ['action' => 'resources', 'branch' => $branch, 'date' => $day]);
    $ok($c === 200 && in_array($doctorId, array_column($r['practitioners'], 'id'), true) && isset($r['open']), 'resources: practitioners, rooms and opening hours');
    [$c, $r] = $api($admin, ['action' => 'search', 'q' => "Pat$tag"]);
    $ok($c === 200 && ($r[0]['kind'] ?? '') === 'patient' && $r[0]['id'] === $pid, 'search finds the patient');
    [$c, $r] = $api($admin, ['action' => 'search', 'q' => "Lead$tag"]);
    $ok(($r[0]['kind'] ?? '') === 'lead' && $r[0]['id'] === $lid, 'and open leads');

    echo "Booking\n";
    [$c, $r] = $apiPost($admin, ['action' => 'book', 'branch' => $branch, 'patient_id' => $pid, 'service_ids' => [$svc], 'date' => $day, 'time' => '10:00', 'practitioner_id' => $doctorId, 'visit_type' => 'first_visit']);
    $a1 = (int)($r['id'] ?? 0); if ($a1) $made['appts'][] = $a1;
    $ok($c === 200 && $a1 > 0, 'booked a patient');
    [$c, $r] = $apiPost($admin, ['action' => 'book', 'branch' => $branch, 'lead_id' => $lid, 'service_ids' => [$svc], 'date' => $day, 'time' => '10:05', 'practitioner_id' => $doctorId]);
    $ok($c === 422 && str_contains($r['error'] ?? '', 'already has an appointment'), 'double booking refused: ' . ($r['error'] ?? ''));
    [$c, $r] = $apiPost($admin, ['action' => 'book', 'branch' => $branch, 'lead_id' => $lid, 'service_ids' => [$svc], 'date' => $day, 'time' => '14:00', 'practitioner_id' => $doctorId]);
    $a2 = (int)($r['id'] ?? 0); if ($a2) $made['appts'][] = $a2;
    $lead = $db->query("SELECT stage, patient_id FROM leads WHERE id = $lid")->fetch();
    if ($lead['patient_id']) $made['patients'][] = (int)$lead['patient_id'];
    $ok($a2 > 0 && $lead['patient_id'] && $lead['stage'] === 'booked' && (int)$db->query("SELECT patient_id FROM appointments WHERE id = $a2")->fetchColumn() === (int)$lead['patient_id'],
        'booking a lead makes it a patient and moves the lead to Booked');
    [$c, $r] = $api($admin, ['action' => 'events', 'branch' => $branch, 'start' => $day . 'T00:00', 'end' => $day . 'T23:59']);
    $ev = array_values(array_filter($r, fn($e) => $e['id'] === $a1))[0] ?? null;
    $ok($ev && $ev['practitionerId'] === $doctorId && $ev['editable'] === true, 'events: the visit with its practitioner, movable');

    echo "Moving\n";
    [$c, $r] = $apiPost($admin, ['action' => 'move', 'branch' => $branch, 'id' => $a1, 'date' => $day, 'time' => '14:15', 'practitioner_id' => $doctorId]);
    $ok($c === 422 && str_contains($r['error'] ?? '', 'already has an appointment'), 'moving onto another booking is refused');
    [$c, $r] = $apiPost($admin, ['action' => 'move', 'branch' => $branch, 'id' => $a1, 'date' => $day, 'time' => '11:30', 'practitioner_id' => $doctorId]);
    $ok($c === 200 && substr($db->query("SELECT scheduled_at FROM appointments WHERE id = $a1")->fetchColumn(), 11, 5) === '11:30', 'moved');

    echo "Visit steps by role\n";
    $rec = $login('reception@clinic.test');
    $nurse = $login('nurse@clinic.test');
    $doc = $login('doctor@clinic.test');
    [$c, $r] = $apiPost($nurse, ['action' => 'step', 'id' => $a1, 'to' => 'arrived']);
    $ok($c === 422 && str_contains($r['error'] ?? '', 'permission'), 'a nurse can’t check people in');
    [$c] = $apiPost($rec, ['action' => 'step', 'id' => $a1, 'to' => 'arrived']);
    $ok($c === 200, 'reception checks the patient in');
    [$c, $r] = $apiPost($rec, ['action' => 'step', 'id' => $a1, 'to' => 'in_triage']);
    $ok($c === 422, 'reception can’t triage');
    [$c] = $apiPost($nurse, ['action' => 'step', 'id' => $a1, 'to' => 'in_triage']);
    [$c2] = $apiPost($doc, ['action' => 'step', 'id' => $a1, 'to' => 'with_doctor']);
    $st = $db->query("SELECT status, arrived_at, triage_at, doctor_in_at FROM appointments WHERE id = $a1")->fetch();
    $ok($c === 200 && $c2 === 200 && $st['status'] === 'with_doctor' && $st['arrived_at'] && $st['triage_at'] && $st['doctor_in_at'], 'nurse triages, doctor calls in; every step stamped');
    [$c, $r] = $api($admin, ['action' => 'appointment', 'id' => $a1]);
    $ok(count(array_filter($r['journey'], fn($j) => $j['at'])) === 4 && $r['waited'] !== null, 'the journey shows four steps done and the waiting time');
    [, , $b] = $http($rec, 'GET', '/pages/visit_board.php?branch=' . $branch);
    $ok(!$phpError($b), 'visit board loads for reception');

    echo "Who sees what\n";
    $db->prepare("INSERT INTO appointments (patient_id, service_id, scheduled_at, ends_at, duration_min, practitioner_id, branch_id) VALUES (?, ?, ?, ?, 30, ?, ?)")
       ->execute([$pid, $svc, "$day 16:00", "$day 16:30", $nurseId, $branch]);
    $other = (int)$db->lastInsertId(); $made['appts'][] = $other;
    [$c, $r] = $api($doc, ['action' => 'events', 'branch' => $branch, 'start' => $day . 'T00:00', 'end' => $day . 'T23:59']);
    $ids = array_column($r, 'id');
    $ok(in_array($a1, $ids, true) && !in_array($other, $ids, true), 'a practitioner sees their own visits, not a colleague’s');
    [$c] = $api($doc, ['action' => 'appointment', 'id' => $other]);
    $ok($c === 404, 'nor can they open a colleague’s visit');
    [$c] = $apiPost($doc, ['action' => 'book', 'branch' => $branch, 'patient_id' => $pid, 'service_ids' => [$svc], 'date' => $day, 'time' => '18:00']);
    $ok($c === 403, 'practitioners don’t book in the scheduler by default');
    $inv = $login('inventory@clinic.test');
    [$c] = $http($inv, 'GET', '/pages/scheduler.php');
    $ok($c === 403, 'inventory staff can’t open the scheduler');
    [$c] = $http($admin, 'POST', '/pages/scheduler_api.php', ['action' => 'book', 'patient_id' => $pid]);
    $ok($c === 419, 'a write without the CSRF token is refused');

    echo "Block time and waiting list\n";
    [$c] = $apiPost($rec, ['action' => 'block', 'branch' => $branch, 'staff_id' => $doctorId, 'date' => $day, 'from' => '17:00', 'to' => '18:00', 'reason' => "Meeting $tag"]);
    $made['blocks'] = $db->query("SELECT id FROM schedule_blocks WHERE reason = 'Meeting $tag'")->fetchAll(PDO::FETCH_COLUMN);
    [$c2, $r] = $apiPost($admin, ['action' => 'book', 'branch' => $branch, 'patient_id' => $pid, 'service_ids' => [$svc], 'date' => $day, 'time' => '17:15', 'practitioner_id' => $doctorId]);
    $ok($c === 200 && $c2 === 422 && str_contains($r['error'] ?? '', "Meeting $tag"), 'reception blocks time; booking into it is refused with the reason');
    [$c] = $apiPost($admin, ['action' => 'waitlist_add', 'branch' => $branch, 'patient_id' => $pid, 'service_id' => $svc, 'notes' => "Wait $tag"]);
    $wid = (int)$db->query("SELECT id FROM waiting_list WHERE notes = 'Wait $tag'")->fetchColumn(); $made['wait'][] = $wid;
    [, , $b] = $http($admin, 'GET', '/pages/waiting_list.php');
    $ok($wid > 0 && str_contains($b, "Wait $tag"), 'added to the waiting list, shown on its page');
    [$c, $r] = $apiPost($admin, ['action' => 'book', 'branch' => $branch, 'patient_id' => $pid, 'service_ids' => [$svc], 'date' => $day, 'time' => '19:00', 'waitlist_id' => $wid]);
    if (!empty($r['id'])) $made['appts'][] = (int)$r['id'];
    $ok($c === 200 && $db->query("SELECT resolved_note FROM waiting_list WHERE id = $wid")->fetchColumn() === 'booked', 'booking from the waiting list resolves the entry');

    echo "Lead page booking (old way) fills the new fields\n";
    $db->prepare("INSERT INTO leads (name, phone, stage, source, assigned_to) VALUES (?, ?, 'new', 'other', 1)")->execute(["Old$tag Way", "05098{$tag}2"]);
    $lid2 = (int)$db->lastInsertId(); $made['leads'][] = $lid2;
    [, , $b] = $http($admin, 'GET', "/pages/appointment.php?lead=$lid2"); preg_match('/name="_csrf" value="([a-f0-9]+)"/', $b, $m);
    [$c, $loc] = $http($admin, 'POST', "/pages/appointment.php?lead=$lid2", ['_csrf' => $m[1] ?? '', 'service_id' => $svc, 'date' => $day, 'time' => '20:00', 'practitioner_id' => '', 'notes' => '']);
    $a3 = preg_match('/id=(\d+)/', $loc, $mm) ? (int)$mm[1] : 0; if ($a3) $made['appts'][] = $a3;
    $row = $a3 ? $db->query("SELECT * FROM appointments WHERE id = $a3")->fetch() : null;
    $ok($row && $row['ends_at'] && $row['branch_id'] && $db->query("SELECT COUNT(*) FROM appointment_services WHERE appointment_id = $a3")->fetchColumn() == 1, 'book from the lead page: end time, branch and treatment link filled');
    [$c, , $b] = $http($admin, 'GET', "/pages/appointment.php?id=$a1");
    $ok($c === 200 && !$phpError($b) && str_contains($b, 'Journey') && str_contains($b, 'With doctor'), 'the appointment page shows the status and the journey');
    [$c, , $b] = $http($admin, 'GET', '/pages/appointments.php?tab=upcoming');
    $ok($c === 200 && !$phpError($b) && str_contains($b, "Pat$tag"), "the appointments list shows patient-only visits ($c" . (preg_match('/(Fatal error|Warning|Uncaught)[^<]{0,200}/', $b, $em) ? ' ' . $em[0] : '') . ')');
} finally {
    foreach ($db->query("SELECT id, status FROM appointments WHERE id > $maxAppt")->fetchAll() as $a)
        if (in_array($a['status'], APPT_OPEN, true)) { try { cancelAppointment($db, (int)$a['id'], 'cancelled', 'test cleanup'); } catch (Throwable $e) {} }
    foreach ($db->query("SELECT id FROM appointments WHERE id > $maxAppt")->fetchAll(PDO::FETCH_COLUMN) as $id)
        foreach (['stock_movements', 'appointment_items', 'appointment_gcal', 'appointment_services', 'appointment_status_history'] as $t) $db->exec("DELETE FROM $t WHERE appointment_id = $id");
    $db->exec("DELETE FROM appointments WHERE id > $maxAppt");
    foreach ($made['blocks'] as $b) $db->exec("DELETE FROM schedule_blocks WHERE id = " . (int)$b);
    foreach ($made['wait'] as $w) $db->exec("DELETE FROM waiting_list WHERE id = " . (int)$w);
    foreach ($made['leads'] as $l) { foreach (['lead_phones', 'activity_log', 'lead_score_log'] as $t) $db->exec("DELETE FROM $t WHERE lead_id = $l"); $db->exec("UPDATE leads SET patient_id = NULL WHERE id = $l"); }
    foreach (array_unique($made['patients']) as $p) { foreach (['patient_phones', 'patient_access_log'] as $t) $db->exec("DELETE FROM $t WHERE patient_id = $p"); $db->exec("DELETE FROM audit_log WHERE entity = 'patient' AND entity_id = $p"); $db->exec("DELETE FROM patients WHERE id = $p"); }
    foreach ($made['leads'] as $l) $db->exec("DELETE FROM leads WHERE id = $l");
    $db->exec("DELETE FROM activity_log WHERE action LIKE 'appointment_%' AND created_at > NOW() - INTERVAL 1 HOUR AND new_value > $maxAppt");
    $db->exec("DELETE FROM activity_log WHERE action = 'schedule_block_added' AND created_at > NOW() - INTERVAL 1 HOUR");
    $db->exec("DELETE FROM login_log WHERE user_agent = 'ClinicCRM-scheduler-suite/1.0'");
}
echo "\n$pass passed, $fail failed\n";
exit($fail ? 1 : 0);

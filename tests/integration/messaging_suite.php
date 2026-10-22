<?php
const ROOT = __DIR__ . '/../..';   // the project folder
// Phase 10 through the real pages, cron and the WhatsApp / SMS stand-ins (8085 / 8084): admin connects WhatsApp and
// switches the 24-hour reminder on; a patient booked for tomorrow gets exactly one reminder over two cron runs; the
// self-service link confirms the visit; feedback 10 shows the Google review link; the owner-only health switch; the
// webhook (bad signature refused, incoming message in the inbox, reply within 24 hours); who sees what.
// Everything it creates is removed and the settings restored at the end.
require ROOT . '/includes/auth.php';
require ROOT . '/includes/messaging.php';
$db = getDB();
$pass = 0; $fail = 0;
$ok = function (bool $c, string $m) use (&$pass, &$fail) { $c ? $pass++ : $fail++; echo ($c ? "  ok   " : "  FAIL "), $m, PHP_EOL; };
$BASE = 'http://127.0.0.1:8080';
$http = function (string $jar, string $method, string $path, $form = [], array $headers = []) use ($BASE) {
    $ch = curl_init(str_starts_with($path, 'http') ? $path : $BASE . $path);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => true, CURLOPT_COOKIEJAR => $jar, CURLOPT_COOKIEFILE => $jar, CURLOPT_USERAGENT => 'ClinicCRM-messaging-suite/1.0', CURLOPT_HTTPHEADER => $headers]);
    if ($method === 'POST') { curl_setopt($ch, CURLOPT_POST, true); curl_setopt($ch, CURLOPT_POSTFIELDS, is_string($form) ? $form : http_build_query($form)); }
    $raw = (string)curl_exec($ch); $code = curl_getinfo($ch, CURLINFO_HTTP_CODE); $hs = curl_getinfo($ch, CURLINFO_HEADER_SIZE); curl_close($ch);
    preg_match('/^Location:\s*(\S+)/mi', substr($raw, 0, $hs), $m);
    return [$code, $m[1] ?? '', substr($raw, $hs)];
};
$acc = file(ROOT . '/local/test_accounts.txt', FILE_IGNORE_NEW_LINES);
$pw = trim(preg_replace('/^Password:\s*/i', '', $acc[2]));
$tok = [];
$login = function (string $email) use ($http, $pw, &$tok) {
    $jar = tempnam(sys_get_temp_dir(), 'ms');
    [, , $b] = $http($jar, 'GET', '/login.php'); preg_match('/name="_csrf" value="([a-f0-9]+)"/', $b, $m);
    $http($jar, 'POST', '/login.php', ['_csrf' => $m[1] ?? '', 'step' => 'password', 'email' => $email, 'password' => $pw]);
    [, , $d] = $http($jar, 'GET', '/pages/dashboard.php'); preg_match('/name="csrf-token" content="([a-f0-9]+)"/', $d, $t);
    $tok[$jar] = $t[1] ?? '';
    return $jar;
};
$post = function (string $jar, string $path, array $f) use ($http, &$tok) { return $http($jar, 'POST', $path, ['_csrf' => $tok[$jar]] + $f); };
$phpError = fn(string $h) => (bool)preg_match('/(Fatal error|Parse error|Uncaught |Warning: |Notice: |Deprecated: )/', $h);
$flashOf = fn(string $h) => preg_match('/alert-(success|danger)[^>]*>.*?<strong>(.*?)<\/strong>/s', $h, $m) ? $m[1] . ': ' . html_entity_decode(strip_tags($m[2])) : '';
$mockSent = function () { return json_decode((string)@file_get_contents('http://127.0.0.1:8085/sent'), true) ?: []; };
$cron = fn() => (string)shell_exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(ROOT . '/cron.php') . ' --tenant=main 2>&1');

// The stand-ins, when run on their own (run_all starts them)
$mocks = [];
foreach ([8085 => 'local/wa_cloud_mock.php', 8084 => 'local/sms_mock.php'] as $port => $f)
    if (!@fsockopen('127.0.0.1', $port, $e1, $e2, 0.3)) { $mocks[] = proc_open([PHP_BINARY, '-S', "127.0.0.1:$port", $f], [1 => ['file', 'NUL', 'w'], 2 => ['file', 'NUL', 'w']], $pp, ROOT); usleep(500000); }
@file_get_contents('http://127.0.0.1:8085/reset');

ensureDefaultTemplates($db);
$oldSettings = $db->query("SELECT k, v FROM integration_settings WHERE k LIKE 'msg.%'")->fetchAll(PDO::FETCH_KEY_PAIR);
$oldAuto = $db->query("SELECT `key`, is_active, config FROM automations")->fetchAll(PDO::FETCH_ASSOC);
$oldWa = $db->query("SELECT id, wa_status FROM message_templates WHERE channel = 'whatsapp'")->fetchAll(PDO::FETCH_KEY_PAIR);
$oldHourly = metaGet($db, 'cron_hourly_last');
metaSet($db, 'cron_hourly_last', date('Y-m-d H:i:s'));   // keep this test's cron runs to the messages only
$tag = (string)random_int(1000, 9999);
$phone = '+97150' . random_int(1000000, 9999999);
$branch = (int)$db->query("SELECT id FROM branches WHERE is_active = 1 ORDER BY id LIMIT 1")->fetchColumn();
$svc = (int)$db->query("SELECT id FROM services WHERE is_active = 1 LIMIT 1")->fetchColumn();
$db->prepare("INSERT INTO patients (mrn, first_name, last_name, branch_id, status, whatsapp_opt_in, preferred_language) VALUES (?, ?, 'Msg', ?, 'active', 1, 'en')")->execute(["MSG-$tag", "Rania$tag", $branch]);
$pid = (int)$db->lastInsertId();
$db->prepare("INSERT INTO patient_phones (patient_id, phone, is_primary) VALUES (?, ?, 1)")->execute([$pid, $phone]);
$db->prepare("INSERT INTO appointments (patient_id, service_id, scheduled_at, ends_at, duration_min, branch_id, status) VALUES (?, ?, NOW() + INTERVAL 20 HOUR, NOW() + INTERVAL 21 HOUR, 60, ?, 'booked')")->execute([$pid, $svc, $branch]);
$appt = (int)$db->lastInsertId();
$tplId = 0;

try {
    echo "Set-up\n";
    $admin = $login('admin@clinic.test');
    $post($admin, '/pages/messaging_settings.php', ['action' => 'whatsapp', 'wa_enabled' => 1, 'wa_phone_number_id' => '1098765432', 'wa_token' => "tok-$tag", 'wa_app_secret' => "secret-$tag", 'wa_graph_base' => 'http://127.0.0.1:8085']);
    $post($admin, '/pages/messaging_settings.php', ['action' => 'automations', 'auto' => ['reminder_24h' => 1, 'feedback' => 1], 'feedback_hours' => 3, 'quiet_from' => '21:00', 'quiet_to' => '09:00', 'cancel_cutoff_hours' => 24,
        'map_link' => '', 'booking_link' => '', 'google_review_url' => 'https://g.page/r/example-review', 'base_url' => 'http://127.0.0.1:8080']);
    $db->exec("UPDATE message_templates SET wa_status = 'approved' WHERE channel = 'whatsapp'");
    [$c, , $b] = $http($admin, 'GET', '/pages/messaging_settings.php');
    $ok($c === 200 && str_contains($b, 'Connected') && str_contains($b, '/pages/api_wa_cloud.php') && !str_contains($b, "tok-$tag") && !$phpError($b), 'WhatsApp connected; the token is never shown back');
    $ok(!str_contains((string)$db->query("SELECT v FROM integration_settings WHERE k = 'msg.wa_token'")->fetchColumn(), "tok-$tag"), 'the token is stored encrypted');

    echo "The 24-hour reminder\n";
    $cron(); $cron();
    $mine = array_values(array_filter($mockSent(), fn($m) => ($m['message']['to'] ?? '') === ltrim($phone, '+')));
    $ok(count($mine) === 1 && ($mine[0]['message']['template']['name'] ?? '') === 'clinic_reminder_24h', 'two cron runs: exactly one WhatsApp reminder, using the approved template');
    $params = array_column($mine[0]['message']['template']['components'][0]['parameters'] ?? [], 'text');
    $link = array_values(array_filter($params, fn($p) => str_contains($p, '/s.php?t=')))[0] ?? '';
    $ok(in_array("Rania$tag", $params, true) && $link !== '', 'the reminder carries the first name and a self-service link');

    echo "Self-service link and feedback\n";
    $anon = tempnam(sys_get_temp_dir(), 'ms');
    [$c, , $b] = $http($anon, 'GET', $link);
    $ok($c === 200 && str_contains($b, "Rania$tag") && str_contains($b, 'Confirm') && !str_contains($b, 'Botox') && !$phpError($b), 'the link shows the visit without signing in (no treatment named)');
    $http($anon, 'POST', '/s.php', ['t' => substr($link, -48), 'do' => 'confirm']);
    $ok($db->query("SELECT status FROM appointments WHERE id = $appt")->fetchColumn() === 'confirmed', 'the patient confirms it');
    [$c, , $b] = $http($anon, 'GET', '/s.php?t=' . str_repeat('a', 48));
    $ok($c === 200 && str_contains($b, 'expired or is not valid'), 'a made-up link shows nothing');
    $db->exec("UPDATE appointments SET status = 'completed', completed_at = NOW() - INTERVAL 4 HOUR, scheduled_at = NOW() - INTERVAL 5 HOUR WHERE id = $appt");
    $cron();
    $fb = array_values(array_filter($mockSent(), fn($m) => ($m['message']['template']['name'] ?? '') === 'clinic_feedback' && ($m['message']['to'] ?? '') === ltrim($phone, '+')));
    $flink = array_values(array_filter(array_column($fb[0]['message']['template']['components'][0]['parameters'] ?? [], 'text'), fn($p) => str_contains($p, '/f.php?t=')))[0] ?? '';
    $ok($flink !== '', 'three hours after the visit the feedback link was sent');
    $http($anon, 'POST', '/f.php', ['t' => substr($flink, -48), 'score' => 10, 'comment' => 'Lovely']);
    [, , $b] = $http($anon, 'GET', $flink);
    $ok(str_contains($b, 'Thank you for your feedback') && str_contains($b, 'g.page/r/example-review'), 'score 10: thank you and the Google review link');

    echo "Health information switch\n";
    $base = ['channel' => 'sms', 'purpose' => 'custom', 'name' => "Aftercare $tag", 'language' => 'en', 'body' => 'Hi {first_name}, after your {service} avoid sun.', 'is_active' => 1];
    [, , $b] = $post($admin, '/pages/message_templates.php', $base + ['id' => 0, 'contains_health_info' => 1]);
    [, , $b] = $http($admin, 'GET', '/pages/message_templates.php?new=1');
    $ok(str_contains($flashOf($b), 'Tick the acknowledgement') && !$db->query("SELECT COUNT(*) FROM message_templates WHERE name = " . $db->quote("Aftercare $tag"))->fetchColumn(), 'the owner can’t switch it on without the acknowledgement');
    $post($admin, '/pages/message_templates.php', $base + ['id' => 0, 'contains_health_info' => 1, 'health_ack' => 1]);
    $tplId = (int)$db->query("SELECT id FROM message_templates WHERE name = " . $db->quote("Aftercare $tag"))->fetchColumn();
    $ok($tplId && (int)$db->query("SELECT contains_health_info FROM message_templates WHERE id = $tplId")->fetchColumn() === 1, 'with it, it’s allowed (audit-logged)');
    $rec = $login('reception@clinic.test');
    [$c] = $http($rec, 'GET', '/pages/message_templates.php');
    $ok($c === 403, 'reception can’t change templates');

    echo "Webhook and inbox\n";
    $in = json_encode(['entry' => [['changes' => [['value' => ['messages' => [['from' => ltrim($phone, '+'), 'id' => "wamid.IN$tag", 'type' => 'text', 'text' => ['body' => "Can I bring my sister $tag?"]]]]]]]]]);
    [$c] = $http($anon, 'POST', '/pages/api_wa_cloud.php', $in, ['Content-Type: application/json', 'X-Hub-Signature-256: sha256=' . hash_hmac('sha256', $in, 'wrong')]);
    $ok($c === 403 && !$db->query("SELECT COUNT(*) FROM messages WHERE provider_id = 'wamid.IN$tag'")->fetchColumn(), 'a webhook call with a wrong signature is refused');
    [$c] = $http($anon, 'POST', '/pages/api_wa_cloud.php', $in, ['Content-Type: application/json', 'X-Hub-Signature-256: sha256=' . hash_hmac('sha256', $in, "secret-$tag")]);
    [$c2, , $ch] = $http($anon, 'GET', '/pages/api_wa_cloud.php?hub_mode=subscribe&hub_verify_token=' . urlencode(msgSetting($db, 'wa_verify_token')) . '&hub_challenge=12345');
    $ok($c === 200 && (int)$db->query("SELECT patient_id FROM messages WHERE provider_id = 'wamid.IN$tag'")->fetchColumn() === $pid && $c2 === 200 && $ch === '12345', 'a signed incoming message is saved against the patient; Meta’s verification answered');
    [, , $b] = $http($rec, 'GET', '/pages/inbox.php?with=' . urlencode($phone));
    $ok(str_contains($b, "Can I bring my sister $tag?") && str_contains($b, 'Reply') && !$phpError($b), 'reception sees the conversation');
    $http($rec, 'POST', '/pages/inbox.php?with=' . urlencode($phone), ['_csrf' => $tok[$rec], 'action' => 'reply', 'text' => "Of course $tag"]);
    $last = array_values(array_filter($mockSent(), fn($m) => ($m['message']['text']['body'] ?? '') === "Of course $tag"));
    $ok(count($last) === 1, 'the reply went out on WhatsApp (within 24 hours of their message)');
    [, , $b] = $http($rec, 'GET', "/pages/patient.php?id=$pid&tab=messages");
    $ok(str_contains($b, "Of course $tag") && str_contains($b, 'automatic (reminder_24h)') && !$phpError($b), 'the patient’s Messages tab shows the whole history');

    echo "Who sees what\n";
    $inv = $login('inventory@clinic.test');
    [$c] = $http($inv, 'GET', '/pages/inbox.php');
    $ok($c === 403, 'inventory staff don’t see messages');
    $con = $login('layla@clinic.test');
    [$c, , $b] = $http($con, 'GET', '/pages/inbox.php?with=' . urlencode($phone));
    [$c2] = $http($con, 'GET', '/pages/messaging_settings.php');
    $ok($c === 200 && !str_contains($b, 'name="text"') && $c2 === 403, 'consultants can read but not reply, and can’t change settings');
    foreach (['/pages/inbox.php?tab=requests', '/pages/message_templates.php', '/pages/settings_clinic.php'] as $p) { [$c, , $b] = $http($admin, 'GET', $p); $ok($c === 200 && !$phpError($b), "$p loads"); }
} finally {
    $db->exec("DELETE FROM messages WHERE patient_id = $pid OR to_from IN (" . $db->quote($phone) . ", " . $db->quote(ltrim($phone, '+')) . ")");
    $db->exec("DELETE FROM feedback WHERE patient_id = $pid");
    $db->exec("DELETE FROM staff_notifications WHERE link LIKE '%id=$appt%' OR link LIKE '%id=$pid%'");
    if ($tplId) { $db->exec("DELETE FROM message_templates WHERE id = $tplId"); $db->exec("DELETE FROM audit_log WHERE entity = 'message_template' AND entity_id = $tplId"); }
    $db->exec("DELETE FROM audit_log WHERE (entity = 'appointment' AND entity_id = $appt) OR (entity = 'patient' AND entity_id = $pid) OR (entity = 'setting' AND action IN ('whatsapp_settings','automations') AND at > NOW() - INTERVAL 1 HOUR)");
    foreach (['appointment_status_history', 'appointment_services'] as $t) $db->exec("DELETE FROM $t WHERE appointment_id = $appt");
    $db->exec("DELETE FROM appointments WHERE id = $appt");
    foreach (['patient_phones', 'patient_access_log'] as $t) $db->exec("DELETE FROM $t WHERE patient_id = $pid");
    $db->exec("DELETE FROM patients WHERE id = $pid");
    $db->exec("DELETE FROM integration_settings WHERE k LIKE 'msg.%'");
    foreach ($oldSettings as $k => $v) $db->prepare("INSERT INTO integration_settings (k, v) VALUES (?, ?)")->execute([$k, $v]);
    foreach ($oldAuto as $a) $db->prepare("REPLACE INTO automations (`key`, is_active, config) VALUES (?,?,?)")->execute([$a['key'], $a['is_active'], $a['config']]);
    foreach ($oldWa as $id => $st) $db->prepare("UPDATE message_templates SET wa_status = ? WHERE id = ?")->execute([$st, $id]);
    if ($oldHourly) metaSet($db, 'cron_hourly_last', $oldHourly); else $db->exec("DELETE FROM app_meta WHERE k = 'cron_hourly_last'");
    $db->exec("DELETE FROM login_log WHERE user_agent = 'ClinicCRM-messaging-suite/1.0'");
    foreach ($mocks as $p) { proc_terminate($p); proc_close($p); }
}
echo "\n$pass passed, $fail failed\n";
exit($fail ? 1 : 0);

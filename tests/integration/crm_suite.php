<?php
const ROOT = __DIR__ . '/../..';   // the project folder
// Phase 11 through the real pages, cron and the stand-ins (Meta 8082, SMS 8084): a visitor books on /book.php with a
// one-time code by SMS, which makes a lead, a patient and an online appointment and sends Meta a website "Schedule";
// a Meta-form lead's paid invoice is queued as a "Purchase" and sent; the funnel page shows the revenue by campaign;
// the pipeline board moves a lead; recalls and a broadcast reach a consented patient; who sees what.
// Everything it creates is removed and the settings restored at the end.
require ROOT . '/includes/auth.php';
require_once ROOT . '/includes/crm.php';
require_once ROOT . '/includes/billing.php';
require_once ROOT . '/includes/meta_leads.php';
require_once ROOT . '/includes/clinic_setup.php';
$db = getDB();
$pass = 0; $fail = 0;
$ok = function (bool $c, string $m) use (&$pass, &$fail) { $c ? $pass++ : $fail++; echo ($c ? "  ok   " : "  FAIL "), $m, PHP_EOL; };
$BASE = 'http://127.0.0.1:8080';
$UA = 'ClinicCRM-crm-suite/1.0';
$http = function (string $jar, string $method, string $path, $form = [], array $headers = []) use ($BASE, $UA) {
    $ch = curl_init(str_starts_with($path, 'http') ? $path : $BASE . $path);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => true, CURLOPT_COOKIEJAR => $jar, CURLOPT_COOKIEFILE => $jar, CURLOPT_USERAGENT => $UA, CURLOPT_HTTPHEADER => $headers]);
    if ($method === 'POST') { curl_setopt($ch, CURLOPT_POST, true); curl_setopt($ch, CURLOPT_POSTFIELDS, is_string($form) ? $form : http_build_query($form)); }
    $raw = (string)curl_exec($ch); $code = curl_getinfo($ch, CURLINFO_HTTP_CODE); $hs = curl_getinfo($ch, CURLINFO_HEADER_SIZE); curl_close($ch);
    preg_match('/^Location:\s*(\S+)/mi', substr($raw, 0, $hs), $m);
    return [$code, $m[1] ?? '', substr($raw, $hs)];
};
$acc = file(ROOT . '/local/test_accounts.txt', FILE_IGNORE_NEW_LINES);
$pw = trim(preg_replace('/^Password:\s*/i', '', $acc[2]));
$tok = [];
$login = function (string $email) use ($http, $pw, &$tok) {
    $jar = tempnam(sys_get_temp_dir(), 'cs');
    [, , $b] = $http($jar, 'GET', '/login.php'); preg_match('/name="_csrf" value="([a-f0-9]+)"/', $b, $m);
    $http($jar, 'POST', '/login.php', ['_csrf' => $m[1] ?? '', 'step' => 'password', 'email' => $email, 'password' => $pw]);
    [, , $d] = $http($jar, 'GET', '/pages/dashboard.php'); preg_match('/name="csrf-token" content="([a-f0-9]+)"/', $d, $t);
    $tok[$jar] = $t[1] ?? '';
    return $jar;
};
$post = function (string $jar, string $path, array $f) use ($http, &$tok) { return $http($jar, 'POST', $path, ['_csrf' => $tok[$jar]] + $f); };
$phpError = fn(string $h) => (bool)preg_match('/(Fatal error|Parse error|Uncaught |Warning: |Notice: |Deprecated: )/', $h);
$smsTo = fn(string $phone) => array_values(array_filter(json_decode((string)@file_get_contents('http://127.0.0.1:8084/sent'), true) ?: [], fn($m) => ltrim((string)$m['to'], '+') === ltrim($phone, '+')));
$metaEvents = fn() => json_decode((string)@file_get_contents(ROOT . '/local/meta_mock_events.json'), true) ?: [];
$cron = fn() => (string)shell_exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(ROOT . '/cron.php') . ' --tenant=main 2>&1');

// The stand-ins, when run on their own (run_all starts them)
$mocks = [];
foreach ([8082 => 'local/meta_mock.php', 8084 => 'local/sms_mock.php'] as $port => $f)
    if (!@fsockopen('127.0.0.1', $port, $e1, $e2, 0.3)) { $mocks[] = proc_open([PHP_BINARY, '-S', "127.0.0.1:$port", $f], [1 => ['file', 'NUL', 'w'], 2 => ['file', 'NUL', 'w']], $pp, ROOT); usleep(500000); }

ensureDefaultTemplates($db);
$oldSettings = $db->query("SELECT k, v FROM integration_settings WHERE k LIKE 'msg.%' OR k LIKE 'meta.%'")->fetchAll(PDO::FETCH_KEY_PAIR);
$oldHourly = metaGet($db, 'cron_hourly_last');
metaSet($db, 'cron_hourly_last', date('Y-m-d H:i:s'));   // keep this test's cron runs to messages and broadcasts
$oldEvents = @file_get_contents(ROOT . '/local/meta_mock_events.json');
$tag = (string)random_int(1000, 9999);
$phone = '+97150' . random_int(1000000, 9999999);
$branch = (int)$db->query("SELECT id FROM branches WHERE is_active = 1 ORDER BY id LIMIT 1")->fetchColumn();
$day = (new DateTimeImmutable('today', new DateTimeZone(CLINIC_TZ)))->modify('+2 days')->format('Y-m-d');
$db->prepare("INSERT INTO services (name, price, vat_rate, duration_min, is_active, is_online_bookable) VALUES (?, 400, 5, 60, 1, 1)")->execute(["Suite Facial $tag"]);
$svc = (int)$db->lastInsertId();
$db->prepare("INSERT INTO staff (name, email, password, role) VALUES (?, ?, 'x', 'practitioner')")->execute(["Dr Online $tag", "online$tag@example.test"]);
$doc = (int)$db->lastInsertId();
$db->exec("INSERT INTO practitioner_profiles (staff_id, is_bookable) VALUES ($doc, 1)");
savePractitionerSchedule($db, $doc, $branch, array_map(fn($d) => [$d, '10:00', '13:00'], range(0, 6)));
$pids = []; $leads = []; $camp = 0; $bc = 0; $rule = 0;

try {
    echo "Set-up\n";
    $admin = $login('admin@clinic.test');
    $post($admin, '/pages/messaging_settings.php', ['action' => 'sms', 'sms_provider' => 'mock']);
    $post($admin, '/pages/messaging_settings.php', ['action' => 'booking', 'online_booking' => 1, 'booking_days_ahead' => 30, 'booking_notice_hours' => 0]);
    $ok(msgSetting($db, 'online_booking') === '1' && smsProvider($db) === 'mock', 'admin switches online booking on (codes by SMS)');
    $pixels = json_decode($oldSettings['meta.web_pixels'] ?? '{}', true) ?: [];
    foreach (['graph_base' => 'http://127.0.0.1:8082', 'token' => 'good-token', 'dataset_id' => '100000000000001', 'capi_enabled' => '1', 'test_event_code' => '',
              'web_pixels' => json_encode($pixels + ['book' => '100000000000002'])] as $k => $v) metaSaveSetting($db, $k, $v);
    @file_put_contents(ROOT . '/local/meta_mock_events.json', '[]');

    echo "Online booking\n";
    $anon = tempnam(sys_get_temp_dir(), 'cs');
    $slots = onlineSlots($db, $branch, $svc, $doc, $day);
    $time = $slots[0]['time'] ?? '';
    [$c, , $b] = $http($anon, 'GET', "/book.php?branch=$branch&service=$svc&staff=$doc&date=$day");
    $ok($c === 200 && $time !== '' && str_contains($b, "Suite Facial $tag") && str_contains($b, substr($time, 11)) && !$phpError($b), 'the booking page lists the online treatment and its free times');
    [, , $b] = $http($anon, 'GET', "/book.php?branch=$branch&service=$svc&staff=$doc&date=$day&time=" . urlencode($time));
    preg_match('/name="_csrf" value="([a-f0-9]+)"/', $b, $m); $csrf = $m[1] ?? '';
    [$c, , $b] = $http($anon, 'POST', '/book.php', ['_csrf' => $csrf, 'action' => 'code', 'branch_id' => $branch, 'service_id' => $svc, 'staff_id' => $doc, 'time' => $time, 'name' => "Noor Online$tag", 'phone' => $phone, 'wa' => 1]);
    $sms = $smsTo($phone);
    preg_match('/\b(\d{6})\b/', (string)($sms[0]['text'] ?? ''), $cm); $code = $cm[1] ?? '';
    $ok($code !== '' && str_contains($b, 'name="code"') && $db->query("SELECT body FROM messages WHERE automation_key = 'otp' AND to_from = " . $db->quote($phone))->fetchColumn() === 'One-time code (hidden)', 'a 6-digit code arrives by SMS; the message log hides it');
    [, , $b] = $http($anon, 'POST', '/book.php', ['_csrf' => $csrf, 'action' => 'verify', 'code' => $code === '000000' ? '111111' : '000000']);
    $ok(str_contains($b, 'not right') && !$db->query("SELECT COUNT(*) FROM leads WHERE phone = " . $db->quote($phone))->fetchColumn(), 'a wrong code books nothing');
    [$c, , $b] = $http($anon, 'POST', '/book.php', ['_csrf' => $csrf, 'action' => 'verify', 'code' => $code]);
    $lead = $db->query("SELECT * FROM leads WHERE phone = " . $db->quote($phone))->fetch() ?: [];
    $leads[] = (int)($lead['id'] ?? 0); $pids[] = $pid = (int)($lead['patient_id'] ?? 0);
    $ap = $db->query("SELECT * FROM appointments WHERE patient_id = $pid")->fetch() ?: [];
    $ok($c === 200 && !$phpError($b) && ($lead['source'] ?? '') === 'online_booking' && $pid > 0, 'the right code creates a lead (source Online booking) and its patient');
    $ok(($ap['booked_via'] ?? '') === 'online' && substr((string)($ap['scheduled_at'] ?? ''), 0, 16) === $time && (int)$ap['practitioner_id'] === $doc && (int)$db->query("SELECT whatsapp_opt_in FROM patients WHERE id = $pid")->fetchColumn() === 1, 'and an online appointment at the chosen time and practitioner (WhatsApp updates ticked)');
    $sched = array_values(array_filter($metaEvents(), fn($e) => ($e['pixel'] ?? '') === '100000000000002' && ($e['data'][0]['event_name'] ?? '') === 'Schedule'));
    $ok(count($sched) === 1 && ($sched[0]['data'][0]['event_id'] ?? '') === 'book-' . ($ap['id'] ?? 0) && !empty($sched[0]['data'][0]['user_data']['ph']), 'Meta got a website "Schedule" event (hashed phone) from the booking page');
    $ok(!in_array($time, array_column(onlineSlots($db, $branch, $svc, $doc, $day), 'time'), true), 'the time is no longer offered');
    [, , $b] = $http($anon, 'POST', '/book.php', ['_csrf' => $csrf, 'action' => 'verify', 'code' => $code]);
    $ok((int)$db->query("SELECT COUNT(*) FROM appointments WHERE patient_id = $pid")->fetchColumn() === 1, 'sending the code again books nothing more');

    echo "Funnel and Meta Purchase\n";
    $cname = "Suite Camp $tag";
    foreach (['A', 'B'] as $i => $x) {
        $db->prepare("INSERT INTO leads (name, phone, source, source_campaign, stage, meta_lead_id) VALUES (?, ?, 'meta_form', ?, 'new', ?)")->execute(["Funnel $x$tag", '+97152' . random_int(1000000, 9999999), $cname, $i ? null : "12000000$tag"]);
        $leads[] = (int)$db->lastInsertId();
    }
    [$c, $loc] = $post($admin, '/pages/campaigns.php', ['action' => 'backfill']);
    $camp = (int)$db->query("SELECT id FROM campaigns WHERE name = " . $db->quote($cname))->fetchColumn();
    $ok($camp > 0 && (int)$db->query("SELECT COUNT(*) FROM leads WHERE campaign_id = $camp")->fetchColumn() === 2, '"Find new campaigns" makes the campaign and links its leads');
    $post($admin, '/pages/campaigns.php', ['id' => $camp, 'name' => $cname, 'source' => 'meta_form', 'budget' => 500]);
    [$pa] = patientFromLead($db, $leads[1]); $pids[] = $pa;
    [$apA] = createAppointment($db, ['service_ids' => [$svc], 'start' => "$day 12:00", 'patient_id' => $pa, 'branch_id' => $branch, 'allow_outside_hours' => true]);
    $db->exec("UPDATE appointments SET status = 'completed' WHERE id = $apA");
    $inv = createInvoice($db, $pa, $branch); addInvoiceLine($db, $inv, ['kind' => 'manual', 'description' => 'Treatment', 'unit_price' => 1000, 'vat_rate' => 5]); issueInvoice($db, $inv);
    createReceipt($db, $pa, $branch, [['method' => 'card', 'amount' => 1050]], [$inv => 1050]);
    $q = $db->query("SELECT event_name, value FROM meta_capi_events WHERE lead_id = {$leads[1]} ORDER BY id")->fetchAll(PDO::FETCH_KEY_PAIR);
    $ok(array_key_exists('Schedule', $q) && ($q['Purchase'] ?? '') === '1000.00', 'the Meta-form lead’s booking and paid invoice are queued as Schedule and Purchase (1,000 net)');
    $r = metaSendStageEvents($db);
    $sent = array_merge(...array_map(fn($e) => $e['data'], array_filter($metaEvents(), fn($e) => !isset($e['pixel']))));
    $pur = array_values(array_filter($sent, fn($e) => $e['event_name'] === 'Purchase' && $e['user_data']['lead_id'] === (int)"12000000$tag"));
    $ok($r['failed'] === 0 && count($pur) === 1 && $pur[0]['custom_data']['value'] == 1000 && $pur[0]['custom_data']['currency'] === 'AED', 'cron sends the Purchase to the dataset with its value');
    [$c, , $b] = $http($admin, 'GET', '/pages/campaigns.php?from=' . date('Y-m-d', strtotime('-1 day')) . '&to=' . date('Y-m-d'));
    $row = preg_match('/' . preg_quote($cname, '/') . '.*?<\/tr>/s', $b, $mm) ? strip_tags($mm[0]) : '';
    $ok($c === 200 && !$phpError($b) && str_contains($row, '1,000.00') && str_contains($row, '1,050.00') && str_contains($row, '500.00'), 'the funnel page shows the campaign’s revenue 1,000, collected 1,050, cost per patient 500 (budget / 1 patient)');
    [$c, , $b] = $http($admin, 'GET', '/pages/campaigns.php?export=csv&from=' . date('Y-m-d', strtotime('-1 day')) . '&to=' . date('Y-m-d'));
    $ok($c === 200 && str_contains($b, $cname), 'and exports it as CSV');

    echo "Pipeline\n";
    [$c, , $b] = $http($admin, 'GET', '/pages/pipeline.php');
    $ok($c === 200 && !$phpError($b) && str_contains($b, "Funnel B$tag") && str_contains($b, 'Sortable.min.js'), 'the board shows the leads');
    [, , $j] = $http($admin, 'POST', '/pages/api.php', ['action' => 'change_stage', 'lead_id' => $leads[2], 'stage' => 'contacted'], ['X-CSRF-Token: ' . $tok[$admin]]);
    $ok((json_decode($j, true)['success'] ?? false) && $db->query("SELECT stage FROM leads WHERE id = {$leads[2]}")->fetchColumn() === 'contacted', 'dragging a card changes the stage');
    [, , $j] = $http($admin, 'POST', '/pages/api.php', ['action' => 'change_stage', 'lead_id' => $leads[2], 'stage' => 'qualified'], ['X-CSRF-Token: bad']);
    $ok($db->query("SELECT stage FROM leads WHERE id = {$leads[2]}")->fetchColumn() === 'contacted', 'not without the page’s token');

    echo "Recalls\n";
    $db->exec("UPDATE patients SET marketing_consent = 1 WHERE id = $pa");
    $db->exec("UPDATE appointments SET scheduled_at = NOW() - INTERVAL 100 DAY, ends_at = NOW() - INTERVAL 100 DAY + INTERVAL 1 HOUR WHERE id = $apA");
    $post($admin, '/pages/recalls.php', ['action' => 'rule', 'id' => 0, 'name' => "Facial $tag", 'kind' => 'service_interval', 'service_id' => $svc, 'days_after' => 90, 'is_active' => 1]);
    $rule = (int)$db->query("SELECT id FROM recall_rules WHERE name = " . $db->quote("Facial $tag"))->fetchColumn();
    $post($admin, '/pages/recalls.php', ['action' => 'run']);
    $rid = (int)$db->query("SELECT id FROM recalls WHERE rule_id = $rule AND patient_id = $pa")->fetchColumn();
    $rec = $login('reception@clinic.test');
    [$c, , $b] = $http($rec, 'GET', '/pages/recalls.php');
    $ok($rule && $rid && $c === 200 && str_contains($b, "Funnel A$tag") && !$phpError($b), 'admin adds a rule; "Check now" finds the patient due; reception sees it');
    $post($rec, '/pages/recalls.php', ['action' => 'status', 'id' => $rid, 'status' => 'contacted', 'note' => 'Called']);
    [, , $b] = $post($rec, '/pages/recalls.php', ['action' => 'rule', 'id' => $rule, 'name' => 'Hijack', 'kind' => 'not_visited', 'days_after' => 1, 'is_active' => 1]);
    $ok($db->query("SELECT status FROM recalls WHERE id = $rid")->fetchColumn() === 'contacted' && $db->query("SELECT name FROM recall_rules WHERE id = $rule")->fetchColumn() === "Facial $tag", 'reception records the call but can’t change rules');

    echo "Broadcasts\n";
    $db->prepare("INSERT INTO patient_phones (patient_id, phone, is_primary) SELECT ?, phone, 1 FROM leads WHERE id = ? AND NOT EXISTS (SELECT 1 FROM patient_phones WHERE patient_id = ?)")->execute([$pa, $leads[1], $pa]);
    $bphone = (string)$db->query("SELECT phone FROM patient_phones WHERE patient_id = $pa")->fetchColumn();
    $db->prepare("INSERT INTO message_templates (channel, purpose, name, language, body, is_active) VALUES ('sms', 'custom', ?, 'en', 'Hi {first_name}, 20% off facials this week.', 1)")->execute(["Offer $tag"]);
    $tpl = (int)$db->lastInsertId();
    $post($admin, '/pages/messaging_settings.php', ['action' => 'automations', 'auto' => [], 'feedback_hours' => 3, 'quiet_from' => '00:00', 'quiet_to' => '00:00', 'cancel_cutoff_hours' => 24, 'map_link' => '', 'booking_link' => '', 'google_review_url' => '', 'base_url' => 'http://127.0.0.1:8080']);
    [$c, , $j] = $http($admin, 'GET', "/pages/broadcasts.php?count=1&service_id=$svc");
    $ok($c === 200 && (json_decode($j, true)['n'] ?? -1) === 1, 'the segment count: one consented patient had this treatment (the online booker did not agree to marketing)');
    $post($admin, '/pages/broadcasts.php', ['action' => 'create', 'name' => "Offer $tag", 'template_id' => $tpl, 'service_id' => $svc]);
    $bc = (int)$db->query("SELECT id FROM broadcasts WHERE name = " . $db->quote("Offer $tag"))->fetchColumn();
    $post($admin, '/pages/broadcasts.php', ['action' => 'start', 'id' => $bc]);
    $cron(); $cron();
    $ok($tpl && $bc && count($smsTo($bphone)) === 1 && $db->query("SELECT status FROM broadcasts WHERE id = $bc")->fetchColumn() === 'sent', 'started, cron sends it once (two runs) and marks it sent');
    [$c, , $b] = $http($admin, 'GET', '/pages/broadcasts.php');
    $ok($c === 200 && !$phpError($b) && str_contains($b, "Offer $tag"), 'the broadcast and its results are listed');

    echo "Who sees what\n";
    [$c] = $http($rec, 'GET', '/pages/broadcasts.php'); [$c2] = $http($rec, 'GET', '/pages/campaigns.php');
    $ok($c === 403 && $c2 === 403, 'reception can’t open broadcasts or campaigns');
    $con = $login('layla@clinic.test');
    [$c] = $http($con, 'GET', '/pages/campaigns.php'); [$c2, , $b] = $http($con, 'GET', '/pages/pipeline.php');
    $ok($c === 403 && $c2 === 200 && !$phpError($b), 'consultants have the pipeline but not campaigns');
    [, , $b] = $http($admin, 'GET', '/pages/dashboard.php');
    $ok(str_contains($b, '/pages/pipeline.php') && str_contains($b, '/pages/broadcasts.php'), 'the Marketing menu is there for admins');
    [, , $b] = $http($anon, 'GET', '/book.php?lang=ar');
    $ok(str_contains($b, 'dir="rtl"') && !$phpError($b), 'the booking page in Arabic');
    msgSaveSetting($db, 'online_booking', '0');
    [, , $b] = $http(tempnam(sys_get_temp_dir(), 'cs'), 'GET', '/book.php');
    $ok(!str_contains($b, "Suite Facial $tag"), 'switched off, it offers nothing');
} finally {
    $pids = array_filter($pids); $leads = array_filter($leads);
    $appts = $pids ? $db->query("SELECT id FROM appointments WHERE patient_id IN (" . implode(',', $pids) . ")")->fetchAll(PDO::FETCH_COLUMN) : [];
    if ($pids) {
        $p = implode(',', $pids);
        $invIds = $db->query("SELECT id FROM invoices WHERE patient_id IN ($p)")->fetchAll(PDO::FETCH_COLUMN);
        if ($invIds) { $in = implode(',', $invIds);
            $db->exec("DELETE FROM document_renditions WHERE doc_type = 'invoice' AND doc_id IN ($in)");
            $db->exec("DELETE FROM invoice_lines WHERE invoice_id IN ($in)");
            $db->exec("DELETE FROM audit_log WHERE entity = 'invoice' AND entity_id IN ($in)");
            $db->exec("DELETE FROM invoices WHERE id IN ($in)"); }
        $rids = $db->query("SELECT id FROM receipts WHERE patient_id IN ($p)")->fetchAll(PDO::FETCH_COLUMN);
        if ($rids) { $in = implode(',', $rids);
            foreach (['receipt_methods', 'receipt_allocations'] as $t) $db->exec("DELETE FROM $t WHERE receipt_id IN ($in)");
            $db->exec("DELETE FROM document_renditions WHERE doc_type = 'receipt' AND doc_id IN ($in)");
            $db->exec("DELETE FROM audit_log WHERE entity = 'receipt' AND entity_id IN ($in)");
            $db->exec("DELETE FROM receipts WHERE id IN ($in)"); }
        $db->exec("DELETE FROM messages WHERE patient_id IN ($p)");
        $db->exec("DELETE FROM recalls WHERE patient_id IN ($p)");
        $db->exec("DELETE FROM patient_wallet WHERE patient_id IN ($p)");
    }
    if ($appts) { $a = implode(',', $appts);
        foreach (['appointment_status_history', 'appointment_services', 'appointment_items', 'appointment_gcal'] as $t) $db->exec("DELETE FROM $t WHERE appointment_id IN ($a)");
        $db->exec("DELETE FROM staff_notifications WHERE " . implode(' OR ', array_map(fn($i) => "link LIKE '%id=$i%'", $appts)));
        $db->exec("DELETE FROM audit_log WHERE entity = 'appointment' AND entity_id IN ($a)");
        $db->exec("DELETE FROM appointments WHERE id IN ($a)"); }
    if ($pids) { $p = implode(',', $pids);
        foreach (['patient_phones', 'patient_access_log', 'patient_documents'] as $t) $db->exec("DELETE FROM $t WHERE patient_id IN ($p)");
        $db->exec("DELETE FROM audit_log WHERE entity = 'patient' AND entity_id IN ($p)");
        $db->exec("DELETE FROM patients WHERE id IN ($p)"); }
    if ($leads) { $l = implode(',', $leads);
        foreach (['meta_capi_events', 'activity_log'] as $t) $db->exec("DELETE FROM $t WHERE lead_id IN ($l)");
        $db->exec("DELETE FROM leads WHERE id IN ($l)"); }
    $db->exec("DELETE FROM messages WHERE to_from IN (" . $db->quote($phone) . ", " . $db->quote(ltrim($phone, '+')) . ")");
    $db->exec("DELETE FROM otp_codes WHERE phone = " . $db->quote($phone));
    $db->exec("DELETE FROM rate_limits WHERE bucket LIKE '%" . substr($phone, -7) . "'");
    if ($bc) $db->exec("DELETE FROM broadcasts WHERE id = $bc");
    if (!empty($tpl)) $db->exec("DELETE FROM message_templates WHERE id = $tpl");
    if ($rule) $db->exec("DELETE FROM recall_rules WHERE id = $rule");
    if ($camp) $db->exec("DELETE FROM campaigns WHERE id = $camp");
    $db->exec("DELETE FROM practitioner_schedules WHERE staff_id = $doc");
    $db->exec("DELETE FROM practitioner_profiles WHERE staff_id = $doc");
    $db->exec("DELETE FROM staff WHERE id = $doc");
    $db->exec("DELETE FROM services WHERE id = $svc");
    $db->exec("DELETE FROM audit_log WHERE entity = 'setting' AND at > NOW() - INTERVAL 1 HOUR AND action IN ('online_booking','sms_settings','automations')");
    $db->exec("DELETE FROM integration_settings WHERE k LIKE 'msg.%' OR k LIKE 'meta.%'");
    foreach ($oldSettings as $k => $v) $db->prepare("INSERT INTO integration_settings (k, v) VALUES (?, ?)")->execute([$k, $v]);
    if ($oldHourly) metaSet($db, 'cron_hourly_last', $oldHourly); else $db->exec("DELETE FROM app_meta WHERE k = 'cron_hourly_last'");
    if ($oldEvents === false) @unlink(ROOT . '/local/meta_mock_events.json'); else file_put_contents(ROOT . '/local/meta_mock_events.json', $oldEvents);
    $db->exec("DELETE FROM login_log WHERE user_agent = " . $db->quote($UA));
    foreach ($mocks as $p) { proc_terminate($p); proc_close($p); }
}
echo "\n$pass passed, $fail failed\n";
exit($fail ? 1 : 0);

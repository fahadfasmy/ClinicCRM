<?php
const ROOT = __DIR__ . '/../..';   // the project folder
// Returning-enquiry checks: Meta (direct), website form + WhatsApp (over HTTP to the local server on 8080).
require ROOT . '/includes/reenquiry.php';
require_once ROOT . '/includes/api_keys.php';
$db = getDB();
ensureMetaSchema($db); ensureReEnquirySchema($db);
$pass = 0; $fail = 0;
$ok = function (bool $c, string $m) use (&$pass, &$fail) { $c ? $pass++ : $fail++; echo ($c ? "  ok  " : "  FAIL "), $m, PHP_EOL; };
$lead = function (int $id) use ($db) { $s = $db->prepare("SELECT * FROM leads WHERE id = ?"); $s->execute([$id]); return $s->fetch(); };
$count = fn(string $sql, array $p = []) => (function () use ($db, $sql, $p) { $s = $db->prepare($sql); $s->execute($p); return (int)$s->fetchColumn(); })();
$mk = function (string $name, string $phone, string $stage, ?string $lastContacted = null, ?int $owner = null) use ($db) {
    $db->prepare("INSERT INTO leads (name, phone, source, stage, assigned_to, notes, last_contacted) VALUES (?,?,'other',?,?,'orig',?)")
       ->execute([$name, $phone, $stage, $owner, $lastContacted]);
    $id = (int)$db->lastInsertId(); saveLeadPhones($id, [$phone], $db); return $id;
};
$http = function (string $url, $body, array $headers = []) {
    $c = curl_init($url);
    curl_setopt_array($c, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => $body, CURLOPT_RETURNTRANSFER => true, CURLOPT_HTTPHEADER => $headers]);
    $r = curl_exec($c); curl_close($c); return json_decode((string)$r, true) ?? $r;
};
$db->exec("DELETE FROM leads WHERE phone LIKE '+97150008%'");
$admin = (int)$db->query("SELECT id FROM staff WHERE role='admin' AND is_active=1 ORDER BY id LIMIT 1")->fetchColumn();
$staff = (int)$db->query("SELECT id FROM staff WHERE is_active=1 AND id <> $admin ORDER BY id LIMIT 1")->fetchColumn() ?: $admin;

echo "== website: lost lead fills the dental form\n";
$a = $mk('Re Lost', '+971500080001', 'lost', date('Y-m-d H:i:s', strtotime('-60 days')));
$r = $http('http://127.0.0.1:8080/pages/api_public_lead.php', ['name' => 'Re Lost', 'phones[]' => '+971500080001', 'notes' => 'Preferred time: evening', 'landing' => 'dental']);
$l = $lead($a);
$ok(($r['success'] ?? false) && empty($r['lead_id']), 'answered success, no new lead');
$ok($count("SELECT COUNT(*) FROM leads WHERE phone = '+971500080001'") === 1, 'still one lead');
$ok($l['stage'] === 'new', 'lost → new');
$ok(str_contains($l['notes'], 'New enquiry') && str_contains($l['notes'], 'Preferred time: evening'), 'enquiry + answers in notes');
$ok(isReEnquiryOpen($l) && $l['reenquiry_note'] === 'Website form – dental', 'flagged returning');
$ok($count("SELECT COUNT(*) FROM reminders WHERE lead_id = ? AND staff_id = ?", [$a, $admin]) === 1, 'reminder to first admin (no owner)');
$ok($count("SELECT COUNT(*) FROM activity_log WHERE lead_id = ? AND action='stage_changed' AND old_value='lost' AND new_value='new'", [$a]) === 1, 'stage change logged');
$ok($count("SELECT COUNT(*) FROM activity_log WHERE lead_id = ? AND action='reenquiry'", [$a]) === 1, 'reenquiry logged');

echo "== flag clears when contact is logged\n";
$db->prepare("UPDATE leads SET last_contacted = NOW() + INTERVAL 1 SECOND WHERE id = ?")->execute([$a]);
$ok(!isReEnquiryOpen($lead($a)), 'cleared after follow-up');
$ok($count("SELECT COUNT(*) FROM leads l WHERE l.id = ? AND " . REENQUIRY_OPEN_SQL, [$a]) === 0, 'SQL condition agrees');

echo "== converted patient, owned lead, in-progress stage\n";
$b = $mk('Re Patient', '+971500080002', 'converted', null, $staff);
recordReEnquiry($db, $b, 'Website form – hydrafacial', 'x');
$l = $lead($b);
$ok($l['stage'] === 'converted' && str_starts_with($l['reenquiry_note'], 'Existing patient – new interest'), 'converted stays, flagged existing patient');
$ok($count("SELECT COUNT(*) FROM reminders WHERE lead_id = ? AND staff_id = ?", [$b, $staff]) === 1, 'reminder to owner');
$c = $mk('Re Booked', '+971500080003', 'booked');
recordReEnquiry($db, $c, 'Website form – dental');
$ok($lead($c)['stage'] === 'booked', 'booked unchanged');

echo "== Meta: fresh form from an existing lead switches the Meta lead id\n";
$d = $mk('Re Meta', '+971500080004', 'cold');
$db->prepare("UPDATE leads SET meta_lead_id = 'OLD1' WHERE id = ?")->execute([$d]);
$db->prepare("INSERT INTO meta_capi_events (lead_id, stage, event_name, status) VALUES (?, 'new', 'Lead', 'sent'), (?, 'cold', 'Other', 'sent')")->execute([$d, $d]);
$res = metaImportLead($db, ['id' => 'NEW2', 'created_time' => gmdate('Y-m-d\TH:i:sO', time() - 600), 'ad_id' => 'AD9',
    'field_data' => [['name' => 'full_name', 'values' => ['Re Meta']], ['name' => 'phone_number', 'values' => ['+971500080004']]]]);
$l = $lead($d);
$ok($res['status'] === 'updated' && $res['lead_id'] === $d, 'matched existing');
$ok($l['meta_lead_id'] === 'NEW2' && $l['meta_ad_id'] === 'AD9', 'points at the new Meta lead and ad');
$ok($l['stage'] === 'new' && isReEnquiryOpen($l), 'cold → new, flagged');
$ok($count("SELECT COUNT(*) FROM meta_capi_events WHERE lead_id = ?", [$d]) === 0, 'old stage events cleared');
metaQueueStageEvents($db);
$ok($count("SELECT COUNT(*) FROM meta_capi_events WHERE lead_id = ? AND stage='new' AND status='queued'", [$d]) === 1, 'fresh Lead event queued for new id');

echo "== Meta: old form (catch-up import) only links\n";
$e = $mk('Re Old', '+971500080005', 'lost');
metaImportLead($db, ['id' => 'OLD3', 'created_time' => gmdate('Y-m-d\TH:i:sO', time() - 5 * 86400),
    'field_data' => [['name' => 'full_name', 'values' => ['Re Old']], ['name' => 'phone_number', 'values' => ['+971500080005']]]]);
$l = $lead($e);
$ok($l['stage'] === 'lost' && empty($l['reenquiry_at']) && $l['meta_lead_id'] === 'OLD3', 'linked, not flagged');

echo "== WhatsApp\n";
$key = createApiKey($db, 'reenq test', null, $admin);
$wa = fn($phone, $msg) => $http('http://127.0.0.1:8080/pages/api_whatsapp.php?action=capture', json_encode(['phone' => $phone, 'name' => '', 'message' => $msg, 'auto' => true]), ['Content-Type: application/json', 'X-CRM-Key: ' . $key]);
$f = $mk('Re WA cold', '+971500080006', 'cold', date('Y-m-d H:i:s', strtotime('-40 days')));
$g = $mk('Re WA chatting', '+971500080007', 'contacted', date('Y-m-d H:i:s', strtotime('-2 days')));
$h = $mk('Re WA recent', '+971500080008', 'lost', date('Y-m-d H:i:s', strtotime('-3 days')));
$r = $wa('+971500080006', 'Hi, do you do botox?');
$ok(($r['existing'] ?? false) && $lead($f)['stage'] === 'new' && str_contains($lead($f)['notes'], 'do you do botox'), 'quiet cold lead → re-enquiry');
$wa('+971500080006', 'again');
$ok($count("SELECT COUNT(*) FROM reminders WHERE lead_id = ?", [$f]) === 1, 'second message same week: no second flag');
$wa('+971500080007', 'ok see you');
$ok(empty($lead($g)['reenquiry_at']), 'ongoing chat not flagged');
$wa('+971500080008', 'hello');
$ok(empty($lead($h)['reenquiry_at']), 'lost but contacted 3 days ago: not flagged');

echo "== pages render\n";
// cleanup
$db->exec("DELETE e FROM meta_capi_events e JOIN leads l ON l.id = e.lead_id WHERE l.phone LIKE '+97150008%'");   // stage events queued for the test leads
$db->exec("DELETE FROM leads WHERE phone LIKE '+97150008%'");
$db->prepare("DELETE FROM api_keys WHERE label = 'reenq test'")->execute();
echo "\n$pass passed, $fail failed\n";

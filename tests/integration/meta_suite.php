<?php
const ROOT = __DIR__ . '/../..';   // the project folder
// Engine test for includes/meta_leads.php against local/meta_mock.php (port 8082). Restores the local DB afterwards.
require ROOT . '/includes/meta_leads.php';
$db = getDB();
ensureMetaSchema($db);
$pass = 0; $fail = 0;
$ok = function (bool $c, string $what) use (&$pass, &$fail) { $c ? $pass++ : $fail++; echo ($c ? '  ok   ' : '  FAIL ') . $what . "\n"; };
$q = fn(string $sql, array $p = []) => (function () use ($db, $sql, $p) { $s = $db->prepare($sql); $s->execute($p); return $s; })();

// Snapshot what the test touches
$matched = $q("SELECT * FROM leads WHERE RIGHT(REGEXP_REPLACE(phone,'[^0-9]',''),9) = '527726844' LIMIT 1")->fetch();
$matchedTags = $q("SELECT treatment_id FROM lead_treatments WHERE lead_id = ?", [$matched['id']])->fetchAll(PDO::FETCH_COLUMN);
$maxLog = (int)$db->query("SELECT COALESCE(MAX(id),0) FROM meta_webhook_log")->fetchColumn();
$maxAct = (int)$db->query("SELECT COALESCE(MAX(id),0) FROM activity_log")->fetchColumn();
$settingsBefore = $db->query("SELECT k, v FROM integration_settings")->fetchAll(PDO::FETCH_KEY_PAIR);
@unlink(ROOT . '/local/meta_mock_events.json');
$db->exec("DELETE e FROM meta_capi_events e LEFT JOIN leads l ON l.id = e.lead_id WHERE l.id IS NULL");   // orphans left by other runs would skew the counts

try {
    foreach (['graph_base' => 'http://127.0.0.1:8082', 'page_id' => '111222333444555', 'token' => 'good-token', 'dataset_id' => '100000000000001',
              'capi_enabled' => '1', 'assign' => 'rr', 'app_secret' => 'secret123', 'last_poll' => '', 'test_event_code' => ''] as $k => $v) metaSaveSetting($db, $k, $v);

    echo "Connection\n";
    $t = metaTestConnection($db);
    $ok(!$t['problems'] && str_contains(implode(' ', $t['ok']), 'Demo Aesthetic Clinic (mock)'), 'test connection finds the page: ' . implode(' | ', $t['ok']));
    $ok(str_contains(implode(' ', $t['ok']), '2 lead forms') && str_contains(implode(' ', $t['ok']), 'Dataset'), 'forms and dataset found');
    $ok(metaSubscribePage($db) === 'Page subscribed to instant lead notifications', 'page subscribed to leadgen with the page token');

    echo "Import last 90 days\n";
    $r = metaPullLeads($db, time() - 90 * 86400, 'import');
    $ok($r['created'] === 4 && $r['updated'] === 1 && count($r['errors']) === 1, "4 new, 1 matched, 1 problem (got {$r['created']}/{$r['updated']}/" . count($r['errors']) . ')');
    $ok(str_contains($r['errors'][0] ?? '', 'no phone number or email'), 'lead without contact details reported: ' . ($r['errors'][0] ?? ''));
    $r2 = metaPullLeads($db, time() - 90 * 86400, 'import');
    $ok($r2['created'] === 0 && $r2['updated'] === 0 && $r2['duplicate'] === 5, "re-import adds nothing ({$r2['duplicate']} already in CRM)");

    $m = $q("SELECT * FROM leads WHERE meta_lead_id = '1200000000000001'")->fetch();
    $ok($m && $m['phone'] === '+971509990001' && $m['source'] === 'meta_form' && $m['source_campaign'] === 'Lip Filler Oct', 'new lead: phone normalised, source and campaign set');
    $ok(str_contains($m['notes'], 'Which treatment are you interested in?: Lip Filler') && str_contains($m['notes'], '“Lip Filler Offer – Oct”') && str_contains($m['notes'], 'Ad: Lips video'), 'notes carry form name, ad and the question labels with answers');
    $ok((int)$m['assigned_to'] > 0 && $q("SELECT role FROM staff WHERE id = ?", [$m['assigned_to']])->fetchColumn() === 'consultant', 'assigned to a consultant in turn');
    $ok((int)$q("SELECT COUNT(*) FROM reminders WHERE lead_id = ? AND message LIKE 'New Meta lead%'", [$m['id']])->fetchColumn() === 1, 'call-now reminder created');
    $tags = $q("SELECT t.name FROM lead_treatments lt JOIN treatments t ON t.id = lt.treatment_id WHERE lt.lead_id = ? ORDER BY t.name", [$m['id']])->fetchAll(PDO::FETCH_COLUMN);
    $ok($tags === ['Lip Filler'], 'interest tag from the answer: ' . implode(', ', $tags));
    $a = $q("SELECT * FROM leads WHERE meta_lead_id = '1200000000000002'")->fetch();
    $tagsA = $q("SELECT t.name FROM lead_treatments lt JOIN treatments t ON t.id = lt.treatment_id WHERE lt.lead_id = ? ORDER BY t.name", [$a['id']])->fetchAll(PDO::FETCH_COLUMN);
    $ok($a['name'] === 'سارة أحمد' && $a['phone'] === '+971509990002', 'Arabic name kept, local 05… number gets +971');
    $ok($tagsA === ['Botox', 'Lip Filler'], 'two treatments from one answer: ' . implode(', ', $tagsA));
    $ok(abs(strtotime($a['created_at']) - (time() - 3 * 86400)) < 120, '3-day-old lead keeps its Meta date: ' . $a['created_at']);
    $ok((int)$q("SELECT COUNT(*) FROM reminders WHERE lead_id = ? AND message LIKE 'New Meta lead%'", [$a['id']])->fetchColumn() === 0, 'no call-now reminder for an old lead');
    $ok(abs(strtotime($m['created_at']) - (time() - 600)) < 120, 'fresh lead dated 10 minutes ago, as on Meta');
    $ok(str_contains($q("SELECT notes FROM leads WHERE meta_lead_id = '1200000000000004'")->fetchColumn(), 'Organic'), 'organic lead noted');
    $e = $q("SELECT * FROM leads WHERE meta_lead_id = '1200000000000004'")->fetch();
    $ok($e && $e['email'] === 'emailonly@example.test' && $e['phone'] === '', 'email-only lead imported');
    $mm = $q("SELECT * FROM leads WHERE id = ?", [$matched['id']])->fetch();
    $ok($mm['meta_lead_id'] === '1200000000000003' && str_contains($mm['notes'], 'Dermal Fillers') && $mm['name'] === $matched['name'], 'existing lead with the same phone linked, answers added, name kept');
    $ok((int)$q("SELECT COUNT(*) FROM leads WHERE RIGHT(REGEXP_REPLACE(phone,'[^0-9]',''),9) = '527726844'")->fetchColumn() === 1, 'no duplicate lead created for that phone');

    echo "Hourly check\n";
    metaSaveSetting($db, 'last_poll', '');
    $p = metaPollNow($db);
    $ok(($p['duplicate'] ?? -1) === 1 && $p['created'] === 0, 'first hourly check looks back 24h, finds only known leads');
    $ok((int)metaSetting($db, 'last_poll') >= time() - 5, 'last check time saved');

    echo "Stage updates to Meta\n";
    $q("UPDATE leads SET stage = 'contacted' WHERE id = ?", [$m['id']]);
    $q("UPDATE leads SET stage = 'booked' WHERE id = ?", [$a['id']]);
    $q("UPDATE leads SET stage = 'qualified' WHERE id = ?", [$mm['id']]);
    $n = metaQueueStageEvents($db);
    $ok($n === 11, "5 Lead events + 6 stage events (incl. skipped stages) queued (got $n)");
    metaSaveSetting($db, 'test_event_code', 'TEST123');
    $t = metaSendStageEvents($db);
    $ok($t['sent'] === 5 && !empty($t['test']), 'test send: 5 sample events with the test code');
    $ok((int)$db->query("SELECT COUNT(*) FROM meta_capi_events WHERE status = 'queued'")->fetchColumn() === 11, 'test send leaves all 11 queued for the real send (got ' . $db->query("SELECT COUNT(*) FROM meta_capi_events WHERE status = 'queued'")->fetchColumn() . ')');
    metaSaveSetting($db, 'test_event_code', '');
    $s = metaSendStageEvents($db);
    $ok($s['sent'] === 11 && !$s['error'], 'sent to Meta: ' . json_encode($s));
    $names = array_column($q("SELECT event_name FROM meta_capi_events WHERE lead_id = ? ORDER BY id", [$a['id']])->fetchAll(), 'event_name');
    $ok($names === ['Lead', 'Contacted', 'Qualified', 'Booked'], 'skipped stages reported in funnel order: ' . implode(' → ', $names));
    $ev = json_decode(file_get_contents(ROOT . '/local/meta_mock_events.json'), true);
    $first = $ev[0]['data'][0] ?? [];
    $ok(($ev[0]['test_event_code'] ?? '') === 'TEST123' && ($ev[1]['test_event_code'] ?? null) === null, 'test code only on the test send');
    $ev = array_slice($ev, 1);   // the real send
    $ok(is_int($first['user_data']['lead_id'] ?? null) && $first['action_source'] === 'system_generated' && $first['custom_data']['event_source'] === 'crm', 'event shape: integer lead_id, system_generated, crm source');
    $ok(isset($first['user_data']['ph'][0]) && strlen($first['user_data']['ph'][0]) === 64, 'phone sent hashed (sha256)');
    $ok(in_array('Booked', array_column($ev[0]['data'], 'event_name'), true), 'event names follow the stages');
    $ok(metaQueueStageEvents($db) === 0, 'nothing re-queued when stages are unchanged');
    $q("UPDATE leads SET stage = 'converted' WHERE id = ?", [$a['id']]);
    $ok(metaQueueStageEvents($db) === 1 && metaSendStageEvents($db)['sent'] === 1, 'a further stage sends one more event');
    $q("UPDATE leads SET stage = 'contacted' WHERE id = ?", [$a['id']]);
    $ok(metaQueueStageEvents($db) === 0, 'moving back down the funnel is not reported');

    echo "Failures\n";
    metaSaveSetting($db, 'token', 'expired');
    $t = metaTestConnection($db);
    $ok(str_contains(implode(' ', $t['problems']), 'expired or was revoked'), 'expired token explained: ' . ($t['problems'][0] ?? ''));
    $q("UPDATE leads SET stage = 'lost' WHERE id = ?", [$m['id']]);
    metaQueueStageEvents($db);
    $s = metaSendStageEvents($db);
    $ok($s['failed'] === 1 && $q("SELECT status FROM meta_capi_events WHERE lead_id = ? AND stage = 'lost'", [$m['id']])->fetchColumn() === 'error', 'failed send kept for retry');
    metaSaveSetting($db, 'token', 'good-token');
    $ok(metaSendStageEvents($db)['sent'] === 1, 'retried successfully once the token works');
    $ok(metaPollNow($db) !== null, 'poll runs');
    metaSaveSetting($db, 'dataset_id', '999');
    metaQueueStageEvents($db);
    $q("UPDATE leads SET stage = 'qualified' WHERE id = ?", [$m['id']]);
    metaQueueStageEvents($db);
    $s = metaSendStageEvents($db);
    $ok($s['failed'] >= 1 && str_contains((string)$s['error'], 'does not exist'), 'wrong dataset reported: ' . $s['error']);
    metaSaveSetting($db, 'dataset_id', '100000000000002');
    metaSendStageEvents($db);

    echo "Webhook\n";
    $post = function (string $body, ?string $secret) {
        $ch = curl_init('http://127.0.0.1:8080/pages/meta_receiver.php');
        $h = ['Content-Type: application/json'];
        if ($secret !== null) $h[] = 'X-Hub-Signature-256: sha256=' . hash_hmac('sha256', $body, $secret);
        curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => $body, CURLOPT_HTTPHEADER => $h, CURLOPT_RETURNTRANSFER => true]);
        $out = curl_exec($ch); $code = curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
        return [$code, $out];
    };
    $ch = curl_init('http://127.0.0.1:8080/pages/meta_receiver.php?hub_mode=subscribe&hub_verify_token=clinic_crm_verify_2024&hub_challenge=12345');
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true); $chal = curl_exec($ch); curl_close($ch);
    $ok($chal === '12345', 'Meta verification handshake answered');
    $body = json_encode(['object' => 'page', 'entry' => [['id' => '111222333444555', 'changes' => [['field' => 'leadgen', 'value' => ['leadgen_id' => '1200000000000001', 'form_id' => '900000000000001', 'page_id' => '111222333444555']]]]]]);
    [$c] = $post($body, 'wrong-secret');
    $ok($c === 403, "wrong signature refused (HTTP $c)");
    [$c] = $post($body, null);
    $ok($c === 403, "missing signature refused (HTTP $c)");
    // A brand-new lead through the webhook: remove lead 1 first so it arrives fresh
    $q("DELETE FROM meta_capi_events WHERE lead_id = ?", [$m['id']]);
    $q("DELETE FROM leads WHERE id = ?", [$m['id']]);
    [$c, $out] = $post($body, 'secret123');
    $new = $q("SELECT * FROM leads WHERE meta_lead_id = '1200000000000001'")->fetch();
    $ok($c === 200 && $new && $new['name'] === 'Mariam Test', "signed delivery creates the lead (HTTP $c)");
    [$c] = $post($body, 'secret123');
    $ok($q("SELECT status FROM meta_webhook_log ORDER BY id DESC LIMIT 1")->fetchColumn() === 'duplicate', 'second delivery of the same lead logged as already in CRM');
    $ok((int)$q("SELECT COUNT(*) FROM leads WHERE meta_lead_id = '1200000000000001'")->fetchColumn() === 1, 'still one lead');
    $bad = json_encode(['object' => 'page', 'entry' => [['changes' => [['field' => 'leadgen', 'value' => ['leadgen_id' => '777']]]]]]);
    [$c] = $post($bad, 'secret123');
    $ok($c === 200 && str_contains($q("SELECT error_msg FROM meta_webhook_log ORDER BY id DESC LIMIT 1")->fetchColumn(), 'does not exist'), 'unknown lead id logged as a problem, Meta still gets 200');
} catch (Throwable $e) {
    $fail++; echo "  EXCEPTION " . $e->getMessage() . ' @' . $e->getLine() . "\n";
} finally {
    // Restore the local database
    $ids = $db->query("SELECT id FROM leads WHERE meta_lead_id LIKE '12000000000000%' AND id <> " . (int)$matched['id'])->fetchAll(PDO::FETCH_COLUMN);
    if ($ids) $db->exec("DELETE FROM leads WHERE id IN (" . implode(',', $ids) . ")");
    $q("UPDATE leads SET meta_lead_id = ?, meta_form_id = ?, meta_ad_id = ?, email = ?, notes = ?, stage = ?, updated_at = ? WHERE id = ?",
       [$matched['meta_lead_id'], $matched['meta_form_id'], $matched['meta_ad_id'], $matched['email'], $matched['notes'], $matched['stage'], $matched['updated_at'], $matched['id']]);
    $q("DELETE FROM lead_treatments WHERE lead_id = ?", [$matched['id']]);
    foreach ($matchedTags as $tid) $q("INSERT INTO lead_treatments (lead_id, treatment_id) VALUES (?,?)", [$matched['id'], $tid]);
    $db->exec("DELETE FROM meta_capi_events");
    $db->exec("DELETE FROM meta_webhook_log WHERE id > $maxLog");
    $db->exec("DELETE FROM activity_log WHERE id > $maxAct");
    $db->exec("DELETE FROM integration_settings");
    foreach ($settingsBefore as $k => $v) $q("INSERT INTO integration_settings (k, v) VALUES (?,?)", [$k, $v]);
    updateLeadScore((int)$matched['id'], $db);
    echo "Restored. Leads left from the test: " . $db->query("SELECT COUNT(*) FROM leads WHERE meta_lead_id LIKE '12000000000000%'")->fetchColumn() . "\n";
}
echo "\n$pass passed, $fail failed\n";

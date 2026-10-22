<?php
// Phase 11: campaigns and their backfill, the funnel (revenue by campaign, cost per patient), Meta Schedule / Purchase
// events, online booking (free slots, one-time code, patient + appointment + Schedule event), recalls and broadcasts.
// Scratch database clinic_crm_test_crm. Providers are replaced by a recording transport.
require_once __DIR__ . '/../includes/crm.php';
require_once __DIR__ . '/../includes/billing.php';
require_once __DIR__ . '/../includes/meta_leads.php';

function crmDb(): PDO {
    static $done = false;
    $db = testDb('clinic_crm_test_crm', !$done);
    if (!$done) {
        migrateApply($db, __DIR__ . '/../migrations/tenant', fn($l) => null);
        ensureMetaSchema($db);
        $db->exec("UPDATE branches SET invoice_prefix = 'CRM' WHERE id = 1");
        $db->exec("INSERT INTO staff (id, name, email, password, role, is_owner) VALUES (901, 'Cara Admin', 'cara.c@example.test', 'x', 'admin', 1), (902, 'Dr Slot', 'slot.c@example.test', 'x', 'practitioner', 0)");
        $db->exec("INSERT INTO practitioner_profiles (staff_id, is_bookable) VALUES (902, 1)");
        $db->exec("INSERT INTO services (id, name, price, vat_rate, duration_min, is_online_bookable) VALUES (61, 'Facial', 400, 5, 60, 1), (62, 'Botox', 1200, 5, 30, 0)");
        $db->exec("INSERT INTO integration_settings (k, v) VALUES ('meta.capi_enabled', '1'), ('meta.dataset_id', '123'), ('meta.token', 't')");
        $done = true;
    }
    $_SESSION['staff_id'] = 901; $_SESSION['role'] = 'admin'; staffPermissionOverrides(901, true);
    metaSetting($db, '__reload');
    return $db;
}
function crmSent(?array $reset = null): array {
    static $log = [];
    if ($reset !== null) $log = $reset;
    msgTransport(function (array $m) use (&$log) { $log[] = $m; return $m['channel'] . '-' . bin2hex(random_bytes(6)); });
    return $log;
}
function lead(PDO $db, string $name, string $phone, ?string $campaign, string $created = 'NOW()'): int {
    $db->prepare("INSERT INTO leads (name, phone, source, source_campaign, stage, created_at) VALUES (?, ?, 'meta_form', ?, 'new', $created)")->execute([$name, $phone, $campaign]);
    return (int)$db->lastInsertId();
}

function test_campaign_backfill_and_funnel_revenue() {
    $db = crmDb();
    $a = lead($db, 'Amal A', '+971500000101', 'Summer Glow'); $b = lead($db, 'Basma B', '+971500000102', 'Summer Glow'); $c = lead($db, 'Cyra C', '+971500000103', 'Summer Glow');
    $d = lead($db, 'Dina D', '+971500000104', 'Hair PRP');
    $r = campaignBackfill($db);
    check($r['campaigns'] === 2 && $r['leads'] === 4, 'two campaigns made from the leads’ campaign names, four leads linked');
    check(campaignBackfill($db) === ['campaigns' => 0, 'leads' => 0], 'running it again changes nothing');
    $cid = (int)$db->query("SELECT campaign_id FROM leads WHERE id = $a")->fetchColumn();
    $db->exec("UPDATE leads SET meta_lead_id = '1200000000009001' WHERE id = $a");   // Amal came from a Meta lead form
    saveCampaign($db, $cid, ['name' => 'Summer Glow', 'source' => 'meta_form', 'budget' => 600]);
    // Amal: booked, came, invoiced and paid 1,000 net. Basma: booked, didn't come. Cyra: nothing yet.
    [$pa] = patientFromLead($db, $a); [$pb] = patientFromLead($db, $b);
    [$apA] = createAppointment($db, ['service_ids' => [61], 'start' => date('Y-m-d 10:00', strtotime('+1 day')), 'patient_id' => $pa, 'branch_id' => 1, 'allow_outside_hours' => true]);
    createAppointment($db, ['service_ids' => [61], 'start' => date('Y-m-d 12:00', strtotime('+1 day')), 'patient_id' => $pb, 'branch_id' => 1, 'allow_outside_hours' => true]);
    $db->exec("UPDATE appointments SET status = 'completed' WHERE id = $apA");
    $inv = createInvoice($db, $pa, 1); addInvoiceLine($db, $inv, ['kind' => 'manual', 'description' => 'Treatment', 'unit_price' => 1000, 'vat_rate' => 5]); issueInvoice($db, $inv);
    createReceipt($db, $pa, 1, [['method' => 'card', 'amount' => 1050]], [$inv => 1050]);
    $f = funnelReport($db, date('Y-m-d', strtotime('-1 day')), date('Y-m-d'));
    $sg = $f[(string)$cid];
    check($sg['leads'] == 3 && $sg['booked'] == 2 && $sg['showed'] == 1 && $sg['patients'] == 2 && $sg['invoiced'] == 1, "Summer Glow: 3 leads → 2 booked → 1 came → 1 invoiced");
    check($sg['revenue'] == 1000.0 && $sg['collected'] == 1050.0 && $sg['cost_per_patient'] == 300.0, 'revenue by campaign 1,000 (net), collected 1,050, cost per patient 600 / 2 = 300');
    $bySource = funnelReport($db, date('Y-m-d', strtotime('-1 day')), date('Y-m-d'), 'source');
    check($bySource['meta_form']['leads'] == 4 && $bySource['meta_form']['revenue'] == 1000.0, 'the same funnel by source');
    $ev = $db->query("SELECT stage, event_name, value FROM meta_capi_events WHERE lead_id = $a ORDER BY id")->fetchAll();
    check(in_array('Schedule', array_column($ev, 'event_name'), true) && in_array(['stage' => 'purchase', 'event_name' => 'Purchase', 'value' => '1000.00'], $ev), 'booking queued a Meta Schedule event; the paid invoice a Purchase worth 1,000 (net)');
    $inv2 = createInvoice($db, $pa, 1); addInvoiceLine($db, $inv2, ['kind' => 'manual', 'description' => 'More', 'unit_price' => 100, 'vat_rate' => 5]); issueInvoice($db, $inv2);
    createReceipt($db, $pa, 1, [['method' => 'card', 'amount' => 105]], [$inv2 => 105]);
    check((int)$db->query("SELECT COUNT(*) FROM meta_capi_events WHERE lead_id = $a AND event_name = 'Purchase'")->fetchColumn() === 1, 'only the first paid invoice is a Purchase');
    $row = $db->query("SELECT e.*, l.meta_lead_id, l.phone, l.email FROM meta_capi_events e JOIN leads l ON l.id = e.lead_id WHERE e.lead_id = $a AND e.stage = 'purchase'")->fetch();
    $e = metaCapiEvent($row);
    check($e['custom_data']['value'] === 1000.0 && $e['custom_data']['currency'] === 'AED' && $e['user_data']['lead_id'] === 1200000000009001 && isset($e['user_data']['ph']), 'the event: Meta lead id, value and currency, hashed phone, no line details');
    [$pc] = patientFromLead($db, $c);
    createAppointment($db, ['service_ids' => [61], 'start' => date('Y-m-d 15:00', strtotime('+1 day')), 'patient_id' => $pc, 'branch_id' => 1, 'allow_outside_hours' => true]);
    check(!$db->query("SELECT COUNT(*) FROM meta_capi_events WHERE lead_id = $c")->fetchColumn(), 'leads not from a Meta form get no CRM events');
}

function test_online_booking() {
    $db = crmDb(); crmSent([]);
    msgSaveSetting($db, 'sms_provider', 'mock'); msgSaveSetting($db, 'online_booking', '1'); msgSaveSetting($db, 'booking_notice_hours', '0');
    $tz = new DateTimeZone(CLINIC_TZ);
    $day = (new DateTimeImmutable('+2 days', $tz));
    savePractitionerSchedule($db, 902, 1, [[(int)$day->format('w'), '10:00', '13:00']]);
    $slots = onlineSlots($db, 1, 61, 0, $day->format('Y-m-d'));
    check(count($slots) === 9 && $slots[0]['time'] === $day->format('Y-m-d') . ' 10:00' && $slots[0]['staff_id'] === 902, '10:00–13:00, 60-minute facial: nine start times (every 15 minutes)');
    check(onlineSlots($db, 1, 62, 0, $day->format('Y-m-d')) === [], 'a treatment not offered online has no times');
    $db->exec("INSERT INTO appointments (patient_id, service_id, scheduled_at, ends_at, practitioner_id, branch_id, status) VALUES (NULL, 61, '" . $day->format('Y-m-d') . " 11:00', '" . $day->format('Y-m-d') . " 12:00', 902, 1, 'booked')");
    $taken = array_column(onlineSlots($db, 1, 61, 0, $day->format('Y-m-d')), 'time');
    check(!in_array($day->format('Y-m-d') . ' 11:00', $taken, true) && !in_array($day->format('Y-m-d') . ' 10:15', $taken, true) && in_array($day->format('Y-m-d') . ' 12:00', $taken, true), 'times overlapping a booking are gone');
    $ch = otpSend($db, '050 999 0001');
    $sent = crmSent();
    preg_match('/code is (\d{6})/', end($sent)['body'], $m);
    check($ch === 'sms' && isset($m[1]) && !$db->query("SELECT COUNT(*) FROM messages WHERE body LIKE '%{$m[1]}%'")->fetchColumn(), 'the code goes by SMS; it is never stored in the message log');
    check(!otpVerify($db, '+971509990001', '000000') && otpVerify($db, '+971509990001', $m[1]) && !otpVerify($db, '+971509990001', $m[1]), 'a wrong code fails; the right one works once');
    for ($i = 0; $i < 4; $i++) otpSend($db, '+971509990001');
    checkThrows(fn() => otpSend($db, '+971509990001'), 'Too many codes', 'five codes an hour per number at most');
    [$appt, $pid] = onlineBook($db, ['name' => 'Online Olivia', 'phone' => '+971509990001', 'branch_id' => 1, 'service_id' => 61, 'staff_id' => 0, 'time' => $day->format('Y-m-d') . ' 10:00', 'whatsapp_opt_in' => 1]);
    $a = $db->query("SELECT * FROM appointments WHERE id = $appt")->fetch();
    $p = findPatient($db, $pid);
    check($a['booked_via'] === 'online' && (int)$a['practitioner_id'] === 902 && $p['first_name'] === 'Online' && $p['whatsapp_opt_in'] == 1 && $p['lead_id'], 'online booking made the patient (from a new lead) and the appointment, booked online');
    check($db->query("SELECT source FROM leads WHERE id = " . (int)$p['lead_id'])->fetchColumn() === 'online_booking', 'the lead’s source is Online booking');
    check((int)$db->query("SELECT COUNT(*) FROM meta_capi_events WHERE lead_id = " . (int)$p['lead_id'])->fetchColumn() === 0, 'no CRM event for a lead without a Meta lead id (the booking page sends a website event instead)');
    checkThrows(fn() => onlineBook($db, ['name' => 'Second', 'phone' => '+971509990002', 'branch_id' => 1, 'service_id' => 61, 'time' => $day->format('Y-m-d') . ' 10:00']), 'just taken', 'the same time can’t be booked twice');
    [$appt2, $pid2] = onlineBook($db, ['name' => 'Online Olivia', 'phone' => '+971509990001', 'branch_id' => 1, 'service_id' => 61, 'time' => $day->format('Y-m-d') . ' 12:00']);
    check($pid2 === $pid, 'booking again with the same mobile finds the same patient');
}

function test_recalls() {
    $db = crmDb();
    $db->exec("INSERT INTO patients (id, mrn, first_name, last_name, status, branch_id, marketing_consent) VALUES (501, 'R-1', 'Rana', 'Recall', 'active', 1, 1), (502, 'R-2', 'Sara', 'Soon', 'active', 1, 1), (503, 'R-3', 'Tala', 'Recent', 'active', 1, 0)");
    foreach ([[501, 100, 62], [502, 100, 62], [503, 20, 62]] as [$pid, $ago, $svc]) {
        $db->exec("INSERT INTO appointments (patient_id, service_id, scheduled_at, ends_at, branch_id, status) VALUES ($pid, $svc, NOW() - INTERVAL $ago DAY, NOW() - INTERVAL $ago DAY + INTERVAL 30 MINUTE, 1, 'completed')");
        $db->exec("INSERT INTO appointment_services (appointment_id, service_id) VALUES (" . $db->lastInsertId() . ", $svc)");
    }
    $db->exec("INSERT INTO appointments (patient_id, service_id, scheduled_at, ends_at, branch_id, status) VALUES (502, 61, NOW() + INTERVAL 5 DAY, NOW() + INTERVAL 5 DAY + INTERVAL 1 HOUR, 1, 'booked')");
    $rule = saveRecallRule($db, 0, ['name' => 'Botox every 3 months', 'kind' => 'service_interval', 'service_id' => 62, 'days_after' => 90, 'send_message' => 1, 'is_active' => 1]);
    check(generateRecalls($db) >= 1 && (int)$db->query("SELECT COUNT(*) FROM recalls WHERE rule_id = $rule")->fetchColumn() === 1 && (int)$db->query("SELECT patient_id FROM recalls WHERE rule_id = $rule")->fetchColumn() === 501, 'only the patient due and without a booking is recalled');
    generateRecalls($db);
    check((int)$db->query("SELECT COUNT(*) FROM recalls WHERE rule_id = $rule")->fetchColumn() === 1, 'no second recall while the first is open');
    checkThrows(fn() => saveRecallRule($db, 0, ['name' => 'x', 'kind' => 'service_interval', 'days_after' => 30]), 'treatment', 'a treatment rule needs its treatment');
    $rid = (int)$db->query("SELECT id FROM recalls WHERE rule_id = $rule")->fetchColumn();
    updateRecall($db, $rid, 'contacted', 'Called, will book');
    check($db->query("SELECT status FROM recalls WHERE id = $rid")->fetchColumn() === 'contacted', 'staff record the follow-up');
}

function test_broadcasts() {
    $db = crmDb(); crmSent([]);
    ensureDefaultTemplates($db);
    msgSaveSetting($db, 'sms_provider', 'mock'); msgSaveSetting($db, 'quiet_from', '00:00'); msgSaveSetting($db, 'quiet_to', '00:00');
    $db->exec("INSERT INTO patient_phones (patient_id, phone, is_primary) VALUES (501, '+971500005001', 1), (502, '+971500005002', 1), (503, '+971500005003', 1)");
    $tpl = (int)$db->query("SELECT id FROM message_templates WHERE channel = 'sms' AND purpose = 'custom' LIMIT 1")->fetchColumn()
        ?: saveTemplate($db, 0, ['channel' => 'sms', 'purpose' => 'custom', 'name' => 'Offer', 'body' => 'Hi {first_name}, 20% off facials this week: {link}', 'is_active' => 1]);
    $seg = ['service_id' => 62];
    check(segmentPatientIds($db, $seg) === [501, 502], 'the segment has only patients who agreed to marketing (503 did not)');
    $b = createBroadcast($db, 'Botox clients offer', $tpl, $seg);
    startBroadcast($db, $b);
    $n = runBroadcasts($db);
    check($n === 2 && count(crmSent()) === 2 && $db->query("SELECT status FROM broadcasts WHERE id = $b")->fetchColumn() === 'sent', 'sent to both, the broadcast is done');
    check(runBroadcasts($db) === 0 && count(crmSent()) === 2, 'nothing is sent twice');
    check(broadcastResults($db, $b) === ['sent' => 2], 'results by status');
    checkThrows(fn() => startBroadcast($db, $b), 'already', 'a broadcast starts once');
    msgSaveSetting($db, 'quiet_from', '00:00'); msgSaveSetting($db, 'quiet_to', '23:59');
    $b2 = createBroadcast($db, 'Night test', $tpl, $seg); startBroadcast($db, $b2);
    check(runBroadcasts($db) === 0, 'nothing goes out in quiet hours');
}

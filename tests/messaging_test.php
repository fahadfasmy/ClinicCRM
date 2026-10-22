<?php
// Phase 10: messaging. The brief's tests: book for tomorrow and the 24h reminder is sent once (a second cron run sends
// nothing); a template flagged as health information can't be switched on without the owner's acknowledgement.
// Also: channel order and consent, quiet hours, health fields never leaking, self-service links, feedback, the
// WhatsApp webhook (signature, statuses, incoming) and rate limits. Providers are replaced by a recording transport.
// Scratch database clinic_crm_test_messaging.
require_once __DIR__ . '/../includes/messaging.php';

function msgDb(): PDO {
    static $done = false;
    $db = testDb('clinic_crm_test_messaging', !$done);
    if (!$done) {
        migrateApply($db, __DIR__ . '/../migrations/tenant', fn($l) => null);
        $db->exec("INSERT INTO staff (id, name, email, password, role, is_owner) VALUES (801, 'Olivia Owner', 'owner.m@example.test', 'x', 'admin', 1), (802, 'Adam Admin', 'adam.m@example.test', 'x', 'admin', 0)");
        $db->exec("INSERT INTO services (id, name, price, duration_min) VALUES (51, 'Botox', 1200, 30)");
        $db->exec("INSERT INTO patients (id, mrn, first_name, last_name, status, branch_id, whatsapp_opt_in, marketing_consent, preferred_language, email, dob) VALUES
                   (1, 'M-1', 'Lina', 'Msg', 'active', 1, 1, 0, 'en', 'lina@example.test', '1990-01-01'), (2, 'M-2', 'Huda', 'NoWa', 'active', 1, 0, 1, 'ar', NULL, '1991-02-02')");
        $db->exec("INSERT INTO patient_phones (patient_id, phone, is_primary) VALUES (1, '+971501110001', 1), (2, '+971501110002', 1)");
        $done = true;
    }
    $_SESSION['staff_id'] = 801; $_SESSION['role'] = 'admin'; staffPermissionOverrides(801, true);
    return $db;
}
/** Records what would have gone to WhatsApp/SMS/email. */
function sent(?array $reset = null): array {
    static $log = [];
    if ($reset !== null) $log = $reset;
    msgTransport(function (array $m) use (&$log) { $log[] = $m; return $m['channel'] . '-' . bin2hex(random_bytes(6)); });
    return $log;
}
function lastSent(): array { $l = sent(); return $l ? $l[count($l) - 1] : []; }
function waOn(PDO $db): void {
    msgSaveSetting($db, 'wa_enabled', '1'); msgSaveSetting($db, 'wa_phone_number_id', '1098765'); msgSaveSetting($db, 'wa_token', 'test-token', true);
    msgSaveSetting($db, 'wa_app_secret', 'app-secret', true); msgSaveSetting($db, 'base_url', 'https://crm.example.test');
    ensureDefaultTemplates($db);
    $db->exec("UPDATE message_templates SET wa_status = 'approved' WHERE channel = 'whatsapp'");
}

function test_the_24h_reminder_is_sent_once() {
    $db = msgDb(); waOn($db); sent([]);
    $db->exec("UPDATE automations SET is_active = 1 WHERE `key` IN ('reminder_24h', 'reminder_2h')");
    $db->exec("INSERT INTO appointments (patient_id, service_id, scheduled_at, ends_at, branch_id, status) VALUES (1, 51, NOW() + INTERVAL 20 HOUR, NOW() + INTERVAL 21 HOUR, 1, 'booked')");
    $ap = (int)$db->lastInsertId();
    $r = runAutomations($db);
    $log = sent();
    check(($r['reminder_24h'] ?? 0) === 1 && count($log) === 1 && $log[0]['channel'] === 'whatsapp' && $log[0]['wa_template'] === 'clinic_reminder_24h', 'booked for tomorrow: one 24-hour reminder by WhatsApp');
    check(!isset($r['reminder_2h']), 'no 2-hour reminder yet');
    check(in_array('Lina', $log[0]['params'], true) && !array_filter($log[0]['params'], fn($p) => str_contains((string)$p, 'Botox')), 'the template gets first name, date, time… but not the treatment');
    $r2 = runAutomations($db);
    check(!isset($r2['reminder_24h']) && count(sent()) === 1, 'a second cron run sends nothing');
    $link = array_values(array_filter($log[0]['params'], fn($p) => str_contains((string)$p, '/s.php?t=')))[0] ?? '';
    check($link && appointmentFromSelfToken($db, substr($link, -48)), 'and the link in the reminder still works after later runs');
    check((int)$db->query("SELECT COUNT(*) FROM messages WHERE appointment_id = $ap AND automation_key = 'reminder_24h'")->fetchColumn() === 1, 'one row in the message log');
    $db->exec("UPDATE appointments SET scheduled_at = NOW() + INTERVAL 90 MINUTE WHERE id = $ap");
    $r3 = runAutomations($db);
    check(($r3['reminder_2h'] ?? 0) === 1 && !isset($r3['reminder_24h']), 'two hours before: the 2-hour reminder, still no second 24-hour one');
    $db->exec("UPDATE appointments SET status = 'cancelled' WHERE id = $ap");
}

function test_health_information_needs_the_owners_acknowledgement() {
    $db = msgDb();
    $base = ['channel' => 'whatsapp', 'purpose' => 'custom', 'name' => 'Aftercare', 'language' => 'en', 'body' => 'Hi {first_name}, after your {service}: avoid sun for 2 days.', 'is_active' => 1];
    checkThrows(fn() => saveTemplate($db, 0, $base), 'Contains health information', 'a treatment field without the health flag is refused');
    $_SESSION['staff_id'] = 802; staffPermissionOverrides(802, true);
    checkThrows(fn() => saveTemplate($db, 0, $base + ['contains_health_info' => 1, 'health_ack' => 1]), 'Only the clinic owner', 'an admin who isn’t the owner can’t switch it on');
    $_SESSION['staff_id'] = 801; staffPermissionOverrides(801, true);
    checkThrows(fn() => saveTemplate($db, 0, $base + ['contains_health_info' => 1]), 'Tick the acknowledgement', 'the owner must tick the acknowledgement');
    $id = saveTemplate($db, 0, $base + ['contains_health_info' => 1, 'health_ack' => 1]);
    $t = findTemplate($db, $id);
    check($t['contains_health_info'] == 1 && (int)$t['health_ack_by'] === 801 && $t['health_ack_at'], 'with it, the template is allowed and the acknowledgement recorded');
    check((int)$db->query("SELECT COUNT(*) FROM audit_log WHERE entity = 'message_template' AND entity_id = $id AND action = 'health_info_allowed'")->fetchColumn() === 1, 'and audit-logged');
    $_SESSION['staff_id'] = 802; staffPermissionOverrides(802, true);
    saveTemplate($db, $id, array_merge($base, ['contains_health_info' => 1, 'body' => 'Hi {first_name}, after your {service}: no sun.']));
    check((int)findTemplate($db, $id)['health_ack_by'] === 801, 'editing later keeps the owner’s acknowledgement');
    checkThrows(fn() => saveTemplate($db, 0, array_merge($base, ['body' => 'Hi {password}'])), 'Unknown field', 'unknown fields are refused');
    $_SESSION['staff_id'] = 801; staffPermissionOverrides(801, true);
}

function test_channels_consent_and_quiet_hours() {
    $db = msgDb(); waOn($db); sent([]);
    msgSaveSetting($db, 'sms_provider', 'mock');
    $m = sendTemplateMessage($db, 'confirmation', 2, null, ['vars' => ['date' => 'Mon 1 Jan', 'time' => '10:00 AM', 'branch' => 'Main', 'link' => 'L']]);
    $log = sent();
    check($m && lastSent()['channel'] === 'sms' && str_contains(lastSent()['body'], 'مرحباً Huda'), 'no WhatsApp opt-in: SMS instead, in the patient’s language (Arabic)');
    msgSaveSetting($db, 'sms_provider', 'none');
    $db->exec("UPDATE message_templates SET wa_status = 'pending' WHERE channel = 'whatsapp' AND purpose = 'cancelled'");
    $m2 = sendTemplateMessage($db, 'cancelled', 2, null, []);
    check($m2 === null && $db->query("SELECT status FROM messages ORDER BY id DESC LIMIT 1")->fetchColumn() === 'skipped', 'no channel left: recorded as skipped, nothing sent');
    $before = count(sent());
    sendTemplateMessage($db, 'birthday', 1, null, []);
    check(count(sent()) === $before && str_contains((string)$db->query("SELECT error FROM messages ORDER BY id DESC LIMIT 1")->fetchColumn(), 'marketing consent'), 'birthday greetings need marketing consent');
    msgSaveSetting($db, 'quiet_from', '21:00'); msgSaveSetting($db, 'quiet_to', '09:00');
    $tz = new DateTimeZone(CLINIC_TZ);
    check(inQuietHours($db, new DateTimeImmutable('2026-10-18 22:30', $tz)) && inQuietHours($db, new DateTimeImmutable('2026-10-19 08:59', $tz)) && !inQuietHours($db, new DateTimeImmutable('2026-10-19 09:00', $tz)), 'quiet hours 21:00–09:00 Dubai');
    $db->exec("UPDATE automations SET is_active = 1 WHERE `key` = 'birthday'");
    $db->exec("UPDATE patients SET dob = '1991-" . (new DateTimeImmutable('2026-10-18 23:00', $tz))->format('m-d') . "' WHERE id = 2");
    $q = runAutomations($db, new DateTimeImmutable('2026-10-18 23:00', $tz));
    check(!isset($q['birthday']), 'no birthday message at 11 pm');
    $db->exec("UPDATE automations SET is_active = 0 WHERE `key` = 'birthday'");
    // A template not cleared for health information never receives the treatment, even if a caller passes it
    $db->exec("UPDATE message_templates SET body = 'Hi {first_name} {service}' WHERE channel = 'whatsapp' AND purpose = 'confirmation' AND language = 'en'");
    sendTemplateMessage($db, 'confirmation', 1, null, ['vars' => ['service' => 'Botox']]);
    check(!str_contains(lastSent()['body'], 'Botox'), 'health fields are dropped for templates not cleared for them');
}

function test_self_service_links() {
    $db = msgDb(); sent([]);
    msgSaveSetting($db, 'cancel_cutoff_hours', '24');
    $db->exec("INSERT INTO appointments (patient_id, service_id, scheduled_at, ends_at, branch_id, status) VALUES (1, 51, NOW() + INTERVAL 3 DAY, NOW() + INTERVAL 3 DAY + INTERVAL 30 MINUTE, 1, 'booked')");
    $ap = (int)$db->lastInsertId();
    $old = substr(appointmentSelfLink($db, $ap), -48);
    $t = substr(appointmentSelfLink($db, $ap), -48);
    check(appointmentFromSelfToken($db, $old) === null && appointmentFromSelfToken($db, 'nope') === null, 'a newer link replaces the older one; made-up tokens open nothing');
    $a = appointmentFromSelfToken($db, $t);
    check(selfServiceActions($db, $a) === ['confirm', 'cancel', 'reschedule'], 'three days ahead: confirm, cancel or ask to reschedule');
    selfServiceAct($db, $a, 'confirm');
    $a = appointmentFromSelfToken($db, $t);
    check($a['status'] === 'confirmed' && !in_array('confirm', selfServiceActions($db, $a), true), 'confirmed');
    selfServiceAct($db, $a, 'reschedule');
    check((int)$db->query("SELECT COUNT(*) FROM staff_notifications WHERE kind = 'reschedule_request'")->fetchColumn() === 1, 'a reschedule request notifies the front desk');
    $db->exec("UPDATE appointments SET scheduled_at = NOW() + INTERVAL 5 HOUR WHERE id = $ap");
    $a = appointmentFromSelfToken($db, $t);
    checkThrows(fn() => selfServiceAct($db, $a, 'cancel'), 'call the clinic', 'inside the 24-hour cut-off it can’t be cancelled online');
    $db->exec("UPDATE appointments SET scheduled_at = NOW() + INTERVAL 2 DAY WHERE id = $ap");
    selfServiceAct($db, appointmentFromSelfToken($db, $t), 'cancel');
    check($db->query("SELECT status FROM appointments WHERE id = $ap")->fetchColumn() === 'cancelled', 'cancelled online before the cut-off');
}

function test_feedback() {
    $db = msgDb();
    $db->exec("INSERT INTO appointments (patient_id, service_id, scheduled_at, ends_at, branch_id, status, completed_at) VALUES (1, 51, NOW() - INTERVAL 5 HOUR, NOW() - INTERVAL 4 HOUR, 1, 'completed', NOW() - INTERVAL 4 HOUR)");
    $ap = (int)$db->lastInsertId();
    $db->exec("UPDATE automations SET is_active = 1 WHERE `key` = 'feedback'");
    sent([]);
    $r = runAutomations($db);
    check(($r['feedback'] ?? 0) === 1 && str_contains(lastSent()['params'][array_key_last(lastSent()['params'])] ?? '', '/f.php?t='), 'three hours after the visit the feedback link goes out');
    $link = $db->query("SELECT body FROM messages WHERE appointment_id = $ap AND automation_key = 'feedback'")->fetchColumn();
    preg_match('/t=([a-f0-9]{48})/', $link, $m);
    $f = feedbackFromToken($db, $m[1]);
    check($f && (int)$f['appointment_id'] === $ap, 'the link opens this visit’s feedback');
    checkThrows(fn() => submitFeedback($db, $f, 11, ''), '0 to 10', 'scores are 0–10');
    submitFeedback($db, $f, 4, 'Waited too long');
    check((int)$db->query("SELECT COUNT(*) FROM staff_notifications WHERE kind = 'low_feedback'")->fetchColumn() === 1, 'a score of 6 or less alerts the managers');
    checkThrows(fn() => submitFeedback($db, feedbackFromToken($db, $m[1]), 9, ''), 'already', 'feedback is given once');
    $db->exec("UPDATE automations SET is_active = 0 WHERE `key` = 'feedback'");
}

function test_whatsapp_webhook() {
    $db = msgDb(); waOn($db); sent([]);
    $mid = sendTemplateMessage($db, 'reminder_2h', 1, null, ['vars' => ['time' => '10:00', 'branch' => 'Main']]);
    $pid = $db->query("SELECT provider_id FROM messages WHERE id = $mid")->fetchColumn();
    $raw = json_encode(['entry' => [['changes' => [['value' => ['statuses' => [['id' => $pid, 'status' => 'read']]]]]]]]);
    check(waSignatureValid($db, $raw, 'sha256=' . hash_hmac('sha256', $raw, 'app-secret')) && !waSignatureValid($db, $raw, 'sha256=' . hash_hmac('sha256', $raw, 'wrong')), 'the webhook signature is checked with the app secret');
    waHandleWebhook($db, json_decode($raw, true));
    waHandleWebhook($db, ['entry' => [['changes' => [['value' => ['statuses' => [['id' => $pid, 'status' => 'delivered']]]]]]]]);
    check($db->query("SELECT status FROM messages WHERE id = $mid")->fetchColumn() === 'read', 'statuses only move forward (a late “delivered” doesn’t undo “read”)');
    $n = waHandleWebhook($db, ['entry' => [['changes' => [['value' => ['messages' => [['from' => '971501110001', 'id' => 'wamid.IN1', 'type' => 'text', 'text' => ['body' => 'Can I come at 11?']]]]]]]]]);
    $in = $db->query("SELECT * FROM messages WHERE provider_id = 'wamid.IN1'")->fetch();
    check($n[1] === 1 && (int)$in['patient_id'] === 1 && $in['direction'] === 'in', 'an incoming message is matched to the patient by phone');
    waHandleWebhook($db, ['entry' => [['changes' => [['value' => ['messages' => [['from' => '971501110001', 'id' => 'wamid.IN1', 'type' => 'text', 'text' => ['body' => 'Can I come at 11?']]]]]]]]]);
    check((int)$db->query("SELECT COUNT(*) FROM messages WHERE provider_id = 'wamid.IN1'")->fetchColumn() === 1, 'Meta resending the same message doesn’t duplicate it');
    $reply = sendReply($db, 'whatsapp', '+971501110001', 'Yes, 11 is fine.', 1, null);
    check($reply && lastSent()['body'] === 'Yes, 11 is fine.', 'within 24 hours staff can reply in free text');
    checkThrows(fn() => sendReply($db, 'whatsapp', '+971501110002', 'Hello', 2, null), '24 hours', 'otherwise WhatsApp needs a template');
}

function test_rate_limits() {
    $db = msgDb();
    $ok = 0; for ($i = 0; $i < 25; $i++) if (rateLimitOk($db, 'test:1.2.3.4', 20, 600)) $ok++;
    check($ok === 20, 'the 21st try in 10 minutes is refused');
    $db->exec("UPDATE rate_limits SET window_start = NOW() - INTERVAL 11 MINUTE WHERE bucket = 'test:1.2.3.4'");
    check(rateLimitOk($db, 'test:1.2.3.4', 20, 600), 'a new window starts afresh');
}

<?php
// includes/messaging.php — patient messages and automations (Phase 10).
// Channels: WhatsApp Cloud API (Meta; approved templates for messages the clinic starts, free text within 24 hours of
// the patient's last message), SMS through a provider adapter (only the local mock until the clinic chooses a provider),
// and email through includes/mailer.php. WhatsApp first, then SMS, then email; the patient's language (EN/AR).
// Every send checks consent (WhatsApp needs the patient's opt-in; marketing needs marketing consent), keeps marketing out
// of quiet hours (default 21:00–09:00 Dubai) and has an idempotency key, so an automation never sends the same message twice.
// Default templates carry only first name, date, time, branch, a map link and a secure link. A template may only carry
// health information (treatment, amounts) once the clinic owner has acknowledged it; that is audit-logged.
require_once __DIR__ . '/patients.php';
require_once __DIR__ . '/inventory.php';   // stockTx, cancelAppointment
require_once __DIR__ . '/mailer.php';
require_once __DIR__ . '/phone_helpers.php';
require_once __DIR__ . '/data_flows.php';

const MSG_CHANNELS = ['whatsapp' => 'WhatsApp', 'sms' => 'SMS', 'email' => 'Email'];
const MSG_PURPOSES = ['confirmation' => 'Booking confirmation', 'reminder_24h' => 'Reminder, 24 hours before', 'reminder_2h' => 'Reminder, 2 hours before',
                      'cancelled' => 'Cancellation', 'feedback' => 'Feedback request', 'package_expiry' => 'Package ending soon', 'birthday' => 'Birthday greeting',
                      'recall' => 'Recall (time for a visit)', 'otp' => 'One-time code (online booking)', 'custom' => 'Other / broadcast'];
const MSG_MARKETING = ['birthday'];   // these need marketing consent and stay out of quiet hours
const MSG_VARS = ['first_name', 'date', 'time', 'branch', 'map_link', 'link', 'clinic', 'code'];
const MSG_HEALTH_VARS = ['service', 'practitioner', 'amount'];   // only in templates the owner has cleared for health information
// automation key => [name, purpose, default config]
const AUTOMATIONS = [
    'confirmation' => ['Booking confirmation', 'confirmation', []],
    'reminder_24h' => ['Reminder 24 hours before', 'reminder_24h', []],
    'reminder_2h'  => ['Reminder 2 hours before', 'reminder_2h', []],
    'feedback'     => ['Feedback request after the visit', 'feedback', ['hours_after' => 3]],
    'package_expiry' => ['Package ending (30 and 7 days)', 'package_expiry', []],
    'birthday'     => ['Birthday greeting (marketing consent only)', 'birthday', []],
    'recall'       => ['Recall messages (rules marked “send a message”)', 'recall', []],
];

// ── Settings ──

function msgSetting(PDO $db, string $k, string $default = ''): string {
    $s = $db->prepare("SELECT v FROM integration_settings WHERE k = ?");
    $s->execute(['msg.' . $k]);
    $v = $s->fetchColumn();
    return $v === false || $v === null ? $default : (string)$v;
}

function msgSaveSetting(PDO $db, string $k, ?string $v, bool $secret = false): void {
    $db->prepare("REPLACE INTO integration_settings (k, v) VALUES (?, ?)")->execute(['msg.' . $k, $v === null ? null : ($secret && $v !== '' ? tenantEncrypt($v) : $v)]);
}

/** A secret setting (encrypted in the database); a constant in the config file wins (e.g. WA_CLOUD_TOKEN). */
function msgSecret(PDO $db, string $k, string $const): string {
    if (defined($const) && constant($const) !== '') return (string)constant($const);
    $v = msgSetting($db, $k);
    if ($v === '') return '';
    try { return tenantDecrypt($v); } catch (Throwable $e) { return ''; }
}

function waConfig(PDO $db): array {
    return ['phone_number_id' => defined('WA_CLOUD_PHONE_NUMBER_ID') && WA_CLOUD_PHONE_NUMBER_ID ? WA_CLOUD_PHONE_NUMBER_ID : msgSetting($db, 'wa_phone_number_id'),
            'token' => msgSecret($db, 'wa_token', 'WA_CLOUD_TOKEN'), 'app_secret' => msgSecret($db, 'wa_app_secret', 'WA_CLOUD_APP_SECRET'),
            'verify_token' => defined('WA_CLOUD_VERIFY_TOKEN') && WA_CLOUD_VERIFY_TOKEN ? WA_CLOUD_VERIFY_TOKEN : msgSetting($db, 'wa_verify_token'),
            'version' => msgSetting($db, 'wa_graph_version', 'v23.0'), 'base' => msgSetting($db, 'wa_graph_base', 'https://graph.facebook.com')];
}
function waReady(PDO $db): bool { $c = waConfig($db); return $c['phone_number_id'] !== '' && $c['token'] !== '' && msgSetting($db, 'wa_enabled') === '1'; }
function smsProvider(PDO $db): string { return defined('SMS_PROVIDER') && SMS_PROVIDER ? SMS_PROVIDER : msgSetting($db, 'sms_provider', 'none'); }
function smsReady(PDO $db): bool { return smsProvider($db) !== 'none'; }

/** Marketing quiet hours in Dubai time (default 21:00–09:00). */
function inQuietHours(PDO $db, ?DateTimeImmutable $at = null): bool {
    $at = ($at ?? new DateTimeImmutable('now'))->setTimezone(new DateTimeZone(CLINIC_TZ));
    $from = msgSetting($db, 'quiet_from', '21:00'); $to = msgSetting($db, 'quiet_to', '09:00'); $t = $at->format('H:i');
    return $from > $to ? ($t >= $from || $t < $to) : ($t >= $from && $t < $to);
}

// ── Transport (swappable in tests) ──

/** Sends one message through a provider and returns its id. $transport replaces the real providers (tests). */
function msgTransport(?callable $set = null): ?callable {
    static $t = null;
    if ($set) $t = $set;
    return $t;
}

function waHttp(PDO $db, string $path, array $payload): array {
    $c = waConfig($db);
    $url = rtrim($c['base'], '/') . '/' . $c['version'] . '/' . ltrim($path, '/');
    outboundGuard($url);
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => json_encode($payload), CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 20, CURLOPT_CONNECTTIMEOUT => 8,
                            CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Authorization: Bearer ' . $c['token']]]);
    $body = curl_exec($ch); $err = curl_error($ch); curl_close($ch);
    if ($body === false) throw new RuntimeException('Could not reach WhatsApp: ' . $err);
    $j = json_decode((string)$body, true);
    if (!is_array($j)) throw new RuntimeException('WhatsApp sent an unreadable reply.');
    if (isset($j['error'])) throw new RuntimeException('WhatsApp: ' . ($j['error']['error_user_msg'] ?? $j['error']['message'] ?? 'error'));
    return $j;
}

/** $m: channel, to, body, subject, wa_template, wa_language, params (ordered values for the template). Returns the provider id. */
function deliver(PDO $db, array $m): string {
    planCheck($db, 'messages_per_month');   // over the plan's monthly messages: an upgrade message, nothing sent
    if ($t = msgTransport()) return (string)$t($m);
    switch ($m['channel']) {
        case 'whatsapp':
            $to = preg_replace('/\D/', '', $m['to']);
            $payload = $m['wa_template'] ?? null
                ? ['messaging_product' => 'whatsapp', 'to' => $to, 'type' => 'template', 'template' => ['name' => $m['wa_template'], 'language' => ['code' => $m['wa_language'] ?? 'en'],
                   'components' => [['type' => 'body', 'parameters' => array_map(fn($v) => ['type' => 'text', 'text' => (string)$v], $m['params'] ?? [])]]]]
                : ['messaging_product' => 'whatsapp', 'to' => $to, 'type' => 'text', 'text' => ['body' => $m['body']]];
            $j = waHttp($db, waConfig($db)['phone_number_id'] . '/messages', $payload);
            return (string)($j['messages'][0]['id'] ?? '');
        case 'sms':
            if (smsProvider($db) === 'mock') {
                outboundGuard($smsUrl = msgSetting($db, 'sms_mock_url', 'http://127.0.0.1:8084/send'));
                $ch = curl_init($smsUrl);
                curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => json_encode(['to' => $m['to'], 'text' => $m['body']]), CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 10,
                                        CURLOPT_HTTPHEADER => ['Content-Type: application/json']]);
                $j = json_decode((string)curl_exec($ch), true); curl_close($ch);
                if (empty($j['id'])) throw new RuntimeException('The SMS test service did not accept the message.');
                return (string)$j['id'];
            }
            throw new RuntimeException('No SMS provider is set up.');
        case 'email':
            sendEmail($db, $m['to'], $m['subject'] ?: clinicName(), nl2br(htmlspecialchars($m['body'], ENT_QUOTES, 'UTF-8')), $m['body'], 'patient_message');
            return 'email-' . bin2hex(random_bytes(8));
    }
    throw new RuntimeException('Unknown channel.');
}

// ── Templates ──

function templateVars(string $body): array {
    preg_match_all('/\{([a-z_]+)\}/', $body, $m);
    return array_values(array_unique($m[1]));
}

/** Creates or updates a template. Health-information variables need the flag, and the flag needs the owner's acknowledgement. */
function saveTemplate(PDO $db, int $id, array $in): int {
    if (!can('setup.messaging')) throw new RuntimeException('You don’t have permission to change message templates.');
    $channel = isset(MSG_CHANNELS[$in['channel'] ?? '']) ? $in['channel'] : null;
    $purpose = isset(MSG_PURPOSES[$in['purpose'] ?? '']) ? $in['purpose'] : null;
    $body = trim(str_replace("\r\n", "\n", (string)($in['body'] ?? '')));
    $name = trim((string)($in['name'] ?? ''));
    if (!$channel || !$purpose || $body === '' || $name === '') throw new RuntimeException('Choose the channel and purpose, and fill in the name and text.');
    if ($channel === 'sms' && mb_strlen($body) > 480) throw new RuntimeException('An SMS can be 480 characters at most.');
    $vars = templateVars($body);
    if ($bad = array_diff($vars, MSG_VARS, MSG_HEALTH_VARS)) throw new RuntimeException('Unknown field: {' . implode('}, {', $bad) . '}.');
    $old = $id ? findTemplate($db, $id) : null;
    $health = !empty($in['contains_health_info']);
    if (array_intersect($vars, MSG_HEALTH_VARS) && !$health) throw new RuntimeException('{' . implode('}, {', array_intersect($vars, MSG_HEALTH_VARS)) . '} is health information. Switch on “Contains health information” first.');
    $ack = $old && $old['contains_health_info'] ? [$old['health_ack_by'], $old['health_ack_at']] : [null, null];
    if ($health && !($old['contains_health_info'] ?? false)) {
        $s = $db->prepare("SELECT is_owner FROM staff WHERE id = ?"); $s->execute([currentStaffId()]);
        if (!(int)$s->fetchColumn()) throw new RuntimeException('Only the clinic owner can allow a template to carry health information.');
        if (empty($in['health_ack'])) throw new RuntimeException('Tick the acknowledgement: this sends health information through a provider outside the UAE.');
        $ack = [currentStaffId(), date('Y-m-d H:i:s')];
    }
    if (!$health) $ack = [null, null];
    $f = [$channel, $purpose, mb_substr($name, 0, 100), ($in['language'] ?? '') === 'ar' ? 'ar' : 'en', mb_substr(trim((string)($in['subject'] ?? '')), 0, 150) ?: null, $body, json_encode($vars),
          mb_substr(trim((string)($in['wa_template_name'] ?? '')), 0, 100) ?: null, in_array($in['wa_status'] ?? '', ['none', 'pending', 'approved', 'rejected'], true) ? $in['wa_status'] : 'none',
          $health ? 1 : 0, $ack[0], $ack[1], empty($in['is_active']) ? 0 : 1];
    if ($id) $db->prepare("UPDATE message_templates SET channel = ?, purpose = ?, name = ?, language = ?, subject = ?, body = ?, variables = ?, wa_template_name = ?, wa_status = ?, contains_health_info = ?, health_ack_by = ?, health_ack_at = ?, is_active = ? WHERE id = ?")->execute([...$f, $id]);
    else { $db->prepare("INSERT INTO message_templates (channel, purpose, name, language, subject, body, variables, wa_template_name, wa_status, contains_health_info, health_ack_by, health_ack_at, is_active) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)")->execute($f); $id = (int)$db->lastInsertId(); }
    if ($health !== (bool)($old['contains_health_info'] ?? false)) auditLog($db, 'message_template', $id, $health ? 'health_info_allowed' : 'health_info_removed', ['name' => $name]);
    else auditLog($db, 'message_template', $id, 'saved', ['name' => $name]);
    return $id;
}

function findTemplate(PDO $db, int $id): ?array {
    $s = $db->prepare("SELECT * FROM message_templates WHERE id = ?"); $s->execute([$id]);
    return $s->fetch() ?: null;
}

/** The default templates (EN and AR, each channel), added for any purpose that has none yet. */
function ensureDefaultTemplates(PDO $db): void {
    $have = $db->query("SELECT DISTINCT purpose FROM message_templates")->fetchAll(PDO::FETCH_COLUMN);
    $t = [
        'confirmation' => ["Hi {first_name}, your appointment at {clinic} is booked for {date} at {time} ({branch}). Confirm or change it here: {link}",
                           "مرحباً {first_name}، تم حجز موعدك في {clinic} يوم {date} الساعة {time} ({branch}). للتأكيد أو التغيير: {link}"],
        'reminder_24h' => ["Hi {first_name}, a reminder of your appointment tomorrow, {date} at {time} at {branch}. Confirm or change: {link} Map: {map_link}",
                           "مرحباً {first_name}، تذكير بموعدك غداً {date} الساعة {time} في {branch}. للتأكيد أو التغيير: {link} الموقع: {map_link}"],
        'reminder_2h'  => ["Hi {first_name}, see you today at {time} at {branch}. Map: {map_link}", "مرحباً {first_name}، نراك اليوم الساعة {time} في {branch}. الموقع: {map_link}"],
        'cancelled'    => ["Hi {first_name}, your appointment on {date} at {time} is cancelled. To book again: {link}", "مرحباً {first_name}، تم إلغاء موعدك يوم {date} الساعة {time}. للحجز مجدداً: {link}"],
        'feedback'     => ["Hi {first_name}, thank you for visiting {clinic}. How was it? Tell us in one tap: {link}", "مرحباً {first_name}، شكراً لزيارتك {clinic}. كيف كانت تجربتك؟ أخبرنا: {link}"],
        'package_expiry' => ["Hi {first_name}, your package at {clinic} ends on {date}. Book your remaining sessions: {link}", "مرحباً {first_name}، تنتهي باقتك في {clinic} يوم {date}. احجز جلساتك المتبقية: {link}"],
        'birthday'     => ["Happy birthday {first_name}! Best wishes from all of us at {clinic}.", "عيد ميلاد سعيد {first_name}! أطيب التمنيات من فريق {clinic}."],
        'recall'       => ["Hi {first_name}, it’s been a while since your last visit to {clinic}. Book your next appointment: {link}", "مرحباً {first_name}، مر وقت منذ زيارتك الأخيرة لـ {clinic}. احجز موعدك القادم: {link}"],
        'otp'          => ["Your {clinic} code is {code}. It expires in 10 minutes.", "رمز {clinic} الخاص بك هو {code}. ينتهي خلال 10 دقائق."],
    ];
    $ins = $db->prepare("INSERT INTO message_templates (channel, purpose, name, language, subject, body, variables, wa_template_name) VALUES (?,?,?,?,?,?,?,?)");
    foreach ($t as $purpose => [$en, $ar]) if (!in_array($purpose, $have, true)) foreach (['whatsapp', 'sms', 'email'] as $ch) foreach (['en' => $en, 'ar' => $ar] as $lang => $body)
        $ins->execute([$ch, $purpose, MSG_PURPOSES[$purpose] . ' (' . strtoupper($lang) . ')', $lang, $ch === 'email' ? MSG_PURPOSES[$purpose] : null, $body, json_encode(templateVars($body)), $ch === 'whatsapp' ? 'clinic_' . $purpose : null]);
    foreach (AUTOMATIONS as $k => [, , $cfg]) $db->prepare("INSERT IGNORE INTO automations (`key`, is_active, config) VALUES (?, 0, ?)")->execute([$k, json_encode($cfg ?: new stdClass())]);
}

/** The active template for a purpose, channel and language (falls back to English). */
function pickTemplate(PDO $db, string $purpose, string $channel, string $lang): ?array {
    $s = $db->prepare("SELECT * FROM message_templates WHERE purpose = ? AND channel = ? AND is_active = 1 AND language IN (?, 'en') ORDER BY language = ? DESC, id LIMIT 1");
    $s->execute([$purpose, $channel, $lang, $lang]);
    return $s->fetch() ?: null;
}

function fillTemplate(string $body, array $vars): string {
    return preg_replace_callback('/\{([a-z_]+)\}/', fn($m) => (string)($vars[$m[1]] ?? ''), $body);
}

// ── Sending ──

/** Where to reach a patient (or lead): ['phone', 'email', 'lang', 'first_name', 'wa_ok', 'marketing_ok', 'patient_id', 'lead_id']. */
function msgRecipient(PDO $db, ?int $patientId, ?int $leadId = null): ?array {
    if ($patientId && ($p = findPatient($db, $patientId))) {
        $ph = patientPhones($db, (int)$p['id']);
        return ['phone' => $ph[0]['phone'] ?? '', 'email' => (string)$p['email'], 'lang' => $p['preferred_language'] === 'ar' ? 'ar' : 'en', 'first_name' => $p['first_name'],
                'wa_ok' => (bool)$p['whatsapp_opt_in'], 'marketing_ok' => (bool)$p['marketing_consent'], 'patient_id' => (int)$p['id'], 'lead_id' => $p['lead_id'] ? (int)$p['lead_id'] : null];
    }
    if ($leadId) {
        $s = $db->prepare("SELECT id, name, phone, email FROM leads WHERE id = ?"); $s->execute([$leadId]);
        if ($l = $s->fetch()) return ['phone' => (string)$l['phone'], 'email' => (string)$l['email'], 'lang' => 'en', 'first_name' => explode(' ', trim((string)$l['name']))[0],
                                      'wa_ok' => false, 'marketing_ok' => false, 'patient_id' => null, 'lead_id' => (int)$l['id']];
    }
    return null;
}

/**
 * Sends a template message to a patient (or lead) on the best channel allowed: WhatsApp (opted in, connected, approved
 * template), else SMS (provider set up), else email (address on file). $opt: idempotency (key), automation, appointment_id,
 * vars (extra values), channel (force one). Returns the messages row id, or null when nothing could be sent (recorded as skipped).
 */
function sendTemplateMessage(PDO $db, string $purpose, ?int $patientId, ?int $leadId, array $opt = []): ?int {
    $key = $opt['idempotency'] ?? null;
    if ($key) { $s = $db->prepare("SELECT id FROM messages WHERE idempotency_key = ?"); $s->execute([$key]); if ($s->fetchColumn()) return null; }
    $r = msgRecipient($db, $patientId, $leadId);
    $marketing = in_array($purpose, MSG_MARKETING, true) || !empty($opt['marketing']);
    $fixed = !empty($opt['template_id']) ? findTemplate($db, (int)$opt['template_id']) : null;   // a broadcast's own template and channel
    $skip = function (string $why) use ($db, $r, $purpose, $opt, $key, $patientId, $leadId) {
        $db->prepare("INSERT IGNORE INTO messages (channel, direction, patient_id, lead_id, appointment_id, to_from, body, status, error, automation_key, idempotency_key) VALUES ('whatsapp','out',?,?,?,?,?, 'skipped', ?, ?, ?)")
           ->execute([$r['patient_id'] ?? $patientId, $r['lead_id'] ?? $leadId, $opt['appointment_id'] ?? null, $r['phone'] ?? '—', '(' . MSG_PURPOSES[$purpose] . ')', $why, $opt['automation'] ?? null, $key]);
        return null;
    };
    if (!$r) return $skip('No patient or lead.');
    if ($marketing && !$r['marketing_ok']) return $skip('No marketing consent.');
    $channels = $fixed ? [$fixed['channel']] : ($opt['channel'] ?? null ? [$opt['channel']] : ['whatsapp', 'sms', 'email']);
    foreach ($channels as $ch) {
        if ($ch === 'whatsapp' && (!$r['wa_ok'] || !waReady($db) || !$r['phone'])) continue;
        if ($ch === 'sms' && (!smsReady($db) || !$r['phone'])) continue;
        if ($ch === 'email' && (!$r['email'] || !mailConfigured($db))) continue;
        $tpl = $fixed ?: pickTemplate($db, $purpose, $ch, $r['lang']);
        if (!$tpl || ($ch === 'whatsapp' && ($tpl['wa_status'] !== 'approved' || !$tpl['wa_template_name']))) continue;
        $vars = ['first_name' => $r['first_name'], 'clinic' => clinicName()] + ($opt['vars'] ?? []);
        if (!$tpl['contains_health_info']) foreach (MSG_HEALTH_VARS as $hv) unset($vars[$hv]);   // never leaks into a template not cleared for it
        $body = fillTemplate($tpl['body'], $vars);
        $to = $ch === 'email' ? $r['email'] : $r['phone'];
        try {
            $db->prepare("INSERT INTO messages (channel, direction, patient_id, lead_id, appointment_id, to_from, template_id, body, status, automation_key, idempotency_key, staff_id) VALUES (?, 'out', ?,?,?,?,?,?, 'queued', ?, ?, ?)")
               ->execute([$ch, $r['patient_id'], $r['lead_id'], $opt['appointment_id'] ?? null, $to, $tpl['id'], $body, $opt['automation'] ?? null, $key, currentStaffId() ?: null]);
        } catch (PDOException $e) {
            if ($e->getCode() === '23000') return null;   // another run took this idempotency key a moment ago
            throw $e;
        }
        $mid = (int)$db->lastInsertId();
        try {
            $pid = deliver($db, ['channel' => $ch, 'to' => $to, 'body' => $body, 'subject' => $tpl['subject'], 'wa_template' => $tpl['wa_template_name'], 'wa_language' => $tpl['language'],
                                 'params' => array_map(fn($v) => $vars[$v] ?? '', json_decode((string)$tpl['variables'], true) ?: templateVars($tpl['body']))]);
            $db->prepare("UPDATE messages SET status = 'sent', provider_id = ?, sent_at = NOW() WHERE id = ?")->execute([$pid ?: null, $mid]);
        } catch (Throwable $e) {
            $db->prepare("UPDATE messages SET status = 'failed', error = ? WHERE id = ?")->execute([mb_substr($e->getMessage(), 0, 500), $mid]);
        }
        return $mid;
    }
    return $skip('No channel available (WhatsApp opt-in/connection/approved template, SMS provider or email).');
}

/** A free-text reply from the inbox. WhatsApp allows it only within 24 hours of the patient's last message. */
function sendReply(PDO $db, string $channel, string $to, string $text, ?int $patientId, ?int $leadId): int {
    if (!can('messages.send')) throw new RuntimeException('You don’t have permission to send messages.');
    $text = trim($text);
    if ($text === '' || mb_strlen($text) > 1000) throw new RuntimeException('Write the message (1000 characters at most).');
    if ($channel === 'whatsapp') {
        $s = $db->prepare("SELECT MAX(created_at) FROM messages WHERE channel = 'whatsapp' AND direction = 'in' AND to_from = ?"); $s->execute([$to]);
        $last = $s->fetchColumn();
        if (!$last || strtotime($last) < time() - 86400) throw new RuntimeException('WhatsApp only allows free text within 24 hours of the patient’s last message. Send a template instead.');
        if (!waReady($db)) throw new RuntimeException('WhatsApp is not connected.');
    }
    $db->prepare("INSERT INTO messages (channel, direction, patient_id, lead_id, to_from, body, status, staff_id) VALUES (?, 'out', ?, ?, ?, ?, 'queued', ?)")->execute([$channel, $patientId, $leadId, $to, $text, currentStaffId()]);
    $mid = (int)$db->lastInsertId();
    try {
        $pid = deliver($db, ['channel' => $channel, 'to' => $to, 'body' => $text, 'subject' => clinicName()]);
        $db->prepare("UPDATE messages SET status = 'sent', provider_id = ?, sent_at = NOW() WHERE id = ?")->execute([$pid ?: null, $mid]);
    } catch (Throwable $e) {
        $db->prepare("UPDATE messages SET status = 'failed', error = ? WHERE id = ?")->execute([mb_substr($e->getMessage(), 0, 500), $mid]);
        throw new RuntimeException('Not sent: ' . $e->getMessage());
    }
    return $mid;
}

// ── WhatsApp webhook ──

/** Checks Meta's X-Hub-Signature-256 against the app secret. */
function waSignatureValid(PDO $db, string $raw, string $header): bool {
    $secret = waConfig($db)['app_secret'];
    return $secret !== '' && hash_equals('sha256=' . hash_hmac('sha256', $raw, $secret), $header);
}

/** Message statuses (sent / delivered / read / failed) and incoming messages from the webhook. Returns [statuses, incoming]. */
function waHandleWebhook(PDO $db, array $payload): array {
    $n = [0, 0];
    $rank = ['queued' => 0, 'sent' => 1, 'delivered' => 2, 'read' => 3, 'failed' => 4];
    foreach ($payload['entry'] ?? [] as $entry) foreach ($entry['changes'] ?? [] as $chg) {
        $v = $chg['value'] ?? [];
        foreach ($v['statuses'] ?? [] as $st) {
            $s = (string)($st['status'] ?? ''); if (!isset($rank[$s])) continue;
            $m = $db->prepare("SELECT id, status FROM messages WHERE provider_id = ?"); $m->execute([(string)($st['id'] ?? '')]);
            if (!($row = $m->fetch()) || ($rank[$row['status']] ?? 0) >= $rank[$s]) continue;   // statuses can arrive out of order
            $db->prepare("UPDATE messages SET status = ?, status_at = NOW(), error = ? WHERE id = ?")->execute([$s, $s === 'failed' ? mb_substr((string)($st['errors'][0]['title'] ?? 'failed'), 0, 500) : null, $row['id']]);
            $n[0]++;
        }
        foreach ($v['messages'] ?? [] as $in) {
            $from = '+' . preg_replace('/\D/', '', (string)($in['from'] ?? ''));
            $text = (string)($in['text']['body'] ?? $in['button']['text'] ?? ('[' . ($in['type'] ?? 'message') . ']'));
            [$pid, $lid] = msgMatchPhone($db, $from);
            $db->prepare("INSERT IGNORE INTO messages (channel, direction, patient_id, lead_id, to_from, body, status, provider_id) VALUES ('whatsapp', 'in', ?, ?, ?, ?, 'received', ?)")
               ->execute([$pid, $lid, $from, mb_substr($text, 0, 4000), (string)($in['id'] ?? '') ?: null]);
            $n[1]++;
        }
    }
    return $n;
}

/** The patient (or lead) with this phone number: [patient_id, lead_id]. */
function msgMatchPhone(PDO $db, string $phone): array {
    $s = $db->prepare("SELECT pp.patient_id FROM patient_phones pp JOIN patients p ON p.id = pp.patient_id AND p.status <> 'merged' WHERE pp.phone = ? ORDER BY pp.is_primary DESC LIMIT 1");
    $s->execute([$phone]);
    if ($pid = $s->fetchColumn()) return [(int)$pid, null];
    $s = $db->prepare("SELECT id FROM leads WHERE phone = ? ORDER BY id DESC LIMIT 1"); $s->execute([$phone]);
    return [null, ($lid = $s->fetchColumn()) ? (int)$lid : null];
}

// ── Self-service links ──

/** The clinic's web address for links in messages: APP_URL, else the one saved on the Messaging page (cron has no request). */
function appUrl(): string {
    $u = defined('APP_URL') && APP_URL ? APP_URL : msgSetting(getDB(), 'base_url');
    if ($u === '' && isset($_SERVER['HTTP_HOST'])) $u = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' ? 'https://' : 'http://') . $_SERVER['HTTP_HOST'];
    return rtrim($u ?: 'http://localhost', '/');
}

/** A fresh link for the patient to confirm, cancel or ask to reschedule (older links for the visit stop working). */
function appointmentSelfLink(PDO $db, int $apptId): string {
    $t = bin2hex(random_bytes(24));
    $db->prepare("UPDATE appointments SET self_token_hash = ? WHERE id = ?")->execute([hash('sha256', $t), $apptId]);
    return appUrl() . '/s.php?t=' . $t;
}

function appointmentFromSelfToken(PDO $db, string $t): ?array {
    if (!preg_match('/^[a-f0-9]{48}$/', $t)) return null;
    $s = $db->prepare("SELECT a.*, b.name AS branch_name FROM appointments a LEFT JOIN branches b ON b.id = a.branch_id WHERE a.self_token_hash = ?");
    $s->execute([hash('sha256', $t)]);
    return $s->fetch() ?: null;
}

/** What the patient may still do: confirm, cancel (until the cut-off before the visit), ask to reschedule. */
function selfServiceActions(PDO $db, array $a): array {
    if (!in_array($a['status'], ['booked', 'confirmed'], true) || strtotime($a['scheduled_at']) < time()) return [];
    $cut = (int)msgSetting($db, 'cancel_cutoff_hours', '24');
    $out = [];
    if ($a['status'] === 'booked') $out[] = 'confirm';
    if (strtotime($a['scheduled_at']) - time() >= $cut * 3600) $out[] = 'cancel';
    if (!$a['reschedule_requested_at']) $out[] = 'reschedule';
    return $out;
}

function selfServiceAct(PDO $db, array $a, string $action): void {
    if (!in_array($action, selfServiceActions($db, $a), true)) throw new RuntimeException('This can no longer be done online. Please call the clinic.');
    $id = (int)$a['id'];
    if ($action === 'confirm') {
        $db->prepare("UPDATE appointments SET status = 'confirmed', confirmed_at = NOW() WHERE id = ? AND status = 'booked'")->execute([$id]);
        $db->prepare("INSERT INTO appointment_status_history (appointment_id, from_status, to_status, staff_id) VALUES (?, 'booked', 'confirmed', NULL)")->execute([$id]);
    } elseif ($action === 'cancel') {
        cancelAppointment($db, $id, 'cancelled', 'Cancelled by the patient (online link)');
        staffNotify($db, 'appointments.view', 'cancelled_online', 'Appointment cancelled online', date('D d M, H:i', strtotime($a['scheduled_at'])), '/pages/appointment.php?id=' . $id);
    } else {
        $db->prepare("UPDATE appointments SET reschedule_requested_at = NOW() WHERE id = ?")->execute([$id]);
        staffNotify($db, 'appointments.view', 'reschedule_request', 'A patient asked to reschedule', date('D d M, H:i', strtotime($a['scheduled_at'])), '/pages/appointment.php?id=' . $id);
    }
    auditLog($db, 'appointment', $id, 'self_' . $action, []);
}

function staffNotify(PDO $db, ?string $permission, string $kind, string $title, string $body = '', string $link = ''): void {
    $db->prepare("INSERT INTO staff_notifications (permission, kind, title, body, link) VALUES (?,?,?,?,?)")->execute([$permission, $kind, mb_substr($title, 0, 200), mb_substr($body, 0, 500) ?: null, $link ?: null]);
}

// ── Feedback ──

/** A feedback request for a completed visit: returns the link (one per visit). */
function feedbackLink(PDO $db, int $apptId, ?int $patientId): string {
    $t = bin2hex(random_bytes(24));
    $db->prepare("INSERT INTO feedback (appointment_id, patient_id, token_hash) VALUES (?,?,?) ON DUPLICATE KEY UPDATE token_hash = IF(submitted_at IS NULL, VALUES(token_hash), token_hash)")->execute([$apptId, $patientId, hash('sha256', $t)]);
    return appUrl() . '/f.php?t=' . $t;
}

function feedbackFromToken(PDO $db, string $t): ?array {
    if (!preg_match('/^[a-f0-9]{48}$/', $t)) return null;
    $s = $db->prepare("SELECT * FROM feedback WHERE token_hash = ?"); $s->execute([hash('sha256', $t)]);
    return $s->fetch() ?: null;
}

/** Saves a score 0–10 and comment, once. A low score (6 or less) alerts the managers. */
function submitFeedback(PDO $db, array $f, int $score, string $comment): void {
    if ($f['submitted_at']) throw new RuntimeException('Thank you, we already have your feedback.');
    if ($score < 0 || $score > 10) throw new RuntimeException('Choose a score from 0 to 10.');
    $db->prepare("UPDATE feedback SET score = ?, comment = ?, submitted_at = NOW() WHERE id = ? AND submitted_at IS NULL")->execute([$score, mb_substr(trim($comment), 0, 1000) ?: null, $f['id']]);
    if ($score <= 6) staffNotify($db, 'setup.clinic', 'low_feedback', "Low feedback score: $score / 10", mb_substr(trim($comment), 0, 300), '/pages/patient.php?id=' . (int)$f['patient_id']);
}

// ── Public page protection ──

/** Counts hits per bucket in a time window; false once over the limit. */
function rateLimitOk(PDO $db, string $bucket, int $max, int $seconds): bool {
    $db->prepare("INSERT INTO rate_limits (bucket, hits, window_start) VALUES (?, 1, NOW())
                  ON DUPLICATE KEY UPDATE hits = IF(window_start < NOW() - INTERVAL ? SECOND, 1, hits + 1), window_start = IF(window_start < NOW() - INTERVAL ? SECOND, NOW(), window_start)")
       ->execute([mb_substr($bucket, 0, 120), $seconds, $seconds]);
    $s = $db->prepare("SELECT hits FROM rate_limits WHERE bucket = ?"); $s->execute([mb_substr($bucket, 0, 120)]);
    return (int)$s->fetchColumn() <= $max;
}

// ── Automations (cron, every 5 minutes) ──

function automationsActive(PDO $db): array {
    ensureDefaultTemplates($db);
    $out = [];
    foreach ($db->query("SELECT * FROM automations WHERE is_active = 1") as $a) $out[$a['key']] = json_decode((string)$a['config'], true) ?: [];
    return $out;
}

/** Runs every active automation once; idempotency keys make repeated runs send nothing new. Returns [key => sent]. */
function runAutomations(PDO $db, ?DateTimeImmutable $now = null): array {
    if (!planFeature('automations')) return [];   // not in the clinic's plan
    $now ??= new DateTimeImmutable('now');
    $ts = $now->format('Y-m-d H:i:s');
    $active = automationsActive($db);
    $out = [];
    $tz = new DateTimeZone(CLINIC_TZ);
    $apptVars = function (array $a) use ($db, $tz) {
        $at = (new DateTimeImmutable($a['scheduled_at']))->setTimezone($tz);
        return ['date' => $at->format('D j M'), 'time' => $at->format('g:i A'), 'branch' => (string)$a['branch_name'], 'map_link' => msgSetting($db, 'map_link'),
                'link' => appointmentSelfLink($db, (int)$a['id']), 'service' => (string)$a['service_name'], 'practitioner' => (string)$a['practitioner_name']];
    };
    $appts = function (string $where, array $args) use ($db) {
        $s = $db->prepare("SELECT a.*, b.name AS branch_name, s.name AS service_name, st.name AS practitioner_name FROM appointments a LEFT JOIN branches b ON b.id = a.branch_id
                           LEFT JOIN services s ON s.id = a.service_id LEFT JOIN staff st ON st.id = a.practitioner_id WHERE $where LIMIT 500");
        $s->execute($args);
        return $s->fetchAll();
    };
    // $vars is built only when the message is really due: building it makes new links, which would break ones already sent
    $send = function (string $key, string $purpose, ?int $pid, ?int $lid, string $idem, callable $vars, ?int $appt = null) use ($db, &$out) {
        $s = $db->prepare("SELECT 1 FROM messages WHERE idempotency_key = ?"); $s->execute(["$key:$idem"]);
        if ($s->fetchColumn()) return;
        $r = sendTemplateMessage($db, $purpose, $pid, $lid, ['idempotency' => "$key:$idem", 'automation' => $key, 'vars' => $vars(), 'appointment_id' => $appt]);
        if ($r) $out[$key] = ($out[$key] ?? 0) + 1;
    };
    $open = "a.status IN ('booked','confirmed')";
    if (isset($active['confirmation']))
        foreach ($appts("$open AND a.created_at >= ? - INTERVAL 2 DAY AND a.scheduled_at > ?", [$ts, $ts]) as $a) $send('confirmation', 'confirmation', $a['patient_id'], $a['lead_id'], 'appt:' . $a['id'], fn() => $apptVars($a), (int)$a['id']);
    if (isset($active['reminder_24h']))   // within the next 24 hours but more than 3 hours away (the 2-hour reminder covers the rest)
        foreach ($appts("$open AND a.scheduled_at <= ? + INTERVAL 24 HOUR AND a.scheduled_at > ? + INTERVAL 3 HOUR", [$ts, $ts]) as $a) $send('reminder_24h', 'reminder_24h', $a['patient_id'], $a['lead_id'], 'appt:' . $a['id'], fn() => $apptVars($a), (int)$a['id']);
    if (isset($active['reminder_2h']))
        foreach ($appts("$open AND a.scheduled_at <= ? + INTERVAL 2 HOUR AND a.scheduled_at > ?", [$ts, $ts]) as $a) $send('reminder_2h', 'reminder_2h', $a['patient_id'], $a['lead_id'], 'appt:' . $a['id'], fn() => $apptVars($a), (int)$a['id']);
    if (isset($active['feedback'])) {
        $h = max(1, (int)($active['feedback']['hours_after'] ?? 3));
        foreach ($appts("a.status = 'completed' AND a.completed_at <= ? - INTERVAL $h HOUR AND a.completed_at > ? - INTERVAL 3 DAY", [$ts, $ts]) as $a)
            $send('feedback', 'feedback', $a['patient_id'], $a['lead_id'], 'appt:' . $a['id'], fn() => ['link' => feedbackLink($db, (int)$a['id'], $a['patient_id'] ? (int)$a['patient_id'] : null)], (int)$a['id']);
    }
    if (isset($active['package_expiry']))
        foreach ([30, 7] as $d) {
            $s = $db->prepare("SELECT id, patient_id, expires_on FROM patient_packages WHERE status = 'active' AND expires_on = DATE(?) + INTERVAL $d DAY"); $s->execute([$ts]);
            foreach ($s as $p) $send('package_expiry', 'package_expiry', (int)$p['patient_id'], null, "pkg:{$p['id']}:$d", fn() => ['date' => (new DateTimeImmutable($p['expires_on']))->format('j M Y'), 'link' => msgSetting($db, 'booking_link')]);
        }
    if (isset($active['recall'])) {
        $s = $db->prepare("SELECT r.id, r.patient_id FROM recalls r JOIN recall_rules rr ON rr.id = r.rule_id AND rr.send_message = 1 WHERE r.status = 'open' AND r.due_on >= DATE(?) - INTERVAL 30 DAY"); $s->execute([$ts]);
        foreach ($s as $r) $send('recall', 'recall', (int)$r['patient_id'], null, 'recall:' . $r['id'], fn() => ['link' => msgSetting($db, 'booking_link')]);
    }
    if (isset($active['birthday']) && !inQuietHours($db, $now)) {
        $md = $now->setTimezone($tz)->format('m-d');
        $s = $db->prepare("SELECT id FROM patients WHERE status = 'active' AND marketing_consent = 1 AND DATE_FORMAT(dob, '%m-%d') = ?"); $s->execute([$md]);
        foreach ($s->fetchAll(PDO::FETCH_COLUMN) as $pid) $send('birthday', 'birthday', (int)$pid, null, "pt:$pid:" . $now->format('Y'), fn() => []);
    }
    return $out;
}

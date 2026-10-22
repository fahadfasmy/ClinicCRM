<?php
// includes/lead_email.php — "we'll call you" email to new Meta leads that gave an email address.
// Sent through the CRM mailer (Admin → Email). Queued per enquiry (never twice), sent straight after the lead
// is saved, retried by the hourly cron for 24 hours. Switched on/off on the Meta Leads page (meta.mail_enabled).
require_once __DIR__ . '/meta_leads.php';
require_once __DIR__ . '/mailer.php';

/** The clinic details the email shows, from Admin → Clinic profile. */
function leadEmailClinic(): array {
    $wa = tenantSetting('clinic.whatsapp');
    return ['name' => tenantSetting('brand.name'), 'phone' => tenantSetting('clinic.phone'), 'whatsapp' => $wa,
            'wa_link' => 'https://wa.me/' . preg_replace('/\D/', '', $wa), 'logo' => tenantSetting('clinic.logo_url'),
            'address' => tenantSetting('clinic.address')];
}

/** The email names the clinic and its numbers, so it is only sent once the profile has them. */
function leadEmailReady(): bool {
    $c = leadEmailClinic();
    return $c['name'] !== '' && $c['phone'] !== '' && $c['whatsapp'] !== '' && $c['address'] !== '';
}

function ensureLeadEmailSchema(PDO $db): void {
    static $done = false;
    if ($done) return;
    $db->exec("CREATE TABLE IF NOT EXISTS lead_emails (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        lead_id INT UNSIGNED NOT NULL,
        ref VARCHAR(60) NOT NULL,
        kind VARCHAR(20) NOT NULL,
        to_email VARCHAR(190) NOT NULL,
        status ENUM('queued','sent','error','expired') NOT NULL DEFAULT 'queued',
        attempts TINYINT UNSIGNED NOT NULL DEFAULT 0,
        error VARCHAR(500) NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        sent_at DATETIME NULL,
        UNIQUE KEY uq_lead_ref (lead_id, ref),
        KEY idx_status (status)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $done = true;
}

/**
 * When the team will call, from the clinic's calling hours (11:00 AM – 9:30 PM, Dubai time).
 * Enquiries in the last 30 minutes are promised tomorrow, so the email never promises a call that can't happen.
 * Returns ['subject' => short phrase, 'line' => sentence for the email].
 */
function callbackPromise(DateTimeImmutable $now): array {
    $now = $now->setTimezone(new DateTimeZone('Asia/Dubai'));
    $mins = (int)$now->format('G') * 60 + (int)$now->format('i');
    if ($mins < 11 * 60) {
        return ['subject' => "we'll call you today from 11:00 AM", 'line' => 'will call you <strong>today, from 11:00 AM</strong>'];
    }
    if ($mins < 21 * 60) {
        return ['subject' => "we'll call you shortly", 'line' => 'will call you <strong>shortly today</strong>'];
    }
    $tomorrow = $now->modify('+1 day')->format('l j F');
    return ['subject' => "we'll call you tomorrow from 11:00 AM", 'line' => "will call you <strong>tomorrow, $tomorrow, from 11:00 AM</strong>"];
}

/** First name for "Dear …", or null when the lead has no real name (e.g. "Meta lead +9715…"). */
function leadEmailFirstName(string $name): ?string {
    $name = trim($name);
    if ($name === '' || preg_match('/^(meta lead|whatsapp)\b/i', $name) || preg_match('/^[\d\s+\-().?]+$/', $name)) return null;
    $first = preg_split('/\s+/u', $name)[0];
    return mb_strtoupper(mb_substr($first, 0, 1)) . mb_substr($first, 1);
}

/** Builds the email. $lead needs name and phone; $treatments are names. Returns [subject, html, text]. */
function buildLeadEmail(array $lead, array $treatments, bool $returning, DateTimeImmutable $now): array {
    $e = fn($v) => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
    $first = leadEmailFirstName((string)$lead['name']);
    $treat = count($treatments) === 0 ? null
           : (count($treatments) === 1 ? $treatments[0] : implode(', ', array_slice($treatments, 0, -1)) . ' and ' . end($treatments));
    $digits = preg_replace('/\D/', '', (string)($lead['phone'] ?? ''));
    $promise = callbackPromise($now);
    $c = leadEmailClinic();
    [$name, $phone, $wa, $waLink, $address] = [$e($c['name']), $e($c['phone']), $e($c['whatsapp']), $e($c['wa_link']), $e($c['address'])];

    $subject = ($first ? "Thank you, $first" : 'Thank you') . ' – ' . $promise['subject'] . ' | ' . $c['name'];
    $greeting = $first ? 'Dear ' . $e($first) . ',' : 'Hello,';
    $intro = ($returning ? "Thank you for getting in touch with $name again" : 'Thank you for your enquiry')
           . ($treat ? ' about <strong>' . $e($treat) . '</strong>' : '') . ($returning ? '' : " at $name")
           . '. We’ve received your details.';
    $callLine = 'A member of our patient care team ' . $promise['line']
              . (strlen($digits) >= 4 ? ', on your number ending in <strong>' . substr($digits, -4) . '</strong>' : '') . '.';
    $about = $treat ? $e($treat) : 'our treatments';

    $p = 'margin:0 0 16px;font-size:15px;line-height:1.6;color:#2b2622';
    $html = '<!DOCTYPE html><html><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1"></head>'
      . '<body style="margin:0;padding:0;background:#f5f1ec;font-family:Arial,Helvetica,sans-serif">'
      . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f5f1ec;padding:24px 12px"><tr><td align="center">'
      . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:560px;background:#ffffff;border-radius:12px;overflow:hidden">'
      . ($c['logo'] !== '' ? '<tr><td align="center" style="padding:28px 24px 8px"><img src="' . $e($c['logo']) . '" width="72" height="72" alt="' . $name . '" style="display:block;border:0;border-radius:50%"></td></tr>' : '<tr><td style="padding:12px"></td></tr>')
      . '<tr><td style="padding:16px 32px 8px">'
      . "<p style=\"$p\">$greeting</p>"
      . "<p style=\"$p\">$intro</p>"
      . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin:0 0 20px;background:#faf6f1;border-left:4px solid #b08d57;border-radius:6px"><tr><td style="padding:16px 18px">'
      . "<p style=\"margin:0 0 8px;font-size:15px;line-height:1.6;color:#2b2622\">&#128222; $callLine</p>"
      . '<p style="margin:0 0 4px;font-size:13px;line-height:1.5;color:#6b625a">Our call will come from <strong>' . $phone . '</strong>.</p>'
      . '<p style="margin:0;font-size:13px;line-height:1.5;color:#6b625a">Our team calls between 11:00 AM and 9:30 PM (UAE time).</p>'
      . '</td></tr></table>'
      . '<p style="margin:0 0 8px;font-size:15px;line-height:1.6;color:#2b2622"><strong>What to expect from the call (about 5–10 minutes):</strong></p>'
      . '<ul style="margin:0 0 16px;padding-left:20px;font-size:15px;line-height:1.6;color:#2b2622">'
      . "<li>We’ll take a few minutes to understand what you’d like to achieve and answer your questions about $about.</li>"
      . '<li>We’ll explain the options, what results to expect, and the pricing.</li>'
      . '<li>If you’d like, we’ll book a consultation at a time that suits you. There’s no obligation.</li></ul>'
      . "<p style=\"$p\"><strong>Prefer a particular time, or would you rather chat on WhatsApp?</strong> Simply reply to this email or message us on "
      . '<a href="' . $waLink . '" style="color:#8a6a3a">WhatsApp at ' . $wa . '</a>.</p>'
      . "<p style=\"$p\">If we miss you, we’ll follow up on WhatsApp.</p>"
      . "<p style=\"$p\">We look forward to speaking with you.</p>"
      . "<p style=\"$p\">Warm regards,<br><strong>The Patient Care Team</strong><br>$name</p>"
      . '</td></tr>'
      . '<tr><td style="padding:18px 32px;background:#2b2622;color:#e9e2d9;font-size:13px;line-height:1.6">'
      . '<strong style="color:#ffffff">' . $name . '</strong><br>' . $address . '<br>Open daily<br>'
      . '&#128222; ' . $phone . ' &nbsp;·&nbsp; <a href="' . $waLink . '" style="color:#e0c48f">WhatsApp ' . $wa . '</a>'
      . '</td></tr></table>'
      . '<p style="max-width:560px;margin:14px auto 0;font-size:12px;line-height:1.5;color:#8c837a;text-align:center">You’re receiving this email because you submitted an enquiry to ' . $name . '. If this wasn’t you, reply and we’ll remove your details.</p>'
      . '</td></tr></table></body></html>';

    $plain = fn($s) => html_entity_decode(strip_tags($s), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $text = ($first ? "Dear $first," : 'Hello,') . "\n\n"
          . $plain($intro) . "\n\n"
          . $plain($callLine) . "\n"
          . 'Our call will come from ' . $c['phone'] . ".\n"
          . "Our team calls between 11:00 AM and 9:30 PM (UAE time).\n\n"
          . "What to expect from the call (about 5–10 minutes):\n"
          . '- We’ll take a few minutes to understand what you’d like to achieve and answer your questions about ' . ($treat ?? 'our treatments') . ".\n"
          . "- We’ll explain the options, what results to expect, and the pricing.\n"
          . "- If you’d like, we’ll book a consultation at a time that suits you. There’s no obligation.\n\n"
          . 'Prefer a particular time, or would you rather chat on WhatsApp? Simply reply to this email or message us on WhatsApp at ' . $c['whatsapp'] . ".\n\n"
          . "If we miss you, we’ll follow up on WhatsApp.\n\n"
          . "We look forward to speaking with you.\n\n"
          . "Warm regards,\nThe Patient Care Team\n{$c['name']}\n\n"
          . $c['address'] . "\nOpen daily\n" . $c['phone'] . ' · WhatsApp ' . $c['whatsapp'] . "\n\n"
          . 'You’re receiving this email because you submitted an enquiry to ' . $c['name'] . '. If this wasn’t you, reply and we’ll remove your details.';
    return [$subject, $html, $text];
}

/** Queues the email for one enquiry (only when sending is on and the address is valid). $ref = the Meta lead id. */
function queueLeadEmail(PDO $db, int $leadId, string $ref, string $email, bool $returning): void {
    if (metaSetting($db, 'mail_enabled') !== '1' || !mailConfigured($db) || !leadEmailReady() || !filter_var($email, FILTER_VALIDATE_EMAIL)) return;
    ensureLeadEmailSchema($db);
    $db->prepare("INSERT IGNORE INTO lead_emails (lead_id, ref, kind, to_email) VALUES (?,?,?,?)")
       ->execute([$leadId, $ref, $returning ? 'returning' : 'welcome', mb_substr($email, 0, 190)]);
}

/** Sends queued emails; failed ones retry (5 tries) until they are 24 hours old. Returns ['sent' => n, 'failed' => n]. */
function sendQueuedLeadEmails(PDO $db): array {
    $out = ['sent' => 0, 'failed' => 0];
    if (metaSetting($db, 'mail_enabled') !== '1' || !mailConfigured($db)) return $out;
    ensureLeadEmailSchema($db);
    $db->exec("UPDATE lead_emails SET status = 'expired' WHERE status IN ('queued','error') AND created_at < NOW() - INTERVAL 24 HOUR");
    $rows = $db->query("SELECT e.*, l.name, l.phone FROM lead_emails e JOIN leads l ON l.id = e.lead_id
                        WHERE e.status IN ('queued','error') AND e.attempts < 5 ORDER BY e.id LIMIT 20")->fetchAll();
    foreach ($rows as $r) {
        // Claim the row first, so a webhook and the cron running together can't both send it
        $claim = $db->prepare("UPDATE lead_emails SET attempts = attempts + 1 WHERE id = ? AND attempts = ?");
        $claim->execute([$r['id'], $r['attempts']]);
        if (!$claim->rowCount()) continue;
        $t = $db->prepare("SELECT t.name FROM lead_treatments lt JOIN treatments t ON t.id = lt.treatment_id WHERE lt.lead_id = ? ORDER BY t.name");
        $t->execute([$r['lead_id']]);
        [$subject, $html, $text] = buildLeadEmail($r, $t->fetchAll(PDO::FETCH_COLUMN), $r['kind'] === 'returning', new DateTimeImmutable());
        try {
            sendEmail($db, $r['to_email'], $subject, $html, $text, 'Call-back email', (int)$r['lead_id']);
            $db->prepare("UPDATE lead_emails SET status = 'sent', sent_at = NOW(), error = NULL WHERE id = ?")->execute([$r['id']]);
            $note = '✉ Call-back email sent to ' . $r['to_email'] . ' · ' . date('d M Y, h:i A') . ' (“' . $subject . '”)';
            $db->prepare("UPDATE leads SET notes = CONCAT(COALESCE(notes, ''), IF(COALESCE(notes, '') = '', '', '\n\n'), ?) WHERE id = ?")->execute([$note, $r['lead_id']]);
            metaActivity($db, 'email_sent', (int)$r['lead_id'], 'Call-back email to ' . $r['to_email']);
            $out['sent']++;
        } catch (Throwable $e) {
            $db->prepare("UPDATE lead_emails SET status = 'error', error = ? WHERE id = ?")->execute([mb_substr($e->getMessage(), 0, 500), $r['id']]);
            $out['failed']++;
            $out['error'] = $e->getMessage();
        }
    }
    if ($rows) metaSaveSetting($db, 'last_mail', json_encode(['at' => time()] + $out, JSON_UNESCAPED_UNICODE));
    return $out;
}

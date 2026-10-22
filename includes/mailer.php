<?php
// includes/mailer.php — the CRM's outgoing email. One mailbox (Admin → Email settings), used by every feature:
//   sendEmail($db, $to, $subject, $html, $text, 'what it is for', $leadId)
// Talks SMTP directly (SSL on 465, STARTTLS on 587, or plain for a local test server) and logs every email.
require_once __DIR__ . '/db.php';

const MAIL_DEFAULTS = ['host' => 'smtp.hostinger.com', 'port' => '465', 'security' => 'ssl', 'reply_to' => ''];

/** A setting left blank on the Email page falls back to the clinic profile (clinic email, clinic name). */
function mailDefault(string $k): string {
    return match ($k) {
        'username', 'from_email' => tenantSetting('clinic.email'),
        'from_name' => tenantSetting('brand.name'),
        default => MAIL_DEFAULTS[$k] ?? '',
    };
}

function ensureMailSchema(PDO $db): void {
    static $done = false;
    if ($done) return;
    $opts = "ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
    $db->exec("CREATE TABLE IF NOT EXISTS integration_settings (k VARCHAR(60) PRIMARY KEY, v TEXT NULL,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP) $opts");
    $db->exec("CREATE TABLE IF NOT EXISTS email_log (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        to_email VARCHAR(190) NOT NULL,
        subject VARCHAR(255) NOT NULL,
        context VARCHAR(60) NOT NULL DEFAULT '',
        lead_id INT UNSIGNED NULL,
        status ENUM('sent','error') NOT NULL,
        error VARCHAR(500) NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        KEY idx_created (created_at), KEY idx_lead (lead_id)
    ) $opts");
    $done = true;
}

/** A mail setting (mail.* in integration_settings), falling back to MAIL_DEFAULTS. */
function mailSetting(PDO $db, string $k): string {
    static $cache = null;
    if ($cache === null || $k === '__reload') {
        ensureMailSchema($db);
        $cache = $db->query("SELECT k, v FROM integration_settings WHERE k LIKE 'mail.%'")->fetchAll(PDO::FETCH_KEY_PAIR);
        if ($k === '__reload') return '';
    }
    $v = $cache["mail.$k"] ?? '';
    return $v !== '' ? $v : mailDefault($k);
}

function mailSaveSetting(PDO $db, string $k, string $v): void {
    ensureMailSchema($db);
    $db->prepare("REPLACE INTO integration_settings (k, v) VALUES (?, ?)")->execute(["mail.$k", $v]);
    mailSetting($db, '__reload');
}

/** True once a password is saved (everything else has defaults). */
function mailConfigured(PDO $db): bool {
    return mailSetting($db, 'password') !== '';
}

/**
 * Sends one email and logs it. $text is the plain-text version (made from the HTML when empty).
 * Throws RuntimeException with a readable reason on failure (also logged).
 */
function sendEmail(PDO $db, string $to, string $subject, string $html, string $text = '', string $context = '', ?int $leadId = null): void {
    $to = trim($to);
    $subject = trim(str_replace(["\r", "\n"], ' ', $subject));
    try {
        if (!filter_var($to, FILTER_VALIDATE_EMAIL)) throw new RuntimeException("Not a valid email address: $to");
        if (!mailConfigured($db)) throw new RuntimeException('Email isn’t set up yet. An admin can add the mailbox password in Admin → Email.');
        if ($text === '') $text = trim(html_entity_decode(strip_tags(preg_replace(['/<(br|\/p|\/li|\/tr|\/h\d)\b[^>]*>/i', '/<(head|style)\b.*?<\/\1>/is'], ["\n", ''], $html)), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        smtpDeliver($db, $to, $subject, $html, $text);
        $status = 'sent'; $err = null;
    } catch (Throwable $e) {
        $status = 'error'; $err = $e->getMessage();
    }
    ensureMailSchema($db);
    $db->prepare("INSERT INTO email_log (to_email, subject, context, lead_id, status, error) VALUES (?,?,?,?,?,?)")
       ->execute([mb_substr($to, 0, 190), mb_substr($subject, 0, 255), mb_substr($context, 0, 60), $leadId, $status, $err === null ? null : mb_substr($err, 0, 500)]);
    if ($err !== null) throw new RuntimeException($err);
}

/** The SMTP conversation. Throws on any refusal. */
function smtpDeliver(PDO $db, string $to, string $subject, string $html, string $text): void {
    $c = [];
    foreach (['host', 'port', 'security', 'from_email', 'from_name', 'username', 'password', 'reply_to'] as $k) $c[$k] = mailSetting($db, $k);
    smtpSendWith($c, $to, $subject, $html, $text);
}

/** The SMTP conversation with explicit settings (a clinic's mailbox, or the platform's own for signup and billing emails). */
function smtpSendWith(array $c, string $to, string $subject, string $html, string $text): void {
    $host = $c['host'];
    $port = (int)$c['port'];
    $security = $c['security'];            // ssl | tls (STARTTLS) | none
    $from = $c['from_email'];
    // A local copy only ever talks to the stand-in mail server: tests must never reach a real mailbox with real settings
    if (APP_ENV === 'local' && !in_array($host, ['127.0.0.1', 'localhost'], true)) {
        throw new RuntimeException("Local copy: real mail servers are blocked ($host). Point Admin → Email at the stand-in on 127.0.0.1.");
    }
    require_once __DIR__ . '/data_flows.php';
    outboundGuard($host);
    $sock = @stream_socket_client(($security === 'ssl' ? 'ssl://' : 'tcp://') . "$host:$port", $errno, $errstr, 15);
    if (!$sock) throw new RuntimeException("Can’t reach the mail server $host:$port" . ($errstr ? " ($errstr)" : '') . '. Check the server, port and security.');
    stream_set_timeout($sock, 25);
    $read = function (array $ok) use ($sock): string {
        $all = '';
        while (($line = fgets($sock, 1024)) !== false) { $all .= $line; if (strlen($line) < 4 || $line[3] !== '-') break; }
        if (!in_array((int)substr($all, 0, 3), $ok, true)) throw new RuntimeException('The mail server said: ' . (trim($all) ?: 'nothing (timed out)'));
        return $all;
    };
    $cmd = function (string $c, array $ok) use ($sock, $read): string { fwrite($sock, "$c\r\n"); return $read($ok); };
    try {
        $read([220]);
        $helo = 'EHLO ' . (preg_replace('/[^A-Za-z0-9.-]/', '', $_SERVER['SERVER_NAME'] ?? '') ?: 'localhost');
        $ehlo = $cmd($helo, [250]);
        if ($security === 'tls') {
            $cmd('STARTTLS', [220]);
            if (!stream_socket_enable_crypto($sock, true, STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT | STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT)) throw new RuntimeException('Secure connection (STARTTLS) failed.');
            $ehlo = $cmd($helo, [250]);
        }
        $user = (string)($c['username'] ?? '');
        if ($user !== '') {
            if (!preg_match('/AUTH[ =][^\r\n]*LOGIN/i', $ehlo)) throw new RuntimeException('The mail server doesn’t offer password sign-in on this connection. Use SSL (465) or STARTTLS (587).');
            $cmd('AUTH LOGIN', [334]);
            $cmd(base64_encode($user), [334]);
            try { $cmd(base64_encode((string)($c['password'] ?? '')), [235]); }
            catch (RuntimeException $e) { throw new RuntimeException('The mailbox username or password was refused. ' . $e->getMessage()); }
        }
        $cmd("MAIL FROM:<$from>", [250]);
        $cmd("RCPT TO:<$to>", [250, 251]);
        $cmd('DATA', [354]);
        $b = 'b' . bin2hex(random_bytes(12));
        $replyTo = ($c['reply_to'] ?? '') ?: $from;
        $msg = 'Date: ' . date('r') . "\r\n"
             . 'From: ' . mb_encode_mimeheader((string)($c['from_name'] ?? ''), 'UTF-8', 'B') . " <$from>\r\n"
             . "To: <$to>\r\n"
             . "Reply-To: <$replyTo>\r\n"
             . 'Subject: ' . mb_encode_mimeheader($subject, 'UTF-8', 'B', "\r\n", 9) . "\r\n"
             . 'Message-ID: <' . bin2hex(random_bytes(16)) . '@' . (substr(strrchr($from, '@'), 1) ?: 'localhost') . ">\r\n"
             . "MIME-Version: 1.0\r\nContent-Type: multipart/alternative; boundary=\"$b\"\r\n\r\n"
             . "--$b\r\nContent-Type: text/plain; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n" . chunk_split(base64_encode($text)) . "\r\n"
             . "--$b\r\nContent-Type: text/html; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n" . chunk_split(base64_encode($html)) . "\r\n"
             . "--$b--\r\n";
        // base64 bodies never start a line with ".", so no dot-stuffing is needed
        $cmd($msg . '.', [250]);
        @fwrite($sock, "QUIT\r\n");
    } finally {
        fclose($sock);
    }
}

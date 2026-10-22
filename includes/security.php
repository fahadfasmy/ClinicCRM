<?php
// includes/security.php — sign-in security: lockout after failed attempts, sign-in log, 2-step sign-in by email code
// (with trusted devices and one-time backup codes), password rules, password reset by email, and "sign out everywhere"
// (staff.session_version; requireLogin() signs out any session made before the version changed).
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/mailer.php';

const SEC_LOCK_FAILS    = 5;        // wrong passwords per account …
const SEC_LOCK_MINUTES  = 15;       // … within this window pause that account
const SEC_IP_FAILS      = 20;       // failed sign-ins from one connection within the window
const SEC_CODE_MINUTES  = 10;
const SEC_CODE_TRIES    = 5;
const SEC_TRUST_DAYS    = 30;
const SEC_RESET_MINUTES = 30;
const SEC_TRUST_COOKIE  = 'crm_trusted';

function ensureSecuritySchema(PDO $db): void {
    static $done = false;
    if ($done) return;
    $opts = "ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
    if (!$db->query("SHOW COLUMNS FROM staff LIKE 'session_version'")->fetch()) {
        $db->exec("ALTER TABLE staff ADD COLUMN session_version INT UNSIGNED NOT NULL DEFAULT 0, ADD COLUMN password_changed_at DATETIME NULL");
    }
    $db->exec("CREATE TABLE IF NOT EXISTS login_log (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, staff_id INT UNSIGNED NULL, email VARCHAR(150) NOT NULL,
        ip VARCHAR(45) NOT NULL, user_agent VARCHAR(255) NULL, result VARCHAR(20) NOT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        KEY idx_email (email, created_at), KEY idx_ip (ip, created_at), KEY idx_staff (staff_id, created_at)) $opts");
    $db->exec("CREATE TABLE IF NOT EXISTS login_codes (
        staff_id INT UNSIGNED PRIMARY KEY, code_hash CHAR(64) NOT NULL, expires_at DATETIME NOT NULL,
        tries TINYINT UNSIGNED NOT NULL DEFAULT 0, sent_at DATETIME NOT NULL) $opts");
    $db->exec("CREATE TABLE IF NOT EXISTS trusted_devices (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, staff_id INT UNSIGNED NOT NULL, token_hash CHAR(64) NOT NULL,
        label VARCHAR(255) NULL, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, expires_at DATETIME NOT NULL, last_used DATETIME NULL,
        UNIQUE KEY uq_token (token_hash), KEY idx_staff (staff_id)) $opts");
    $db->exec("CREATE TABLE IF NOT EXISTS backup_codes (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, staff_id INT UNSIGNED NOT NULL, code_hash CHAR(64) NOT NULL,
        used_at DATETIME NULL, KEY idx_staff (staff_id)) $opts");
    $db->exec("CREATE TABLE IF NOT EXISTS password_resets (
        token_hash CHAR(64) PRIMARY KEY, staff_id INT UNSIGNED NOT NULL, expires_at DATETIME NOT NULL, used_at DATETIME NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, KEY idx_staff (staff_id)) $opts");
    $done = true;
}

function secHash(string $v): string { return hash('sha256', $v); }
function secIp(): string { return substr((string)($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45); }
function secAgent(): string { return mb_substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255); }

/** "Chrome on Windows"-style label for a user agent. */
function secDeviceLabel(?string $ua): string {
    $ua = (string)$ua;
    $browser = preg_match('/Edg\//', $ua) ? 'Edge' : (preg_match('/OPR\//', $ua) ? 'Opera' : (preg_match('/Chrome\//', $ua) ? 'Chrome' : (preg_match('/Firefox\//', $ua) ? 'Firefox' : (preg_match('/Safari\//', $ua) ? 'Safari' : 'Browser'))));
    $os = preg_match('/iPhone|iPad/', $ua) ? 'iPhone/iPad' : (preg_match('/Android/', $ua) ? 'Android' : (preg_match('/Windows/', $ua) ? 'Windows' : (preg_match('/Mac OS X/', $ua) ? 'Mac' : (preg_match('/Linux/', $ua) ? 'Linux' : 'unknown device'))));
    return "$browser on $os";
}

function secLog(PDO $db, ?int $staffId, string $email, string $result): void {
    ensureSecuritySchema($db);
    $db->prepare("INSERT INTO login_log (staff_id, email, ip, user_agent, result) VALUES (?,?,?,?,?)")
       ->execute([$staffId, mb_substr(strtolower($email), 0, 150), secIp(), secAgent(), $result]);
}

/** Minutes left on a lockout for this email or connection, or 0 when sign-in is allowed. */
function secLockedMinutes(PDO $db, string $email): int {
    ensureSecuritySchema($db);
    $win = SEC_LOCK_MINUTES;
    $q = $db->prepare("SELECT COUNT(*), MAX(created_at) FROM login_log WHERE email = ? AND result = 'bad_password' AND created_at > NOW() - INTERVAL $win MINUTE");
    $q->execute([strtolower($email)]);
    [$n, $last] = $q->fetch(PDO::FETCH_NUM);
    $q2 = $db->prepare("SELECT COUNT(*), MAX(created_at) FROM login_log WHERE ip = ? AND result IN ('bad_password','bad_code') AND created_at > NOW() - INTERVAL $win MINUTE");
    $q2->execute([secIp()]);
    [$n2, $last2] = $q2->fetch(PDO::FETCH_NUM);
    $until = 0;
    if ($n >= SEC_LOCK_FAILS) $until = max($until, strtotime($last) + $win * 60);
    if ($n2 >= SEC_IP_FAILS) $until = max($until, strtotime($last2) + $win * 60);
    return $until > time() ? (int)ceil(($until - time()) / 60) : 0;
}

/**
 * Whether the email code is needed at sign-in (setting on, email actually works, and — when the admin picked roles —
 * the person's role is one of them; no roles picked = everyone).
 */
function secTwoStepRequired(PDO $db, ?string $role = null): bool {
    if (!mailConfigured($db) || mailSetting($db, 'require_2fa') === '0') return false;
    $roles = secTwoStepRoles($db);
    return !$roles || $role === null || in_array($role, $roles, true);
}

/** The roles that must use 2-step sign-in ([] = every role). */
function secTwoStepRoles(PDO $db): array {
    $r = json_decode(mailSetting($db, 'require_2fa_roles'), true);
    return is_array($r) ? array_values(array_intersect($r, array_keys(ROLES))) : [];
}

/** Minutes without activity before staff are signed out (0 = never). */
function secIdleMinutes(PDO $db): int {
    return max(0, (int)mailSetting($db, 'idle_minutes'));
}

/** Problems with a new password ([] = fine). */
function secPasswordProblems(string $pw, array $staff, ?string $currentHash = null): array {
    $p = [];
    if (mb_strlen($pw) < 10) $p[] = 'Use at least 10 characters.';
    $lower = mb_strtolower($pw);
    $common = ['password', 'password1', 'password123', '1234567890', '0123456789', 'qwertyuiop', 'abcd123456', 'welcome123', 'iloveyou12',
               'admin12345', 'letmein123', 'clinic12345', 'clinic1234', 'qwerty1234', '1q2w3e4r5t', 'aaaaaaaaaa', '1111111111', 'dubai12345'];
    if (in_array($lower, $common, true) || preg_match('/^(.)\1+$/u', $pw)) $p[] = 'That password is too easy to guess.';
    $local = mb_strtolower(strstr((string)($staff['email'] ?? ''), '@', true) ?: '');
    if (($local !== '' && str_contains($lower, $local)) || (!empty($staff['name']) && str_contains($lower, mb_strtolower(preg_split('/\s+/', trim($staff['name']))[0])) && mb_strlen(preg_split('/\s+/', trim($staff['name']))[0]) >= 3))
        $p[] = 'Don’t use your name or email in the password.';
    if ($currentHash && password_verify($pw, $currentHash)) $p[] = 'Choose a password different from your current one.';
    return $p;
}

/** Sets a new password and signs the person out everywhere (other sessions end on their next click). */
function secSetPassword(PDO $db, int $staffId, string $pw): void {
    ensureSecuritySchema($db);
    $db->prepare("UPDATE staff SET password = ?, password_changed_at = NOW(), session_version = session_version + 1 WHERE id = ?")
       ->execute([password_hash($pw, PASSWORD_BCRYPT), $staffId]);
}

/** Ends every session of this person except, optionally, the current one. */
function secSignOutEverywhere(PDO $db, int $staffId, bool $keepThisSession = false): void {
    ensureSecuritySchema($db);
    $db->prepare("UPDATE staff SET session_version = session_version + 1 WHERE id = ?")->execute([$staffId]);
    if ($keepThisSession && (int)($_SESSION['staff_id'] ?? 0) === $staffId) {
        $s = $db->prepare("SELECT session_version FROM staff WHERE id = ?");
        $s->execute([$staffId]);
        $_SESSION['sv'] = (int)$s->fetchColumn();
    }
}

// ── Email code ──

/** Emails a fresh 6-digit code. Throws when it can't be sent, or when one was sent less than a minute ago ($force skips that). */
function secSendCode(PDO $db, array $staff, bool $force = false): void {
    ensureSecuritySchema($db);
    $s = $db->prepare("SELECT sent_at FROM login_codes WHERE staff_id = ?");
    $s->execute([$staff['id']]);
    $sent = $s->fetchColumn();
    if (!$force && $sent && strtotime($sent) > time() - 60) throw new RuntimeException('A code was just sent. Wait a minute before asking for another.');
    $code = str_pad((string)random_int(0, 999999), 6, '0', STR_PAD_LEFT);
    $db->prepare("REPLACE INTO login_codes (staff_id, code_hash, expires_at, tries, sent_at) VALUES (?,?,NOW() + INTERVAL " . SEC_CODE_MINUTES . " MINUTE,0,NOW())")
       ->execute([$staff['id'], secHash($staff['id'] . ':' . $code)]);
    $first = htmlspecialchars(preg_split('/\s+/', trim($staff['name']))[0], ENT_QUOTES);
    sendEmail($db, $staff['email'], 'Your Clinic CRM sign-in code',   // never the code itself: subjects show in the email log
        "<p>Hello $first,</p><p>Your Clinic CRM sign-in code is:</p><p style=\"font-size:28px;font-weight:700;letter-spacing:6px;margin:8px 0 16px\">$code</p>"
        . '<p>It works for ' . SEC_CODE_MINUTES . ' minutes. If you didn’t just try to sign in, someone may know your password: change it on your My account page and tell an admin.</p>'
        . '<p style="color:#888;font-size:12px">' . htmlspecialchars(secDeviceLabel(secAgent()) . ' · ' . secIp()) . '</p>',
        '', 'Sign-in code');
}

/** Checks an email code or a backup code. Returns 'ok', 'bad', 'expired' or 'locked'. */
function secCheckCode(PDO $db, int $staffId, string $input): string {
    ensureSecuritySchema($db);
    $input = preg_replace('/[\s-]/', '', $input);
    // One-time backup code (10 characters)
    if (preg_match('/^[a-z0-9]{10}$/i', $input)) {
        $b = $db->prepare("SELECT id FROM backup_codes WHERE staff_id = ? AND code_hash = ? AND used_at IS NULL");
        $b->execute([$staffId, secHash(strtolower($input))]);
        if ($id = $b->fetchColumn()) { $db->prepare("UPDATE backup_codes SET used_at = NOW() WHERE id = ?")->execute([$id]); return 'ok'; }
        return 'bad';
    }
    $s = $db->prepare("SELECT *, expires_at < NOW() AS expired FROM login_codes WHERE staff_id = ?");
    $s->execute([$staffId]);
    $c = $s->fetch();
    if (!$c) return 'expired';
    if ($c['tries'] >= SEC_CODE_TRIES) return 'locked';
    if ($c['expired']) return 'expired';
    if (preg_match('/^\d{6}$/', $input) && hash_equals($c['code_hash'], secHash($staffId . ':' . $input))) {
        $db->prepare("DELETE FROM login_codes WHERE staff_id = ?")->execute([$staffId]);
        return 'ok';
    }
    $db->prepare("UPDATE login_codes SET tries = tries + 1 WHERE staff_id = ?")->execute([$staffId]);
    return $c['tries'] + 1 >= SEC_CODE_TRIES ? 'locked' : 'bad';
}

// ── Trusted devices ──

function secCookie(string $name, string $value, int $expires): void {
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https';
    setcookie($name, $value, ['expires' => $expires, 'path' => '/', 'secure' => $https, 'httponly' => true, 'samesite' => 'Lax']);
}

function secTrustThisDevice(PDO $db, int $staffId): void {
    ensureSecuritySchema($db);
    $token = bin2hex(random_bytes(32));
    $db->prepare("INSERT INTO trusted_devices (staff_id, token_hash, label, expires_at, last_used) VALUES (?,?,?,NOW() + INTERVAL " . SEC_TRUST_DAYS . " DAY, NOW())")
       ->execute([$staffId, secHash($token), secDeviceLabel(secAgent())]);
    secCookie(SEC_TRUST_COOKIE, $staffId . '.' . $token, time() + SEC_TRUST_DAYS * 86400);
}

function secDeviceTrusted(PDO $db, int $staffId): bool {
    ensureSecuritySchema($db);
    $c = (string)($_COOKIE[SEC_TRUST_COOKIE] ?? '');
    if (!preg_match('/^(\d+)\.([a-f0-9]{64})$/', $c, $m) || (int)$m[1] !== $staffId) return false;
    $s = $db->prepare("SELECT id FROM trusted_devices WHERE staff_id = ? AND token_hash = ? AND expires_at > NOW()");
    $s->execute([$staffId, secHash($m[2])]);
    if (!$id = $s->fetchColumn()) return false;
    $db->prepare("UPDATE trusted_devices SET last_used = NOW() WHERE id = ?")->execute([$id]);
    return true;
}

// ── Backup codes ──

/** Replaces this person's backup codes with 10 new ones and returns them (shown once). */
function secNewBackupCodes(PDO $db, int $staffId): array {
    ensureSecuritySchema($db);
    $db->prepare("DELETE FROM backup_codes WHERE staff_id = ?")->execute([$staffId]);
    $codes = [];
    $alphabet = 'abcdefghjkmnpqrstuvwxyz23456789';
    for ($i = 0; $i < 10; $i++) {
        $c = '';
        for ($j = 0; $j < 10; $j++) $c .= $alphabet[random_int(0, strlen($alphabet) - 1)];
        $codes[] = substr($c, 0, 5) . '-' . substr($c, 5);
        $db->prepare("INSERT INTO backup_codes (staff_id, code_hash) VALUES (?,?)")->execute([$staffId, secHash($c)]);
    }
    return $codes;
}

function secBackupCodesLeft(PDO $db, int $staffId): int {
    ensureSecuritySchema($db);
    $s = $db->prepare("SELECT COUNT(*) FROM backup_codes WHERE staff_id = ? AND used_at IS NULL");
    $s->execute([$staffId]);
    return (int)$s->fetchColumn();
}

/** Admin "reset 2-step": forget trusted devices and backup codes, and sign them out everywhere. */
function secResetTwoStep(PDO $db, int $staffId): void {
    ensureSecuritySchema($db);
    $db->prepare("DELETE FROM trusted_devices WHERE staff_id = ?")->execute([$staffId]);
    $db->prepare("DELETE FROM backup_codes WHERE staff_id = ?")->execute([$staffId]);
    $db->prepare("DELETE FROM login_codes WHERE staff_id = ?")->execute([$staffId]);
    secSignOutEverywhere($db, $staffId);
}

// ── Password reset by email ──

/** Emails a reset link when the address belongs to an active account. Silent otherwise (no hint which emails exist). */
function secRequestReset(PDO $db, string $email, string $baseUrl): void {
    ensureSecuritySchema($db);
    $s = $db->prepare("SELECT * FROM staff WHERE email = ? AND is_active = 1");
    $s->execute([trim($email)]);
    if (!$staff = $s->fetch()) return;
    $recent = $db->prepare("SELECT COUNT(*) FROM password_resets WHERE staff_id = ? AND created_at > NOW() - INTERVAL 1 HOUR");
    $recent->execute([$staff['id']]);
    if ((int)$recent->fetchColumn() >= 3) return;
    $token = bin2hex(random_bytes(32));
    $db->prepare("INSERT INTO password_resets (token_hash, staff_id, expires_at) VALUES (?,?,NOW() + INTERVAL " . SEC_RESET_MINUTES . " MINUTE)")
       ->execute([secHash($token), $staff['id']]);
    $link = rtrim($baseUrl, '/') . '/login.php?reset=' . $token;
    $first = htmlspecialchars(preg_split('/\s+/', trim($staff['name']))[0], ENT_QUOTES);
    sendEmail($db, $staff['email'], 'Reset your Clinic CRM password',
        "<p>Hello $first,</p><p>Someone (hopefully you) asked to reset your Clinic CRM password. Use this link within " . SEC_RESET_MINUTES . " minutes; it works once:</p>"
        . '<p><a href="' . htmlspecialchars($link, ENT_QUOTES) . '" style="display:inline-block;background:#b08d57;color:#fff;padding:10px 18px;border-radius:6px;text-decoration:none">Choose a new password</a></p>'
        . '<p>If you didn’t ask for this, ignore this email; your password stays the same.</p>',
        '', 'Password reset');
}

/** The staff row for a valid, unused reset token, or null. */
function secResetStaff(PDO $db, string $token): ?array {
    ensureSecuritySchema($db);
    if (!preg_match('/^[a-f0-9]{64}$/', $token)) return null;
    $s = $db->prepare("SELECT s.* FROM password_resets r JOIN staff s ON s.id = r.staff_id
                       WHERE r.token_hash = ? AND r.used_at IS NULL AND r.expires_at > NOW() AND s.is_active = 1");
    $s->execute([secHash($token)]);
    return $s->fetch() ?: null;
}

function secUseReset(PDO $db, string $token, int $staffId): void {
    $db->prepare("UPDATE password_resets SET used_at = NOW() WHERE token_hash = ?")->execute([secHash($token)]);
    $db->prepare("UPDATE password_resets SET used_at = NOW() WHERE staff_id = ? AND used_at IS NULL")->execute([$staffId]);   // older links die too
}

/** Emails "new sign-in" when this browser hasn't signed in to this account before. Never throws. */
function secNewDeviceAlert(PDO $db, array $staff): void {
    try {
        $s = $db->prepare("SELECT COUNT(*) FROM login_log WHERE staff_id = ? AND result = 'success' AND user_agent = ? AND id < (SELECT MAX(id) FROM login_log WHERE staff_id = ?)");
        $s->execute([$staff['id'], secAgent(), $staff['id']]);
        if ((int)$s->fetchColumn() > 0 || !mailConfigured($db)) return;
        $first = htmlspecialchars(preg_split('/\s+/', trim($staff['name']))[0], ENT_QUOTES);
        sendEmail($db, $staff['email'], 'New sign-in to your Clinic CRM account',
            "<p>Hello $first,</p><p>Your Clinic CRM account was just signed in to from <strong>" . htmlspecialchars(secDeviceLabel(secAgent())) . '</strong> (' . htmlspecialchars(secIp()) . ') on ' . date('d M Y, h:i A') . '.</p>'
            . '<p>If this was you, there’s nothing to do. If not, change your password on your My account page straight away and tell an admin.</p>',
            '', 'New sign-in alert');
    } catch (Throwable $e) {}
}

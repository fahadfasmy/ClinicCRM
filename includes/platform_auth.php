<?php
// includes/platform_auth.php — the platform side (signup, back office, Stripe webhook) runs on the platform's own address
// (PLATFORM_HOST), never a clinic's, with its own session cookie: a clinic session and a platform session never mix.
// Platform admins sign in with a password plus an authenticator-app code (TOTP, mandatory), and are signed out after
// 15 minutes without activity.
require_once __DIR__ . '/platform_billing.php';
require_once __DIR__ . '/plans.php';   // TENANT_STATUS_LABELS, PLAN_* labels

const PLATFORM_IDLE_MIN = 15;
const PLATFORM_ROLES = ['owner' => 'Owner', 'support' => 'Support', 'billing' => 'Billing'];

/** Only on the platform address, in multi-clinic mode; anything else is a plain 404. Starts the platform session. */
function platformPage(): void {
    if (!multiTenant() || requestHost() !== strtolower(platformHost())) { http_response_code(404); exit('Not found'); }
    if (session_status() === PHP_SESSION_NONE) {
        $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https';
        session_name('crm_platform');
        session_set_cookie_params(['lifetime' => 0, 'path' => '/', 'secure' => $https, 'httponly' => true, 'samesite' => 'Strict']);
        session_start();
    }
    header('X-Frame-Options: DENY');
    header("Content-Security-Policy: default-src 'self'; script-src 'self' 'unsafe-inline'; style-src 'self' 'unsafe-inline'; img-src 'self' data:; font-src 'self'; form-action 'self' https://checkout.stripe.com; frame-ancestors 'none'");
}

function pCsrf(): string { return $_SESSION['p_csrf'] ??= bin2hex(random_bytes(32)); }
function pCsrfField(): string { return '<input type="hidden" name="_csrf" value="' . pCsrf() . '">'; }
function pCsrfCheck(): void { if (!hash_equals(pCsrf(), (string)($_POST['_csrf'] ?? ''))) { http_response_code(403); exit('The form expired. Go back and try again.'); } }
function e(?string $s): string { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

/** The signed-in platform admin (row), or redirect to sign in. $roles limits who may continue. */
function requirePlatformAdmin(array $roles = []): array {
    $id = (int)($_SESSION['p_admin'] ?? 0);
    if ($id && time() - (int)($_SESSION['p_seen'] ?? 0) > PLATFORM_IDLE_MIN * 60) { $_SESSION = []; $id = 0; }
    $a = $id ? platformDB()->query("SELECT * FROM platform_admins WHERE id = $id AND is_active = 1")->fetch() : null;
    if (!$a) { header('Location: /platform/index.php'); exit; }
    $_SESSION['p_seen'] = time();
    if ($roles && !in_array($a['role'], $roles, true)) { http_response_code(403); exit('Your platform role can’t do this.'); }
    return $a;
}

// ── TOTP (RFC 6238: 30-second steps, 6 digits, SHA-1), the usual authenticator-app codes ──

function totpSecret(): string {
    $alpha = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567'; $s = '';
    foreach (str_split(random_bytes(32)) as $b) $s .= $alpha[ord($b) & 31];   // 32 characters × 5 bits = 160 bits
    return $s;
}

function base32Decode(string $s): string {
    $alpha = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567'; $bits = ''; $out = '';
    foreach (str_split(strtoupper(preg_replace('/[^A-Za-z2-7]/', '', $s))) as $c) $bits .= str_pad(decbin(strpos($alpha, $c)), 5, '0', STR_PAD_LEFT);
    foreach (str_split($bits, 8) as $byte) if (strlen($byte) === 8) $out .= chr(bindec($byte));
    return $out;
}

function totpCode(string $secret, int $step): string {
    $h = hash_hmac('sha1', pack('N2', 0, $step), base32Decode($secret), true);
    $o = ord($h[19]) & 0xf;
    $n = ((ord($h[$o]) & 0x7f) << 24) | (ord($h[$o + 1]) << 16) | (ord($h[$o + 2]) << 8) | ord($h[$o + 3]);
    return str_pad((string)($n % 1000000), 6, '0', STR_PAD_LEFT);
}

/** A code is right for this 30-second step or the one either side (clock drift). */
function totpVerify(string $secret, string $code, ?int $now = null): bool {
    $code = preg_replace('/\D/', '', $code);
    if (strlen($code) !== 6) return false;
    $step = intdiv($now ?? time(), 30);
    foreach ([-1, 0, 1] as $d) if (hash_equals(totpCode($secret, $step + $d), $code)) return true;
    return false;
}

// ── Support sessions: a signed, one-minute ticket from the back office to the clinic's address ──

function supportTicket(int $grantId, int $adminId): string {
    $data = $grantId . '.' . $adminId . '.' . (time() + 60) . '.' . bin2hex(random_bytes(8));
    return $data . '.' . hash_hmac('sha256', 'support|' . $data, masterKey()[1]);
}

/** [grant id, admin id] from a ticket, or null if it is forged, expired or used. */
function supportTicketOpen(string $ticket): ?array {
    $p = explode('.', $ticket);
    if (count($p) !== 5) return null;
    [$g, $a, $exp, $nonce, $sig] = $p;
    if (!hash_equals(hash_hmac('sha256', "support|$g.$a.$exp.$nonce", masterKey()[1]), $sig) || (int)$exp < time()) return null;
    try { platformDB()->prepare("INSERT INTO webhook_events (provider, event_id, type, payload) VALUES ('support', ?, 'ticket', '{}')")->execute([$nonce]); }
    catch (PDOException $e) { return null; }   // each ticket works once
    return [(int)$g, (int)$a];
}

/**
 * The open grant a support person may use for a clinic: approved by the clinic (named person or any support), not
 * expired or revoked. Null = no session may start.
 */
function supportGrantFor(int $tenantId, int $adminId): ?array {
    $s = platformDB()->prepare("SELECT * FROM support_grants WHERE tenant_id = ? AND (platform_admin_id IS NULL OR platform_admin_id = ?) AND revoked_at IS NULL
                                AND starts_at <= NOW() AND expires_at > NOW() ORDER BY expires_at DESC LIMIT 1");
    $s->execute([$tenantId, $adminId]);
    return $s->fetch() ?: null;
}

// ── Back-office page frame (plain, separate from the clinic screens) ──

function pHead(string $title, ?array $admin = null): void {
    header('Content-Type: text/html; charset=utf-8');
    echo '<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>' . e($title) . ' · Platform</title>'
       . '<style>body{font:14px/1.5 system-ui,Segoe UI,Arial,sans-serif;margin:0;background:#f5f5f4;color:#1c1917}header{background:#1c1917;color:#fff;padding:10px 20px;display:flex;gap:18px;align-items:center}'
       . 'header a{color:#e7d3b0;text-decoration:none}main{max-width:1200px;margin:20px auto;padding:0 16px}table{border-collapse:collapse;width:100%;background:#fff}th,td{text-align:left;padding:7px 9px;border-bottom:1px solid #e7e5e4;vertical-align:top}'
       . 'th{background:#fafaf9;font-size:12px;text-transform:uppercase;color:#57534e}.card{background:#fff;border-radius:10px;padding:16px;margin-bottom:16px;box-shadow:0 1px 4px rgba(0,0,0,.06)}'
       . '.tiles{display:flex;flex-wrap:wrap;gap:12px;margin-bottom:16px}.tile{background:#fff;border-radius:10px;padding:12px 16px;min-width:150px;box-shadow:0 1px 4px rgba(0,0,0,.06)}.tile b{display:block;font-size:22px}'
       . 'input,select,textarea{font:inherit;padding:6px 8px;border:1px solid #d6d3d1;border-radius:6px}button{font:inherit;padding:6px 12px;border:0;border-radius:6px;background:#a16207;color:#fff;cursor:pointer}'
       . 'button.ghost{background:#e7e5e4;color:#1c1917}.err{background:#fee2e2;color:#991b1b;padding:8px 12px;border-radius:6px}.ok{background:#dcfce7;color:#166534;padding:8px 12px;border-radius:6px}'
       . '.muted{color:#78716c}.chip{display:inline-block;padding:1px 8px;border-radius:10px;background:#e7e5e4;font-size:12px}label{display:block;font-weight:600;margin:8px 0 3px}</style></head><body>';
    if ($admin) echo '<header><b>Platform</b><a href="/platform/index.php">Overview</a><a href="/platform/index.php?view=tenants">Clinics</a><a href="/platform/plans.php">Plans</a>'
        . '<span style="margin-left:auto">' . e($admin['name']) . ' · ' . e(PLATFORM_ROLES[$admin['role']]) . ' · <a href="/platform/index.php?logout=1">Sign out</a></span></header>';
    echo '<main><h1>' . e($title) . '</h1>';
}

function pFoot(): void { echo '</main></body></html>'; }

<?php
// includes/auth.php
require_once __DIR__ . '/db.php';   // currentTenant()

if (session_status() === PHP_SESSION_NONE) {
    // Session cookie: HTTPS only (when served over HTTPS), hidden from page scripts, not sent by other sites' forms
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https';
    session_set_cookie_params(['lifetime' => 0, 'path' => '/', 'secure' => $https, 'httponly' => true, 'samesite' => 'Lax']);
    session_start();
    // A session (signed in or half-way through sign-in) belongs to one clinic: carried to another clinic's address it is
    // emptied, never reused. Sessions from before clinics existed are kept in single-clinic mode only.
    $tid = currentTenant()['id'];
    if (($_SESSION['tenant_id'] ?? null) !== $tid) {
        if (isset($_SESSION['tenant_id']) || multiTenant()) $_SESSION = [];
        $_SESSION['tenant_id'] = $tid;
    }
    unset($tid);
}
require_once __DIR__ . '/permissions.php';   // can(), requirePermission()
require_once __DIR__ . '/i18n.php';          // t(), uiLang(), uiDir()
require_once __DIR__ . '/plans.php';         // plan limits and the read-only guard

function requireLogin(): void {
    if (empty($_SESSION['staff_id'])) {
        header('Location: /login.php');
        exit;
    }
    // The device was handed to a patient to sign a consent: only the consent screen until staff unlock it
    if (!empty($_SESSION['kiosk_lock']) && !defined('KIOSK_PAGE')) {
        header('Location: /pages/consent_sign.php');
        exit;
    }
    // Deactivated, or signed out everywhere (password changed, admin action): this session ends now
    try {
        $s = getDB()->prepare("SELECT is_active, session_version FROM staff WHERE id = ?");
        $s->execute([(int)$_SESSION['staff_id']]);
        $row = $s->fetch();
        if (!$row || !$row['is_active'] || (int)$row['session_version'] !== (int)($_SESSION['sv'] ?? 0)) {
            logoutStaff();
            header('Location: /login.php?signed_out=1');
            exit;
        }
    } catch (PDOException $e) {
        if ($e->getCode() !== '42S22') throw $e;   // session_version not added yet (before the first sign-in page load)
    }
    // Signed out after a time without activity, when the admin set one (Staff → security; off by default)
    $idle = (int)(getDB()->query("SELECT v FROM integration_settings WHERE k = 'mail.idle_minutes'")->fetchColumn() ?: 0);
    if ($idle > 0 && !empty($_SESSION['last_seen']) && time() - (int)$_SESSION['last_seen'] > $idle * 60 && empty($_SESSION['kiosk_lock'])) {
        logoutStaff();
        header('Location: /login.php?idle=1');
        exit;
    }
    $_SESSION['last_seen'] = time();
    $GLOBALS['IDLE_MINUTES'] = $idle;
    // A support session ends the moment its grant expires or the clinic revokes it
    if (!empty($_SESSION['support']) && !supportGrantOpen((int)$_SESSION['support']['grant_id'])) {
        logoutStaff();
        header('Location: /login.php?signed_out=1');
        exit;
    }
    // Every page a support person opens is in the clinic's audit log
    if (!empty($_SESSION['support'])) {
        getDB()->prepare("INSERT INTO audit_log (entity, entity_id, action, changes, staff_id, ip) VALUES ('support_view', ?, ?, ?, ?, ?)")
            ->execute([(int)$_SESSION['support']['grant_id'], substr(strtolower($_SERVER['REQUEST_METHOD'] ?? 'get') . ' ' . basename($_SERVER['SCRIPT_NAME'] ?? ''), 0, 40),
                       json_encode(['url' => mb_substr((string)($_SERVER['REQUEST_URI'] ?? ''), 0, 300)]), currentStaffId(), $_SERVER['REMOTE_ADDR'] ?? null]);
    }
    tenantGuard();
}

/** Whether a support grant is still open (not expired, not revoked). */
function supportGrantOpen(int $grantId): bool {
    if (!multiTenant() || !$grantId) return false;
    $s = platformDB()->prepare("SELECT COUNT(*) FROM support_grants WHERE id = ? AND tenant_id = ? AND revoked_at IS NULL AND starts_at <= NOW() AND expires_at > NOW()");
    $s->execute([$grantId, currentTenant()['id']]);
    return (bool)$s->fetchColumn();
}

function requireRole(string ...$roles): void {
    requireLogin();
    if (!in_array($_SESSION['role'] ?? '', $roles, true)) {
        http_response_code(403);
        die('<h2>Access Denied</h2><p>You do not have permission to view this page.</p>');
    }
}

function currentStaffId(): int {
    return (int)($_SESSION['staff_id'] ?? 0);
}

function currentRole(): string {
    return $_SESSION['role'] ?? '';
}

function isAdmin(): bool {
    return currentRole() === 'admin';
}

function staffName(): string {
    return $_SESSION['staff_name'] ?? 'Unknown';
}

function loginStaff(array $staff): void {
    session_regenerate_id(true);
    $_SESSION['tenant_id']  = currentTenant()['id'];
    $_SESSION['staff_id']   = $staff['id'];
    $_SESSION['staff_name'] = $staff['name'];
    $_SESSION['role']       = $staff['role'];
    $_SESSION['email']      = $staff['email'];
    $_SESSION['sv']         = (int)($staff['session_version'] ?? 0);
    $_SESSION['ui_lang']    = ($staff['ui_language'] ?? 'en') === 'ar' ? 'ar' : 'en';
}

function logoutStaff(): void {
    $_SESSION = [];
    if (ini_get("session.use_cookies")) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000,
            $params["path"], $params["domain"],
            $params["secure"], $params["httponly"]
        );
    }
    session_destroy();
}

/**
 * Browser rules for staff pages: scripts, styles, fonts and images only from this server (the libraries live in
 * /assets/vendor), never framed by another site, and no page address sent to other sites.
 * ponytail: inline scripts and onclick= are still allowed ('unsafe-inline'); move to nonces when pages are reworked.
 * No form-action rule: "Connect Google Calendar" posts here and is redirected on to Google's sign-in.
 */
function sendStaffPageHeaders(): void {
    if (headers_sent()) return;
    header("Content-Security-Policy: default-src 'self'; script-src 'self' 'unsafe-inline'; style-src 'self' 'unsafe-inline'; img-src 'self' data: blob:; font-src 'self' data:; connect-src 'self'; object-src 'none'; base-uri 'self'; frame-ancestors 'self'");
    header('X-Content-Type-Options: nosniff');
    header('Referrer-Policy: same-origin');
}

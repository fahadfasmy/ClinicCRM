<?php
// platform/index.php — the platform back office (on PLATFORM_HOST only): sign-in (password, then an authenticator-app
// code; the first sign-in sets the app up), the overview (MRR, trials, conversion, churn, failed webhooks, clinics near
// their limits) and the clinics list. Never shows patient data.
const PLATFORM_REQUEST = true;   // the platform's address, not a clinic's
require_once __DIR__ . '/../includes/platform_auth.php';
platformPage();
$p = platformDB();

if (isset($_GET['logout'])) { platformAudit($_SESSION['p_admin'] ?? null, null, 'signed_out'); $_SESSION = []; header('Location: /platform/index.php'); exit; }

// ── Sign-in ──
if (empty($_SESSION['p_admin'])) {
    $err = '';
    $ip = $_SERVER['REMOTE_ADDR'] ?? '';
    $fails = (int)$p->query("SELECT COUNT(*) FROM platform_audit_log WHERE action = 'sign_in_failed' AND ip = " . $p->quote($ip) . " AND at > NOW() - INTERVAL 15 MINUTE")->fetchColumn();
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        pCsrfCheck();
        if ($fails >= 10) $err = 'Too many attempts. Wait 15 minutes.';
        elseif (($_POST['step'] ?? '') === 'password') {
            $s = $p->prepare("SELECT * FROM platform_admins WHERE email = ? AND is_active = 1"); $s->execute([strtolower(trim((string)($_POST['email'] ?? '')))]);
            $a = $s->fetch();
            if ($a && password_verify((string)($_POST['password'] ?? ''), $a['password'])) {
                session_regenerate_id(true);
                $_SESSION['p_pending'] = (int)$a['id']; $_SESSION['p_pending_at'] = time();
                if (!$a['totp_secret_enc']) $_SESSION['p_enrol'] = totpSecret();
            } else { $err = 'That email and password don’t match.'; platformAudit(null, null, 'sign_in_failed', ['email' => $_POST['email'] ?? '']); }
        } elseif (($_POST['step'] ?? '') === 'code' && !empty($_SESSION['p_pending']) && $_SESSION['p_pending_at'] > time() - 600) {
            $a = $p->query("SELECT * FROM platform_admins WHERE id = " . (int)$_SESSION['p_pending'] . " AND is_active = 1")->fetch();
            $secret = !empty($_SESSION['p_enrol']) ? $_SESSION['p_enrol'] : ($a && $a['totp_secret_enc'] ? masterOpen($a['totp_secret_enc']) : '');
            if ($a && $secret && totpVerify($secret, (string)($_POST['code'] ?? ''))) {
                if (!empty($_SESSION['p_enrol'])) $p->prepare("UPDATE platform_admins SET totp_secret_enc = ? WHERE id = ?")->execute([masterSeal($secret), $a['id']]);
                session_regenerate_id(true);
                $_SESSION = ['p_admin' => (int)$a['id'], 'p_seen' => time(), 'p_csrf' => bin2hex(random_bytes(32))];
                $p->prepare("UPDATE platform_admins SET last_login_at = NOW() WHERE id = ?")->execute([$a['id']]);
                platformAudit((int)$a['id'], null, 'signed_in');
                header('Location: /platform/index.php'); exit;
            }
            $err = 'That code isn’t right. Use the current code from the authenticator app.';
            platformAudit(null, null, 'sign_in_failed', ['step' => 'code']);
        }
    }
    pHead('Platform sign-in');
    if ($err) echo '<p class="err" role="alert">' . e($err) . '</p>';
    echo '<div class="card" style="max-width:420px"><form method="POST">' . pCsrfField();
    if (!empty($_SESSION['p_pending']) && $_SESSION['p_pending_at'] > time() - 600) {
        if (!empty($_SESSION['p_enrol'])) {
            $uri = 'otpauth://totp/' . rawurlencode('Clinic CRM Platform') . '?secret=' . $_SESSION['p_enrol'] . '&issuer=' . rawurlencode('Clinic CRM');
            echo '<p><b>Set up 2-step sign-in.</b> Add this key to an authenticator app (Microsoft or Google Authenticator, 1Password…), then enter the 6-digit code it shows.</p>'
               . '<p style="font:600 18px monospace;letter-spacing:2px;word-break:break-all">' . e(chunk_split($_SESSION['p_enrol'], 4, ' ')) . '</p><p class="muted" style="word-break:break-all">' . e($uri) . '</p>';
        }
        echo '<input type="hidden" name="step" value="code"><label for="pc">Authenticator code</label><input id="pc" name="code" inputmode="numeric" autocomplete="one-time-code" required autofocus maxlength="6"> <button>Sign in</button>';
    } else {
        echo '<input type="hidden" name="step" value="password"><label for="pe">Email</label><input id="pe" name="email" type="email" required autofocus style="width:100%">'
           . '<label for="pp">Password</label><input id="pp" name="password" type="password" required style="width:100%"><p><button>Continue</button></p>';
    }
    echo '</form></div>';
    pFoot();
    exit;
}

$admin = requirePlatformAdmin();

if (($_GET['view'] ?? '') === 'tenants') {
    $q = trim((string)($_GET['q'] ?? '')); $st = (string)($_GET['status'] ?? '');
    $w = []; $a = [];
    if ($q !== '') { $w[] = '(t.name LIKE ? OR t.slug LIKE ? OR t.owner_email LIKE ?)'; array_push($a, "%$q%", "%$q%", "%$q%"); }
    if (isset(TENANT_STATUS_LABELS[$st])) { $w[] = 't.status = ?'; $a[] = $st; }
    $s = $p->prepare("SELECT t.*, pl.name AS plan, (SELECT host FROM tenant_domains d WHERE d.tenant_id = t.id ORDER BY is_primary DESC LIMIT 1) AS host,
                      u.messages_sent, u.active_staff, u.storage_bytes FROM tenants t LEFT JOIN plans pl ON pl.id = t.plan_id
                      LEFT JOIN usage_counters u ON u.tenant_id = t.id AND u.period = DATE_FORMAT(NOW(), '%Y-%m')" . ($w ? ' WHERE ' . implode(' AND ', $w) : '') . " ORDER BY t.id DESC LIMIT 500");
    $s->execute($a);
    pHead('Clinics', $admin);
    echo '<form class="card" style="display:flex;gap:8px;align-items:end"><input type="hidden" name="view" value="tenants"><div><label for="q">Search</label><input id="q" name="q" value="' . e($q) . '"></div>'
       . '<div><label for="s">Status</label><select id="s" name="status"><option value="">All</option>';
    foreach (TENANT_STATUS_LABELS as $k => $l) echo '<option value="' . $k . '"' . ($st === $k ? ' selected' : '') . '>' . e($l) . '</option>';
    echo '</select></div><button>Show</button></form><table><thead><tr><th>Clinic</th><th>Status</th><th>Plan</th><th>Trial ends</th><th>Created</th><th>Messages this month</th><th>Staff</th><th>Storage</th></tr></thead><tbody>';
    foreach ($s as $t) echo '<tr><td><a href="/platform/tenant.php?id=' . (int)$t['id'] . '">' . e($t['name']) . '</a><div class="muted">' . e($t['host']) . '</div></td><td><span class="chip">' . e(TENANT_STATUS_LABELS[$t['status']] ?? $t['status']) . '</span></td>'
        . '<td>' . e($t['plan'] ?? '— (no limits)') . '</td><td>' . e(substr((string)$t['trial_ends_at'], 0, 10)) . '</td><td>' . e(substr($t['created_at'], 0, 10)) . '</td><td>' . (int)$t['messages_sent'] . '</td><td>' . (int)$t['active_staff'] . '</td><td>' . number_format(((int)$t['storage_bytes']) / 1048576, 1) . ' MB</td></tr>';
    echo '</tbody></table>';
    pFoot();
    exit;
}

// ── Overview ──
$byStatus = $p->query("SELECT status, COUNT(*) FROM tenants GROUP BY status")->fetchAll(PDO::FETCH_KEY_PAIR);
$mrr = (float)$p->query("SELECT COALESCE(SUM(CASE WHEN s.`interval` = 'year' THEN pl.price_year_aed / 12 ELSE pl.price_month_aed END), 0) FROM tenants t JOIN plans pl ON pl.id = t.plan_id
                         JOIN subscriptions s ON s.tenant_id = t.id WHERE t.status IN ('active','past_due') AND s.status IN ('active','past_due')")->fetchColumn();
$signups90 = (int)$p->query("SELECT COUNT(*) FROM signup_requests WHERE tenant_id IS NOT NULL AND verified_at > NOW() - INTERVAL 90 DAY")->fetchColumn();
$paid90 = (int)$p->query("SELECT COUNT(*) FROM signup_requests r JOIN tenants t ON t.id = r.tenant_id WHERE r.verified_at > NOW() - INTERVAL 90 DAY AND t.status IN ('active','past_due')")->fetchColumn();
$churn30 = (int)$p->query("SELECT COUNT(*) FROM tenants WHERE cancelled_at > NOW() - INTERVAL 30 DAY")->fetchColumn();
$failedHooks = $p->query("SELECT provider, event_id, type, error, received_at FROM webhook_events WHERE error IS NOT NULL AND received_at > NOW() - INTERVAL 7 DAY AND provider = 'stripe' ORDER BY id DESC LIMIT 20")->fetchAll();
$near = [];
foreach ($p->query("SELECT t.id, t.name, pl.limits, u.messages_sent, u.active_staff, u.storage_bytes FROM tenants t JOIN plans pl ON pl.id = t.plan_id
                    JOIN usage_counters u ON u.tenant_id = t.id AND u.period = DATE_FORMAT(NOW(), '%Y-%m') WHERE t.status IN ('trial','active','past_due')") as $r) {
    $l = json_decode($r['limits'], true) ?: [];
    foreach (['messages_per_month' => (int)$r['messages_sent'], 'staff_seats' => (int)$r['active_staff'], 'storage_gb' => (int)$r['storage_bytes'] / 1073741824] as $k => $used)
        if (!empty($l[$k]) && $used >= 0.8 * $l[$k]) $near[] = [$r['id'], $r['name'], $k, $used, $l[$k]];
}
$recent = $p->query("SELECT l.*, a.name AS admin, t.name AS clinic FROM platform_audit_log l LEFT JOIN platform_admins a ON a.id = l.admin_id LEFT JOIN tenants t ON t.id = l.tenant_id ORDER BY l.id DESC LIMIT 25")->fetchAll();
pHead('Overview', $admin);
echo '<div class="tiles"><div class="tile">MRR<b>AED ' . number_format($mrr) . '</b></div><div class="tile">Active trials<b>' . (int)($byStatus['trial'] ?? 0) . '</b></div>'
   . '<div class="tile">Trial → paid (90 days)<b>' . ($signups90 ? round($paid90 / $signups90 * 100) . '%' : '–') . '</b><span class="muted">' . "$paid90 of $signups90" . '</span></div>'
   . '<div class="tile">Cancelled (30 days)<b>' . $churn30 . '</b></div><div class="tile">Failed webhooks (7 days)<b>' . count($failedHooks) . '</b></div></div>';
echo '<div class="card"><b>Clinics by status:</b> ';
foreach (TENANT_STATUS_LABELS as $k => $l) echo '<a href="/platform/index.php?view=tenants&amp;status=' . $k . '">' . e($l) . '</a> ' . (int)($byStatus[$k] ?? 0) . ' · ';
echo '</div>';
if ($near) { echo '<div class="card"><b>Near their limits</b><ul>'; foreach ($near as [$id, $n, $k, $u, $l]) echo '<li><a href="/platform/tenant.php?id=' . (int)$id . '">' . e($n) . '</a>: ' . e($k) . ' ' . round($u, 1) . ' of ' . (int)$l . '</li>'; echo '</ul></div>'; }
if ($failedHooks) { echo '<div class="card"><b>Failed webhooks</b><ul>'; foreach ($failedHooks as $h) echo '<li>' . e($h['received_at'] . ' ' . $h['type'] . ' ' . $h['event_id'] . ': ' . $h['error']) . '</li>'; echo '</ul></div>'; }
echo '<h2>Recent platform actions</h2><table><thead><tr><th>When</th><th>Who</th><th>Clinic</th><th>Action</th><th>Detail</th></tr></thead><tbody>';
foreach ($recent as $r) echo '<tr><td>' . e(substr($r['at'], 0, 16)) . '</td><td>' . e($r['admin'] ?? 'system') . '</td><td>' . e($r['clinic'] ?? '') . '</td><td>' . e($r['action']) . '</td><td class="muted">' . e(mb_substr((string)$r['detail'], 0, 160)) . '</td></tr>';
echo '</tbody></table>';
pFoot();

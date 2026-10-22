<?php
// platform/tenant.php?id= — one clinic in the back office: status, plan, subscription, usage, addresses, legal
// acceptances, support grants and the platform actions on it. Every action needs a written reason and goes to
// platform_audit_log. Opening the clinic (support session) needs an open grant from the clinic's admin; emergency
// access without one is for the platform owner only, needs a reason, and emails the clinic's owner at once.
const PLATFORM_REQUEST = true;   // the platform's address, not a clinic's
require_once __DIR__ . '/../includes/platform_auth.php';
require_once __DIR__ . '/../includes/backup.php';
platformPage();
$admin = requirePlatformAdmin();
$p = platformDB();
$id = (int)($_GET['id'] ?? 0);
$t = $p->query("SELECT t.*, (SELECT host FROM tenant_domains d WHERE d.tenant_id = t.id ORDER BY is_primary DESC LIMIT 1) AS primary_host FROM tenants t WHERE t.id = $id")->fetch();
if (!$t) { http_response_code(404); exit('No such clinic'); }
$msg = ''; $err = '';
$tenantDb = fn() => dbConnect(($row = tenantFromRow($t))['db_name'], $row['db_user'], $row['db_pass'], $row['db_host']);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    pCsrfCheck();
    $act = (string)($_POST['action'] ?? '');
    $why = trim((string)($_POST['reason'] ?? ''));
    try {
        if (mb_strlen($why) < 5) throw new RuntimeException('Write the reason (it goes in the audit log).');
        $ownerOnly = ['suspend', 'unsuspend', 'emergency'];
        if (in_array($act, $ownerOnly, true) && $admin['role'] !== 'owner') throw new RuntimeException('Only the platform owner can do this.');
        if (in_array($act, ['extend_trial', 'set_plan', 'force_read_only', 'reactivate'], true) && !in_array($admin['role'], ['owner', 'billing'], true)) throw new RuntimeException('Your role can’t change billing.');
        switch ($act) {
            case 'extend_trial':
                $days = max(1, min(60, (int)($_POST['days'] ?? 7)));
                $p->prepare("UPDATE tenants SET trial_ends_at = GREATEST(COALESCE(trial_ends_at, NOW()), NOW()) + INTERVAL $days DAY WHERE id = ?")->execute([$id]);
                tenantSetStatus($id, 'trial', "trial extended by $days days: $why", (int)$admin['id']);
                platformAudit((int)$admin['id'], $id, 'trial_extended', ['days' => $days, 'why' => $why]);
                $msg = "Trial extended by $days days.";
                break;
            case 'set_plan':
                $pid = (int)($_POST['plan_id'] ?? 0) ?: null;
                $p->prepare("UPDATE tenants SET plan_id = ? WHERE id = ?")->execute([$pid, $id]);
                platformAudit((int)$admin['id'], $id, 'plan_set', ['plan_id' => $pid, 'why' => $why]);
                $msg = 'Plan changed.';
                break;
            case 'force_read_only': tenantSetStatus($id, 'read_only', $why, (int)$admin['id']); $msg = 'Now read-only.'; break;
            case 'reactivate': tenantSetStatus($id, 'active', $why, (int)$admin['id']); $msg = 'Active again.'; break;
            case 'suspend': tenantSetStatus($id, 'suspended', $why, (int)$admin['id']); $msg = 'Suspended.'; break;
            case 'unsuspend': tenantSetStatus($id, 'active', $why, (int)$admin['id']); $msg = 'Unsuspended.'; break;
            case 'resend_invite':
                $db = $tenantDb();
                $owner = $db->query("SELECT id, email, name FROM staff WHERE is_owner = 1 AND is_active = 1 ORDER BY id LIMIT 1")->fetch();
                if (!$owner) throw new RuntimeException('The clinic has no active owner account.');
                $tok = bin2hex(random_bytes(32));
                $db->prepare("INSERT INTO password_resets (token_hash, staff_id, expires_at) VALUES (?, ?, NOW() + INTERVAL 7 DAY)")->execute([hash('sha256', $tok), $owner['id']]);
                $link = tenantUrl($t, '/login.php?reset=' . $tok);
                platformMail($owner['email'], 'Your Clinic CRM sign-in link', '<p>Hello ' . e($owner['name']) . ',</p><p>Choose a password for <b>' . e($t['name']) . '</b> here (valid 7 days):</p><p><a href="' . e($link) . '">' . e($link) . '</a></p>');
                platformAudit((int)$admin['id'], $id, 'owner_invite_resent', ['why' => $why]);
                $msg = 'A new sign-in link was emailed to the owner.';
                break;
            case 'backup':
                $r = runBackup($tenantDb());
                platformAudit((int)$admin['id'], $id, 'backup', ['file' => basename($r['file']), 'rows' => $r['rows'], 'why' => $why]);
                $msg = 'Backup made: ' . basename($r['file']) . ' (' . $r['rows'] . ' rows).';
                break;
            case 'support':
            case 'emergency':
                if ($act === 'emergency') {
                    $p->prepare("INSERT INTO support_grants (tenant_id, platform_admin_id, reason, mode, emergency, starts_at, expires_at) VALUES (?,?,?, 'read_only', 1, NOW(), NOW() + INTERVAL 1 HOUR)")
                      ->execute([$id, $admin['id'], 'EMERGENCY: ' . $why]);
                    if ($t['owner_email']) platformMail($t['owner_email'], 'Emergency support access to ' . $t['name'],
                        '<p>Platform support (' . e($admin['name']) . ') opened your clinic without a prior approval, for one hour, read-only.</p><p>Reason: ' . e($why) . '</p>'
                        . '<p>Everything they view is in your audit log and under Admin → Support access, where you can revoke it at once.</p>');
                    platformAudit((int)$admin['id'], $id, 'emergency_access', ['why' => $why]);
                }
                $g = supportGrantFor($id, (int)$admin['id']);
                if (!$g) throw new RuntimeException('There is no open support grant from this clinic. Ask the clinic’s admin to allow support (Admin → Support access).');
                platformAudit((int)$admin['id'], $id, 'support_session_opening', ['grant' => (int)$g['id'], 'why' => $why]);
                header('Location: ' . tenantUrl($t, '/support_login.php?t=' . supportTicket((int)$g['id'], (int)$admin['id'])), true, 303);
                exit;
            default: throw new RuntimeException('Unknown action.');
        }
        $t = $p->query("SELECT t.*, (SELECT host FROM tenant_domains d WHERE d.tenant_id = t.id ORDER BY is_primary DESC LIMIT 1) AS primary_host FROM tenants t WHERE t.id = $id")->fetch();
    } catch (RuntimeException $x) { $err = $x->getMessage(); }
}

$plan = $t['plan_id'] ? $p->query("SELECT * FROM plans WHERE id = " . (int)$t['plan_id'])->fetch() : null;
$sub = tenantSubscription($id);
$usage = $p->query("SELECT * FROM usage_counters WHERE tenant_id = $id ORDER BY period DESC LIMIT 6")->fetchAll();
$hosts = $p->query("SELECT * FROM tenant_domains WHERE tenant_id = $id")->fetchAll();
$grants = $p->query("SELECT * FROM support_grants WHERE tenant_id = $id ORDER BY id DESC LIMIT 10")->fetchAll();
$legal = $p->query("SELECT doc, version, accepted_by_email, at FROM legal_acceptances WHERE tenant_id = $id ORDER BY at DESC")->fetchAll();
$log = $p->query("SELECT l.*, a.name AS admin FROM platform_audit_log l LEFT JOIN platform_admins a ON a.id = l.admin_id WHERE l.tenant_id = $id ORDER BY l.id DESC LIMIT 40")->fetchAll();
$plans = $p->query("SELECT id, name FROM plans ORDER BY sort_order")->fetchAll(PDO::FETCH_KEY_PAIR);
$openGrant = supportGrantFor($id, (int)$admin['id']);

pHead($t['name'], $admin);
if ($msg) echo '<p class="ok" role="status">' . e($msg) . '</p>';
if ($err) echo '<p class="err" role="alert">' . e($err) . '</p>';
echo '<div class="tiles"><div class="tile">Status<b>' . e(TENANT_STATUS_LABELS[$t['status']] ?? $t['status']) . '</b></div><div class="tile">Plan<b>' . e($plan['name'] ?? 'None (no limits)') . '</b></div>'
   . '<div class="tile">Trial ends<b>' . e(substr((string)$t['trial_ends_at'], 0, 10) ?: '–') . '</b></div><div class="tile">Stripe<b>' . e($sub['status'] ?? '–') . '</b><span class="muted">' . e($sub['stripe_customer_id'] ?? '') . '</span></div>'
   . '<div class="tile">Created<b>' . e(substr($t['created_at'], 0, 10)) . '</b></div></div>';
echo '<div class="card"><b>Addresses:</b> ' . implode(', ', array_map(fn($h) => e($h['host']) . ($h['is_primary'] ? ' (main)' : ''), $hosts))
   . '<br><b>Owner:</b> ' . e($t['owner_email']) . ' · <b>DHA licence:</b> ' . e($t['dha_license']) . ' · <b>TRN:</b> ' . e($t['trn']) . ' · <b>Region:</b> ' . e($t['region'])
   . '<br><b>Legal:</b> ' . ($legal ? implode('; ', array_map(fn($l) => e("{$l['doc']} {$l['version']} by {$l['accepted_by_email']} on " . substr($l['at'], 0, 10)), $legal)) : 'none recorded') . '</div>';

$f = fn(string $act, string $label, string $extra = '', string $cls = '') => '<form method="POST" class="card" style="display:flex;gap:8px;align-items:end;flex-wrap:wrap">' . pCsrfField() . '<input type="hidden" name="action" value="' . $act . '">'
    . $extra . '<div style="flex:1;min-width:220px"><label>Reason</label><input name="reason" required minlength="5" style="width:100%"></div><button class="' . $cls . '">' . e($label) . '</button></form>';
echo '<h2>Support</h2><p class="muted">' . ($openGrant ? 'Open grant until ' . e(substr($openGrant['expires_at'], 0, 16)) . ' (' . e($openGrant['mode']) . '): ' . e($openGrant['reason']) : 'No open grant: the clinic’s admin must allow support first (Admin → Support access).') . '</p>';
echo $f('support', 'Open the clinic');
if ($admin['role'] === 'owner') echo $f('emergency', 'Emergency access (1 hour, read-only, owner is emailed)', '', 'ghost');
echo '<h2>Billing and status</h2>';
echo $f('extend_trial', 'Extend the trial', '<div><label>Days</label><input name="days" type="number" min="1" max="60" value="7" style="width:80px"></div>');
$opts = '<option value="">No plan (no limits, comp)</option>'; foreach ($plans as $pid => $pn) $opts .= '<option value="' . (int)$pid . '"' . ((int)$t['plan_id'] === (int)$pid ? ' selected' : '') . '>' . e($pn) . '</option>';
echo $f('set_plan', 'Set the plan', '<div><label>Plan</label><select name="plan_id">' . $opts . '</select></div>');
echo $t['status'] === 'read_only' ? $f('reactivate', 'Make active again') : $f('force_read_only', 'Make read-only', '', 'ghost');
if ($admin['role'] === 'owner') echo $t['status'] === 'suspended' ? $f('unsuspend', 'Unsuspend') : $f('suspend', 'Suspend', '', 'ghost');
echo '<h2>Other</h2>' . $f('resend_invite', 'Email the owner a new sign-in link', '', 'ghost') . $f('backup', 'Back up this clinic now', '', 'ghost');
echo '<h2>Usage</h2><table><thead><tr><th>Month</th><th>Messages</th><th>Active staff</th><th>Storage</th></tr></thead><tbody>';
foreach ($usage as $u) echo '<tr><td>' . e($u['period']) . '</td><td>' . (int)$u['messages_sent'] . '</td><td>' . (int)$u['active_staff'] . '</td><td>' . number_format($u['storage_bytes'] / 1048576, 1) . ' MB</td></tr>';
echo '</tbody></table><h2>Support grants</h2><table><thead><tr><th>Reason</th><th>Mode</th><th>From</th><th>Until</th><th>By</th><th></th></tr></thead><tbody>';
foreach ($grants as $g) echo '<tr><td>' . e($g['reason']) . '</td><td>' . e($g['mode']) . '</td><td>' . e(substr($g['starts_at'], 0, 16)) . '</td><td>' . e(substr($g['expires_at'], 0, 16)) . '</td><td>' . e($g['granted_by_name'] ?: ($g['emergency'] ? 'emergency' : '')) . '</td><td>' . ($g['revoked_at'] ? 'revoked' : '') . '</td></tr>';
echo '</tbody></table><h2>Platform actions</h2><table><thead><tr><th>When</th><th>Who</th><th>Action</th><th>Detail</th></tr></thead><tbody>';
foreach ($log as $l) echo '<tr><td>' . e(substr($l['at'], 0, 16)) . '</td><td>' . e($l['admin'] ?? 'system') . '</td><td>' . e($l['action']) . '</td><td class="muted">' . e(mb_substr((string)$l['detail'], 0, 200)) . '</td></tr>';
echo '</tbody></table>';
pFoot();

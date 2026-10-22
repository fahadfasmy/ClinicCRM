<?php
// support_login.php?t= — where the back office sends a support person (on the clinic's own address). The one-minute,
// one-use ticket must be signed by the platform and the clinic's grant must still be open. They get a marked staff
// record ("Support · name", no password), a session limited to the grant's mode and end time, and a banner on every page.
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/patients.php';      // auditLog
require_once __DIR__ . '/includes/platform_auth.php';  // supportTicketOpen
if (!multiTenant()) { http_response_code(404); exit; }
$ticket = supportTicketOpen((string)($_GET['t'] ?? ''));
$tid = currentTenant()['id'];
$g = null;
if ($ticket) {
    [$gid, $aid] = $ticket;
    $s = platformDB()->prepare("SELECT g.*, a.name AS admin_name, a.email AS admin_email FROM support_grants g JOIN platform_admins a ON a.id = ? AND a.is_active = 1
                                WHERE g.id = ? AND g.tenant_id = ? AND (g.platform_admin_id IS NULL OR g.platform_admin_id = a.id) AND g.revoked_at IS NULL AND g.starts_at <= NOW() AND g.expires_at > NOW()");
    $s->execute([$aid, $gid, $tid]);
    $g = $s->fetch() ?: null;
}
if (!$g) { http_response_code(403); exit('This support link is not valid for this clinic, has been used, or the clinic’s permission has ended.'); }

$db = getDB();
$email = 'support+' . (int)$g['platform_admin_id'] . '-' . $aid . '@support.invalid';   // never a real mailbox: can't reset a password
$name = 'Support · ' . $g['admin_name'];
$s = $db->prepare("SELECT * FROM staff WHERE email = ?"); $s->execute([$email]);
if (!($staff = $s->fetch())) {
    $db->prepare("INSERT INTO staff (name, email, password, role, is_support, is_active) VALUES (?, ?, ?, 'admin', 1, 1)")->execute([$name, $email, '!' . bin2hex(random_bytes(16))]);
    $s->execute([$email]); $staff = $s->fetch();
} else {
    $db->prepare("UPDATE staff SET name = ?, is_active = 1 WHERE id = ?")->execute([$name, $staff['id']]);
}
logoutStaff();
session_start();
$_SESSION['tenant_id'] = $tid;
loginStaff($staff);
$_SESSION['support'] = ['grant_id' => (int)$g['id'], 'admin_id' => $aid, 'admin_name' => $g['admin_name'], 'mode' => $g['mode'], 'expires_at' => $g['expires_at']];
auditLog($db, 'support_session', (int)$g['id'], 'started', ['by' => $g['admin_name'], 'mode' => $g['mode'], 'emergency' => (bool)$g['emergency']]);
platformAudit($aid, $tid, 'support_session_started', ['grant' => (int)$g['id']]);
header('Location: /pages/dashboard.php');

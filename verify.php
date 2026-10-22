<?php
// verify.php?t= — the link from the signup email. Creates the clinic (its own database, schema, owner account), starts
// the 14-day Growth trial, files the legal acceptances under the new clinic, and sends the owner to choose a password on
// the clinic's own address. Works once; a second click goes to the clinic's sign-in page. Links expire after 24 hours.
const PLATFORM_REQUEST = true;   // the platform's address, not a clinic's
require_once __DIR__ . '/includes/platform_auth.php';
require_once __DIR__ . '/includes/provision.php';
platformPage();

$p = platformDB();
$token = (string)($_GET['t'] ?? '');
$r = null;
if (preg_match('/^[a-f0-9]{64}$/', $token)) { $s = $p->prepare("SELECT * FROM signup_requests WHERE token_hash = ?"); $s->execute([hash('sha256', $token)]); $r = $s->fetch() ?: null; }
$fail = function (string $msg) {
    http_response_code(400);
    exit('<!DOCTYPE html><meta charset="utf-8"><title>Sign-up link</title><body style="font:16px system-ui;max-width:560px;margin:60px auto;padding:0 18px"><h1>That link doesn’t work</h1><p>'
        . e($msg) . '</p><p><a href="/signup.php">Sign up again</a></p></body>');
};
if (!$r) $fail('It is not a valid sign-up link.');
if ($r['tenant_id']) {
    $host = $p->query("SELECT host FROM tenant_domains WHERE tenant_id = " . (int)$r['tenant_id'] . " ORDER BY is_primary DESC LIMIT 1")->fetchColumn();
    header('Location: ' . tenantUrl(['primary_host' => $host], '/login.php')); exit;
}
if (strtotime($r['created_at']) < time() - 86400) $fail('It has expired (links work for 24 hours).');

// One click at a time: a double-click must not create two clinics
$lock = (int)$p->query("SELECT GET_LOCK('crm_signup_" . (int)$r['id'] . "', 30)")->fetchColumn();
try {
    $s = $p->prepare("SELECT tenant_id FROM signup_requests WHERE id = ?"); $s->execute([$r['id']]);
    if ($lock !== 1 || $s->fetchColumn()) { header('Location: /verify.php?t=' . $token); exit; }
    $d = json_decode($r['data'], true);
    try {
        [$t, $reset] = provisionTenant(['slug' => $d['slug'], 'name' => $d['name'], 'owner_name' => $d['owner_name'], 'owner_email' => $r['email'],
                                        'legal_name' => $d['name'], 'trn' => $d['trn'] ?? '', 'dha_license' => $d['dha_license'] ?? '']);
    } catch (RuntimeException $x) {
        $p->prepare("UPDATE signup_requests SET error = ? WHERE id = ?")->execute([mb_substr($x->getMessage(), 0, 500), $r['id']]);
        appLog('Signup ' . $r['id'] . ' failed: ' . $x->getMessage());
        $fail('We couldn’t create the clinic: ' . $x->getMessage());
    }
    $p->prepare("UPDATE signup_requests SET verified_at = NOW(), tenant_id = ? WHERE id = ?")->execute([$t['id'], $r['id']]);
    $p->prepare("UPDATE legal_acceptances SET tenant_id = ? WHERE signup_id = ?")->execute([$t['id'], $r['id']]);
    try { billingStartTrial($t['id'], ['name' => $d['name'], 'email' => $r['email'], 'slug' => $d['slug'], 'trn' => $d['trn'] ?? '']); }
    catch (Throwable $x) { appLog("Trial billing setup for clinic {$t['id']} failed: " . $x->getMessage()); billingStartTrialLocalOnly($t['id']); }
    platformAudit(null, $t['id'], 'signup', ['email' => $r['email'], 'dha_license' => $d['dha_license'] ?? '']);
    // Store the mobile for the onboarding checklist (clinic profile)
    try {
        $db = dbConnect($t['db_name'], $t['db_user'], $t['db_pass'], $t['db_host']);
        $db->prepare("REPLACE INTO integration_settings (k, v) VALUES ('clinic.phone', ?)")->execute([$d['mobile'] ?? '']);
    } catch (Throwable $x) { appLog('Signup phone not saved: ' . $x->getMessage()); }
    header('Location: ' . tenantUrl($t, '/login.php?reset=' . $reset . '&welcome=1'));
} finally {
    $p->query("SELECT RELEASE_LOCK('crm_signup_" . (int)$r['id'] . "')");
}

/** Without Stripe the trial still starts (dates and plan only); billing is connected later from the back office. */
function billingStartTrialLocalOnly(int $tid): void {
    $plan = planByCode('growth');
    platformDB()->prepare("UPDATE tenants SET status = 'trial', plan_id = ?, trial_ends_at = NOW() + INTERVAL " . TRIAL_DAYS . " DAY WHERE id = ?")->execute([$plan['id'] ?? null, $tid]);
}

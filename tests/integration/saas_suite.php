<?php
const ROOT = __DIR__ . '/../..';   // the project folder
// Phase 15 through the real pages, the Stripe stand-in (8086) and the mail stand-in (2525):
//  - a clinic signs up on the platform address, verifies its email, gets its own database and a 14-day Growth trial
//  - plan limits: a 4th branch on Growth is refused with an upgrade message; Starter turns insurance and broadcasts off
//  - Stripe's test clock moves the trial past its end: the clinic becomes read-only (writes refused, billing and export
//    open); subscribing through Checkout makes it active again; a failed renewal gives 7 days' grace, then read-only
//  - webhooks: a bad signature is refused, a repeated event is ignored
//  - platform admins sign in with an authenticator code; a support session cannot start without the clinic's grant;
//    with one it opens read-only, is logged, can't be reused or used on another clinic, and ends when revoked
//  - the owner's full export; suspension
// The clinic it creates (database, MySQL user, platform rows, files) is removed at the end.
require ROOT . '/includes/db.php';
require ROOT . '/includes/platform_auth.php';
require ROOT . '/includes/provision.php';
if (!multiTenant()) { echo "Multi-clinic mode only.\n\n0 passed, 0 failed\n"; exit(0); }
$p = platformDB();
$pass = 0; $fail = 0;
$ok = function (bool $c, string $m) use (&$pass, &$fail) { $c ? $pass++ : $fail++; echo ($c ? "  ok   " : "  FAIL "), $m, PHP_EOL; };
$UA = 'ClinicCRM-saas-suite/1.0';
$PH = platformHost();
$http = function (string $host, string $jar, string $method, string $path, $form = [], array $headers = []) use ($UA) {
    $ch = curl_init('http://127.0.0.1:8080' . $path);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => true, CURLOPT_COOKIEJAR => $jar, CURLOPT_COOKIEFILE => $jar, CURLOPT_USERAGENT => $UA,
        CURLOPT_HTTPHEADER => array_merge(["Host: $host"], $headers), CURLOPT_TIMEOUT => 120]);
    if ($method === 'POST') { curl_setopt($ch, CURLOPT_POST, true); curl_setopt($ch, CURLOPT_POSTFIELDS, is_string($form) ? $form : http_build_query($form)); }
    $raw = (string)curl_exec($ch); $code = curl_getinfo($ch, CURLINFO_HTTP_CODE); $hs = curl_getinfo($ch, CURLINFO_HEADER_SIZE); curl_close($ch);
    preg_match('/^Location:\s*(\S+)/mi', substr($raw, 0, $hs), $m);
    return [$code, $m[1] ?? '', substr($raw, $hs), substr($raw, 0, $hs)];
};
$jar = fn() => tempnam(sys_get_temp_dir(), 'ss');
$csrf = fn(string $html) => preg_match('/name="_csrf" value="([a-f0-9]+)"/', $html, $m) ? $m[1] : (preg_match('/name="csrf-token" content="([a-f0-9]+)"/', $html, $m) ? $m[1] : '');
$mock = function (string $method, string $path, $body = null) {
    $ch = curl_init('http://127.0.0.1:8086' . $path);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_CUSTOMREQUEST => $method, CURLOPT_TIMEOUT => 60]);
    if ($body !== null) { curl_setopt($ch, CURLOPT_POSTFIELDS, is_array($body) ? json_encode($body) : $body); curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']); }
    $r = curl_exec($ch); curl_close($ch);
    return json_decode((string)$r, true);
};
$mailTo = function (string $to, float $since) {
    $files = glob(ROOT . '/local/smtp_mock_out/*.eml') ?: [];
    rsort($files);
    foreach ($files as $f) {
        if ((float)basename($f, '.eml') < $since) continue;
        $raw = file_get_contents($f);
        if (!str_contains($raw, "X-Mock-To: RCPT TO:<$to>")) continue;
        $text = '';
        foreach (preg_split('/\r?\n--b[a-f0-9]+/', $raw) as $part) if (preg_match('/base64\r?\n\r?\n([A-Za-z0-9+\/=\r\n]+)/', $part, $m)) $text .= base64_decode(preg_replace('/\s+/', '', $m[1]));
        return $text;
    }
    return '';
};
$state = fn() => $mock('GET', '/__state');

// The stand-ins, when run on their own (run_all starts them)
$procs = [];
if (!@fsockopen('127.0.0.1', 8086, $e1, $e2, 0.3)) { $procs[] = proc_open([PHP_BINARY, '-S', '127.0.0.1:8086', 'local/stripe_mock.php'], [1 => ['file', 'NUL', 'w'], 2 => ['file', 'NUL', 'w']], $pp, ROOT); usleep(600000); }
if (!@fsockopen('127.0.0.1', 2525, $e1, $e2, 0.3)) { $procs[] = proc_open([PHP_BINARY, 'local/smtp_mock.php'], [0 => ['pipe', 'r'], 1 => ['file', 'NUL', 'w'], 2 => ['file', 'NUL', 'w']], $pp, ROOT); usleep(600000); }
$mock('POST', '/__reset');
$mock('POST', '/__config', ['webhook_url' => 'http://127.0.0.1:8080/pages/api_stripe.php', 'webhook_secret' => STRIPE_WEBHOOK_SECRET, 'webhook_headers' => ["Host: $PH"]]);

$tag = (string)random_int(1000, 9999);
$slug = "trial$tag"; $host = "$slug.localhost"; $email = "owner$tag@clinic.test"; $pw = "Long enough $tag pass!";
$tid = 0; $adminEmail = "padmin$tag@platform.test"; $t0 = microtime(true);

try {
    echo "Sign-up and the trial\n";
    $v = $jar();
    [$c, , $b] = $http($PH, $v, 'GET', '/signup.php');
    $ok($c === 200 && str_contains($b, 'Start your free trial'), 'the sign-up page is on the platform address');
    [$c] = $http('main.localhost', $jar(), 'GET', '/signup.php');
    $ok($c === 404, 'and not on a clinic’s address');
    $form = ['_csrf' => $csrf($b), 'name' => "Trial Clinic $tag", 'slug' => $slug, 'dha_license' => "DHA-$tag", 'trn' => '100000000000003', 'owner_name' => "Dr Trial $tag", 'email' => $email, 'mobile' => '+971 50 123 4567'];
    [, , $b] = $http($PH, $v, 'POST', '/signup.php', $form);
    $ok(!str_contains($b, 'Check your email') && str_contains($b, 'Please accept the Terms of Service'), 'without accepting the legal documents nothing happens');
    [, , $b] = $http($PH, $v, 'POST', '/signup.php', $form + ['accept' => 1]);
    $mail = $mailTo($email, $t0);
    preg_match('#http://' . preg_quote($PH, '#') . '/verify\.php\?t=([a-f0-9]{64})#', $mail, $m);
    $ok(str_contains($b, 'Check your email') && !empty($m[1]) && !tenantBySlug($slug), 'accepted: a verification link is emailed; nothing is created yet');
    [$c, $loc] = $http($PH, $v, 'GET', '/verify.php?t=' . ($m[1] ?? ''));
    $t = tenantBySlug($slug); $tid = (int)($t['id'] ?? 0);
    $ok($c === 302 && str_starts_with($loc, "http://$host/login.php?reset=") && str_contains($loc, 'welcome=1') && $tid, 'the link creates the clinic and sends the owner to choose a password on its own address');
    $row = $p->query("SELECT t.*, pl.code FROM tenants t LEFT JOIN plans pl ON pl.id = t.plan_id WHERE t.id = $tid")->fetch();
    $sub = tenantSubscription($tid);
    $ok($row['status'] === 'trial' && $row['code'] === 'growth' && abs(strtotime($row['trial_ends_at']) - (time() + 14 * 86400)) < 300 && $row['dha_license'] === "DHA-$tag", '14-day Growth trial, DHA licence on record');
    $ok(!empty($sub['stripe_customer_id']) && $sub['status'] === 'trialing' && (int)$p->query("SELECT COUNT(*) FROM legal_acceptances WHERE tenant_id = $tid")->fetchColumn() === 3, 'a Stripe customer and trial subscription; terms, privacy and DPA acceptance filed under the clinic');
    [$c] = $http($PH, $v, 'GET', '/verify.php?t=' . ($m[1] ?? ''));
    $ok($c === 302 && (int)$p->query("SELECT COUNT(*) FROM tenants WHERE slug = " . $p->quote($slug))->fetchColumn() === 1, 'clicking the link again creates nothing more');

    $own = $jar();
    $resetPath = substr($loc, strlen("http://$host"));
    [, , $b] = $http($host, $own, 'GET', $resetPath);
    $ok(str_contains($b, 'Your clinic is ready'), 'the owner is welcomed');
    [$c, $l2] = $http($host, $own, 'POST', $resetPath, ['_csrf' => $csrf($b), 'step' => 'reset', 'new_password' => $pw, 'confirm_password' => $pw]);
    [, , $b] = $http($host, $own, 'GET', '/login.php');
    $http($host, $own, 'POST', '/login.php', ['_csrf' => $csrf($b), 'step' => 'password', 'email' => $email, 'password' => $pw]);
    [$c, , $b] = $http($host, $own, 'GET', '/pages/dashboard.php');
    $ok($c === 200 && str_contains($b, 'days left') && str_contains($b, 'of 5 done'), 'signed in: the trial banner and the set-up checklist link');
    [$c, , $b] = $http($host, $own, 'GET', '/pages/onboarding.php');
    $ok($c === 200 && substr_count($b, 'list-group-item') === 5, 'the five set-up steps');
    [$c, $l] = $http('main.localhost', $own, 'GET', '/pages/dashboard.php');
    $ok($c === 302 && str_contains($l, 'login.php'), 'the new clinic’s session means nothing on another clinic’s address');

    echo "Plan limits\n";
    $tok = $csrf($b);
    foreach (['B2', 'B3'] as $px) $http($host, $own, 'POST', '/pages/settings_clinic.php', ['_csrf' => $tok, 'action' => 'branch_add', 'name' => "Branch $px", 'invoice_prefix' => $px]);
    [, , $b] = $http($host, $own, 'POST', '/pages/settings_clinic.php', ['_csrf' => $tok, 'action' => 'branch_add', 'name' => 'Branch 4', 'invoice_prefix' => 'BF']);
    [, , $b] = $http($host, $own, 'GET', '/pages/settings_clinic.php');
    $tdb = dbConnect($t['db_name'], $t['db_user'], $t['db_pass'], $t['db_host']);
    $ok((int)$tdb->query("SELECT COUNT(*) FROM branches WHERE is_active = 1")->fetchColumn() === 3 && str_contains($b, 'Growth plan includes 3 branches'), 'Growth: the 4th branch is refused with an upgrade message');
    [$c] = $http($host, $own, 'GET', '/pages/claims.php');
    $ok($c === 200, 'Growth includes insurance claims');

    echo "Stripe test clock: trial over → read-only; subscribing → active\n";
    $S = $state();
    $clock = $S['customers'][$sub['stripe_customer_id']]['test_clock'] ?? '';
    stripeApi('POST', "/v1/test_helpers/test_clocks/$clock/advance", ['frozen_time' => time() + 15 * 86400]);
    $ok($p->query("SELECT status FROM tenants WHERE id = $tid")->fetchColumn() === 'read_only', 'the test clock passes the trial end without a card: Stripe pauses it and the clinic is read-only');
    [$c, , $b] = $http($host, $own, 'GET', '/pages/dashboard.php');
    $ok($c === 200 && str_contains($b, 'read-only because the trial ended'), 'staff can still look, with a banner saying why');
    $before = (int)$tdb->query("SELECT COUNT(*) FROM branches")->fetchColumn();
    [$c] = $http($host, $own, 'POST', '/pages/settings_clinic.php', ['_csrf' => $tok, 'action' => 'branch_add', 'name' => 'Nope', 'invoice_prefix' => 'NO']);
    [$c2, , $j] = $http($host, $own, 'POST', '/pages/api.php', ['action' => 'change_stage', 'lead_id' => 1, 'stage' => 'lost'], ["X-CSRF-Token: $tok"]);
    $ok($c === 423 && $c2 === 423 && str_contains($j, 'read-only') && (int)$tdb->query("SELECT COUNT(*) FROM branches")->fetchColumn() === $before, 'every change is refused, pages and API alike (one central guard)');
    [$c, , $b] = $http($host, $own, 'GET', '/pages/subscription.php');
    $ok($c === 200 && str_contains($b, 'Monthly') && str_contains($b, 'Read-only'), 'the Subscription page stays open');
    [$c, $loc] = $http($host, $own, 'POST', '/pages/subscription.php', ['_csrf' => $csrf($b), 'plan' => 'growth', 'interval' => 'month']);
    $ok($c === 303 && str_starts_with($loc, 'http://127.0.0.1:8086/checkout/cs_'), 'choosing Growth monthly goes to Stripe Checkout');
    $oldSub = $sub['stripe_subscription_id'];
    $ch = curl_init($loc . '/pay'); curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_RETURNTRANSFER => true]); curl_exec($ch); curl_close($ch);
    $sub = tenantSubscription($tid);
    $S = $state();
    $ok($p->query("SELECT status FROM tenants WHERE id = $tid")->fetchColumn() === 'active' && $sub['stripe_subscription_id'] !== $oldSub && $sub['status'] === 'active'
        && ($S['subscriptions'][$oldSub]['status'] ?? '') === 'canceled', 'paying makes the clinic active again; the paused trial subscription is cancelled');
    [$c] = $http($host, $own, 'POST', '/pages/settings_clinic.php', ['_csrf' => $tok, 'action' => 'branch_add', 'name' => 'Nope', 'invoice_prefix' => 'NO']);
    $ok($c !== 423, 'changes are accepted again');

    echo "A failed renewal\n";
    $mock('POST', '/__fail_next/' . $sub['stripe_customer_id']);
    stripeApi('POST', "/v1/test_helpers/test_clocks/$clock/advance", ['frozen_time' => time() + 15 * 86400 + 31 * 86400]);
    $sub = tenantSubscription($tid);
    $ok($p->query("SELECT status FROM tenants WHERE id = $tid")->fetchColumn() === 'past_due' && !empty($sub['grace_until']) && abs(strtotime($sub['grace_until']) - (time() + 7 * 86400)) < 300, 'payment failed: overdue with 7 days’ grace');
    [, , $b] = $http($host, $own, 'GET', '/pages/dashboard.php');
    $ok(str_contains($b, 'last payment failed'), 'a banner asks to update the card');
    $p->exec("UPDATE subscriptions SET grace_until = NOW() - INTERVAL 1 MINUTE WHERE tenant_id = $tid");
    billingSweep();
    $ok($p->query("SELECT status FROM tenants WHERE id = $tid")->fetchColumn() === 'read_only', 'grace over: read-only (cron)');

    echo "Webhook safety\n";
    $ev = json_encode(['id' => "evt_forged$tag", 'type' => 'invoice.paid', 'data' => ['object' => ['customer' => $sub['stripe_customer_id']]]]);
    [$c] = $http($PH, $jar(), 'POST', '/pages/api_stripe.php', $ev, ['Content-Type: application/json', 'Stripe-Signature: t=' . time() . ',v1=' . hash_hmac('sha256', time() . ".$ev", 'wrong')]);
    $ok($c === 400 && $p->query("SELECT status FROM tenants WHERE id = $tid")->fetchColumn() === 'read_only', 'a forged “invoice paid” is refused (signature)');
    $sig = fn($body) => 'Stripe-Signature: t=' . time() . ',v1=' . hash_hmac('sha256', time() . ".$body", STRIPE_WEBHOOK_SECRET);
    [$c, , $r1] = $http($PH, $jar(), 'POST', '/pages/api_stripe.php', $ev, ['Content-Type: application/json', $sig($ev)]);
    [$c2, , $r2] = $http($PH, $jar(), 'POST', '/pages/api_stripe.php', $ev, ['Content-Type: application/json', $sig($ev)]);
    $ok(str_contains($r1, '"paid"') && str_contains($r2, 'duplicate') && $p->query("SELECT status FROM tenants WHERE id = $tid")->fetchColumn() === 'active', 'a signed one is applied once; the repeat is ignored');

    echo "Platform back office and support access\n";
    $out = (string)shell_exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(ROOT . '/tools/platform_admin.php') . " --email=$adminEmail --name=\"Pat Platform $tag\" --role=owner 2>&1");
    preg_match('/\n  (\S+)\n/', $out, $m); $apw = $m[1] ?? '';
    $pa = $jar();
    [, , $b] = $http($PH, $pa, 'GET', '/platform/index.php');
    $http($PH, $pa, 'POST', '/platform/index.php', ['_csrf' => $csrf($b), 'step' => 'password', 'email' => $adminEmail, 'password' => 'wrong']);
    [, , $b] = $http($PH, $pa, 'POST', '/platform/index.php', ['_csrf' => $csrf($b), 'step' => 'password', 'email' => $adminEmail, 'password' => $apw]);
    preg_match('/secret=([A-Z2-7]{32})/', $b, $sm);
    $ok($apw !== '' && !empty($sm[1]) && str_contains($b, 'Set up 2-step sign-in'), 'a platform admin: password, then the authenticator set-up (2-step is mandatory)');
    $http($PH, $pa, 'POST', '/platform/index.php', ['_csrf' => $csrf($b), 'step' => 'code', 'code' => '000000']);
    [, , $b] = $http($PH, $pa, 'GET', '/platform/index.php');
    $ok(!str_contains($b, 'MRR'), 'a wrong code doesn’t get in');
    [, , $b] = $http($PH, $pa, 'POST', '/platform/index.php', ['_csrf' => $csrf($b), 'step' => 'code', 'code' => totpCode($sm[1] ?? '', intdiv(time(), 30))]);
    [$c, , $b] = $http($PH, $pa, 'GET', '/platform/index.php');
    $ok($c === 200 && str_contains($b, 'MRR') && str_contains($b, 'Trial → paid'), 'with the right code: the overview');
    [$c, , $b] = $http($PH, $pa, 'GET', '/platform/index.php?view=tenants&q=' . $slug);
    $ok(str_contains($b, "Trial Clinic $tag") && !str_contains($b, 'Emirates'), 'the clinics list (no patient data)');
    [$c, , $b] = $http($PH, $pa, 'GET', "/platform/tenant.php?id=$tid");
    $ptok = $csrf($b);
    [$c, $l, $b] = $http($PH, $pa, 'POST', "/platform/tenant.php?id=$tid", ['_csrf' => $ptok, 'action' => 'support', 'reason' => 'Clinic asked for help']);
    $ok($c === 200 && $l === '' && str_contains($b, 'no open support grant'), 'a support session cannot start without the clinic’s grant');
    [, , $b] = $http($host, $own, 'GET', '/pages/support_access.php');
    $http($host, $own, 'POST', '/pages/support_access.php', ['_csrf' => $csrf($b), 'reason' => 'Invoice layout question', 'hours' => 1, 'mode' => 'read_only']);
    $gid = (int)$p->query("SELECT id FROM support_grants WHERE tenant_id = $tid ORDER BY id DESC LIMIT 1")->fetchColumn();
    $ok($gid > 0, 'the clinic’s admin allows support for one hour, read-only');
    [$c, $l] = $http($PH, $pa, 'POST', "/platform/tenant.php?id=$tid", ['_csrf' => $ptok, 'action' => 'support', 'reason' => 'Invoice layout question']);
    $ok($c === 303 && str_starts_with($l, "http://$host/support_login.php?t="), 'now the back office can open the clinic');
    $sj = $jar(); $ticketPath = substr($l, strlen("http://$host"));
    [$c, $l2] = $http($host, $sj, 'GET', $ticketPath);
    [$c3, , $b] = $http($host, $sj, 'GET', '/pages/dashboard.php');
    $ok($c === 302 && $c3 === 200 && str_contains($b, 'Support session by') && str_contains($b, 'read-only'), 'support lands in the clinic with a banner on every page');
    [$c] = $http($host, $sj, 'POST', '/pages/settings_clinic.php', ['_csrf' => $csrf($b), 'action' => 'branch_add', 'name' => 'By support', 'invoice_prefix' => 'SU']);
    $ok($c === 423, 'a read-only support session can’t change anything');
    $views = (int)$tdb->query("SELECT COUNT(*) FROM audit_log a JOIN staff s ON s.id = a.staff_id AND s.is_support = 1 WHERE a.entity = 'support_view'")->fetchColumn();
    $ok($views >= 2 && (int)$tdb->query("SELECT COUNT(*) FROM audit_log WHERE entity = 'support_session' AND action = 'started'")->fetchColumn() === 1, 'every page support opened is in the clinic’s audit log');
    [, , $b] = $http($host, $own, 'GET', '/pages/support_access.php');
    $ok(str_contains($b, 'Support · Pat Platform') && str_contains($b, 'support_view'), 'the clinic sees it under Support access');
    [$c] = $http($host, $jar(), 'GET', $ticketPath);
    $ok($c === 403, 'the same link can’t be used twice');
    [$c] = $http('main.localhost', $jar(), 'GET', '/support_login.php?t=' . supportTicket($gid, (int)$p->query("SELECT id FROM platform_admins WHERE email = " . $p->quote($adminEmail))->fetchColumn()));
    $ok($c === 403, 'a valid ticket for this clinic’s grant opens nothing at another clinic');
    [$c, $l] = $http($host, $sj, 'GET', '/logout.php');
    [, , $b] = $http($host, $own, 'GET', '/pages/support_access.php');
    $http($host, $own, 'POST', '/pages/support_access.php', ['_csrf' => $csrf($b), 'action' => 'revoke', 'id' => $gid]);
    [$c, $l] = $http($PH, $pa, 'POST', "/platform/tenant.php?id=$tid", ['_csrf' => $ptok, 'action' => 'support', 'reason' => 'One more look']);
    $ok($c === 200 && $l === '', 'after the clinic revokes it, support can’t open the clinic again');
    [$c] = $http($PH, $pa, 'POST', "/platform/tenant.php?id=$tid", ['_csrf' => $ptok, 'action' => 'set_plan', 'plan_id' => (int)$p->query("SELECT id FROM plans WHERE code = 'starter'")->fetchColumn(), 'reason' => 'Downgrade test']);
    [$c1] = $http($host, $own, 'GET', '/pages/claims.php'); [$c2] = $http($host, $own, 'GET', '/pages/broadcasts.php');
    [, , $b] = $http($host, $own, 'GET', '/pages/dashboard.php');
    $ok($c1 === 403 && $c2 === 403 && !str_contains($b, '/pages/claims.php'), 'on Starter, insurance and broadcasts are off (pages and menu)');
    $ok((int)$p->query("SELECT COUNT(*) FROM platform_audit_log WHERE tenant_id = $tid AND action = 'plan_set'")->fetchColumn() === 1, 'the plan change is in the platform audit log with its reason');

    echo "Export and suspension\n";
    [, , $b] = $http($host, $own, 'GET', '/pages/export_all.php');
    [$c, , $gz, $hd] = $http($host, $own, 'POST', '/pages/export_all.php', ['_csrf' => $csrf($b)]);
    $tar = (string)@gzdecode($gz);
    $ok($c === 200 && str_contains($hd, 'application/gzip') && str_contains($tar, 'tables/patients.json') && str_contains($tar, 'tables/branches.csv') && !str_contains($tar, '$2y$'), 'the owner downloads everything (tables as JSON and CSV), without password hashes');
    $http($PH, $pa, 'POST', "/platform/tenant.php?id=$tid", ['_csrf' => $ptok, 'action' => 'suspend', 'reason' => 'Suspension test']);
    [$c, $l] = $http($host, $own, 'GET', '/pages/patients.php');
    $ok($c === 302 && str_contains($l, '/pages/subscription.php'), 'suspended: staff see only the Subscription page');
} finally {
    // Remove everything this suite created
    if ($tid) {
        $t = tenantBySlug($slug);
        try { $root = provisionRoot(); $root->exec("DROP DATABASE IF EXISTS `{$t['db_name']}`"); $root->exec("DROP USER IF EXISTS " . $root->quote($t['db_user']) . "@" . $root->quote(defined('TENANT_DB_USER_HOST') ? TENANT_DB_USER_HOST : '%')); }
        catch (Throwable $e) { echo "  cleanup: {$e->getMessage()}\n"; }
        foreach (['tenant_domains', 'subscriptions', 'usage_counters', 'support_grants', 'platform_audit_log', 'legal_acceptances', 'trial_emails'] as $tb) $p->exec("DELETE FROM $tb WHERE tenant_id = $tid");
        $p->exec("DELETE FROM tenants WHERE id = $tid");
        $dir = rtrim(STORAGE_PATH, '/\\') . "/$tid";
        if (is_dir($dir)) { foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST) as $f) $f->isDir() ? @rmdir($f) : @unlink($f); @rmdir($dir); }
    }
    $p->exec("DELETE FROM signup_requests WHERE email = " . $p->quote($email));
    $p->exec("DELETE FROM legal_acceptances WHERE accepted_by_email = " . $p->quote($email));
    $aid = (int)$p->query("SELECT id FROM platform_admins WHERE email = " . $p->quote($adminEmail))->fetchColumn();
    if ($aid) { $p->exec("DELETE FROM platform_audit_log WHERE admin_id = $aid"); $p->exec("DELETE FROM platform_admins WHERE id = $aid"); }
    $p->exec("DELETE FROM platform_audit_log WHERE action IN ('platform_admin_added', 'sign_in_failed') AND at > NOW() - INTERVAL 1 HOUR");
    $p->exec("DELETE FROM webhook_events WHERE (provider = 'stripe' AND (payload LIKE '%_mock%' OR event_id LIKE 'evt_forged%')) OR provider = 'support'");
    foreach ($procs as $pr) { proc_terminate($pr); proc_close($pr); }
}
echo "\n$pass passed, $fail failed\n";
exit($fail ? 1 : 0);

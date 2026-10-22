<?php
// tests/smoke.php — signs in as every local test account and opens every page in that person's menu, plus a few detail
// pages and the public endpoints. Fails on any error status, PHP warning/fatal, or an unexpected trip back to sign-in.
//   php tests/smoke.php                 (needs the local web server: preview "clinic-crm" on http://127.0.0.1:8080)
//   php tests/smoke.php --base=http://127.0.0.1:8080
// Local copy only: it reads local/test_accounts.txt and tidies the sign-in/email log rows it creates afterwards.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__ . '/../includes/db.php';
if (APP_ENV !== 'local') { fwrite(STDERR, "The smoke test only runs against a local copy.\n"); exit(2); }

$base = rtrim(getopt('', ['base:'])['base'] ?? 'http://127.0.0.1:8080', '/');
$accFile = __DIR__ . '/../local/test_accounts.txt';
if (!is_file($accFile)) { fwrite(STDERR, "local/test_accounts.txt is missing.\n"); exit(2); }
$acc = file($accFile, FILE_IGNORE_NEW_LINES);
$emails = array_map('trim', explode('/', $acc[1] ?? ''));
$password = trim(preg_replace('/^Password:\s*/i', '', $acc[2] ?? ''));

$db = getDB();
$mark = [];
foreach (['login_log', 'email_log'] as $t) {
    try { $mark[$t] = (int)$db->query("SELECT COALESCE(MAX(id),0) FROM $t")->fetchColumn(); } catch (PDOException $e) {}
}

$pass = 0; $fail = 0;
$ok = function (bool $c, string $m) use (&$pass, &$fail) { $c ? $pass++ : $fail++; if (!$c) echo "  FAIL $m\n"; };
/** One request with a cookie jar: [status, location, body]. */
$http = function (string $jar, string $method, string $path, array $form = []) use ($base) {
    $ch = curl_init($base . $path);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => true, CURLOPT_COOKIEJAR => $jar, CURLOPT_COOKIEFILE => $jar,
        CURLOPT_TIMEOUT => 60, CURLOPT_USERAGENT => 'ClinicCRM-smoke/1.0 (' . basename($jar) . ')']);
    if ($method === 'POST') { curl_setopt($ch, CURLOPT_POST, true); curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($form)); }
    $raw = curl_exec($ch);
    if ($raw === false) return [0, '', curl_error($ch)];
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE); $hs = curl_getinfo($ch, CURLINFO_HEADER_SIZE); curl_close($ch);
    preg_match('/^Location:\s*(\S+)/mi', substr($raw, 0, $hs), $m);
    return [$code, $m[1] ?? '', substr($raw, $hs)];
};
$phpError = fn(string $html) => preg_match('/(Fatal error|Parse error|Uncaught |<b>Warning<\/b>|<b>Notice<\/b>|<b>Deprecated<\/b>|Warning: |Notice: |Deprecated: )/', $html, $m) ? $m[1] : null;

[$c] = $http(tempnam(sys_get_temp_dir(), 'sm'), 'GET', '/login.php');
if ($c !== 200) { fwrite(STDERR, "The local web server isn't answering on $base (start the \"clinic-crm\" preview).\n"); exit(2); }

echo "Public endpoints\n";
$j = tempnam(sys_get_temp_dir(), 'sm');
[$c, , $b] = $http($j, 'GET', '/login.php');
$ok($c === 200 && str_contains($b, 'name="_csrf"') && !$phpError($b), 'sign-in page');
[$c] = $http($j, 'GET', '/pages/dashboard.php');
$ok($c === 302, 'pages need sign-in (dashboard redirects)');
[$c] = $http($j, 'GET', '/pages/api_public_lead.php');
$ok($c === 405, 'public lead API refuses GET');
[$c] = $http($j, 'GET', '/pages/meta_receiver.php');
$ok($c === 403, 'Meta receiver refuses an unverified GET');
[$c] = $http($j, 'GET', '/pages/api_whatsapp.php?action=ping');
$ok($c === 401, 'WhatsApp API needs a key');
[$c] = $http($j, 'GET', '/cron.php');
$ok($c === 403, 'cron cannot be run from the web');

foreach ($emails as $email) {
    if ($email === '') continue;
    echo "$email\n";
    $jar = tempnam(sys_get_temp_dir(), 'sm');
    [, , $page] = $http($jar, 'GET', '/login.php');
    preg_match('/name="_csrf" value="([a-f0-9]+)"/', $page, $m);
    [$c, $loc, $body] = $http($jar, 'POST', '/login.php', ['_csrf' => $m[1] ?? '', 'step' => 'password', 'email' => $email, 'password' => $password]);
    if (str_contains($body, 'Check your email')) { $ok(false, "$email: 2-step sign-in is on in the local copy (Staff → 2-step for everyone → off for the smoke test)"); continue; }
    if ($loc !== '/pages/dashboard.php') { $ok(false, "$email: sign-in failed ($c " . trim(preg_replace('/\s+/', ' ', strip_tags($body))) . ')'); continue; }
    [$c, , $dash] = $http($jar, 'GET', '/pages/dashboard.php');
    $ok($c === 200 && !$phpError($dash), "$email: dashboard" . ($phpError($dash) ? ' — ' . $phpError($dash) : ''));
    preg_match_all('/<a class="side-link[^"]*" href="([^"]+)"/', $dash, $links);
    $pages = array_unique(array_merge($links[1], ['/pages/account.php']));
    // A few detail pages, when this person can see one
    [, , $list] = $http($jar, 'GET', '/pages/leads.php?view=list');
    if (preg_match('#/pages/lead_view\.php\?id=(\d+)#', $list, $lm)) $pages[] = "/pages/lead_view.php?id={$lm[1]}";
    [, , $ap] = $http($jar, 'GET', '/pages/appointments.php');
    if (preg_match('#/pages/appointment\.php\?id=(\d+)#', $ap, $am)) $pages[] = "/pages/appointment.php?id={$am[1]}";
    $n = 0;
    foreach ($pages as $p) {
        [$c, $loc, $body] = $http($jar, 'GET', $p);
        $err = $phpError($body);
        $ok($c === 200 && !$err, "$email: $p → $c" . ($loc ? " → $loc" : '') . ($err ? " — $err" : ''));
        $n++;
    }
    echo "  $n pages\n";
    $http($jar, 'GET', '/logout.php');
    @unlink($jar);
}

// Tidy up the rows this run created (local copy only)
if (currentTenant()['db_name'] === 'clinic_crm_local') foreach ($mark as $t => $max) $db->exec("DELETE FROM $t WHERE id > $max");
echo "\n$pass passed, $fail failed\n";
exit($fail ? 1 : 0);

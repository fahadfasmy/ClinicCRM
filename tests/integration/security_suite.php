<?php
const ROOT = __DIR__ . '/../..';   // the project folder
// Sign-in security checks through the real pages (local server 8080) + stand-in mail server (2525).
require ROOT . '/includes/auth.php';
require ROOT . '/includes/inventory.php';
require ROOT . '/includes/security.php';
$db = getDB();
ensureSecuritySchema($db); ensureMailSchema($db);
$pass = 0; $fail = 0;
$ok = function (bool $c, string $m) use (&$pass, &$fail) { $c ? $pass++ : $fail++; echo ($c ? "  ok   " : "  FAIL "), $m, PHP_EOL; };
$BASE = 'http://127.0.0.1:8080';
$OUT = ROOT . '/local/smtp_mock_out';
array_map('unlink', glob("$OUT/*.eml") ?: []);
$settingsBefore = $db->query("SELECT k, v FROM integration_settings")->fetchAll(PDO::FETCH_KEY_PAIR);
$maxLog = (int)$db->query("SELECT COALESCE(MAX(id),0) FROM email_log")->fetchColumn();
$maxAct = (int)$db->query("SELECT COALESCE(MAX(id),0) FROM activity_log")->fetchColumn();

$email = 'sectest@example.test';
$pw = 'Correct horse battery 7';
$db->prepare("DELETE FROM staff WHERE email = ?")->execute([$email]);
$db->prepare("INSERT INTO staff (name, email, password, role) VALUES ('Sec Tester', ?, ?, 'consultant')")->execute([$email, password_hash($pw, PASSWORD_BCRYPT)]);
$sid = (int)$db->lastInsertId();
$db->exec("DELETE FROM login_log WHERE email = '$email' OR ip = '127.0.0.1'");

/** A tiny browser: keeps cookies per jar, returns [status, location, body]. */
$http = function (string $jar, string $method, string $path, array $form = []) use ($BASE) {
    $ch = curl_init($BASE . $path);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => true, CURLOPT_COOKIEJAR => $jar, CURLOPT_COOKIEFILE => $jar, CURLOPT_FOLLOWLOCATION => false, CURLOPT_USERAGENT => "Mozilla/5.0 (Windows NT 10.0) Chrome/120 TestJar-" . basename($jar)]);
    if ($method === 'POST') { curl_setopt($ch, CURLOPT_POST, true); curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($form)); }
    $raw = curl_exec($ch); $code = curl_getinfo($ch, CURLINFO_HTTP_CODE); $hs = curl_getinfo($ch, CURLINFO_HEADER_SIZE); curl_close($ch);
    $head = substr($raw, 0, $hs);
    preg_match('/^Location:\s*(\S+)/mi', $head, $m);
    return [$code, $m[1] ?? '', substr($raw, $hs), $head];
};
$csrf = fn(string $html) => preg_match('/name="_csrf" value="([a-f0-9]+)"/', $html, $m) ? $m[1] : '';
$jar = fn(string $n) => sys_get_temp_dir() . "/sec_$n.txt";
foreach (['a', 'b', 'c', 'd'] as $n) @unlink($jar($n));
$signIn = function (string $j, string $e, string $p) use ($http, $csrf) {
    [, , $page] = $http($j, 'GET', '/login.php');
    return $http($j, 'POST', '/login.php', ['_csrf' => $csrf($page), 'step' => 'password', 'email' => $e, 'password' => $p]);
};
$lastMail = function () use ($OUT) {
    $f = glob("$OUT/*.eml"); if (!$f) return ['', '', ''];
    $raw = file_get_contents(end($f));
    preg_match_all('/Content-Transfer-Encoding: base64\r\n\r\n(.+?)\r\n--/s', $raw, $p);
    preg_match('/^X-Mock-To: RCPT TO:<([^>]+)>/m', $raw, $to);
    $sub = preg_match('/^Subject: (.+(?:\r\n .+)*)/m', $raw, $s) ? mb_decode_mimeheader(str_replace("\r\n ", ' ', $s[1])) : '';
    return [$to[1] ?? '', $sub, base64_decode($p[1][1] ?? '')];
};
$code = fn() => preg_match('/letter-spacing:6px[^>]*>(\d{6})</', $lastMail()[2], $m) ? $m[1] : '';

echo "Passwords\n";
$P = fn($p) => secPasswordProblems($p, ['name' => 'Sec Tester', 'email' => $email]);
$ok((bool)$P('short1') && (bool)$P('password123') && (bool)$P('aaaaaaaaaaaa') && (bool)$P('sectest-is-me-2026') && (bool)$P('Sec tester rocks 1'), 'too short / common / repeated / contains email or name → refused');
$ok(!$P('Blue tea at nine 42'), 'a long phrase is accepted');
$ok((bool)secPasswordProblems($pw, ['name' => 'X', 'email' => 'x@y.z'], password_hash($pw, PASSWORD_BCRYPT)), 'same as current → refused');

echo "Sign-in without email set up (no code asked)\n";
$db->exec("DELETE FROM integration_settings WHERE k LIKE 'mail.%'");
[$c, $loc] = $signIn($jar('a'), $email, $pw);
$ok($c === 302 && $loc === '/pages/dashboard.php', 'right password → straight in while email isn’t set up');
[$c] = $http($jar('a'), 'GET', '/pages/dashboard.php');
$ok($c === 200, 'dashboard opens');
[$c, , $body] = $signIn($jar('b'), 'nobody@example.test', 'whatever123');
$ok(str_contains($body, 'don’t match an active account'), 'unknown email → same message as a wrong password');

echo "Lockout\n";
for ($i = 0; $i < 5; $i++) $signIn($jar('b'), $email, 'wrong password ' . $i);
[$c, $loc, $body] = $signIn($jar('b'), $email, $pw);
$ok($loc === '' && str_contains($body, 'Too many unsuccessful attempts'), '5 wrong passwords → paused, even the right password waits');
$db->exec("UPDATE login_log SET created_at = created_at - INTERVAL 16 MINUTE WHERE email = '$email' AND result = 'bad_password'");
[, $loc] = $signIn($jar('b'), $email, $pw);
$ok($loc === '/pages/dashboard.php', 'after 15 minutes the right password works again');

echo "Sessions end when they should\n";
secSetPassword($db, $sid, $pw);   // e.g. password changed elsewhere
[$c, $loc] = $http($jar('a'), 'GET', '/pages/dashboard.php');
$ok($loc === '/login.php?signed_out=1', 'password changed → other sessions are signed out on their next click');
[, $loc] = $signIn($jar('a'), $email, $pw);
$db->exec("UPDATE staff SET is_active = 0 WHERE id = $sid");
[, $loc] = $http($jar('a'), 'GET', '/pages/leads.php');
$ok($loc === '/login.php?signed_out=1', 'deactivated → signed out at once');
$db->exec("UPDATE staff SET is_active = 1 WHERE id = $sid");

echo "2-step sign-in by email\n";
foreach (['host' => '127.0.0.1', 'port' => '2525', 'security' => 'none', 'password' => 'good-pass'] as $k => $v) mailSaveSetting($db, $k, $v);
@unlink($jar('c'));
[$c, $loc, $body] = $signIn($jar('c'), $email, $pw);
$ok($loc === '' && str_contains($body, 'Check your email') && str_contains($body, 's•••t@example.test'), 'right password → asks for the emailed code (address partly hidden)');
[$to, $sub] = $lastMail();
$first = $code();
$ok($to === $email && strlen($first) === 6 && $sub === 'Your Clinic CRM sign-in code', 'code emailed to the staff member; subject doesn’t contain the code');
$ok(!str_contains((string)$db->query("SELECT GROUP_CONCAT(subject) FROM email_log WHERE id > $maxLog")->fetchColumn(), $first), 'the code never appears in the email log');
[, , $page] = $http($jar('c'), 'GET', '/login.php');
[$c, $loc] = $http($jar('c'), 'GET', '/pages/dashboard.php');
$ok($loc === '/login.php', 'not signed in until the code is entered');
[, , $body] = $http($jar('c'), 'POST', '/login.php', ['_csrf' => $csrf($page), 'step' => 'code', 'code' => $first === '000000' ? '111111' : '000000']);
$ok(str_contains($body, 'That code isn’t right'), 'wrong code → refused');
[, , $body] = $http($jar('c'), 'POST', '/login.php', ['_csrf' => $csrf($page), 'step' => 'resend']);
$ok(str_contains($body, 'Wait a minute'), 'new code limited to one a minute');
[$c, $loc, , $head] = $http($jar('c'), 'POST', '/login.php', ['_csrf' => $csrf($page), 'step' => 'code', 'code' => $first, 'trust' => '1']);
$ok($loc === '/pages/dashboard.php' && str_contains($head, 'crm_trusted=') && stripos($head, 'httponly') !== false, 'right code → signed in, device trusted (cookie hidden from scripts)');
$ok($db->query("SELECT COUNT(*) FROM login_codes WHERE staff_id = $sid")->fetchColumn() == 0, 'a used code can’t be used again');
$ok(str_contains($lastMail()[1], 'New sign-in'), 'first sign-in from this browser → “new sign-in” alert emailed');
$http($jar('c'), 'GET', '/logout.php');
$n = count(glob("$OUT/*.eml"));
[, $loc] = $signIn($jar('c'), $email, $pw);
$ok($loc === '/pages/dashboard.php' && count(glob("$OUT/*.eml")) === $n, 'trusted device → no code next time, no alert for a known browser');

echo "Codes: expiry, tries, backup codes\n";
@unlink($jar('d'));
$signIn($jar('d'), $email, $pw);
[, , $page] = $http($jar('d'), 'GET', '/login.php');
$db->exec("UPDATE login_codes SET expires_at = NOW() - INTERVAL 1 MINUTE WHERE staff_id = $sid");
[, , $body] = $http($jar('d'), 'POST', '/login.php', ['_csrf' => $csrf($page), 'step' => 'code', 'code' => $code()]);
$ok(str_contains($body, 'expired'), 'code older than 10 minutes → expired');
$db->exec("UPDATE login_codes SET sent_at = NOW() - INTERVAL 2 MINUTE WHERE staff_id = $sid");
$http($jar('d'), 'POST', '/login.php', ['_csrf' => $csrf($page), 'step' => 'resend']);
$c2 = $code();
for ($i = 0; $i < 5; $i++) $http($jar('d'), 'POST', '/login.php', ['_csrf' => $csrf($page), 'step' => 'code', 'code' => '99999' . $i]);
[, , $body] = $http($jar('d'), 'POST', '/login.php', ['_csrf' => $csrf($page), 'step' => 'code', 'code' => $c2]);
$ok(str_contains($body, 'Too many wrong codes'), '5 wrong codes → that code stops working, even the right one');
$codes = secNewBackupCodes($db, $sid);
[, $loc] = $http($jar('d'), 'POST', '/login.php', ['_csrf' => $csrf($page), 'step' => 'code', 'code' => strtoupper($codes[3])]);
$ok($loc === '/pages/dashboard.php' && secBackupCodesLeft($db, $sid) === 9, 'backup code works (any case, with dash), 9 left');
$http($jar('d'), 'GET', '/logout.php');
$db->exec("DELETE FROM trusted_devices WHERE staff_id = $sid");
$signIn($jar('d'), $email, $pw);
[, , $page] = $http($jar('d'), 'GET', '/login.php');
[, , $body] = $http($jar('d'), 'POST', '/login.php', ['_csrf' => $csrf($page), 'step' => 'code', 'code' => $codes[3]]);
$ok(str_contains($body, 'isn’t right'), 'a backup code works only once');
$http($jar('d'), 'GET', '/login.php?cancel=1');
$db->exec("DELETE FROM login_log WHERE email = '$email' OR ip = '127.0.0.1'");

echo "My account\n";
[, , $acct] = $http($jar('c'), 'GET', '/pages/account.php');
$ok(str_contains($acct, 'Change my password') && str_contains($acct, 'On.') && str_contains($acct, 'Trusted devices'), 'My account page shows password, 2-step on, trusted devices');
[, , $acct] = $http($jar('c'), 'GET', '/pages/account.php');
$http($jar('c'), 'POST', '/pages/account.php', ['_csrf' => $csrf($acct), 'action' => 'password', 'current_password' => 'not it', 'new_password' => 'Green kite over 9 dunes', 'confirm_password' => 'Green kite over 9 dunes']);
[, , $acct] = $http($jar('c'), 'GET', '/pages/account.php');
$ok(str_contains($acct, 'current password isn’t right'), 'wrong current password → refused');
$signIn($jar('a'), $email, $pw);   // a second session elsewhere (trusted? no: jar a has no device cookie → code)
$new = 'Green kite over 9 dunes';
$http($jar('c'), 'POST', '/pages/account.php', ['_csrf' => $csrf($acct), 'action' => 'password', 'current_password' => $pw, 'new_password' => $new, 'confirm_password' => $new]);
[$c, $loc, $acct] = $http($jar('c'), 'GET', '/pages/account.php');
$ok($c === 200 && str_contains($acct, 'Password changed') && password_verify($new, $db->query("SELECT password FROM staff WHERE id = $sid")->fetchColumn()), 'password changed; still signed in on this device');
$ok(str_contains($lastMail()[1], 'password was changed'), '“password changed” email sent');
$http($jar('c'), 'POST', '/pages/account.php', ['_csrf' => $csrf($acct), 'action' => 'codes']);
$ok(secBackupCodesLeft($db, $sid) === 10, 'new backup codes created (old ones replaced)');

echo "Forgot password\n";
@unlink($jar('b'));
[, , $page] = $http($jar('b'), 'GET', '/login.php?forgot=1');
$n = count(glob("$OUT/*.eml"));
[, , $body] = $http($jar('b'), 'POST', '/login.php?forgot=1', ['_csrf' => $csrf($page), 'step' => 'forgot', 'email' => 'nobody@example.test']);
$ok(str_contains($body, 'If that email belongs') && count(glob("$OUT/*.eml")) === $n, 'unknown email → same message, nothing sent');
[, , $body] = $http($jar('b'), 'POST', '/login.php?forgot=1', ['_csrf' => $csrf($page), 'step' => 'forgot', 'email' => $email]);
preg_match('#/login\.php\?reset=([a-f0-9]{64})#', $lastMail()[2], $m);
$token = $m[1] ?? '';
$ok($token !== '' && $lastMail()[0] === $email, 'reset link emailed');
$ok($db->query("SELECT COUNT(*) FROM password_resets WHERE token_hash = '$token'")->fetchColumn() == 0, 'only a fingerprint of the link is stored, not the link');
[, , $page] = $http($jar('b'), 'GET', "/login.php?reset=$token");
$ok(str_contains($page, 'Choose a new password'), 'link opens the new-password form');
[, , $body] = $http($jar('b'), 'POST', "/login.php?reset=$token", ['_csrf' => $csrf($page), 'step' => 'reset', 'new_password' => 'password123', 'confirm_password' => 'password123']);
$ok(str_contains($body, 'too easy'), 'weak password refused on the reset form too');
$final = 'Purple lamp beside 3 hills';
[, $loc] = $http($jar('b'), 'POST', "/login.php?reset=$token", ['_csrf' => $csrf($page), 'step' => 'reset', 'new_password' => $final, 'confirm_password' => $final]);
$ok($loc === '/login.php?reset_done=1' && password_verify($final, $db->query("SELECT password FROM staff WHERE id = $sid")->fetchColumn()), 'new password saved');
[, $loc] = $http($jar('c'), 'GET', '/pages/dashboard.php');
$ok($loc === '/login.php?signed_out=1' && $db->query("SELECT COUNT(*) FROM trusted_devices WHERE staff_id = $sid")->fetchColumn() == 0, 'reset signs out everywhere and forgets trusted devices');
[, , $body] = $http($jar('b'), 'GET', "/login.php?reset=$token");
$ok(str_contains($body, 'expired or was already used'), 'the link works only once');
$db->exec("INSERT INTO password_resets (token_hash, staff_id, expires_at) VALUES ('" . hash('sha256', str_repeat('ab', 32)) . "', $sid, NOW() - INTERVAL 1 MINUTE)");
[, , $body] = $http($jar('b'), 'GET', '/login.php?reset=' . str_repeat('ab', 32));
$ok(str_contains($body, 'expired'), 'links older than 30 minutes don’t work');

echo "Admin\n";
mailSaveSetting($db, 'require_2fa', '0');
@unlink($jar('d'));
[, $loc] = $signIn($jar('d'), $email, $final);
$ok($loc === '/pages/dashboard.php', 'admin switch off → password only (emergency)');
mailSaveSetting($db, 'require_2fa', '1');
secResetTwoStep($db, $sid);
[, $loc] = $http($jar('d'), 'GET', '/pages/dashboard.php');
$ok($loc === '/login.php?signed_out=1' && secBackupCodesLeft($db, $sid) === 0, 'admin “reset 2-step” → signed out, backup codes gone');

// Clean up
$db->exec("DELETE FROM login_log WHERE staff_id = $sid OR email IN ('$email', 'nobody@example.test') OR ip = '127.0.0.1'");
foreach (['login_codes', 'trusted_devices', 'backup_codes', 'password_resets'] as $t) $db->exec("DELETE FROM $t WHERE staff_id = $sid");
$db->exec("DELETE FROM activity_log WHERE id > $maxAct");
$db->exec("DELETE FROM email_log WHERE id > $maxLog");
$db->exec("DELETE FROM staff WHERE id = $sid");
$db->exec("DELETE FROM integration_settings");
$ins = $db->prepare("INSERT INTO integration_settings (k, v) VALUES (?, ?)");
foreach ($settingsBefore as $k => $v) $ins->execute([$k, $v]);
array_map('unlink', glob("$OUT/*.eml") ?: []);
foreach (['a', 'b', 'c', 'd'] as $n) @unlink($jar($n));
echo "\n$pass passed, $fail failed\n";

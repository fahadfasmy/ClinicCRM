<?php
const ROOT = __DIR__ . '/../..';   // the project folder
// Clinic isolation (Phase 1): a second clinic ("isotest", created on first run) next to the first clinic's local copy. Proves that
// addresses, sessions, ids, APIs, keys and database users never cross between clinics. Local server 8080 + Host headers.
require ROOT . '/includes/auth.php';
require ROOT . '/includes/inventory.php';
require ROOT . '/includes/api_keys.php';
$pass = 0; $fail = 0;
$ok = function (bool $c, string $m) use (&$pass, &$fail) { $c ? $pass++ : $fail++; echo ($c ? "  ok   " : "  FAIL "), $m, PHP_EOL; };
if (!multiTenant()) { echo "Needs multi-clinic mode locally.\n0 passed, 1 failed\n"; exit(1); }
$BASE = 'http://127.0.0.1:8080';
$MAIN = 'main.localhost'; $ISO = 'isotest.localhost';

$mainT = currentTenant();
$main = getDB();
// The second clinic: provisioned once, reused afterwards
if (!tenantBySlug('isotest')) {
    $out = shell_exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(ROOT . '/tools/provision_tenant.php')
        . ' --slug=isotest --name="Iso Test Clinic" --owner-email=owner@isotest.test --owner-name="Iso Owner" 2>&1');
    $ok(str_contains((string)$out, 'Owner account created'), 'second clinic provisioned');
}
$isoT = tenantBySlug('isotest');
$iso = dbConnect($isoT['db_name'], $isoT['db_user'], $isoT['db_pass'], $isoT['db_host']);
$isoPw = 'Iso-' . bin2hex(random_bytes(8));
$iso->prepare("UPDATE staff SET password = ?, is_active = 1 WHERE email = 'owner@isotest.test'")->execute([password_hash($isoPw, PASSWORD_DEFAULT)]);
if (!$iso->query("SELECT COUNT(*) FROM leads")->fetchColumn())
    $iso->exec("INSERT INTO leads (name, phone) VALUES ('Iso Patient Zed', '+971500000001')");
$isoLead1 = $iso->query("SELECT id, name FROM leads ORDER BY id LIMIT 1")->fetch();

// Something only the first clinic has, with an id the second clinic doesn't have
$marker = 'MainOnly ' . bin2hex(random_bytes(3));
$markerPhone = '+97150' . random_int(1000000, 9999999);
$main->prepare("INSERT INTO leads (name, phone, stage) VALUES (?, ?, 'new')")->execute([$marker, $markerPhone]);
$markerId = (int)$main->lastInsertId();
$mainSame = $main->prepare("SELECT name FROM leads WHERE id = ?"); $mainSame->execute([$isoLead1['id']]);
$mainSameName = (string)$mainSame->fetchColumn();   // the first clinic's lead with the same id as the other clinic's first lead
$mainAppt = (int)$main->query("SELECT COALESCE(MAX(id),0) FROM appointments")->fetchColumn();
$mainProduct = (int)$main->query("SELECT COALESCE(MAX(id),0) FROM products")->fetchColumn();
$mainPo = (int)$main->query("SELECT COALESCE(MAX(id),0) FROM purchase_orders")->fetchColumn();

/** One request to a clinic address: [status, location, body]. Cookies kept per jar (curl keys them by 127.0.0.1). */
$http = function (string $host, string $jar, string $method, string $path, array $form = [], array $headers = []) use ($BASE) {
    $ch = curl_init($BASE . $path);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => true, CURLOPT_COOKIEJAR => $jar, CURLOPT_COOKIEFILE => $jar,
        CURLOPT_HTTPHEADER => array_merge(["Host: $host"], $headers), CURLOPT_TIMEOUT => 60, CURLOPT_USERAGENT => 'ClinicCRM-tenancy/1.0 ' . basename($jar)]);
    if ($method === 'POST') { curl_setopt($ch, CURLOPT_POST, true); curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($form)); }
    $raw = (string)curl_exec($ch); $code = curl_getinfo($ch, CURLINFO_HTTP_CODE); $hs = curl_getinfo($ch, CURLINFO_HEADER_SIZE); curl_close($ch);
    preg_match('/^Location:\s*(\S+)/mi', substr($raw, 0, $hs), $m);
    return [$code, $m[1] ?? '', substr($raw, $hs)];
};
/** The session cookie a jar holds for one clinic address, to send it deliberately to another address. */
$sessionOf = function (string $jar, string $host): string {
    foreach (file($jar) as $l) if (preg_match('/^(?:#HttpOnly_)?' . preg_quote($host, '/') . '	.*	PHPSESSID	(\S+)/', $l, $m)) return $m[1];
    return '';
};
/** A request carrying a stolen/copied session cookie: [status, location]. */
$carry = function (string $host, string $sid, string $path) use ($BASE) {
    $ch = curl_init($BASE . $path);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => true, CURLOPT_HTTPHEADER => ["Host: $host", "Cookie: PHPSESSID=$sid"]]);
    $raw = (string)curl_exec($ch); $code = curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
    preg_match('/^Location:\s*(\S+)/mi', $raw, $m);
    return [$code, $m[1] ?? ''];
};
$login = function (string $host, string $email, string $pw) use ($http): string {
    $jar = tempnam(sys_get_temp_dir(), 'tn');
    [, , $page] = $http($host, $jar, 'GET', '/login.php');
    preg_match('/name="_csrf" value="([a-f0-9]+)"/', $page, $m);
    [, $loc] = $http($host, $jar, 'POST', '/login.php', ['_csrf' => $m[1] ?? '', 'step' => 'password', 'email' => $email, 'password' => $pw]);
    return $loc === '/pages/dashboard.php' ? $jar : '';
};
$noMain = fn(string $body) => !str_contains($body, $marker) && !str_contains($body, 'Demo Aesthetic Clinic') && ($mainSameName === '' || $mainSameName === $isoLead1['name'] || !str_contains($body, htmlspecialchars($mainSameName)));

echo "Addresses\n";
$j0 = tempnam(sys_get_temp_dir(), 'tn');
foreach (['/login.php', '/pages/dashboard.php', '/pages/api_public_lead.php', '/pages/api_whatsapp.php?action=ping'] as $p) {
    [$c, , $b] = $http('nobody.localhost', $j0, 'GET', $p);
    $ok($c === 404 && trim($b) === 'Not found', "unknown address → plain 404 ($p → $c)");
}
[$c, , $b] = $http($ISO, $j0, 'GET', '/login.php');
$ok($c === 200 && str_contains($b, 'Iso Test Clinic') && !str_contains($b, 'Demo Aesthetic'), 'second clinic’s sign-in page shows its own name');
[$c, , $b] = $http($MAIN, $j0, 'GET', '/login.php');
$ok($c === 200 && str_contains($b, 'Demo Aesthetic Clinic'), 'the first clinic’s sign-in page unchanged');

echo "Signed in to the second clinic\n";
$jar = $login($ISO, 'owner@isotest.test', $isoPw);
$ok($jar !== '', 'owner signs in on the clinic’s own address');
$ok($login($MAIN, 'owner@isotest.test', $isoPw) === '', 'the same account does not exist at the first clinic');
[$c, , $dash] = $http($ISO, $jar, 'GET', '/pages/dashboard.php');
$ok($c === 200 && $noMain($dash), 'dashboard shows nothing of the first clinic');
preg_match_all('/<a class="side-link[^"]*" href="([^"]+)"/', $dash, $links);
$pages = array_unique($links[1]);
$bad = [];
foreach ($pages as $p) { [$c, , $b] = $http($ISO, $jar, 'GET', $p); if ($c !== 200 || !$noMain($b)) $bad[] = "$p ($c)"; }
$ok(count($pages) >= 10 && !$bad, count($pages) . ' menu pages show only the clinic’s own data' . ($bad ? ': ' . implode(', ', $bad) : ''));
[$c, , $b] = $http($ISO, $jar, 'GET', '/pages/leads.php?view=list&q=' . urlencode($marker));
$ok($c === 200 && str_contains($b, '0 results for'), 'searching for the first clinic’s lead finds nothing');

echo "Tampered ids and URLs\n";
foreach (["/pages/lead_view.php?id=$markerId", "/pages/lead_edit.php?id=$markerId", "/pages/appointment.php?id=$mainAppt",
          "/pages/product.php?id=$mainProduct", "/pages/purchase_order.php?id=$mainPo"] as $p) {
    [$c, , $b] = $http($ISO, $jar, 'GET', $p);
    $ok($c !== 500 && $noMain($b), "$p never shows the first clinic’s record ($c)");
}
[$c, , $b] = $http($ISO, $jar, 'GET', "/pages/lead_view.php?id={$isoLead1['id']}");
$ok($c === 200 && str_contains($b, htmlspecialchars($isoLead1['name'])) && $noMain($b), "lead #{$isoLead1['id']} is the clinic’s own lead, not the first clinic’s lead with the same id");
[$c, , $b] = $http($ISO, $jar, 'GET', '/pages/api.php?action=check_duplicate&phone=' . urlencode($markerPhone));
$ok(json_decode($b, true)['duplicate'] === false, 'duplicate check doesn’t see the first clinic’s phone numbers');
[, , $page] = $http($ISO, $jar, 'GET', '/pages/dashboard.php');
preg_match('/name="csrf-token" content="([a-f0-9]+)"/', $page, $m);
$http($ISO, $jar, 'POST', '/pages/api.php', ['action' => 'change_stage', 'lead_id' => $markerId, 'stage' => 'lost'], ['X-CSRF-Token: ' . ($m[1] ?? '')]);
$st = $main->prepare("SELECT stage FROM leads WHERE id = ?"); $st->execute([$markerId]);
$ok($st->fetchColumn() === 'new', 'changing the stage of the first clinic’s lead id from the other clinic does nothing to the first clinic');

echo "Sessions\n";
$isoSid = $sessionOf($jar, $ISO);
[$c] = $carry($ISO, $isoSid, '/pages/dashboard.php');
$ok($isoSid !== '' && $c === 200, 'the clinic’s own session works on its own address');
[$c, $loc] = $carry($MAIN, $isoSid, '/pages/dashboard.php');
$ok($c === 302 && str_contains($loc, '/login.php'), 'the clinic’s session cookie sent to the first clinic’s address is not signed in');
[$c] = $carry($ISO, $isoSid, '/pages/dashboard.php');
$ok($c === 302, 'and that session is emptied, not kept for later (back on its own address it is signed out)');
$acc = file(ROOT . '/local/test_accounts.txt', FILE_IGNORE_NEW_LINES);
$sJar = $login($MAIN, trim(explode('/', $acc[1])[0]), trim(preg_replace('/^Password:\s*/i', '', $acc[2])));
$ok($sJar !== '', 'First-clinic admin signs in on the first clinic’s address');
$sSid = $sessionOf($sJar, $MAIN);
[$c] = $carry($ISO, $sSid, '/pages/dashboard.php');
$ok($sSid !== '' && $c === 302, 'the first clinic’s session cookie sent to the other clinic is not signed in');
$main->exec("DELETE FROM login_log WHERE user_agent LIKE 'ClinicCRM-tenancy/%'");

echo "Keys and public endpoints\n";
$key = createApiKey($main, 'tenancy test', null, 1);
[$c] = $http($MAIN, $j0, 'GET', '/pages/api_whatsapp.php?action=ping', [], ["X-CRM-Key: $key"]);
[$c2] = $http($ISO, $j0, 'GET', '/pages/api_whatsapp.php?action=ping', [], ["X-CRM-Key: $key"]);
$ok($c === 200 && $c2 === 401, "the first clinic’s WhatsApp key works at the first clinic ($c) and is refused by the other clinic ($c2)");
$main->prepare("DELETE FROM api_keys WHERE label = 'tenancy test'")->execute();
$webName = 'Web Iso ' . bin2hex(random_bytes(3));
[$c] = $http($ISO, $j0, 'POST', '/pages/api_public_lead.php', ['name' => $webName, 'phones' => ['+97155' . random_int(1000000, 9999999)]]);
$inIso = $iso->prepare("SELECT COUNT(*) FROM leads WHERE name = ?"); $inIso->execute([$webName]);
$inMain = $main->prepare("SELECT COUNT(*) FROM leads WHERE name = ?"); $inMain->execute([$webName]);
$ok($c === 200 && $inIso->fetchColumn() == 1 && $inMain->fetchColumn() == 0, 'a landing-page enquiry on the clinic’s address lands in that clinic only');
$iso->prepare("DELETE FROM leads WHERE name = ?")->execute([$webName]);

echo "Database users, storage, keys\n";
$try = function (PDO $db, string $sql) { try { $db->query($sql)->fetchAll(); return 'allowed'; } catch (PDOException $e) { return 'denied'; } };
$ok($try($iso, "SELECT COUNT(*) FROM `{$mainT['db_name']}`.leads") === 'denied', 'the clinic’s MySQL user cannot read the first clinic’s database');
$ok($try($main, "SELECT COUNT(*) FROM `{$isoT['db_name']}`.leads") === 'denied', 'the first clinic’s MySQL user cannot read the other clinic’s database');
$ok($try($iso, 'SELECT COUNT(*) FROM `' . PLATFORM_DB_NAME . '`.tenants') === 'denied', 'clinic users cannot read the platform database');
$ok($try($iso, 'SHOW TABLES FROM `' . REFERENCE_DB_NAME . '`') === 'allowed', 'clinic users can read the shared reference database');
$sealed = tenantEncrypt('784-1990-1234567-1');
$ok(tenantDecrypt($sealed) === '784-1990-1234567-1', 'a clinic can decrypt its own values');
tenantUse($isoT);
try { tenantDecrypt($sealed); $ok(false, 'another clinic cannot decrypt them'); } catch (RuntimeException $e) { $ok(true, 'another clinic cannot decrypt them'); }
$ok(basename(tenantStoragePath()) === (string)$isoT['id'], 'each clinic gets its own storage folder');
tenantUse($mainT);
$ok(cryptoKeyId($isoT['data_key_enc']) === masterKey()[0] && $isoT['data_key_enc'] !== $mainT['data_key_enc'], 'each clinic has its own data key, wrapped by the master key');

echo "Cron\n";
$iso->exec("DELETE FROM app_meta WHERE k = 'cron_last_run'");
$before = $main->query("SELECT v FROM app_meta WHERE k = 'cron_last_run'")->fetchColumn();
exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(ROOT . '/cron.php') . ' --tenant=isotest 2>&1', $o, $code);
$ok($code === 0 && $iso->query("SELECT COUNT(*) FROM app_meta WHERE k = 'cron_last_run'")->fetchColumn() == 1, 'cron runs for one clinic (exit ' . $code . ')');
$ok($main->query("SELECT v FROM app_meta WHERE k = 'cron_last_run'")->fetchColumn() === $before, 'running it for that clinic leaves the first clinic untouched');

$main->prepare("DELETE FROM leads WHERE id = ?")->execute([$markerId]);
$main->prepare("DELETE FROM activity_log WHERE lead_id = ?")->execute([$markerId]);
foreach ([$j0, $jar, $sJar] as $f) @unlink($f);
echo "\n$pass passed, $fail failed\n";
exit($fail ? 1 : 0);

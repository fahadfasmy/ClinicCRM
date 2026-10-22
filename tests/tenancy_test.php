<?php
// Phase 1 building blocks: sealing with key ids, single-clinic mode (today's Hostinger set-up), staff pages loading nothing
// from other sites. The full two-clinic isolation test is tests/integration/tenancy_suite.php.
require_once __DIR__ . '/../includes/tenant.php';

function test_sealed_values_open_only_with_their_key_and_unchanged() {
    $k1 = random_bytes(32); $k2 = random_bytes(32);
    $s = cryptoSeal('784-1990-1234567-1', $k1, 't7');
    check(cryptoKeyId($s) === 't7' && !str_contains($s, '784-1990'), 'sealed value names its key and hides the text');
    check(cryptoOpen($s, $k1) === '784-1990-1234567-1', 'opens with the right key');
    check(cryptoSeal('x', $k1, 't7') !== cryptoSeal('x', $k1, 't7'), 'the same text seals differently each time');
    checkThrows(fn() => cryptoOpen($s, $k2), 'Decryption failed', 'wrong key is refused');
    checkThrows(fn() => cryptoOpen('t8' . substr($s, 2), $k1), 'Decryption failed', 'swapping the key id is detected');
    $raw = base64_decode(substr($s, 3)); $raw[30] = chr(ord($raw[30]) ^ 1);
    checkThrows(fn() => cryptoOpen('t7:' . base64_encode($raw), $k1), 'Decryption failed', 'a changed byte is detected');
    checkThrows(fn() => cryptoSeal('x', 'short', 'k'), '32 bytes', 'short keys are refused');
}

function test_single_clinic_mode_needs_no_platform_database() {
    $dir = tempDir('single');
    testDb('clinic_crm_test');
    file_put_contents("$dir/cfg.php", "<?php define('APP_ENV','local'); define('DB_HOST'," . var_export(DB_HOST, true) . "); define('DB_NAME','clinic_crm_test');"
        . "define('DB_USER'," . var_export(DB_USER, true) . "); define('DB_PASS'," . var_export(DB_PASS, true) . "); define('LOG_PATH', " . var_export("$dir/logs", true) . ");");
    file_put_contents("$dir/run.php", "<?php require " . var_export(realpath(__DIR__ . '/../includes/db.php'), true) . ";"
        . "echo json_encode([multiTenant(), currentTenant()['id'], getDB()->query('SELECT DATABASE()')->fetchColumn(), clinicName()]);");
    putenv("CRM_CONFIG=$dir/cfg.php");
    $out = json_decode((string)shell_exec(escapeshellarg(phpBin()) . ' ' . escapeshellarg("$dir/run.php") . ' 2>&1'), true);
    putenv('CRM_CONFIG');
    check($out === [false, 1, 'clinic_crm_test', 'Clinic CRM'], 'single-clinic mode: clinic #1, database from the config, default name (' . json_encode($out) . ')');
    rmTree($dir);
}

function test_staff_pages_load_nothing_from_other_sites() {
    $root = realpath(__DIR__ . '/..');
    foreach (['includes/header.php', 'includes/footer.php', 'login.php', 'pages/analytics.php', 'pages/reports.php'] as $f) {
        preg_match_all('#<(?:script|link|img)\b[^>]*\b(?:src|href)="(https?:)?//#i', file_get_contents("$root/$f"), $m);
        check(!$m[0], "$f loads no outside scripts, styles, fonts or images");
    }
    foreach (glob("$root/assets/vendor/*/*.css") as $css) {
        preg_match_all('#url\(\s*["\']?(https?:)?//#i', file_get_contents($css), $m);
        check(!$m[0], basename($css) . ' pulls nothing from outside');
    }
    check(str_contains(file_get_contents("$root/includes/header.php"), 'sendStaffPageHeaders()') && str_contains(file_get_contents("$root/login.php"), 'sendStaffPageHeaders()'), 'staff pages send the Content-Security-Policy');
}

function test_no_clinic_details_left_in_the_code() {
    $root = realpath(__DIR__ . '/..');
    $files = array_merge(glob("$root/*.php"), glob("$root/includes/*.php"), glob("$root/pages/*.php"), glob("$root/whatsapp-extension/*.js"), glob("$root/whatsapp-extension/*.html"));
    $hits = [];
    foreach ($files as $f) {
        if (str_ends_with($f, 'db.local.php') || str_ends_with($f, 'data_flows.php') || str_ends_with($f, 'security.php')) continue;   // security.php: a list of weak passwords
        foreach (['Demo Aesthetic', 'exampleclinic'] as $needle)
            if (stripos(file_get_contents($f), $needle) !== false) $hits[] = basename($f) . ": $needle";
    }
    check(!$hits, 'clinic-specific details found in code: ' . implode(', ', $hits));
}

function test_ensure_keys_adds_working_keys_once_without_showing_them() {
    foreach (["\n", "\n?>\n"] as $ending) {
        $dir = tempDir('keys');
        $cfg = "<?php define('APP_ENV','local'); define('DB_HOST'," . var_export(DB_HOST, true) . "); define('DB_NAME','clinic_crm_test');"
             . "define('DB_USER'," . var_export(DB_USER, true) . "); define('DB_PASS'," . var_export(DB_PASS, true) . "); define('LOG_PATH', " . var_export("$dir/logs", true) . ");" . $ending;
        file_put_contents("$dir/cfg.php", $cfg);
        putenv("CRM_CONFIG=$dir/cfg.php");
        $run = fn() => (string)shell_exec(escapeshellarg(phpBin()) . ' ' . escapeshellarg(realpath(__DIR__ . '/../tools/ensure_keys.php')) . ' 2>&1');
        $first = $run();
        $after = file_get_contents("$dir/cfg.php");
        $second = $run();
        $works = trim((string)shell_exec(escapeshellarg(phpBin()) . ' ' . escapeshellarg(realpath(__DIR__ . '/../tools/ensure_keys.php')) . ' --check 2>&1'));
        putenv('CRM_CONFIG');
        preg_match("/define\('MASTER_KEY', '([^']+)'/", $after, $m);
        $label = $ending === "\n" ? 'plain file' : 'file ending with ?>';
        check(str_contains($first, 'added') && !str_contains($first, $m[1] ?? 'nokey'), "$label: keys added, and not printed");
        check(str_contains($second, 'already set and working') && file_get_contents("$dir/cfg.php") === $after, "$label: a second run checks them and changes nothing");
        check($works === 'ok', "$label: encryption works with the new keys");
        check(count(glob("$dir/cfg.php.before-keys-*")) === 1, "$label: the old config file is kept");
        rmTree($dir);
    }
}

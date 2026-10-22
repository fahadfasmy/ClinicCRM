<?php
// Phase 0 safety net: secrets out of db.php, generic database errors, .htaccess rules, local mail guard, ignored files.
require_once __DIR__ . '/../includes/mailer.php';

function test_db_php_holds_no_credentials() {
    $src = file_get_contents(__DIR__ . '/../includes/db.php');
    check(!preg_match("/define\(\s*'DB_(PASS|USER|NAME)'/", $src), 'db.php defines no database name, user or password');
    check(!str_contains($src, 'u000000000'), 'no production account name in db.php');
    check(!preg_match('/\$e->getMessage\(\)\s*\]/', $src) && str_contains($src, 'appLog('), 'connection errors are logged, not shown');
}

function test_database_error_page_is_generic_and_logged() {
    $dir = tempDir('cfg');
    file_put_contents("$dir/cfg.php", "<?php define('APP_ENV','production'); define('DB_HOST','127.0.0.1'); define('DB_NAME','no_such_db_x');"
        . "define('DB_USER','nobody_x'); define('DB_PASS','wrong'); define('LOG_PATH', " . var_export("$dir/logs", true) . ");");
    file_put_contents("$dir/page.php", "<?php require " . var_export(realpath(__DIR__ . '/../includes/db.php'), true) . "; getDB(); echo 'REACHED';");
    $cgi = dirname(phpBin()) . '/php-cgi.exe';
    $bin = is_file($cgi) ? $cgi : phpBin();
    putenv("CRM_CONFIG=$dir/cfg.php");
    $out = (string)shell_exec(escapeshellarg($bin) . ' -q ' . escapeshellarg("$dir/page.php") . ' 2>&1');
    putenv('CRM_CONFIG');
    $log = @file_get_contents("$dir/logs/app.log") ?: '';
    check(!str_contains($out, 'REACHED') && !str_contains($out, 'nobody_x') && !str_contains($out, 'SQLSTATE'), 'visitor sees no database details');
    check(str_contains($out, 'temporarily unavailable') || str_contains($out, 'Database connection failed (details in the log)'), 'a plain "unavailable" message instead');
    check(str_contains($log, 'nobody_x') && str_contains($log, 'Access denied'), 'the real reason is in the log');
    rmTree($dir);
}

/** The .htaccess deny rules, checked as regular expressions (local php -S doesn't read .htaccess). */
function test_htaccess_blocks_the_right_files() {
    $h = file_get_contents(__DIR__ . '/../.htaccess');
    preg_match_all('/<FilesMatch "([^"]+)">/', $h, $fm);
    preg_match('/RedirectMatch 404 (\S+)/', $h, $rm);
    $denied = function (string $path) use ($fm, $rm) {
        if (preg_match('#' . str_replace('#', '\#', $rm[1]) . '#', $path)) return true;
        foreach ($fm[1] as $re) if (preg_match('#' . str_replace('#', '\#', $re) . '#', basename($path))) return true;
        return false;
    };
    foreach (['/u000000000_crm.sql', '/schema.sql', '/schema_updates.sql', '/README.md', '/BUILD_PROMPT.md', '/local/test_accounts.txt', '/local/seed.php',
              '/.claude/launch.json', '/.git/config', '/includes/db.php', '/includes/db.local.php', '/tools/backup.php', '/tests/run.php',
              '/migrations/tenant/README.txt', '/deploy/nginx_clinic_crm.conf.example', '/docs/RUNBOOK_UAE.md', '/deploy/anything.php', '/docs/x.php', '/storage/1/photo.jpg', '/backups/x.sql.gz', '/.env', '/debug.log', '/config.example'] as $p)
        check($denied($p), "blocked: $p");
    foreach (['/login.php', '/pages/dashboard.php', '/pages/api_public_lead.php', '/pages/meta_receiver.php', '/assets/css/app.css', '/assets/js/app.js',
              '/dental.html', '/hydrafacial.html', '/index.php', '/.well-known/acme-challenge/abc', '/pages/includes_report.php'] as $p)
        check(!$denied($p), "still served: $p");
    check(str_contains($h, 'Options -Indexes'), 'folder listings off');
}

function test_local_copy_cannot_reach_a_real_mail_server() {
    check(APP_ENV === 'local', 'tests run as local');
    $db = testDb('clinic_crm_test_mail', true);
    $db->exec("CREATE TABLE integration_settings (k VARCHAR(60) PRIMARY KEY, v TEXT NULL, updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP)");
    foreach (['host' => 'smtp.hostinger.com', 'port' => '465', 'security' => 'ssl', 'password' => 'x'] as $k => $v)
        $db->prepare("INSERT INTO integration_settings (k, v) VALUES (?, ?)")->execute(["mail.$k", $v]);
    mailSetting($db, '__reload');
    checkThrows(fn() => smtpDeliver($db, 'someone@example.test', 'S', '<p>x</p>', 'x'), 'real mail servers are blocked', 'real host refused on a local copy');
    mailSetting(getDB(), '__reload');
    testDb('clinic_crm_test_mail', true);
}

function test_git_ignores_secrets_and_dumps() {
    $root = realpath(__DIR__ . '/..');
    if (!is_dir("$root/.git")) { check(false, 'no git repository'); return; }
    foreach (['includes/db.local.php', 'u000000000_crm.sql', 'local/test_accounts.txt', 'local/smtp_mock_out/x.eml', 'storage/1/a.jpg', 'backups/a.sql.gz', 'crm_config.php'] as $f) {
        $out = trim((string)shell_exec('git -C ' . escapeshellarg($root) . ' check-ignore -q ' . escapeshellarg($f) . ' && echo ignored'));
        check($out === 'ignored', "git ignores $f");
    }
    check(!is_file("$root/u000000000_crm.sql"), 'the production dump is no longer inside the project folder');
}

/** "$name’s" in PHP reads the curly quote as part of the variable name (the value goes missing): write "{$name}’s". */
function test_no_variables_glued_to_curly_quotes() {
    $root = realpath(__DIR__ . '/..');
    $hits = [];
    foreach (array_merge(glob("$root/*.php"), glob("$root/includes/*.php"), glob("$root/includes/*/*.php"), glob("$root/pages/*.php"), glob("$root/tools/*.php")) as $f)
        foreach (file($f) as $n => $line) if (preg_match('/(?<!\{)\$[A-Za-z_][A-Za-z0-9_]*[\x{2018}\x{2019}\x{201C}\x{201D}]/u', $line)) $hits[] = basename($f) . ':' . ($n + 1);
    check(!$hits, 'variables glued to a curly quote: ' . implode(', ', $hits));
}

function test_no_comment_ends_php_by_accident() {
    // A PHP closing tag inside a // comment ends PHP: the rest of the file is then printed on every page (it happened once)
    $root = realpath(__DIR__ . '/..');
    $bad = [];
    foreach (array_merge(glob("$root/*.php"), glob("$root/includes/*.php"), glob("$root/includes/*/*.php"), glob("$root/pages/*.php"), glob("$root/tools/*.php"), glob("$root/lang/*.php")) as $f) {
        $toks = token_get_all(file_get_contents($f));
        foreach ($toks as $i => $t)
            if (is_array($t) && $t[0] === T_COMMENT && preg_match('~^(//|#)~', $t[1]) && is_array($toks[$i + 1] ?? null) && $toks[$i + 1][0] === T_CLOSE_TAG) { $bad[] = basename($f) . ' line ' . $t[2]; break; }
    }
    check(!$bad, 'a // comment closes PHP in: ' . implode(', ', $bad));
}

<?php
// tools/predeploy_check.php — run on the local copy before uploading a release. Stops the release if anything fails.
//   php tools/predeploy_check.php            lint + unit tests + safety checks
//   php tools/predeploy_check.php --full     also the smoke test and the feature regression suites (local web server needed)
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$root = realpath(__DIR__ . '/..');
$php = PHP_BINARY;
$full = in_array('--full', $argv, true);
$failed = [];
$step = function (string $name, bool $ok, string $detail = '') use (&$failed) {
    printf("%-46s %s%s\n", $name, $ok ? 'OK' : 'FAIL', $detail !== '' ? "  $detail" : '');
    if (!$ok) $failed[] = $name;
};

// 1. Every PHP file parses
$bad = [];
$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
foreach ($it as $f) {
    $p = $f->getPathname();
    if (!str_ends_with($p, '.php') || preg_match('#[\\\\/](\.git|\.claude|node_modules)[\\\\/]#', $p)) continue;
    exec(escapeshellarg($php) . ' -l ' . escapeshellarg($p) . ' 2>&1', $o, $code);
    if ($code !== 0) $bad[] = substr($p, strlen($root) + 1);
}
$step('PHP syntax (all files)', !$bad, implode(', ', $bad));

// 2. Nothing secret or real in the release folder
$step('no production dump in the project', !glob("$root/u000000000*.sql"));
$step('db.php has no credentials', !preg_match("/define\(\s*'DB_PASS'/", file_get_contents("$root/includes/db.php")));
$step('.htaccess present', is_file("$root/.htaccess"));
$step('config.example.php has placeholders only', !preg_match("/define\('DB_PASS',\s*'(?!CHANGE_ME)/", file_get_contents("$root/config.example.php")));
exec(escapeshellarg($php) . ' ' . escapeshellarg("$root/tools/residency_check.php") . ' 2>&1', $rc, $rcCode);
$step('UAE residency check (tools/residency_check.php)', $rcCode === 0, trim(implode(' ', $rc)));

// 3. Unit tests
exec(escapeshellarg($php) . ' ' . escapeshellarg("$root/tests/run.php") . ' 2>&1', $out, $code);
$step('unit tests (tests/run.php)', $code === 0, trim((string)end($out)));

if ($full) {
    exec(escapeshellarg($php) . ' ' . escapeshellarg("$root/tests/smoke.php") . ' 2>&1', $o2, $c2);
    $step('smoke test (tests/smoke.php)', $c2 === 0, trim((string)end($o2)));
    exec(escapeshellarg($php) . ' ' . escapeshellarg("$root/tests/integration/run_all.php") . ' 2>&1', $o3, $c3);
    $step('feature suites (tests/integration)', $c3 === 0, trim((string)end($o3)));
}

echo $failed ? "\nNOT READY: " . implode('; ', $failed) . "\n" : "\nReady to deploy. Files NOT to upload: includes/db.local.php, local/, .claude/, .git/, tests/, *.sql outside migrations/, *.md (see docs/DEPLOY.md).\n";
exit($failed ? 1 : 0);

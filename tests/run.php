<?php
// tests/run.php — runs every tests/*_test.php file. Each file defines functions named test_something(); they use check().
//   php tests/run.php                  all unit tests (no web server needed; scratch database clinic_crm_test)
//   php tests/run.php migrations       only files whose name contains "migrations"
// Exit code 0 = all passed. The smoke test (needs the local web server) is separate: php tests/smoke.php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__ . '/lib.php';

$filter = $argv[1] ?? '';
$files = glob(__DIR__ . '/*_test.php') ?: [];
sort($files);
$t0 = microtime(true);
foreach ($files as $f) {
    if ($filter !== '' && !str_contains(basename($f), $filter)) continue;
    $before = get_defined_functions()['user'];
    require_once $f;
    $tests = array_diff(get_defined_functions()['user'], $before);
    echo basename($f) . "\n";
    foreach ($tests as $fn) {
        if (!str_starts_with($fn, 'test_')) continue;
        $GLOBALS['T']['current'] = basename($f, '.php') . '::' . $fn;
        $p = $GLOBALS['T']['pass']; $x = $GLOBALS['T']['fail'];
        try { $fn(); }
        catch (Throwable $e) { check(false, 'crashed: ' . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine()); }
        printf("  %-4s %s (%d checks)\n", $GLOBALS['T']['fail'] > $x ? 'FAIL' : 'ok', substr($fn, 5), $GLOBALS['T']['pass'] - $p + $GLOBALS['T']['fail'] - $x);
    }
}
printf("\n%d passed, %d failed in %.1fs\n", $GLOBALS['T']['pass'], $GLOBALS['T']['fail'], microtime(true) - $t0);
if ($GLOBALS['T']['fail']) { echo "\nFailures:\n  " . implode("\n  ", $GLOBALS['T']['fails']) . "\n"; exit(1); }
exit(0);

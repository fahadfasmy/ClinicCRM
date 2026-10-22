<?php
// tests/lib.php — the tiny test kit: check(), a throwaway test database, and helpers. No PHPUnit, no Composer.
require_once __DIR__ . '/../includes/migrations.php';

$GLOBALS['T'] = ['pass' => 0, 'fail' => 0, 'fails' => []];

/** One assertion. Prints only failures (run.php prints the totals). */
function check(bool $ok, string $what): void {
    if ($ok) { $GLOBALS['T']['pass']++; return; }
    $GLOBALS['T']['fail']++;
    $GLOBALS['T']['fails'][] = ($GLOBALS['T']['current'] ?? '') . ': ' . $what;
    echo "    FAIL  $what\n";
}

/** Runs $fn and passes if it throws an exception whose message contains $needle. */
function checkThrows(callable $fn, string $needle, string $what): void {
    try { $fn(); check(false, "$what (no error was raised)"); }
    catch (Throwable $e) { check(str_contains($e->getMessage(), $needle), "$what (got: " . $e->getMessage() . ')'); }
}

/**
 * A PDO connection to a scratch database (default clinic_crm_test). Refuses any name that isn't a test database,
 * so a test can never touch real data even if the config points somewhere else.
 */
function testDb(string $name = 'clinic_crm_test', bool $fresh = false): PDO {
    if (!preg_match('/^clinic_crm_(test|restore)\w*$/', $name)) throw new RuntimeException("Refusing to use $name as a test database.");
    if (APP_ENV !== 'local') throw new RuntimeException('Tests only run on a local copy (APP_ENV=local).');
    $root = new PDO('mysql:host=' . DB_HOST . ';charset=utf8mb4', DB_USER, DB_PASS, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    if ($fresh) $root->exec("DROP DATABASE IF EXISTS `$name`");
    $root->exec("CREATE DATABASE IF NOT EXISTS `$name` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    return new PDO('mysql:host=' . DB_HOST . ";dbname=$name;charset=utf8mb4", DB_USER, DB_PASS,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC, PDO::ATTR_EMULATE_PREPARES => false]);
}

/** A fresh empty temporary folder (removed by the caller with rmTree). */
function tempDir(string $prefix): string {
    $d = sys_get_temp_dir() . '/' . $prefix . '_' . bin2hex(random_bytes(4));
    mkdir($d, 0700, true);
    return $d;
}

function rmTree(string $d): void {
    if (!is_dir($d)) return;
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($d, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST) as $f)
        $f->isDir() ? rmdir($f->getPathname()) : unlink($f->getPathname());
    rmdir($d);
}

/** The PHP binary running the tests (for checks that need a separate process). */
function phpBin(): string { return PHP_BINARY; }

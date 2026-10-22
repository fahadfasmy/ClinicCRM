<?php
// tools/restore.php — loads a backup made by tools/backup.php into a database.
//   php tools/restore.php --file=/path/db_YYYYmmdd_His.sql.gz --into=clinic_crm_test
// Safety: the target must be EMPTY, or be a scratch database (clinic_crm_test / clinic_crm_restore*). Restoring over a
// live database is a disaster-recovery step done by hand following docs/RUNBOOK_UAE.md, never by this tool's defaults.
// Checks the .sha256 file and refuses a dump that doesn't end with "-- dump complete".
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__ . '/../includes/migrations.php';   // sqlSplitNew/Feed

function restoreDump(PDO $target, string $file): int {
    $sumFile = $file . '.sha256';
    if (is_file($sumFile) && !hash_equals(strtok(file_get_contents($sumFile), ' '), hash_file('sha256', $file))) throw new RuntimeException('Checksum mismatch: the backup file is damaged.');
    // Read the tail first: a dump cut off mid-way must never be half-loaded
    $gz = gzopen($file, 'rb'); $last = '';
    while (!gzeof($gz)) { $line = gzgets($gz, 1 << 20); if (trim($line) !== '') $last = trim($line); }
    gzclose($gz);
    if ($last !== '-- dump complete') throw new RuntimeException('The backup is incomplete (no "dump complete" marker).');
    $gz = gzopen($file, 'rb');
    $st = sqlSplitNew(); $n = 0;
    while (!gzeof($gz)) {
        foreach (sqlSplitFeed($st, gzgets($gz, 1 << 20)) as $s) { $target->exec($s); $n++; }
    }
    foreach (sqlSplitFeed($st, '', true) as $s) { $target->exec($s); $n++; }
    gzclose($gz);
    return $n;
}

if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === realpath(__FILE__)) {
    $a = getopt('', ['file:', 'into:']);
    $file = $a['file'] ?? ''; $into = $a['into'] ?? '';
    if (!is_file($file) || !preg_match('/^[A-Za-z0-9_]+$/', $into)) { fwrite(STDERR, "Usage: php tools/restore.php --file=backup.sql.gz --into=database_name\n"); exit(2); }
    try {
        $pdo = new PDO('mysql:host=' . DB_HOST . ';charset=utf8mb4', DB_USER, DB_PASS, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $pdo->exec("CREATE DATABASE IF NOT EXISTS `$into` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
        $pdo->exec("USE `$into`");
        $tables = (int)$pdo->query("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = " . $pdo->quote($into))->fetchColumn();
        $scratch = $into === 'clinic_crm_test' || str_starts_with($into, 'clinic_crm_restore');
        if ($tables > 0 && !$scratch) throw new RuntimeException("`$into` already has $tables tables. Restore into an empty or scratch database (see docs/RUNBOOK_UAE.md).");
        $t0 = microtime(true);
        $n = restoreDump($pdo, $file);
        printf("Restore OK into `%s`: %d statements in %.1fs.\n", $into, $n, microtime(true) - $t0);
    } catch (Throwable $e) {
        fwrite(STDERR, 'Restore FAILED: ' . $e->getMessage() . "\n");
        exit(1);
    }
}

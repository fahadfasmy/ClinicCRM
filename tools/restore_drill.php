<?php
// tools/restore_drill.php — a restore drill (every 3 months, docs/RESTORE_DRILL.md): loads a backup into a scratch
// database (clinic_crm_restore_drill, emptied first), checks it, and prints the figures to record on Admin → Data flows
// & backups. Run on a computer with MySQL/MariaDB (the local copy, or the UAE server), never against a live database.
//   php tools/restore_drill.php --file=/path/u000000000_crm_20261101_020001.sql.gz
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__ . '/restore.php';

$a = getopt('', ['file:']);
$file = $a['file'] ?? '';
if (!is_file($file)) { fwrite(STDERR, "Usage: php tools/restore_drill.php --file=backup.sql.gz\n"); exit(2); }
$into = 'clinic_crm_restore_drill';
try {
    $pdo = new PDO('mysql:host=' . DB_HOST . ';charset=utf8mb4', DB_USER, DB_PASS, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $pdo->exec("DROP DATABASE IF EXISTS `$into`");
    $pdo->exec("CREATE DATABASE `$into` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    $pdo->exec("USE `$into`");
    $t0 = microtime(true);
    restoreDump($pdo, $file);
    $secs = microtime(true) - $t0;
    $tables = $pdo->query("SHOW FULL TABLES WHERE Table_type = 'BASE TABLE'")->fetchAll(PDO::FETCH_COLUMN);
    $rows = 0;
    foreach ($tables as $t) $rows += (int)$pdo->query("SELECT COUNT(*) FROM `$t`")->fetchColumn();
    $checks = [];
    foreach (['schema_migrations' => 'database updates', 'staff' => 'staff', 'leads' => 'leads', 'patients' => 'patients'] as $t => $label)
        $checks[] = in_array($t, $tables, true) ? sprintf('%s: %d', $label, $pdo->query("SELECT COUNT(*) FROM `$t`")->fetchColumn()) : "$label: TABLE MISSING";
    $ok = !preg_grep('/MISSING/', $checks) && $rows > 0;
    printf("Restore drill %s\n  file:   %s\n  tables: %d\n  rows:   %d\n  time:   %.1fs\n  %s\nRecord it on Admin → Data flows & backups.\n",
        $ok ? 'PASSED' : 'FAILED', basename($file), count($tables), $rows, $secs, implode("\n  ", $checks));
    $pdo->exec("DROP DATABASE `$into`");   // the copy holds personal data: don't leave it lying around
    exit($ok ? 0 : 1);
} catch (Throwable $e) {
    fwrite(STDERR, 'Restore drill FAILED: ' . $e->getMessage() . "\n");
    try { if (isset($pdo)) $pdo->exec("DROP DATABASE IF EXISTS `$into`"); } catch (Throwable $e2) {}
    exit(1);
}
